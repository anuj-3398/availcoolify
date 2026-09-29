<?php

namespace App\Livewire\Settings;

use App\Actions\User\RevokeUserTeamTokens;
use App\Models\Project;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Avail: which projects each guest may see, and until when.
 */
class GuestAccess extends Component
{
    public function mount()
    {
        if (! $this->canManage()) {
            return redirect()->route('dashboard');
        }
    }

    public function toggle(int $userId, int $projectId)
    {
        try {
            $this->authorizeManage();
            $guest = $this->guest($userId);
            $project = $this->project($projectId);
            $hasAccess = DB::table('project_guest_access')->where('project_id', $project->id)->where('user_id', $guest->id)->exists();
            if ($hasAccess) {
                availRevokeGuestProject($guest, $project->id);
            } else {
                availGrantGuestProject($guest, $project->id, auth()->id());
            }
            $this->forgetGuestCaches($guest);
            auditLog($hasAccess ? 'ui.guest_access.revoked' : 'ui.guest_access.granted', [
                'team_id' => currentTeam()->id,
                'member_id' => $guest->id,
                'member_email' => $guest->email,
                'project_id' => $project->id,
                'project_name' => $project->name,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function toggleInvitation(int $invitationId, int $projectId)
    {
        try {
            $this->authorizeManage();
            $invitation = TeamInvitation::whereTeamId(currentTeam()->id)->where('role', 'guest')->findOrFail($invitationId);
            $project = $this->project($projectId);
            $projectIds = collect($invitation->avail_project_ids ?? [])->map(fn ($id) => (int) $id);
            $projectIds = $projectIds->contains($project->id)
                ? $projectIds->reject(fn ($id) => $id === $project->id)
                : $projectIds->push($project->id);
            $invitation->update(['avail_project_ids' => $projectIds->unique()->values()->all()]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function extend(int $userId, int $days)
    {
        try {
            $this->authorizeManage();
            if (! in_array($days, [30, 60, 90], true)) {
                return;
            }
            $guest = $this->guest($userId);
            $current = availGuestExpiresAt($guest, currentTeam()->id);
            $from = $current && $current->isFuture() ? $current : now();
            $this->saveExpiry($guest, $from->copy()->addDays($days));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function setExpiry(int $userId, ?string $date)
    {
        try {
            $this->authorizeManage();
            $guest = $this->guest($userId);
            $expiresAt = blank($date) ? null : Carbon::parse($date)->endOfDay();
            if (! $expiresAt || ! $expiresAt->isFuture()) {
                $this->dispatch('error', 'Pick a date after today.');

                return;
            }
            $this->saveExpiry($guest, $expiresAt);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function removeExpiry(int $userId)
    {
        try {
            $this->authorizeManage();
            $this->saveExpiry($this->guest($userId), null);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function removeGuest(int $userId)
    {
        try {
            $this->authorizeManage();
            $guest = $this->guest($userId);
            $teamId = currentTeam()->id;
            DB::transaction(function () use ($guest, $teamId) {
                $guest->teams()->detach($teamId);
                RevokeUserTeamTokens::forUserTeam($guest, $teamId);
                $guest->clearStoredTeamIfMatches($teamId);
                availRevokeGuestProjectsInTeam($guest, $teamId);
                if ($teamId === 0) {
                    availMarkRemovedFromRoot($guest);
                }
            });
            Cache::forget("team:{$guest->id}");
            Cache::forget("user:{$guest->id}:team:{$teamId}");
            $this->forgetGuestCaches($guest);
            auditLog('ui.team_member.removed', [
                'team_id' => $teamId,
                'member_id' => $guest->id,
                'member_name' => $guest->name,
                'member_email' => $guest->email,
            ]);
            $this->dispatch('success', "{$guest->email} was removed.");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $teamId = currentTeam()->id;
        $projects = Project::where('team_id', $teamId)->orderBy('name')->get(['id', 'uuid', 'name']);
        $guests = currentTeam()->members()->wherePivot('role', 'guest')->orderBy('email')->get();
        $grants = DB::table('project_guest_access')
            ->whereIn('user_id', $guests->pluck('id'))
            ->whereIn('project_id', $projects->pluck('id'))
            ->get(['user_id', 'project_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('project_id')->map(fn ($id) => (int) $id)->all());
        $invitations = TeamInvitation::whereTeamId($teamId)->where('role', 'guest')->orderBy('email')->get();

        return view('livewire.settings.guest-access', [
            'projects' => $projects,
            'guests' => $guests,
            'grants' => $grants,
            'invitations' => $invitations,
            'warningDays' => (int) config('avail.guest.warning_days'),
        ]);
    }

    private function saveExpiry(User $guest, ?Carbon $expiresAt): void
    {
        availSetGuestExpiry($guest, currentTeam()->id, $expiresAt);
        $this->forgetGuestCaches($guest);
        auditLog('ui.guest_access.expiry_updated', [
            'team_id' => currentTeam()->id,
            'member_id' => $guest->id,
            'member_email' => $guest->email,
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);
        $this->dispatch('success', $expiresAt
            ? "Access for {$guest->email} now ends ".$expiresAt->format('M j, Y').'.'
            : "Access for {$guest->email} no longer expires.");
    }

    private function forgetGuestCaches(User $guest): void
    {
        Cache::forget(\App\Livewire\GlobalSearch::getCacheKey(currentTeam()->id).'_guest_'.$guest->id);
    }

    private function guest(int $userId): User
    {
        $guest = currentTeam()->members()->wherePivot('role', 'guest')->whereKey($userId)->first();
        abort_if(! $guest, 404);

        return $guest;
    }

    private function project(int $projectId): Project
    {
        return Project::where('team_id', currentTeam()->id)->findOrFail($projectId);
    }

    private function canManage(): bool
    {
        return isInstanceAdmin() && (bool) auth()->user()?->can('manageMembers', currentTeam());
    }

    private function authorizeManage(): void
    {
        abort_unless($this->canManage(), 403, 'Only admins can manage guest access.');
    }
}
