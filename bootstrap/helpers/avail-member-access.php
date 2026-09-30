<?php

use Illuminate\Support\Facades\Gate;

/**
 * Avail: "New resource" types a team member (not an admin) may create. Only applications from a
 * public repo, a repo they can push to (GitHub connect), or a Dockerfile / image. No databases,
 * services, deploy keys or team-wide Git sources.
 *
 * @return list<string>
 */
function availMemberResourceTypes(): array
{
    return ['public', 'private-gh-app', 'dockerfile', 'docker-image'];
}

function availCanCreateResourceType(?string $type): bool
{
    if (Gate::allows('createAnyResource')) {
        return true;
    }
    if (! Gate::allows('createApplication')) {
        return false;
    }

    return blank($type) || in_array($type, availMemberResourceTypes(), true);
}

/**
 * Avail: a member manages the environment variables of the applications they created
 * (applications.avail_created_by_user_id). Guests never do.
 */
function availOwnsApplication(?\App\Models\User $user, mixed $resource): bool
{
    if (! $user || ! $resource instanceof \App\Models\Application || $resource->avail_created_by_user_id === null) {
        return false;
    }
    $teamId = $resource->team()?->id;

    return $teamId !== null
        && (int) $resource->avail_created_by_user_id === (int) $user->id
        && in_array($user->roleInTeam($teamId), ['member', 'admin', 'owner'], true);
}

/**
 * Avail: environment variable values stay hidden from members and guests, except on the
 * applications a member created.
 */
function availHidesEnvValues(mixed $resource): bool
{
    $user = auth()->user();
    if (! $user) {
        return true;
    }

    return $user->isMember() && ! availOwnsApplication($user, $resource);
}

function availIsAdminOfResource(?\App\Models\User $user, mixed $resource): bool
{
    $teamId = is_object($resource) && method_exists($resource, 'team') ? $resource->team()?->id : null;

    return $user !== null && $teamId !== null && $user->isAdminOfTeam($teamId);
}

/**
 * Avail: {{team.X}}, {{project.X}}, {{environment.X}} and {{server.X}} pull in shared secrets
 * a member cannot see; only admins may reference them.
 */
function availReferencesSharedVariables(mixed $value): bool
{
    return is_string($value) && preg_match('/\{\{\s*(team|project|environment|server)\s*\./i', $value) === 1;
}

function availSharedReferenceError(): string
{
    return 'Only admins can use shared variables ({{team.KEY}}, {{project.KEY}}, ...). Ask an admin to add this variable.';
}

/**
 * Avail: everyone works in the root team (team 0, "Avail Team"). When the Clerk "auto-join root
 * team" setting is on, a user outside it with a company email (config avail.auto_join_domains)
 * joins as a member, unless an admin removed them. Everyone else needs an invitation.
 * Returns false when the user doesn't auto-join.
 */
function availJoinRootTeam(?\App\Models\User $user): bool
{
    if (! $user || $user->id === 0) {
        return false;
    }
    if (! availEmailAutoJoins($user->email) || $user->avail_removed_from_root_at !== null) {
        return false;
    }
    $autoJoin = (bool) \App\Models\OauthSetting::where('provider', 'clerk')->value('auto_join_root_team');
    if (! $autoJoin || ! \App\Models\Team::find(0)) {
        return false;
    }
    if (! $user->teams()->whereKey(0)->exists()) {
        $user->teams()->attach(0, ['role' => 'member']);
    }
    $user->unsetRelation('teams');

    return true;
}

/**
 * Avail: the owner of the root team (Avail Team) runs the platform, e.g. upgrades.
 */
function availIsPlatformOwner(): bool
{
    return auth()->user()?->roleInTeam(0) === 'owner';
}
