<?php

use App\Livewire\Project\New\PublicGitRepository;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Avail: Vercel-style app setup (real default branch, commands and variables before the first
 * deploy) and members editing the everyday settings of the apps they created.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'fqdn' => 'https://coolify.avail.test']));

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
    $this->otherMember = User::factory()->create();
    $this->otherMember->teams()->attach($this->team, ['role' => 'member']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->forceFill(['wildcard_domain' => 'https://apps.avail.test'])->save();
    $this->destination = $this->server->standaloneDockers()->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->publicGithub = GithubApp::forceCreate([
        'uuid' => 'public-github', 'name' => 'Public GitHub', 'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com', 'is_public' => true, 'team_id' => 0,
    ]);
});

function setupActAs(User $user, Team $team): void
{
    test()->actingAs($user->fresh());
    session(['currentTeam' => $team]);
}

function setupApp($test, string $name, ?int $createdBy, array $attributes = []): Application
{
    $application = Application::create([
        'name' => $name,
        'git_repository' => 'availproject/nexus-fast-bridge',
        'git_branch' => 'main',
        'build_pack' => 'railpack',
        'ports_exposes' => '3000',
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
        ...$attributes,
    ]);
    $application->forceFill(['avail_created_by_user_id' => $createdBy])->save();

    return $application->fresh();
}

function setupPublicForm($test)
{
    return Livewire::test(PublicGitRepository::class, ['type' => 'public'])
        ->set('parameters', ['project_uuid' => $test->project->uuid, 'environment_uuid' => $test->environment->uuid])
        ->set('query', ['destination' => $test->destination->uuid])
        ->set('repository_url', 'https://gitlab.example.com/avail/fastbridge.git')
        ->set('git_repository', 'https://gitlab.example.com/avail/fastbridge.git')
        ->set('git_branch', 'master')
        ->set('build_pack', 'railpack');
}

test('the public repository form uses the real default branch and lists the branches', function () {
    Http::fake([
        'api.github.com/repos/availproject/nexus-fast-bridge/branches*' => Http::response([['name' => 'master'], ['name' => 'dev']], 200, ['X-RateLimit-Remaining' => '55', 'X-RateLimit-Reset' => '1790000000']),
        'api.github.com/repos/availproject/nexus-fast-bridge' => Http::response(['default_branch' => 'master'], 200, ['X-RateLimit-Remaining' => '56', 'X-RateLimit-Reset' => '1790000000']),
    ]);
    setupActAs($this->member, $this->team);

    Livewire::test(PublicGitRepository::class, ['type' => 'public'])
        ->set('repository_url', 'https://github.com/availproject/nexus-fast-bridge')
        ->call('loadBranch')
        ->assertSet('branchFound', true)
        ->assertSet('git_branch', 'master')
        ->assertSet('availBranches', ['master', 'dev']);
});

test('when GitHub cannot be read the branch stays editable instead of silently locked to main', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);
    setupActAs($this->member, $this->team);

    Livewire::test(PublicGitRepository::class, ['type' => 'public'])
        ->set('repository_url', 'https://github.com/availproject/nexus-fast-bridge')
        ->call('loadBranch')
        ->assertSet('branchFound', true)
        ->assertSet('availBranches', [])
        ->assertDispatched('warning')
        ->set('git_branch', 'master')
        ->assertSet('git_branch', 'master');
});

test('commands and variables are saved before the first deploy, and deploying can wait', function () {
    setupActAs($this->member, $this->team);

    setupPublicForm($this)
        ->set('installCommand', 'pnpm install --frozen-lockfile')
        ->set('buildCommand', 'pnpm build')
        ->set('startCommand', 'pnpm start')
        ->set('setupEnvironment', "API_URL=https://api.example.com\nOPENAI_API_KEY=sk-test")
        ->set('deployAfterCreate', false)
        ->call('submit');

    $application = Application::where('git_repository', 'https://gitlab.example.com/avail/fastbridge.git')->firstOrFail();
    expect($application->git_branch)->toBe('master')
        ->and($application->install_command)->toBe('pnpm install --frozen-lockfile')
        ->and($application->build_command)->toBe('pnpm build')
        ->and($application->start_command)->toBe('pnpm start')
        ->and($application->avail_created_by_user_id)->toBe($this->member->id)
        ->and($application->environment_variables()->where('key', 'OPENAI_API_KEY')->first()?->value)->toBe('sk-test')
        ->and($application->environment_variables()->where('key', 'API_URL')->first()?->is_buildtime)->toBeTrue()
        ->and(ApplicationDeploymentQueue::where('application_id', $application->id)->exists())->toBeFalse();
});

test('members cannot deploy Docker Compose or use shared variables at setup', function () {
    setupActAs($this->member, $this->team);

    setupPublicForm($this)->set('build_pack', 'dockercompose')->call('submit');
    setupPublicForm($this)->set('setupEnvironment', 'DB_PASSWORD={{team.DB_PASSWORD}}')->call('submit');

    expect(Application::where('git_repository', 'https://gitlab.example.com/avail/fastbridge.git')->exists())->toBeFalse();
});

test('admins may still deploy Docker Compose from a public repository', function () {
    setupActAs($this->admin, $this->team);

    setupPublicForm($this)->set('build_pack', 'dockercompose')->set('deployAfterCreate', false)->call('submit');

    expect(Application::where('git_repository', 'https://gitlab.example.com/avail/fastbridge.git')->value('build_pack'))->toBe('dockercompose');
});

test('only admins and the member who created an app may configure it', function () {
    $own = setupApp($this, 'own', $this->member->id);
    $guest = User::factory()->create();
    $guest->teams()->attach($this->team, ['role' => 'guest']);

    setupActAs($this->member, $this->team);
    expect(auth()->user()->can('configure', $own))->toBeTrue()
        ->and(auth()->user()->can('update', $own))->toBeFalse();
    setupActAs($this->otherMember, $this->team);
    expect(auth()->user()->can('configure', $own))->toBeFalse();
    setupActAs($this->admin, $this->team);
    expect(auth()->user()->can('configure', $own))->toBeTrue();
    setupActAs($guest, $this->team);
    expect(auth()->user()->can('configure', $own))->toBeFalse();
});

test('a member changes the everyday settings of their app but nothing that reaches the server', function () {
    $own = setupApp($this, 'own', $this->member->id);
    setupActAs($this->member, $this->team);

    $own->fill([
        'git_branch' => 'master', 'install_command' => 'pnpm i', 'build_command' => 'pnpm build',
        'start_command' => 'pnpm start', 'base_directory' => '/apps/web', 'ports_exposes' => '8080',
        'pre_deployment_command' => 'php artisan migrate', 'health_check_path' => '/health',
    ]);
    availGuardApplicationChanges($own);
    $own->save();
    expect($own->fresh()->git_branch)->toBe('master');

    foreach ([
        ['custom_docker_run_options' => '--privileged -v /:/host'],
        ['git_repository' => 'someone/else'],
        ['ports_mappings' => '22:22'],
        ['build_pack' => 'dockercompose'],
    ] as $change) {
        $app = $own->fresh()->fill($change);
        expect(fn () => availGuardApplicationChanges($app))->toThrow(RuntimeException::class);
    }

    $app = $own->fresh();
    $app->settings->connect_to_docker_network = true;
    expect(fn () => availGuardApplicationChanges($app))->toThrow(RuntimeException::class);

    $app = $own->fresh();
    $app->settings->is_auto_deploy_enabled = false;
    availGuardApplicationChanges($app);

    setupActAs($this->admin, $this->team);
    $app = $own->fresh()->fill(['custom_docker_run_options' => '--init']);
    availGuardApplicationChanges($app);
});

test('a member may only give their app a free <name>.apps address', function () {
    setupApp($this, 'nexus', null, ['fqdn' => 'https://nexus-fast-bridge.apps.avail.test']);
    $own = setupApp($this, 'own', $this->member->id, ['fqdn' => 'https://random123.apps.avail.test']);
    setupActAs($this->member, $this->team);

    expect(availMemberDomainError($own, 'https://fastbridge-staging.apps.avail.test'))->toBeNull()
        ->and(availMemberDomainError($own, 'https://random123.apps.avail.test'))->toBeNull()
        ->and(availMemberDomainError($own, 'https://nexus-fast-bridge.apps.avail.test'))->toContain('already used')
        ->and(availMemberDomainError($own, 'https://coolify.avail.test'))->toContain('Only admins')
        ->and(availMemberDomainError($own, 'https://api.avail.test'))->toContain('Only admins')
        ->and(availMemberDomainError($own, 'https://www.google.com'))->toContain('Only admins')
        ->and(availMemberDomainError($own, 'https://7.fastbridge.apps.avail.test'))->toContain('reserved for PR previews')
        ->and(availMemberDomainError($own, 'https://www.apps.avail.test'))->toContain('reserved')
        ->and(availMemberDomainError($own, 'https://my-app.apps.avail.test:8443'))->toContain('like https://my-app');

    $own->fqdn = 'https://nexus-fast-bridge.apps.avail.test';
    expect(fn () => availGuardApplicationChanges($own))->toThrow(RuntimeException::class, 'already used');
});
