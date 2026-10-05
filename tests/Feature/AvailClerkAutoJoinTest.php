<?php

use App\Models\InstanceSettings;
use App\Models\OauthIdentity;
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
    config(['avail.auto_join_domains' => 'avail.test']);
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'is_registration_enabled' => true]));
    $this->owner = User::factory()->create(['id' => 0, 'email' => 'owner@avail.test']);
    $this->rootTeam = Team::find(0) ?? Team::unguarded(fn () => Team::create(['id' => 0, 'name' => 'Avail Team', 'personal_team' => true]));
    $this->setting = OauthSetting::updateOrCreate(['provider' => 'clerk'], ['enabled' => true, 'auto_join_root_team' => true, 'base_url' => 'https://example.clerk.accounts.dev']);
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

test('only the Avail Team owner can create teams', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach(0, ['role' => 'admin']);
    $member = User::factory()->create();
    $member->teams()->attach(0, ['role' => 'member']);
    if (! $this->owner->teams()->whereKey(0)->exists()) {
        $this->owner->teams()->attach(0, ['role' => 'owner']);
    }

    expect($this->owner->fresh()->can('create', Team::class))->toBeTrue()
        ->and($admin->fresh()->can('create', Team::class))->toBeFalse()
        ->and($member->fresh()->can('create', Team::class))->toBeFalse();
});

test('a signed-in user left without a team joins the root team instead of a new personal team', function () {
    $user = User::factory()->create(['email' => 'teamless@avail.test']);
    $user->teams()->detach();
    clerkIdentityFor($user, true);
    $teamsBefore = Team::count();

    $this->actingAs($user)->get(route('dashboard'));

    expect($user->fresh()->teams()->pluck('teams.id')->all())->toBe([0])
        ->and(Team::count())->toBe($teamsBefore);
});

test('a signed-in teamless user whose email the provider has not verified stays out of the root team', function () {
    $user = User::factory()->create(['email' => 'unverified@avail.test']);
    $user->teams()->detach();
    clerkIdentityFor($user, false);

    $this->actingAs($user)->get(route('dashboard'));

    expect($user->fresh()->teams()->count())->toBe(0);
});

function clerkIdentityFor(User $user, bool $verified): void
{
    OauthIdentity::create([
        'user_id' => $user->id,
        'provider' => 'clerk',
        'issuer' => 'https://example.clerk.accounts.dev',
        'provider_user_id' => 'user_'.$user->id,
        'email' => $user->email,
        'raw_claims' => ['email_verified' => $verified],
        'last_login_at' => now(),
    ]);
}

function unverifiedClerkUser(string $email, string $id): object
{
    return (object) [
        'id' => $id,
        'email' => $email,
        'name' => 'Unverified Person',
        'user' => ['email_verified' => false, 'sub' => $id],
    ];
}

test('a new Clerk user with an unverified company email does not join the root team', function () {
    $user = app(OauthLoginService::class)->login('clerk', unverifiedClerkUser('claim@avail.test', 'user_claim'), $this->setting);

    expect($user->teams()->count())->toBe(0)
        ->and(availJoinRootTeam($user->fresh()))->toBeFalse()
        ->and($user->fresh()->teams()->count())->toBe(0);
});

test('a teamless company-email user joins once the provider verifies the email', function () {
    $user = app(OauthLoginService::class)->login('clerk', unverifiedClerkUser('later@avail.test', 'user_later'), $this->setting);
    expect($user->teams()->count())->toBe(0);

    app(OauthLoginService::class)->login('clerk', clerkUser('later@avail.test', 'user_later'), $this->setting);

    expect($user->fresh()->teams()->whereKey(0)->first()?->pivot->role)->toBe('member');
});
