<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Avail: new applications (and their PR previews) stop after 10 crash restarts by default.
 */
uses(RefreshDatabase::class);

test('a new application gets a restart limit of 10, and so do its previews', function () {
    $team = Team::factory()->create();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $destination = Server::factory()->create(['team_id' => $team->id])->standaloneDockers()->firstOrFail();

    $application = Application::create([
        'name' => 'new-app',
        'git_repository' => 'anuj-3398/autobot',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ])->fresh();

    expect($application->max_restart_count)->toBe(10);

    $preview = new ApplicationPreview(['pull_request_id' => 7]);
    $preview->setRelation('application', $application);
    expect($preview->restartLimitMaximum())->toBe(10);
});

test('rows inserted without the model also get 10', function () {
    $team = Team::factory()->create();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $destination = Server::factory()->create(['team_id' => $team->id])->standaloneDockers()->firstOrFail();

    $id = DB::table('applications')->insertGetId([
        'uuid' => 'raw-insert-app',
        'name' => 'raw-app',
        'git_repository' => 'anuj-3398/autobot',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((int) DB::table('applications')->where('id', $id)->value('max_restart_count'))->toBe(10);
});

test('one-click service apps keep the upstream opt-in default', function () {
    expect((new \App\Models\ServiceApplication)->max_restart_count)->toBe(0);
});
