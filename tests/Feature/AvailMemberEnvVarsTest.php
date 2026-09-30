<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All as EnvironmentVariableAll;
use App\Livewire\Project\Shared\EnvironmentVariable\Show as EnvironmentVariableShow;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Avail: members manage the environment variables of the applications they created, but may
 * not pull in shared (team/project/environment/server) variables. Everything else stays admin-only.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
    $this->otherMember = User::factory()->create();
    $this->otherMember->teams()->attach($this->team, ['role' => 'member']);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(), 'name' => 'Test Key', 'private_key' => 'test-key',
        'team_id' => $this->team->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $keyId]);
    StandaloneDocker::withoutEvents(function () use ($server) {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });
    $project = Project::create(['uuid' => (string) Str::uuid(), 'name' => 'Test Project', 'team_id' => $this->team->id]);
    $this->environment = $project->environments()->first();

    $this->ownApp = memberEnvTestApplication($this, 'own-app', $this->member->id);
    $this->otherApp = memberEnvTestApplication($this, 'other-app', $this->otherMember->id);
    $this->adminApp = memberEnvTestApplication($this, 'admin-app', null);

    $this->ownVar = memberEnvTestVariable($this->ownApp, 'API_KEY', 'own-secret');
    $this->otherVar = memberEnvTestVariable($this->otherApp, 'API_KEY', 'other-secret');
});

function memberEnvTestApplication($test, string $name, ?int $createdBy): Application
{
    $application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'environment_id' => $test->environment->id,
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
    ]);
    $application->forceFill(['avail_created_by_user_id' => $createdBy])->save();

    return $application->fresh();
}

function memberEnvTestVariable(Application $application, string $key, string $value): EnvironmentVariable
{
    return EnvironmentVariable::create([
        'key' => $key, 'value' => $value,
        'resourceable_type' => Application::class, 'resourceable_id' => $application->id,
        'is_preview' => false, 'is_shown_once' => false, 'is_multiline' => false,
        'is_literal' => false, 'is_runtime' => true, 'is_buildtime' => true,
    ]);
}

function memberEnvActAs(User $user, Team $team): void
{
    test()->actingAs($user->fresh());
    session(['currentTeam' => $team]);
}

function memberEnvAddData(string $key, string $value): array
{
    return [
        'key' => $key, 'value' => $value, 'comment' => null,
        'is_multiline' => false, 'is_literal' => false, 'is_runtime' => true,
        'is_buildtime' => true, 'is_preview' => false,
    ];
}

test('a member manages variables only on the applications they created', function () {
    memberEnvActAs($this->member, $this->team);
    $member = auth()->user();

    expect($member->can('manageEnvironment', $this->ownApp))->toBeTrue()
        ->and($member->can('update', $this->ownVar))->toBeTrue()
        ->and($member->can('delete', $this->ownVar))->toBeTrue()
        ->and($member->can('manageEnvironment', $this->otherApp))->toBeFalse()
        ->and($member->can('update', $this->otherVar))->toBeFalse()
        ->and($member->can('delete', $this->otherVar))->toBeFalse()
        ->and($member->can('manageEnvironment', $this->adminApp))->toBeFalse()
        ->and($member->can('update', $this->ownApp))->toBeFalse();
});

test('a member adds a variable to their own application but not to someone else\'s', function () {
    memberEnvActAs($this->member, $this->team);

    Livewire::test(EnvironmentVariableAll::class, ['resource' => $this->ownApp])
        ->call('submit', memberEnvAddData('OPENAI_API_KEY', 'sk-test'));
    Livewire::test(EnvironmentVariableAll::class, ['resource' => $this->otherApp])
        ->call('submit', memberEnvAddData('OPENAI_API_KEY', 'sk-test'));

    expect($this->ownApp->environment_variables()->where('key', 'OPENAI_API_KEY')->first()?->value)->toBe('sk-test')
        ->and($this->otherApp->environment_variables()->where('key', 'OPENAI_API_KEY')->exists())->toBeFalse();
});

test('a member sees the values on their own application only', function () {
    memberEnvActAs($this->member, $this->team);

    $own = Livewire::test(EnvironmentVariableShow::class, ['env' => $this->ownVar, 'type' => 'application'])->call('loadValues');
    $other = Livewire::test(EnvironmentVariableShow::class, ['env' => $this->otherVar, 'type' => 'application']);

    expect($own->get('value'))->toBe('own-secret')
        ->and($own->get('isValueHidden'))->toBeFalse()
        ->and($other->get('value'))->toBeNull()
        ->and($other->get('isValueHidden'))->toBeTrue()
        ->and($other->call('copyValue')->effects['returns'][0] ?? null)->toBeNull();

    // Opening the edit dialog of someone else's variable is refused.
    Livewire::test(EnvironmentVariableShow::class, ['env' => $this->otherVar, 'type' => 'application'])
        ->call('loadValues')
        ->assertForbidden();
});

test('a member edits and deletes variables on their own application', function () {
    memberEnvActAs($this->member, $this->team);

    Livewire::test(EnvironmentVariableShow::class, ['env' => $this->ownVar, 'type' => 'application'])
        ->call('loadValues')
        ->set('value', 'rotated-secret')
        ->call('submit');
    expect($this->ownVar->fresh()->value)->toBe('rotated-secret');

    Livewire::test(EnvironmentVariableShow::class, ['env' => $this->ownVar->fresh(), 'type' => 'application'])
        ->call('delete');
    expect(EnvironmentVariable::whereKey($this->ownVar->id)->exists())->toBeFalse();
});

test('members cannot reference shared variables, admins can', function () {
    memberEnvActAs($this->member, $this->team);
    Livewire::test(EnvironmentVariableAll::class, ['resource' => $this->ownApp])
        ->call('submit', memberEnvAddData('DB_PASSWORD', '{{team.DB_PASSWORD}}'))
        ->assertDispatched('error');
    expect($this->ownApp->environment_variables()->where('key', 'DB_PASSWORD')->exists())->toBeFalse();

    Livewire::test(EnvironmentVariableShow::class, ['env' => $this->ownVar, 'type' => 'application'])
        ->call('loadValues')
        ->set('value', '{{ project.SECRET }}')
        ->call('submit');
    expect($this->ownVar->fresh()->value)->toBe('own-secret');

    expect(fn () => memberEnvTestVariable($this->ownApp, 'SNEAKY', '{{environment.SECRET}}'))
        ->toThrow(RuntimeException::class);

    memberEnvActAs($this->admin, $this->team);
    Livewire::test(EnvironmentVariableAll::class, ['resource' => $this->ownApp])
        ->call('submit', memberEnvAddData('DB_PASSWORD', '{{team.DB_PASSWORD}}'));
    expect($this->ownApp->environment_variables()->where('key', 'DB_PASSWORD')->exists())->toBeTrue();
});

test('a member can still save an application whose variables an admin gave a shared reference', function () {
    memberEnvActAs($this->admin, $this->team);
    memberEnvTestVariable($this->ownApp, 'DB_URL', '{{project.DB_URL}}');

    memberEnvActAs($this->member, $this->team);
    Livewire::test(EnvironmentVariableAll::class, ['resource' => $this->ownApp])
        ->call('loadEnvironmentVariables')
        ->call('switch')
        ->set('variables', "API_KEY=own-secret\nDB_URL={{project.DB_URL}}\nNEW_FLAG=on")
        ->call('submit')
        ->assertNotDispatched('error');

    expect($this->ownApp->environment_variables()->where('key', 'NEW_FLAG')->first()?->value)->toBe('on');
});

test('guests never manage variables, even on an application recorded as theirs', function () {
    $guest = User::factory()->create();
    $guest->teams()->attach($this->team, ['role' => 'guest']);
    $this->ownApp->forceFill(['avail_created_by_user_id' => $guest->id])->save();
    memberEnvActAs($guest, $this->team);

    expect(auth()->user()->can('manageEnvironment', $this->ownApp->fresh()))->toBeFalse()
        ->and(availHidesEnvValues($this->ownApp->fresh()))->toBeTrue();
});
