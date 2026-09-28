<?php

use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\Auth\OauthLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Avail: Clerk users join the root team (Avail Team) as members; no personal team is created.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'is_registration_enabled' => true]));
    $this->owner = User::factory()->create(['id' => 0, 'email' => 'owner@avail.test']);
    $this->rootTeam = Team::find(0) ?? Team::unguarded(fn () => Team::create(['id' => 0, 'name' => 'Avail Team', 'personal_team' => true]));
    $this->setting = OauthSetting::updateOrCreate(['provider' => 'clerk'], ['enabled' => true, 'auto_join_root_team' => true]);
});

function clerkUser(string $email, string $id): object
{
    return (object) [
        'id' => $id,
        'email' => $email,
        'name' => 'New Person',
        'user' => ['email_verified' => true, 'sub' => $id],
    ];
}

test('a new Clerk user joins the root team as a member without a personal team', function () {
    $user = app(OauthLoginService::class)->login('clerk', clerkUser('new@avail.test', 'user_new'), $this->setting);

    expect($user->teams()->pluck('teams.id')->all())->toBe([0])
        ->and($user->teams()->first()->pivot->role)->toBe('member')
        ->and(Team::where('personal_team', true)->where('name', 'New Person\'s Team')->exists())->toBeFalse();
});

test('an existing user outside the root team is added as a member on Clerk login', function () {
    $existing = User::factory()->create(['email' => 'old@avail.test']);
    expect($existing->teams()->whereKey(0)->exists())->toBeFalse();

    app(OauthLoginService::class)->login('clerk', clerkUser('old@avail.test', 'user_old'), $this->setting);

    expect($existing->teams()->whereKey(0)->first()?->pivot->role)->toBe('member');
});

test('without auto-join nobody is added to the root team', function () {
    $this->setting->update(['auto_join_root_team' => false]);
    $existing = User::factory()->create(['email' => 'other@avail.test']);

    app(OauthLoginService::class)->login('clerk', clerkUser('other@avail.test', 'user_other'), $this->setting);

    expect($existing->teams()->whereKey(0)->exists())->toBeFalse();
});
