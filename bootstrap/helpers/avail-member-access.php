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
