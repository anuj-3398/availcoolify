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
 * Avail: dashboard shows Projects, then Deployments (latest per application), and no Servers.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $this->team->id])->id]);
    $this->makeApp = fn (string $name) => Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'name' => $name,
    ]);
});

function availDashboardDeployment(Application $application, string $status): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'deployment_uuid' => 'deploy-'.fake()->unique()->uuid(),
        'deployment_url' => '/deployments/'.fake()->uuid(),
        'server_id' => test()->server->id,
        'server_name' => test()->server->name,
        'status' => $status,
        'pull_request_id' => 0,
    ]);
}

test('recent deployments show only the latest one per application', function () {
    $first = ($this->makeApp)('first-app');
    $second = ($this->makeApp)('second-app');
    availDashboardDeployment($first, ApplicationDeploymentStatus::FAILED->value);
    $latestFirst = availDashboardDeployment($first, ApplicationDeploymentStatus::FINISHED->value);
    $latestSecond = availDashboardDeployment($second, ApplicationDeploymentStatus::FINISHED->value);
    availDashboardDeployment($second, ApplicationDeploymentStatus::IN_PROGRESS->value);

    $recent = Livewire::test(ActiveDeployments::class)->get('recentDeployments');

    expect($recent->pluck('id')->all())->toBe([$latestSecond->id, $latestFirst->id]);
});

test('the dashboard lists projects before deployments and has no servers section', function () {
    $view = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));

    expect(strpos($view, 'title="Projects"'))->toBeLessThan(strpos($view, '<livewire:dashboard.active-deployments />'))
        ->and($view)->not->toContain('title="Servers"');

    $this->get(route('dashboard'))->assertOk()->assertDontSee('Infrastructure available for deployments');
});
