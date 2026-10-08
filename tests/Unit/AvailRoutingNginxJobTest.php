<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Avail: which Nginx configuration a static image gets (availStaticNginxConfig() of the deployment
 * job): the generated one only when Nginx accepts it, never over a custom one.
 */
uses(TestCase::class, RefreshDatabase::class);

class TestableNginxChoiceDeploymentJob extends ApplicationDeploymentJob
{
    public array $commands = [];

    public string $nginxCheckOutput = 'nginx: configuration file test is successful AVAIL_NGINX_EXIT=0';

    public function __construct() {}

    public function execute_remote_command(...$commands): void
    {
        foreach ($commands as $command) {
            $this->commands[] = $command[0];
            if (($command['save'] ?? null) === 'avail_nginx_check') {
                (new ReflectionProperty(ApplicationDeploymentJob::class, 'saved_outputs'))->getValue($this)->put('avail_nginx_check', str($this->nginxCheckOutput));
            }
        }
    }
}

function nginxChoiceJob(?array $rules, ?string $customNginx = null, bool $spa = false): array
{
    $team = Team::create(['name' => 'Nginx Choice Team', 'personal_team' => false, 'show_boarding' => false]);
    $project = Project::create(['name' => 'Nginx Choice Project', 'team_id' => $team->id]);
    $environment = Environment::where('project_id', $project->id)->firstOrFail();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'static',
        'static_image' => 'nginx:alpine',
        'custom_nginx_configuration' => $customNginx,
    ]);
    $application->settings()->update(['is_spa' => $spa]);
    $application->forceFill(['avail_routing_rules' => $rules])->save();

    $queue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function ($line) use (&$logged) {
        $logged[] = $line;
    });

    $job = new TestableNginxChoiceDeploymentJob;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    foreach ([
        'application' => $application->fresh(),
        'application_deployment_queue' => $queue,
        'pull_request_id' => 0,
        'deployment_uuid' => 'deployment-uuid',
        'workdir' => '/artifacts/app',
        'saved_outputs' => new Collection,
    ] as $property => $value) {
        $reflection->getProperty($property)->setValue($job, $value);
    }

    $logged = [];
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function ($line) use (&$logged) {
        $logged[] = $line;
    });

    return [$job, $reflection->getMethod('availStaticNginxConfig'), &$logged];
}

function nginxChoiceRules(): array
{
    return availRoutingParse(json_encode(['rewrites' => [['source' => '/(.*)', 'destination' => '/']]]), 'availcoolify.json')['rules'];
}

it('uses the generated configuration when nginx -t accepts it', function () {
    [$job, $method] = nginxChoiceJob(nginxChoiceRules());
    $default = base64_encode('default config');

    $result = $method->invoke($job, $default);

    expect(base64_decode($result))->toContain('location @avail_rewrites')
        ->and($job->commands[0])->toContain('nginx -t')->toContain('nginx.avail.conf');
});

it('keeps the default configuration when nginx refuses the generated one', function () {
    [$job, $method] = nginxChoiceJob(nginxChoiceRules());
    $job->nginxCheckOutput = '[emerg] pcre2_compile() failed AVAIL_NGINX_EXIT=1';
    $default = base64_encode('default config');

    expect($method->invoke($job, $default))->toBe($default);
});

it('never replaces a custom Nginx configuration', function () {
    [$job, $method] = nginxChoiceJob(nginxChoiceRules(), 'server { listen 80; }');
    $default = base64_encode('server { listen 80; }');

    expect($method->invoke($job, $default))->toBe($default)->and($job->commands)->toBe([]);
});

it('leaves the configuration alone without rewrites, cleanUrls or trailingSlash', function () {
    $default = base64_encode('default config');

    [$job, $method] = nginxChoiceJob(null);
    expect($method->invoke($job, $default))->toBe($default);

    $headersOnly = availRoutingParse(json_encode(['headers' => [['source' => '/(.*)', 'headers' => [['key' => 'X-A', 'value' => '1']]]]]), 'availcoolify.json')['rules'];
    [$job, $method] = nginxChoiceJob($headersOnly);
    expect($method->invoke($job, $default))->toBe($default)->and($job->commands)->toBe([]);
});

it('does nothing when the feature is switched off', function () {
    config(['avail.routing_rules_enabled' => false]);
    [$job, $method] = nginxChoiceJob(nginxChoiceRules());
    $default = base64_encode('default config');

    expect($method->invoke($job, $default))->toBe($default)->and($job->commands)->toBe([]);
});

it('keeps the single page application fallback as the last rule', function () {
    [$job, $method] = nginxChoiceJob(nginxChoiceRules(), null, true);

    expect(base64_decode($method->invoke($job, base64_encode('default'))))->toContain('rewrite ^ /index.html break;');
});
