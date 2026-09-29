<?php

use App\Livewire\SettingsOauth;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Avail: Clerk is the only sign-in method, so Settings > Authentication lists only Clerk.
 * Upstream tests for the other providers' settings screens were removed; restore them from
 * `main` (tests/Feature/SettingsOauthTest.php) if other providers are ever shown again.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));
    $user = User::factory()->create(['id' => 0]);
    $team = Team::find(0) ?? Team::unguarded(fn () => Team::create(['id' => 0, 'name' => 'Avail Team', 'personal_team' => true]));
    if (! $user->teams()->whereKey(0)->exists()) {
        $user->teams()->attach(0, ['role' => 'owner']);
    }
    $this->actingAs($user);
    session(['currentTeam' => $team]);
    foreach (['clerk', 'github', 'oidc', 'google'] as $provider) {
        OauthSetting::firstOrCreate(['provider' => $provider]);
    }
});

test('the authentication settings list only clerk', function () {
    $component = Livewire::test(SettingsOauth::class);

    expect(array_keys($component->get('oauth_settings_map')))->toBe(['clerk']);
    $component->assertSee('Clerk')->assertDontSee('OpenID Connect')->assertDontSee('Zitadel');
});

test('other providers have no settings page', function () {
    Livewire::test(SettingsOauth::class, ['provider' => 'github'])->assertNotFound();
    Livewire::test(SettingsOauth::class, ['provider' => 'clerk'])->assertOk();
});
