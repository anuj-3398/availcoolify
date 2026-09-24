<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Support\Collection;
use Spatie\Url\Url;

/*
 * Preview Guard: Clerk-backed access control for application URLs.
 *
 * Traefik's ForwardAuth middleware asks Coolify (/preview-guard/verify) whether each
 * request may pass. Visitors without a valid per-domain cookie are sent through the
 * normal Clerk login and then handed back to the app URL with a one-time token.
 */

const PREVIEW_GUARD_SCOPES = ['off', 'previews', 'production', 'both'];

const PREVIEW_GUARD_COOKIE = '__coolify_guard';

const PREVIEW_GUARD_TOKEN_PARAM = '__coolify_guard_token';

function previewGuardEnabledFor(Application $application, int $pullRequestId = 0): bool
{
    $scope = data_get($application, 'preview_guard_scope', 'off') ?? 'off';

    if ($pullRequestId === 0) {
        return in_array($scope, ['production', 'both'], true);
    }

    return in_array($scope, ['previews', 'both'], true);
}

/**
 * Hosts that belong to the application (pull request 0) or one of its preview deployments.
 */
function previewGuardHosts(Application $application, int $pullRequestId = 0): Collection
{
    if ($pullRequestId === 0) {
        $fqdn = $application->fqdn;
    } else {
        $fqdn = ApplicationPreview::where('application_id', $application->id)
            ->where('pull_request_id', $pullRequestId)
            ->value('fqdn');
    }

    return str($fqdn ?? '')->explode(',')
        ->map(fn ($domain) => trim($domain))
        ->filter()
        ->map(function ($domain) {
            try {
                return strtolower(Url::fromString($domain)->getHost());
            } catch (\Throwable) {
                return null;
            }
        })
        ->filter()
        ->unique()
        ->values();
}

function previewGuardVerifyAddress(Application $application, int $pullRequestId = 0): string
{
    $query = http_build_query(['app' => $application->uuid, 'pr' => $pullRequestId]);

    // Traefik on the Coolify host reaches the Coolify container over the shared `coolify` network.
    if ($application->destination?->server?->isLocalhost()) {
        return "http://coolify:8080/preview-guard/verify?{$query}";
    }

    $base = rtrim(instanceSettings()->fqdn ?: config('app.url'), '/');

    return "{$base}/preview-guard/verify?{$query}";
}

/**
 * Adds the ForwardAuth middleware to every Traefik router generated for the application.
 * The guard runs first so it sees the original, unstripped request path.
 */
function applyPreviewGuardLabels(Collection $labels, Application $application, ?ApplicationPreview $preview = null): Collection
{
    $pullRequestId = (int) data_get($preview, 'pull_request_id', 0);
    if (! previewGuardEnabledFor($application, $pullRequestId)) {
        return $labels;
    }

    $routers = $labels
        ->map(fn ($label) => preg_match('/^traefik\.http\.routers\.([^.]+)\.rule=/', (string) $label, $m) ? $m[1] : null)
        ->filter()
        ->unique();
    if ($routers->isEmpty()) {
        return $labels;
    }

    $uuid = $pullRequestId === 0 ? $application->uuid : "{$application->uuid}-pr-{$pullRequestId}";
    $middleware = "preview-guard-{$uuid}";
    $labels = $labels->values();
    $labels->push("traefik.http.middlewares.{$middleware}.forwardauth.address=".previewGuardVerifyAddress($application, $pullRequestId));
    $labels->push("traefik.http.middlewares.{$middleware}.forwardauth.trustForwardHeader=false");

    foreach ($routers as $router) {
        $prefix = "traefik.http.routers.{$router}.middlewares=";
        $index = $labels->search(fn ($label) => str_starts_with((string) $label, $prefix));
        if ($index === false) {
            $labels->push($prefix.$middleware);

            continue;
        }
        $existing = collect(explode(',', substr($labels[$index], strlen($prefix))))->filter();
        // Let plain-HTTP routers redirect to HTTPS before asking for a login.
        $chain = $existing->contains('redirect-to-https')
            ? $existing->push($middleware)
            : $existing->prepend($middleware);
        $labels[$index] = $prefix.$chain->join(',');
    }

    return $labels->sort()->values();
}
