<?php

use App\Enums\ProcessStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
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
 * Avail: GitHub installation lifecycle (uninstall / suspend) and PR preview status checks.
 */
uses(RefreshDatabase::class);

function lifecyclePlatformApp(Team $team): GithubApp
{
    $app = GithubApp::create([
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
    ]);
    $app->forceFill(['is_avail_platform' => true])->save();

    return $app;
}

function lifecycleWebhook($test, string $event, array $payload)
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => $event,
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => '5022547',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'hook-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

test('uninstalling, suspending and restoring an installation updates its sources', function () {
    $team = Team::factory()->create();
    lifecyclePlatformApp($team);
    $source = GithubConnect::sourceForInstallation($team, ['id' => 222, 'account' => 'availproject', 'type' => 'Organization']);

    lifecycleWebhook($this, 'installation', ['action' => 'suspend', 'installation' => ['id' => 222]])->assertOk();
    expect($source->fresh()->avail_installation_status)->toBe('suspended')
        ->and(GithubConnect::sourceProblem($source->fresh()))->toContain('suspended on availproject');

    lifecycleWebhook($this, 'installation', ['action' => 'unsuspend', 'installation' => ['id' => 222]])->assertOk();
    expect($source->fresh()->avail_installation_status)->toBeNull()
        ->and(GithubConnect::sourceProblem($source->fresh()))->toBeNull();

    lifecycleWebhook($this, 'installation', ['action' => 'deleted', 'installation' => ['id' => 222]])->assertOk();
    expect($source->fresh()->avail_installation_status)->toBe('removed')
        ->and(GithubConnect::sourceProblem($source->fresh()))->toContain('uninstalled from availproject');

    // Other installations of the same app are untouched.
    expect(GithubApp::where('installation_id', 111)->value('avail_installation_status'))->toBeNull();
});

test('a push from an uninstalled installation does not deploy', function () {
    Queue::fake();
    $team = Team::factory()->create();
    lifecyclePlatformApp($team);
    $source = GithubConnect::sourceForInstallation($team, ['id' => 222, 'account' => 'availproject', 'type' => 'Organization']);
    $source->forceFill(['avail_installation_status' => 'removed'])->save();

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = $server->standaloneDockers()->firstOrFail();
    Application::create([
        'name' => 'org-app',
        'git_repository' => 'availproject/hello-world',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'repository_project_id' => 555,
        'source_id' => $source->id,
        'source_type' => GithubApp::class,
    ]);

    $response = lifecycleWebhook($this, 'push', [
        'ref' => 'refs/heads/main',
        'installation' => ['id' => 222],
        'repository' => ['id' => 555, 'full_name' => 'availproject/hello-world'],
        'commits' => [['message' => 'change', 'added' => [], 'removed' => [], 'modified' => ['README.md']]],
    ]);

    $response->assertOk();
    expect($response->getContent())->toContain('GitHub source disconnected')
        ->and(ApplicationDeploymentQueue::count())->toBe(0);
});

test('suspended installations are not offered in the picker', function () {
    lifecyclePlatformApp(Team::factory()->create());
    $user = User::factory()->create();
    GithubConnection::create(['user_id' => $user->id, 'github_user_id' => 1, 'github_login' => 'dev', 'access_token' => 'token']);
    Http::fake([
        'api.github.com/user/installations*' => Http::response(['installations' => [
            ['id' => 222, 'app_id' => 5022547, 'account' => ['login' => 'availproject', 'type' => 'Organization'], 'suspended_at' => null],
            ['id' => 444, 'app_id' => 5022547, 'account' => ['login' => 'paused-org', 'type' => 'Organization'], 'suspended_at' => '2026-09-01T00:00:00Z'],
        ]]),
    ]);

    expect(GithubConnect::installations($user)->pluck('account')->all())->toBe(['availproject']);
});

test('a reused source is marked active again', function () {
    $team = Team::factory()->create();
    lifecyclePlatformApp($team);
    $installation = ['id' => 222, 'account' => 'availproject', 'type' => 'Organization'];
    $source = GithubConnect::sourceForInstallation($team, $installation);
    $source->forceFill(['avail_installation_status' => 'suspended'])->save();

    expect(GithubConnect::sourceForInstallation($team, $installation)->avail_installation_status)->toBeNull();
});

test('preview deployment states map to GitHub commit statuses', function () {
    $logs = 'https://coolify.test/deployment/abc';
    $preview = 'http://7.app.example';

    expect(GithubConnect::previewCommitStatus(ProcessStatus::IN_PROGRESS, $logs, $preview))
        ->toBe(['state' => 'pending', 'description' => 'Preview is building', 'target_url' => $logs])
        ->and(GithubConnect::previewCommitStatus(ProcessStatus::FINISHED, $logs, $preview))
        ->toBe(['state' => 'success', 'description' => 'Preview ready', 'target_url' => $preview])
        ->and(GithubConnect::previewCommitStatus(ProcessStatus::ERROR, $logs, $preview)['state'])->toBe('failure')
        ->and(GithubConnect::previewCommitStatus(ProcessStatus::CANCELLED, $logs, $preview)['state'])->toBe('error')
        ->and(GithubConnect::previewCommitStatus(ProcessStatus::CLOSED, $logs, $preview))->toBeNull();
});

test('non-GitHub or active sources have no problem', function () {
    expect(GithubConnect::sourceProblem(null))->toBeNull()
        ->and(GithubConnect::sourceProblem(new GithubApp))->toBeNull();
});
