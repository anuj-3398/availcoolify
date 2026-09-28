<?php

use App\Livewire\Project\New\GithubPrivateRepository;
use App\Livewire\Project\New\Select;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Avail: team members can create, deploy and delete their own applications, but not databases,
 * services, team-wide Git sources or configuration.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->newUrl = route('project.resource.create', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]);
});

function actAsTeamUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('members may create applications but not other resources', function () {
    actAsTeamUser($this->member, $this->team);

    expect($this->member->can('create', Application::class))->toBeTrue()
        ->and($this->member->can('createApplication'))->toBeTrue()
        ->and($this->member->can('createAnyResource'))->toBeFalse()
        ->and(availCanCreateResourceType(null))->toBeTrue()
        ->and(availCanCreateResourceType('private-gh-app'))->toBeTrue()
        ->and(availCanCreateResourceType('dockerfile'))->toBeTrue()
        ->and(availCanCreateResourceType('postgresql'))->toBeFalse()
        ->and(availCanCreateResourceType('one-click-service-n8n'))->toBeFalse()
        ->and(availCanCreateResourceType('docker-compose-empty'))->toBeFalse()
        ->and(availCanCreateResourceType('private-deploy-key'))->toBeFalse();
});

test('admins keep every resource type', function () {
    actAsTeamUser($this->admin, $this->team);

    expect(availCanCreateResourceType('postgresql'))->toBeTrue()
        ->and(availCanCreateResourceType('private-deploy-key'))->toBeTrue();
});

test('a member opens New resource but cannot create a database through it', function () {
    actAsTeamUser($this->member, $this->team);

    $this->get($this->newUrl)->assertOk();
    $this->get($this->newUrl.'?type=postgresql&destination=x')->assertForbidden();
});

test('the resource picker only offers members application types', function () {
    actAsTeamUser($this->member, $this->team);

    $select = new Select;
    $types = $select->loadServices();

    expect($types['databases'])->toBe([])
        ->and($types['services'])->toBe([])
        ->and(collect($types['gitBasedApplications'])->pluck('id')->all())->toBe(['public', 'private-gh-app']);

    $select->setType('postgresql');
    expect($select->loading)->toBeFalse()
        ->and((new ReflectionProperty($select, 'type'))->isInitialized($select))->toBeFalse();
});

function memberTestApplication($test, ?int $createdBy): Application
{
    $server = Server::factory()->create(['team_id' => $test->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::create([
        'name' => 'member-app',
        'git_repository' => 'anuj-3398/autobot',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $test->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $application->forceFill(['avail_created_by_user_id' => $createdBy])->save();

    return $application->fresh();
}

test('a new application records who created it', function () {
    actAsTeamUser($this->member, $this->team);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $application = Application::create([
        'name' => 'created-by-member',
        'git_repository' => 'anuj-3398/autobot',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    expect($application->fresh()->avail_created_by_user_id)->toBe($this->member->id);
});

test('members may delete only the applications they created', function () {
    $otherMember = User::factory()->create();
    $otherMember->teams()->attach($this->team, ['role' => 'member']);
    $outsider = User::factory()->create();

    $own = memberTestApplication($this, $this->member->id);
    $legacy = memberTestApplication($this, null);

    expect($this->member->can('delete', $own))->toBeTrue()
        ->and($otherMember->fresh()->can('delete', $own))->toBeFalse()
        ->and($this->member->can('delete', $legacy))->toBeFalse()
        ->and($this->admin->can('delete', $legacy))->toBeTrue();

    // Leaving the team removes the right to delete.
    $this->member->teams()->detach($this->team);
    expect($this->member->fresh()->can('delete', $own))->toBeFalse()
        ->and($outsider->can('delete', $own))->toBeFalse();
});

test('members cannot use team-wide GitHub sources, only their own connection', function () {
    actAsTeamUser($this->member, $this->team);
    $teamSource = GithubApp::create([
        'name' => 'team-source',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 999,
        'installation_id' => 1,
        'client_id' => 'x',
        'client_secret' => 'y',
        'webhook_secret' => 'z',
        'is_public' => false,
        'team_id' => $this->team->id,
    ]);

    $component = Livewire::test(GithubPrivateRepository::class, ['type' => 'private-gh-app']);

    expect($component->get('github_apps'))->toHaveCount(0);
    $component->call('loadRepositories', $teamSource->id)->assertForbidden();
});

test('the New resource button is shown to members', function () {
    actAsTeamUser($this->member, $this->team);

    $this->get(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]))->assertOk()->assertSee('New resource');
});
