<?php

namespace App\Livewire\Team;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Avail: company-email users an admin removed from Avail Team. Auto-join skips them until an
 * admin lets them back in.
 */
class RemovedMembers extends Component
{
    use AuthorizesRequests;

    public function allowBack(int $userId)
    {
        try {
            $this->authorize('manageMembers', currentTeam());
            $user = $this->removedUsers()->firstWhere('id', $userId);
            if (! $user) {
                return;
            }
            availClearRemovedFromRoot($user);
            auditLog('ui.team_member.allowed_back', [
                'team_id' => currentTeam()->id,
                'member_id' => $user->id,
                'member_email' => $user->email,
            ]);
            $this->dispatch('success', "{$user->email} joins again as a member on their next sign-in.");
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.team.removed-members', [
            'removedUsers' => $this->removedUsers(),
        ]);
    }

    private function removedUsers(): Collection
    {
        if (currentTeam()?->id !== 0 || ! auth()->user()->can('manageMembers', currentTeam())) {
            return collect();
        }

        return User::whereNotNull('avail_removed_from_root_at')
            ->whereDoesntHave('teams', fn ($query) => $query->where('teams.id', 0))
            ->orderBy('email')
            ->get()
            ->filter(fn (User $user) => availEmailAutoJoins($user->email))
            ->values();
    }
}
