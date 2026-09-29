<?php

use App\Livewire\Upgrade;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Avail: only the Avail Team owner sees and starts platform upgrades.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));
    $this->owner = User::factory()->create(['id' => 0]);
    Team::find(0) ?? Team::unguarded(fn () => Team::create(['id' => 0, 'name' => 'Avail Team', 'personal_team' => true]));
    if (! $this->owner->teams()->whereKey(0)->exists()) {
        $this->owner->teams()->attach(0, ['role' => 'owner']);
    }
});

test('only the Avail Team owner is the platform owner', function (string $role, bool $expected) {
    $user = User::factory()->create();
    $user->teams()->attach(0, ['role' => $role]);
    $this->actingAs($user);

    expect(availIsPlatformOwner())->toBe($expected);
})->with([
    'admin' => ['admin', false],
    'member' => ['member', false],
]);

test('the owner is the platform owner', function () {
    $this->actingAs($this->owner);

    expect(availIsPlatformOwner())->toBeTrue();
});

test('an admin cannot start an upgrade', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach(0, ['role' => 'admin']);
    $this->actingAs($admin);
    session(['currentTeam' => Team::find(0)]);

    Livewire::test(Upgrade::class)->call('upgrade')->assertSet('updateInProgress', false);
});
