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
 * Avail: everyone works in the root team (team 0, "Avail Team"). When the Clerk "auto-join root
 * team" setting is on, a user outside it joins as a member. Returns false when auto-join is off.
 */
function availJoinRootTeam(?\App\Models\User $user): bool
{
    if (! $user || $user->id === 0) {
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
