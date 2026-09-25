<?php

use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GithubConnection;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\GithubConnect\GithubConnect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Avail: Vercel-style GitHub connect (one platform app, per-installation sources, per-user connection).
 */
uses(RefreshDatabase::class);

function githubConnectPlatformApp(Team $team, array $overrides = []): GithubApp
{
    $app = GithubApp::create(array_merge([
        'name' => 'avail-platform-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 5022547,
        'installation_id' => 111,
        'client_id' => 'Iv1.platform',
        'client_secret' => 'platform-secret',
        'webhook_secret' => 'hook-secret',
        'is_public' => false,
        'team_id' => $team->id,
    ], $overrides));
    $app->forceFill(['is_avail_platform' => true])->save();

    return $app;
}

function githubConnectUser(): User
{
    $user = User::factory()->create();
    GithubConnection::create([
        'user_id' => $user->id,
        'github_user_id' => 42,
        'github_login' => 'octo-dev',
        'access_token' => 'user-token',
    ]);

    return $user;
}

test('connect is off until a platform app is chosen', function () {
    expect(GithubConnect::isEnabled())->toBeFalse();

    githubConnectPlatformApp(Team::factory()->create());

    expect(GithubConnect::isEnabled())->toBeTrue();
});

test('only installations of the platform app are listed for the user', function () {
    githubConnectPlatformApp(Team::factory()->create());
    $user = githubConnectUser();
    Http::fake([
        'api.github.com/user/installations*' => Http::response(['installations' => [
            ['id' => 222, 'app_id' => 5022547, 'account' => ['login' => 'availproject', 'type' => 'Organization'], 'repository_selection' => 'all', 'html_url' => 'https://github.com/organizations/availproject/settings/installations/222'],
            ['id' => 333, 'app_id' => 999, 'account' => ['login' => 'someone-else', 'type' => 'User'], 'repository_selection' => 'all'],
        ]]),
    ]);

    $installations = GithubConnect::installations($user);

    expect($installations)->toHaveCount(1)
        ->and($installations->first()['account'])->toBe('availproject')
        ->and($installations->first()['type'])->toBe('Organization');
});

test('only repositories the user can push to are deployable', function () {
    githubConnectPlatformApp(Team::factory()->create());
    $user = githubConnectUser();
    Http::fake([
        'api.github.com/user/installations/222/repositories*' => Http::response(['repositories' => [
            ['id' => 1, 'full_name' => 'availproject/read-only', 'permissions' => ['pull' => true, 'push' => false, 'admin' => false]],
            ['id' => 2, 'full_name' => 'availproject/writer', 'permissions' => ['pull' => true, 'push' => true, 'admin' => false]],
            ['id' => 3, 'full_name' => 'availproject/admin', 'permissions' => ['pull' => true, 'push' => false, 'admin' => true]],
        ]]),
    ]);

    expect(GithubConnect::pushableRepositoryIds($user, 222)->all())->toBe([2, 3]);
});

test('an installation gets its own team source with the platform credentials, reused afterwards', function () {
    $team = Team::factory()->create();
    $platform = githubConnectPlatformApp(Team::factory()->create());
    $installation = ['id' => 222, 'account' => 'availproject', 'type' => 'Organization'];

    $source = GithubConnect::sourceForInstallation($team, $installation);

    expect($source->id)->not->toBe($platform->id)
        ->and((int) $source->app_id)->toBe(5022547)
        ->and((int) $source->installation_id)->toBe(222)
        ->and($source->team_id)->toBe($team->id)
        ->and($source->client_id)->toBe('Iv1.platform')
        ->and($source->webhook_secret)->toBe('hook-secret')
        ->and($source->organization)->toBe('availproject')
        ->and((bool) $source->is_avail_platform)->toBeFalse()
        ->and(GithubConnect::isPlatformSource($source))->toBeTrue()
        ->and(GithubConnect::sourceForInstallation($team, $installation)->id)->toBe($source->id);
});

test('a callback without our state is rejected before talking to GitHub', function () {
    githubConnectPlatformApp(Team::factory()->create());
    Http::fake();
    $this->actingAs(User::factory()->create());

    $this->withSession(['github_connect.state' => 'expected-state'])
        ->get('/github/callback?code=abc&state=forged')
        ->assertRedirect();

    Http::assertNothingSent();
    expect(GithubConnection::count())->toBe(0);
});

test('connect only returns to same-site paths', function () {
    githubConnectPlatformApp(Team::factory()->create());
    $this->actingAs(User::factory()->create());

    $response = $this->get('/github/connect?return='.urlencode('https://evil.example/steal'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://github.com/login/oauth/authorize?')
        ->toContain('client_id=Iv1.platform')
        ->and(session('github_connect.return'))->toBe(route('dashboard', absolute: false));
});

test('webhooks reach apps on any installation of the platform app, with their own source', function () {
    Queue::fake();
    $team = Team::factory()->create();
    $platform = githubConnectPlatformApp($team);
    $child = GithubConnect::sourceForInstallation($team, ['id' => 222, 'account' => 'availproject', 'type' => 'Organization']);

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::create([
        'name' => 'org-app',
        'git_repository' => 'availproject/hello-world',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'repository_project_id' => 555,
        'source_id' => $child->id,
        'source_type' => GithubApp::class,
    ]);

    $body = json_encode([
        'action' => 'closed',
        'number' => 7,
        'installation' => ['id' => 222],
        'repository' => ['id' => 555, 'full_name' => 'availproject/hello-world'],
        'pull_request' => [
            'html_url' => 'https://github.com/availproject/hello-world/pull/7',
            'title' => 'Change',
            'author_association' => 'MEMBER',
            'head' => ['ref' => 'feature', 'sha' => 'abc'],
            'base' => ['ref' => 'main'],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => 'pull_request',
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => (string) $platform->app_id,
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'hook-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertOk();

    Queue::assertPushed(ProcessGithubPullRequestWebhook::class, fn (ProcessGithubPullRequestWebhook $job): bool => $job->applicationId === $application->id
        && $job->githubAppId === $child->id);
});
