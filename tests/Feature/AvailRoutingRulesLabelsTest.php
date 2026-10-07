<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Avail: the rules stored on an application or preview reach its generated Traefik labels, in
 * front of the app's own middlewares and behind Preview Guard.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config(['app.maintenance.driver' => 'file']);

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'is_dns_validation_enabled' => false]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $keyId, 'ip' => '203.0.113.10']);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'generate_exact_labels' => false]);
    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'uuid' => 'rules-app',
        'name' => 'Rules App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://rules.example.com',
        'redirect' => 'both',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'is_http_basic_auth_enabled' => false,
    ]);
});

function routingRulesForTest(string $header = 'X-Frame-Options', string $value = 'DENY'): array
{
    return availRoutingParse(json_encode([
        'headers' => [
            ['source' => '/(.*)', 'headers' => [['key' => $header, 'value' => $value]]],
            ['source' => '/assets/:path*', 'headers' => [['key' => 'Cache-Control', 'value' => 'public, max-age=31536000']]],
        ],
        'redirects' => [['source' => '/old', 'destination' => '/new']],
    ]), 'availcoolify.json')['rules'];
}

it('changes nothing for an application without rules', function () {
    $before = collect(generateLabelsApplication($this->application));

    $this->application->forceFill(['avail_routing_rules' => null])->save();

    expect(collect(generateLabelsApplication($this->application->fresh()))->all())->toBe($before->all())
        ->and($before->contains(fn ($label) => str_contains($label, 'avail-')))->toBeFalse();
});

it('adds the stored rules to the generated labels and keeps them as an array', function () {
    $this->application->forceFill(['avail_routing_rules' => routingRulesForTest()])->save();
    $labels = collect(generateLabelsApplication($this->application->fresh()));
    $uuid = 'rules-app';

    expect($this->application->fresh()->avail_routing_rules['file'])->toBe('availcoolify.json')
        ->and($labels->contains("traefik.http.middlewares.avail-rh-{$uuid}.headers.customresponseheaders.X-Frame-Options=DENY"))->toBeTrue()
        ->and($labels->contains(fn ($label) => preg_match("/^traefik\.http\.middlewares\.avail-rr0-{$uuid}\.redirectregex\.regex=/", $label)))->toBeTrue()
        ->and($labels->contains(fn ($label) => preg_match('/^traefik\.http\.routers\.https-0-rules-app-rh1\.rule=.*PathRegexp/', $label)))->toBeTrue()
        ->and($labels->first(fn ($label) => str_starts_with($label, 'traefik.http.routers.https-0-rules-app.middlewares=')))->toContain("avail-rr0-{$uuid},avail-rh-{$uuid}");
});

it('keeps Preview Guard in front of the rules, also on the path routers', function () {
    $this->environment->forceFill(['preview_guard_enabled' => true])->save();
    $this->application->forceFill(['avail_routing_rules' => routingRulesForTest()])->save();

    $labels = collect(generateLabelsApplication($this->application->fresh()));

    foreach (['https-0-rules-app', 'https-0-rules-app-rh1'] as $router) {
        $chain = $labels->first(fn ($label) => str_starts_with($label, "traefik.http.routers.{$router}.middlewares="));
        expect($chain)->toStartWith("traefik.http.routers.{$router}.middlewares=preview-guard-rules-app,")
            ->and($chain)->toContain('avail-rr0-rules-app');
    }
});

it('gives a pull request preview the rules of that preview only', function () {
    $this->application->forceFill(['avail_routing_rules' => routingRulesForTest('X-From', 'app')])->save();
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/7',
        'fqdn' => 'https://7.rules.example.com',
    ]);
    $preview->forceFill(['avail_routing_rules' => routingRulesForTest('X-From', 'preview')])->save();

    $labels = collect(generateLabelsApplication($this->application->fresh(), $preview->fresh()));

    expect($labels->contains('traefik.http.middlewares.avail-rh-rules-app-pr-7.headers.customresponseheaders.X-From=preview'))->toBeTrue()
        ->and($labels->contains(fn ($label) => str_contains($label, 'X-From=app')))->toBeFalse();
});

it('does nothing when the feature is switched off', function () {
    $this->application->forceFill(['avail_routing_rules' => routingRulesForTest()])->save();
    config(['avail.routing_rules_enabled' => false]);

    expect(collect(generateLabelsApplication($this->application->fresh()))->contains(fn ($label) => str_contains($label, 'avail-')))->toBeFalse();
});

it('stores what a deployment used', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'commit' => 'abc123',
        'status' => 'finished',
        'server_id' => $this->server->id,
    ]);
    $deployment->forceFill(['avail_routing_rules' => ['read' => true, 'rules' => routingRulesForTest()]])->save();

    expect($deployment->fresh()->avail_routing_rules['rules']['headers'])->toHaveCount(2);
});
