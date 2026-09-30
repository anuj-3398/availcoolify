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

/**
 * Avail: application columns a member may change on an application they created.
 * Everything else (repository/source, Docker Compose, custom Docker options, container labels,
 * port mappings, network aliases, ...) stays with admins.
 *
 * @return list<string>
 */
function availMemberEditableApplicationFields(): array
{
    return [
        'name', 'description', 'fqdn', 'git_branch', 'git_commit_sha',
        'build_pack', 'install_command', 'build_command', 'start_command',
        'base_directory', 'publish_directory', 'ports_exposes', 'watch_paths', 'redirect',
        'dockerfile', 'dockerfile_location', 'dockerfile_target_build',
        'docker_registry_image_name', 'docker_registry_image_tag', 'custom_nginx_configuration',
        'pre_deployment_command', 'pre_deployment_command_container',
        'post_deployment_command', 'post_deployment_command_container',
        'is_http_basic_auth_enabled', 'http_basic_auth_username', 'http_basic_auth_password',
        'health_check_enabled', 'health_check_type', 'health_check_command', 'health_check_method',
        'health_check_scheme', 'health_check_host', 'health_check_port', 'health_check_path',
        'health_check_return_code', 'health_check_response_text', 'health_check_interval',
        'health_check_timeout', 'health_check_retries', 'health_check_start_period',
        'custom_healthcheck_found', 'updated_at',
    ];
}

/**
 * @return list<string>
 */
function availMemberEditableApplicationSettings(): array
{
    return [
        'is_static', 'is_spa', 'is_force_https_enabled', 'is_gzip_enabled', 'is_stripprefix_enabled',
        'is_auto_deploy_enabled', 'is_git_submodules_enabled', 'is_git_lfs_enabled',
        'is_git_shallow_clone_enabled', 'disable_build_cache', 'inject_build_args_to_dockerfile',
        'include_source_commit_in_build', 'updated_at',
    ];
}

/**
 * @return list<string>
 */
function availMemberBuildPacks(): array
{
    return ['railpack', 'nixpacks', 'static', 'dockerfile', 'dockerimage'];
}

/**
 * Avail: check the pending (unsaved) changes of an application before they are written. Admins
 * may change anything; the member who created the app only the fields above, and only their
 * app's own <name>.<wildcard domain> addresses. Throws with a readable message otherwise.
 */
function availGuardApplicationChanges(\App\Models\Application $application): void
{
    $user = auth()->user();
    if (! $user || availIsAdminOfResource($user, $application)) {
        return;
    }
    if (! availOwnsApplication($user, $application)) {
        throw new \Illuminate\Auth\Access\AuthorizationException('Only admins can change this application.');
    }

    $blocked = array_diff(array_keys($application->getDirty()), availMemberEditableApplicationFields());
    $settings = $application->settings;
    if ($settings) {
        $blocked = [...$blocked, ...array_diff(array_keys($settings->getDirty()), availMemberEditableApplicationSettings())];
    }
    if ($blocked !== []) {
        $names = collect($blocked)->map(fn ($field) => str($field)->replace('_', ' ')->lower())->unique()->join(', ');

        throw new \RuntimeException("Only admins can change: {$names}.");
    }

    if ($application->isDirty('build_pack') && ! in_array($application->build_pack, availMemberBuildPacks(), true)) {
        throw new \RuntimeException('Only admins can use this build pack. Choose Railpack, Nixpacks, Static or Dockerfile.');
    }

    if ($application->isDirty('fqdn')) {
        $error = availMemberDomainError($application, $application->fqdn);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
    }
}

/**
 * Avail: why a member may not give their app these addresses, or null when they may.
 * Allowed: up to 3 addresses of the form http(s)://<name>.<the server's wildcard domain>, where
 * <name> is a single label (dotted names are PR preview hosts) and no other app uses the host.
 */
function availMemberDomainError(\App\Models\Application $application, ?string $fqdn): ?string
{
    $wildcardHost = strtolower((string) parse_url((string) data_get($application->destination?->server, 'settings.wildcard_domain'), PHP_URL_HOST));
    if ($wildcardHost === '') {
        return 'Only admins can set addresses on this server.';
    }

    $urls = collect(explode(',', (string) $fqdn))->map(fn ($url) => trim($url))->filter()->values();
    if ($urls->count() > 3) {
        return 'Use at most 3 addresses.';
    }

    $example = "https://my-app.{$wildcardHost}";
    foreach ($urls as $url) {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['port']) || isset($parts['query'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return "Use an address like {$example}.";
        }
        if (! str_ends_with($host, '.'.$wildcardHost)) {
            return "Only admins can use addresses outside {$wildcardHost}. Use one like {$example}.";
        }
        $label = substr($host, 0, -strlen('.'.$wildcardHost));
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
            return "Use a single name of letters, digits and dashes, like {$example}. Names with dots are reserved for PR previews.";
        }
        if (in_array($label, ['www', 'coolify', 'admin', 'api', 'mail'], true)) {
            return "{$host} is reserved. Pick another name.";
        }
        if (availHostUsedElsewhere($host, $application)) {
            return "{$host} is already used by another app. Pick another name.";
        }
    }

    return null;
}

function availHostUsedElsewhere(string $host, \App\Models\Application $application): bool
{
    $hostsOf = fn (?string $fqdn) => collect(explode(',', (string) $fqdn))
        ->map(fn ($url) => strtolower((string) parse_url(trim($url), PHP_URL_HOST)))
        ->filter();

    if ($hostsOf(instanceSettings()->fqdn)->contains($host)) {
        return true;
    }

    $applications = \App\Models\Application::query()
        ->whereKeyNot($application->getKey())
        ->where('fqdn', 'like', '%'.$host.'%')
        ->pluck('fqdn');
    $services = \App\Models\ServiceApplication::query()
        ->where('fqdn', 'like', '%'.$host.'%')
        ->pluck('fqdn');

    return $applications->concat($services)->contains(fn ($fqdn) => $hostsOf($fqdn)->contains($host));
}
