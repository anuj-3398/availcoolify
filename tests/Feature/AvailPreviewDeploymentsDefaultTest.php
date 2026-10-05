<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Avail: new applications have PR preview deployments switched on (upstream: off). Docker Compose
 * apps stay opt-in, because Preview Guard (the Clerk login in front of previews) doesn't cover them.
 */
uses(RefreshDatabase::class);

function availNewApplication(string $buildPack): Application
{
    $team = Team::factory()->create();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $destination = Server::factory()->create(['team_id' => $team->id])->standaloneDockers()->firstOrFail();

    return Application::create([
        'name' => "new-{$buildPack}-app",
        'git_repository' => 'anuj-3398/autobot',
        'git_branch' => 'main',
        'build_pack' => $buildPack,
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ])->fresh();
}

test('a new application has preview deployments enabled', function (string $buildPack) {
    expect(availNewApplication($buildPack)->settings->is_preview_deployments_enabled)->toBeTrue();
})->with(['nixpacks', 'railpack', 'dockerfile', 'static']);

test('a new Docker Compose application keeps previews off', function () {
    expect(availNewApplication('dockercompose')->settings->is_preview_deployments_enabled)->toBeFalse();
});

test('enabling previews does not widen who can trigger them', function () {
    expect(availNewApplication('nixpacks')->settings->is_pr_deployments_public_enabled)->toBeFalse();
});
