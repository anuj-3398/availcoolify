<?php

use App\Actions\Database\StartDatabase;
use App\Models\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

/*
 * Avail: new resources deploy immediately after they are created, instead of waiting for
 * someone to press Deploy / Start on their configuration page.
 */

/**
 * Queue the first deployment of a newly created application.
 * Returns the deployment uuid, or null when it can't be deployed automatically yet
 * (same preconditions as the Deploy button), so the user lands on its configuration instead.
 */
function availAutoDeployApplication(Application $application): ?string
{
    try {
        if (! auth()->user()?->can('deploy', $application)) {
            return null;
        }
        if ($application->build_pack === 'dockercompose' && is_null($application->docker_compose_raw)) {
            return null;
        }
        $needsImageName = $application->destination?->server?->isSwarm()
            || data_get($application, 'settings.is_build_server_enabled')
            || $application->additional_servers()->count() > 0;
        if ($needsImageName && str($application->docker_registry_image_name)->isEmpty()) {
            return null;
        }

        $deploymentUuid = new_public_id();
        $result = queue_application_deployment(application: $application, deployment_uuid: $deploymentUuid);

        // 'queue_full' or 'skipped' → leave it to the user on the configuration page.
        return ($result['status'] ?? null) === 'queued' ? $deploymentUuid : null;
    } catch (\Throwable $e) {
        Log::warning('Automatic first deployment failed to queue.', [
            'application_uuid' => $application->uuid,
            'exception' => $e->getMessage(),
        ]);

        return null;
    }
}

/**
 * Where to send the user after creating an application: its live deployment log when the
 * first deployment was queued, otherwise its configuration page.
 */
function availRedirectAfterApplicationCreated(Application $application, array $parameters): RedirectResponse
{
    $deploymentUuid = availAutoDeployApplication($application);
    if ($deploymentUuid) {
        return redirect()->route('project.application.deployment.show', [
            ...$parameters,
            'deployment_uuid' => $deploymentUuid,
        ]);
    }

    return redirect()->route('project.application.configuration', $parameters);
}

/**
 * Start a newly created standalone database right away.
 */
function availAutoStartDatabase($database): void
{
    try {
        if (! auth()->user()?->can('manage', $database)) {
            return;
        }
        StartDatabase::run($database);
    } catch (\Throwable $e) {
        Log::warning('Automatic database start failed.', [
            'database_uuid' => data_get($database, 'uuid'),
            'exception' => $e->getMessage(),
        ]);
    }
}
