<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Avail: guests are team members with role "guest". They see only the projects ticked for them
 * (project_guest_access), read-only, until team_user.guest_expires_at (null = no expiry).
 */

/**
 * @return list<string>
 */
function availAutoJoinDomains(): array
{
    return collect(explode(',', (string) config('avail.auto_join_domains')))
        ->map(fn ($domain) => strtolower(ltrim(trim($domain), '@')))
        ->filter()
        ->values()
        ->all();
}

/**
 * Whether Clerk users with this email join Avail Team as members without an invitation.
 */
function availEmailAutoJoins(?string $email): bool
{
    $email = strtolower(trim((string) $email));
    if (! str_contains($email, '@')) {
        return false;
    }

    return in_array(str($email)->afterLast('@')->toString(), availAutoJoinDomains(), true);
}

function availIsGuest(?User $user = null, ?int $teamId = null): bool
{
    $user ??= auth()->user();
    $teamId ??= currentTeam()?->id;
    if (! $user || $teamId === null) {
        return false;
    }

    return $user->roleInTeam($teamId) === 'guest';
}

function availGuestExpiresAt(User $user, int $teamId): ?Carbon
{
    $value = DB::table('team_user')->where('team_id', $teamId)->where('user_id', $user->id)->value('guest_expires_at');

    return $value ? Carbon::parse($value) : null;
}

function availGuestExpired(User $user, int $teamId): bool
{
    return availGuestExpiresAt($user, $teamId)?->isPast() ?? false;
}

/**
 * Project ids the user may see as a guest of the team. Null when the user isn't a guest there
 * (no restriction applies); an empty list once their access has ended.
 *
 * @return list<int>|null
 */
function availGuestProjectIds(?User $user = null, ?int $teamId = null): ?array
{
    $user ??= auth()->user();
    $teamId ??= currentTeam()?->id;
    if (! $user || $teamId === null || ! availIsGuest($user, $teamId)) {
        return null;
    }
    if (availGuestExpired($user, $teamId)) {
        return [];
    }

    return DB::table('project_guest_access')
        ->join('projects', 'projects.id', '=', 'project_guest_access.project_id')
        ->where('projects.team_id', $teamId)
        ->where('project_guest_access.user_id', $user->id)
        ->pluck('projects.id')
        ->map(fn ($id) => (int) $id)
        ->all();
}

function availGuestCanSeeProject(User $user, int $projectId, int $teamId): bool
{
    $ids = availGuestProjectIds($user, $teamId);

    return $ids === null || in_array($projectId, $ids, true);
}

/**
 * Narrow an ownedByCurrentTeam() query to the projects a guest may see. No-op for everyone else.
 * $environmentRelation is the relation leading to the model's environment ('' for projects,
 * 'project' for environments).
 */
function availGuestScope(Builder $query, string $environmentRelation = 'environment'): Builder
{
    $ids = availGuestProjectIds();
    if ($ids === null) {
        return $query;
    }

    return match ($environmentRelation) {
        '' => $query->whereIn($query->getModel()->getTable().'.id', $ids),
        'project' => $query->whereIn($query->getModel()->getTable().'.project_id', $ids),
        default => $query->whereHas($environmentRelation, fn ($environment) => $environment->whereIn('project_id', $ids)),
    };
}

/**
 * The project a model belongs to (project, environment, resource, preview, env var, task...).
 */
function availProjectIdFor(mixed $model, int $depth = 0): ?int
{
    if (! $model instanceof Model || $depth > 4) {
        return null;
    }
    if ($model instanceof Project) {
        return (int) $model->getKey();
    }
    if ($model instanceof Environment) {
        return (int) $model->project_id;
    }
    if (filled($model->getAttribute('environment_id'))) {
        $projectId = Environment::whereKey($model->getAttribute('environment_id'))->value('project_id');

        return $projectId === null ? null : (int) $projectId;
    }
    foreach (['application', 'service', 'resourceable', 'resource'] as $relation) {
        if (method_exists($model, $relation)) {
            try {
                $parent = $model->{$relation};
            } catch (\Throwable) {
                continue;
            }
            if ($parent instanceof Model) {
                return availProjectIdFor($parent, $depth + 1);
            }
        }
    }

    return null;
}

/**
 * Gate::before hook. Guests may only "view" things inside their projects (plus their team);
 * every other ability is denied. Returns null for everyone else so the policies decide.
 */
function availGuestGateDecision(User $user, string $ability, array $arguments): ?bool
{
    if (! $user->teams->contains(fn ($team) => data_get($team, 'pivot.role') === 'guest')) {
        return null;
    }

    $model = $arguments[0] ?? null;
    $projectId = $model instanceof Model ? availProjectIdFor($model) : null;
    $teamId = $projectId !== null ? Project::whereKey($projectId)->value('team_id') : currentTeam()?->id;
    if ($teamId === null || ! availIsGuest($user, (int) $teamId)) {
        return null;
    }

    if ($ability === 'viewAny') {
        return null;
    }
    if ($ability === 'view') {
        if ($model instanceof Team) {
            return null;
        }

        return $projectId !== null && in_array($projectId, availGuestProjectIds($user, (int) $teamId) ?? [], true);
    }

    return false;
}

/**
 * Pages a guest may open. Everything else behind a login sends them to the dashboard.
 *
 * @return list<string>
 */
function availGuestAllowedRoutes(): array
{
    return [
        'dashboard',
        'profile',
        'profile.avatar',
        'profile.appearance',
        'developer-guide',
        'team.select',
        'team.invitation.show',
        'team.invitation.accept',
        'verify.email',
        'verify.verify',
        'auth.force-password-reset',
        'project.index',
        'project.icon',
        'project.show',
        'project.resource.index',
        'project.application.configuration',
        'project.application.deployment.index',
        'project.application.deployment.show',
        'project.database.configuration',
        'project.service.configuration',
    ];
}

function availGrantGuestProject(User $user, int $projectId, ?int $grantedBy = null): void
{
    DB::table('project_guest_access')->insertOrIgnore([
        'project_id' => $projectId,
        'user_id' => $user->id,
        'granted_by_user_id' => $grantedBy,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function availRevokeGuestProject(User $user, int $projectId): void
{
    DB::table('project_guest_access')->where('project_id', $projectId)->where('user_id', $user->id)->delete();
}

/**
 * Remove every project grant the user has inside a team (they left it or stopped being a guest).
 */
function availRevokeGuestProjectsInTeam(User $user, int $teamId): void
{
    DB::table('project_guest_access')
        ->where('user_id', $user->id)
        ->whereIn('project_id', Project::where('team_id', $teamId)->select('id'))
        ->delete();
}

/**
 * Set when a guest's access ends (null = never) and reset the reminder emails.
 */
function availSetGuestExpiry(User $user, int $teamId, ?Carbon $expiresAt): void
{
    DB::table('team_user')->where('team_id', $teamId)->where('user_id', $user->id)->update([
        'guest_expires_at' => $expiresAt,
        'guest_expiry_warning_sent_at' => null,
        'guest_expired_notice_sent_at' => null,
    ]);
    $user->unsetRelation('teams');
}

/**
 * When access from this invitation ends, counted from now (acceptance). Null = no expiry.
 */
function availGuestExpiryFromInvitation(TeamInvitation $invitation): ?Carbon
{
    if (filled($invitation->avail_access_until)) {
        return Carbon::parse($invitation->avail_access_until)->endOfDay();
    }
    if (filled($invitation->avail_access_days)) {
        return now()->addDays((int) $invitation->avail_access_days);
    }

    return null;
}

/**
 * Human wording of an invitation's access duration, e.g. "30 days after accepting".
 */
function availInvitationAccessLabel(TeamInvitation $invitation): string
{
    if (filled($invitation->avail_access_until)) {
        return 'Until '.Carbon::parse($invitation->avail_access_until)->format('M j, Y');
    }
    if (filled($invitation->avail_access_days)) {
        return $invitation->avail_access_days.' days after accepting';
    }

    return 'No expiry';
}

/**
 * Join the invitation's team with its role. Guests also get the invitation's projects and an
 * access end date counted from now. Accepting an invitation undoes an earlier removal.
 */
function availAcceptInvitation(User $user, TeamInvitation $invitation): void
{
    DB::transaction(function () use ($user, $invitation) {
        $teamId = (int) $invitation->team_id;
        $isGuest = $invitation->role === 'guest';
        $user->teams()->attach($teamId, [
            'role' => $invitation->role,
            'guest_expires_at' => $isGuest ? availGuestExpiryFromInvitation($invitation) : null,
        ]);
        if ($isGuest) {
            $projectIds = Project::where('team_id', $teamId)
                ->whereIn('id', collect($invitation->avail_project_ids ?? [])->map(fn ($id) => (int) $id)->all())
                ->pluck('id');
            foreach ($projectIds as $projectId) {
                availGrantGuestProject($user, (int) $projectId);
            }
        }
        if ($teamId === 0) {
            availClearRemovedFromRoot($user);
        }
    });
    $user->unsetRelation('teams');
}

/**
 * Remember that an admin removed this user, so auto-join doesn't add them back on their next login.
 */
function availMarkRemovedFromRoot(User $user): void
{
    User::whereKey($user->id)->update(['avail_removed_from_root_at' => now()]);
    $user->avail_removed_from_root_at = now();
}

function availClearRemovedFromRoot(User $user): void
{
    User::whereKey($user->id)->update(['avail_removed_from_root_at' => null]);
    $user->avail_removed_from_root_at = null;
}
