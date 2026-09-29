<?php

use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Livewire\SettingsOauth;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsInstanceAdmin(): User
{
    $team = Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => true]);
    $user = User::factory()->create(['id' => 0, 'email' => 'root@example.com', 'email_verified_at' => now()]);
    if (! $user->teams()->whereKey($team->id)->exists()) {
        $user->teams()->attach($team, ['role' => 'owner']);
    }
    session(['currentTeam' => $team]);
    test()->actingAs($user);

    return $user;
}

beforeEach(function () {
    $this->withoutVite();
    config()->set('app.maintenance.driver', 'file');

    InstanceSettings::forceCreate(['id' => 0, 'is_registration_enabled' => true]);
    Once::flush();
    OauthSetting::create(['provider' => 'oidc']);
    OauthSetting::create(['provider' => 'authentik']);
    OauthSetting::create(['provider' => 'bitbucket']);
});

it('has an icon for openid connect', function () {
    expect(public_path('svgs/oidc.svg'))->toBeFile();
});

it('auto saves registration policy', function () {
    actingAsInstanceAdmin();

    Livewire::test(SettingsOauth::class)
        ->set('disable_registration_when_oauth_enabled', true)
        ->call('saveRegistrationPolicy')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect(instanceSettings()->fresh()->disable_registration_when_oauth_enabled)->toBeTrue();
});

it('does not show unknown oauth providers', function () {
    actingAsInstanceAdmin();

    $this->withoutMiddleware(DecideWhatToDoWithUser::class)
        ->get('/settings/oauth/unknown')
        ->assertNotFound();
});

it('defaults oidc user creation and verified email requirement to enabled', function () {
    $setting = OauthSetting::where('provider', 'oidc')->first();

    expect($setting->allow_registration)->toBeTrue()
        ->and($setting->require_email_verified)->toBeTrue()
        ->and($setting->auto_join_root_team)->toBeFalse();
});

