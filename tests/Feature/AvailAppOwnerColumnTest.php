<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Dashboard\ActiveDeployments;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Avail: the environment resource table and the dashboard deployment tables
 * show each app's owner (the user who created it), with an App Owner filter.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    $this->member = User::factory()->create(['name' => 'Mia Member']);
    $this->team = Team::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'owner']);
    $this->member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->makeApp = fn (string $name, ?int $creatorId) => Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'name' => $name,
        'avail_created_by_user_id' => $creatorId,
    ]);
});

test('the owner name is the creator name, falling back to the email', function () {
    $owned = ($this->makeApp)('owned-app', $this->member->id);
    $untracked = ($this->makeApp)('old-app', null);
    Application::query()->whereKey($untracked->id)->update(['avail_created_by_user_id' => null]);
    $nameless = User::factory()->create(['name' => '']);
    $namelessApp = ($this->makeApp)('nameless-app', $nameless->id);

    expect(availOwnerName($owned->fresh()))->toBe('Mia Member')
        ->and(availOwnerName($untracked->fresh()))->toBeNull()
        ->and(availOwnerName($namelessApp->fresh()))->toBe($nameless->email)
        ->and(availOwnerName($this->server))->toBeNull();
});

test('the environment resource table shows an owner column and owner filter', function () {
    ($this->makeApp)('member-app', $this->member->id);
    ($this->makeApp)('admin-app', $this->admin->id);

    $this->get(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]))
        ->assertOk()
        ->assertSee('<div class="resource-owner">App Owner</div>', false)
        ->assertSee('Mia Member')
        ->assertSee("label: 'App Owners'", false);
});

test('the dashboard deployment tables show the app owner', function () {
    $app = ($this->makeApp)('member-app', $this->member->id);
    ApplicationDeploymentQueue::create([
        'application_id' => $app->id,
        'application_name' => $app->name,
        'deployment_uuid' => 'deploy-'.fake()->uuid(),
        'deployment_url' => '/deployments/'.fake()->uuid(),
        'server_id' => $this->server->id,
        'server_name' => $this->server->name,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'pull_request_id' => 0,
    ]);

    Livewire::test(ActiveDeployments::class)
        ->assertSeeHtml('<span>App Owner</span>')
        ->assertSee('Mia Member');
});
