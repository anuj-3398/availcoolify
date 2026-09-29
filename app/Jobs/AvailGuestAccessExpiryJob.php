<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TransactionalEmails\AvailGuestAccessNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\Contracts\Silenced;

/**
 * Avail: emails guests whose access ends within config('avail.guest.warning_days'), and guests
 * whose access ended in the last week. Each email goes out once per end date (extending access
 * resets it). Does nothing while transactional email isn't configured, so the emails start on
 * their own once it is.
 */
class AvailGuestAccessExpiryJob implements ShouldQueue, Silenced
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 120;

    public function handle(): void
    {
        if (! is_transactional_emails_enabled()) {
            return;
        }

        $warningDays = (int) config('avail.guest.warning_days');

        DB::table('team_user')
            ->where('role', 'guest')
            ->whereNotNull('guest_expires_at')
            ->where('guest_expires_at', '>', now())
            ->where('guest_expires_at', '<=', now()->addDays($warningDays))
            ->whereNull('guest_expiry_warning_sent_at')
            ->get()
            ->each(fn ($membership) => $this->notify($membership, 'ending', 'guest_expiry_warning_sent_at'));

        DB::table('team_user')
            ->where('role', 'guest')
            ->whereNotNull('guest_expires_at')
            ->where('guest_expires_at', '<=', now())
            ->where('guest_expires_at', '>', now()->subDays(7))
            ->whereNull('guest_expired_notice_sent_at')
            ->get()
            ->each(fn ($membership) => $this->notify($membership, 'ended', 'guest_expired_notice_sent_at'));
    }

    private function notify(object $membership, string $kind, string $sentColumn): void
    {
        $user = User::find($membership->user_id);
        $team = Team::find($membership->team_id);
        if (! $user || ! $team) {
            return;
        }

        // Claim the email first so two runs never send it twice.
        $claimed = DB::table('team_user')
            ->where('team_id', $membership->team_id)
            ->where('user_id', $membership->user_id)
            ->where('guest_expires_at', $membership->guest_expires_at)
            ->whereNull($sentColumn)
            ->update([$sentColumn => now()]);
        if ($claimed !== 1) {
            return;
        }

        $projectNames = Project::where('team_id', $team->id)
            ->whereIn('id', DB::table('project_guest_access')->where('user_id', $user->id)->select('project_id'))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        try {
            $user->notify(new AvailGuestAccessNotice($kind, Carbon::parse($membership->guest_expires_at), $team->name, $projectNames));
        } catch (\Throwable $e) {
            // Let the next run retry.
            DB::table('team_user')
                ->where('team_id', $membership->team_id)
                ->where('user_id', $membership->user_id)
                ->update([$sentColumn => null]);
            report($e);
        }
    }
}
