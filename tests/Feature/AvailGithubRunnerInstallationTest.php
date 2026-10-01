<?php

use App\Jobs\ProvisionGithubRunnerJob;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * Avail: with one github_apps record per installation of the platform app ("Continue with
 * GitHub"), workflow_job webhooks use the record of the installation that sent them.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Server::flushIdentityMap();
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->orgApp = availRunnerApp($this->team, 'availproject (GitHub)', 3001);
    $this->personalApp = availRunnerApp($this->team, 'faguncb (GitHub)', 3002);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings()->update([
        'is_reachable' => true, 'is_usable' => true, 'server_role' => 'build',
        'is_build_server' => true, 'force_disabled' => false,
    ]);

    // Runners are configured for the org's installation only.
    GithubRunnerConfig::create([
        'server_id' => $this->server->id,
        'github_app_id' => $this->orgApp->id,
        'labels' => ['coolify'],
        'max_runners' => 2,
        'docker_mode' => 'dind',
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function availRunnerApp(Team $team, string $name, int $installationId): GithubApp
{
    $rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rsaKey, $pemKey);
    $privateKey = PrivateKey::create(['name' => 'Platform key', 'private_key' => $pemKey, 'team_id' => $team->id]);

    return GithubApp::create([
        'name' => $name,
        'organization' => 'acme',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 5022547,
        'installation_id' => $installationId,
        'webhook_secret' => 'platform-secret',
        'private_key_id' => $privateKey->id,
        'team_id' => $team->id,
        'is_system_wide' => false,
    ]);
}

function availSendQueuedJob($test, ?int $installationId)
{
    $body = json_encode(array_filter([
        'action' => 'queued',
        'workflow_job' => [
            'id' => 7001,
            'labels' => ['self-hosted', 'coolify'],
            'html_url' => 'https://github.com/acme/api/actions/runs/1/job/7001',
            'workflow_name' => 'CI',
            'name' => 'test',
            'runner_name' => null,
        ],
        'repository' => ['id' => 99, 'full_name' => 'acme/api'],
        'installation' => $installationId ? ['id' => $installationId] : null,
    ]), JSON_THROW_ON_ERROR);

    return $test->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => 'workflow_job',
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => '5022547',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'platform-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('starts a runner on the record of the installation that sent the job', function () {
    availSendQueuedJob($this, 3001)->assertOk()->assertSee('Runner queued');

    expect(GithubRunnerExecution::sole()->github_app_id)->toBe($this->orgApp->id);
    Queue::assertPushed(ProvisionGithubRunnerJob::class, 1);
});

it('does not use another installation\'s runner configuration', function () {
    // The personal record comes first by id in other setups; here it simply has no configuration.
    availSendQueuedJob($this, 3002)->assertOk()->assertSee('No runner configuration matches');

    expect(GithubRunnerExecution::count())->toBe(0);
    Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
});

it('ignores unknown and uninstalled installations', function () {
    availSendQueuedJob($this, 9999)->assertOk()->assertSee('No active source for this installation');

    $this->orgApp->forceFill(['avail_installation_status' => 'removed'])->save();
    availSendQueuedJob($this, 3001)->assertOk()->assertSee('No active source for this installation');

    expect(GithubRunnerExecution::count())->toBe(0);
    Queue::assertNotPushed(ProvisionGithubRunnerJob::class);
});

it('does not guess between several records when the job has no installation id', function () {
    availSendQueuedJob($this, null)->assertOk()->assertSee('No active source for this installation');

    expect(GithubRunnerExecution::count())->toBe(0);
});
