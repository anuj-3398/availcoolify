<?php

use App\Models\GithubApp;
use App\Models\GithubConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Avail: "Continue with GitHub" goes on to installing the app when the user has no installation yet.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
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
        'team_id' => $this->team->id,
    ]);
    $app->forceFill(['is_avail_platform' => true])->save();
    Cache::put('github-connect:slug:5022547', 'pilot-availcoolify-app', now()->addHour());

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);
});

function fakeGithubConnect(array $installations): void
{
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'user-token', 'expires_in' => 28800]),
        'api.github.com/user/installations*' => Http::response(['installations' => $installations]),
        'api.github.com/user' => Http::response(['id' => 77, 'login' => 'member-dev']),
    ]);
}

test('a first connect without any installation continues to installing the app', function () {
    fakeGithubConnect([]);

    $response = $this->withSession(['github_connect.state' => 'st', 'github_connect.return' => '/project/x/new'])
        ->get('/github/callback?code=abc&state=st');

    expect($response->headers->get('Location'))->toStartWith('https://github.com/apps/pilot-availcoolify-app/installations/new?state=')
        ->and(session('github_connect.return'))->toBe('/project/x/new')
        ->and(GithubConnection::where('user_id', $this->member->id)->value('github_login'))->toBe('member-dev');
});

test('a connect with an installation goes straight back', function () {
    fakeGithubConnect([
        ['id' => 222, 'app_id' => 5022547, 'account' => ['login' => 'member-dev', 'type' => 'User'], 'suspended_at' => null],
    ]);

    $this->withSession(['github_connect.state' => 'st', 'github_connect.return' => '/project/x/new'])
        ->get('/github/callback?code=abc&state=st')
        ->assertRedirect('/project/x/new');
});

test('coming back from an install never loops back to installing', function () {
    fakeGithubConnect([]);

    $this->withSession(['github_connect.state' => 'st', 'github_connect.return' => '/project/x/new'])
        ->get('/github/callback?code=abc&state=st&installation_id=222&setup_action=install')
        ->assertRedirect('/project/x/new');
});

test('members may start an install on their own GitHub account', function () {
    $response = $this->get('/github/install?return=/project/x/new');

    expect($response->headers->get('Location'))->toStartWith('https://github.com/apps/pilot-availcoolify-app/installations/new?state=')
        ->and(session('github_connect.return'))->toBe('/project/x/new');
});
