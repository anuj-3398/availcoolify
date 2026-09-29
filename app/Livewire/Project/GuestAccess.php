<?php

namespace App\Livewire\Project;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Avail: the guests who may see one project (same data as Settings > Guest access).
 */
class GuestAccess extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public ?string $selectedGuestId = null;

    public function add()
    {
        try {
            $this->authorize('update', $this->project);
            $guest = $this->teamGuests()->firstWhere('id', (int) $this->selectedGuestId);
            if (! $guest) {
                $this->dispatch('error', 'Pick a guest first.');

                return;
            }
            availGrantGuestProject($guest, $this->project->id, auth()->id());
            $this->forgetSearchCache($guest);
            auditLog('ui.guest_access.granted', [
                'team_id' => $this->project->team_id,
                'member_id' => $guest->id,
                'member_email' => $guest->email,
                'project_id' => $this->project->id,
                'project_name' => $this->project->name,
            ]);
            $this->selectedGuestId = null;
            $this->dispatch('success', "{$guest->email} can now see {$this->project->name}.");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function remove(int $userId)
    {
        try {
            $this->authorize('update', $this->project);
            $guest = $this->project->guestUsers()->whereKey($userId)->first();
            if (! $guest) {
                return;
            }
            availRevokeGuestProject($guest, $this->project->id);
            $this->forgetSearchCache($guest);
            auditLog('ui.guest_access.revoked', [
                'team_id' => $this->project->team_id,
                'member_id' => $guest->id,
                'member_email' => $guest->email,
                'project_id' => $this->project->id,
                'project_name' => $this->project->name,
            ]);
            $this->dispatch('success', "{$guest->email} can no longer see {$this->project->name}.");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $guestsWithAccess = $this->project->guestUsers()
            ->whereIn('users.id', $this->teamGuests()->pluck('id'))
            ->orderBy('email')
            ->get();
        $grantedBy = User::whereIn('id', $guestsWithAccess->pluck('pivot.granted_by_user_id')->reject(fn ($id) => $id === null))->pluck('email', 'id');

        return view('livewire.project.guest-access', [
            'guestsWithAccess' => $guestsWithAccess,
            'otherGuests' => $this->teamGuests()->whereNotIn('id', $guestsWithAccess->pluck('id'))->values(),
            'grantedBy' => $grantedBy,
        ]);
    }

    private function teamGuests()
    {
        return $this->project->team->members()->wherePivot('role', 'guest')->orderBy('email')->get();
    }

    private function forgetSearchCache(User $guest): void
    {
        Cache::forget(\App\Livewire\GlobalSearch::getCacheKey($this->project->team_id).'_guest_'.$guest->id);
    }
}
