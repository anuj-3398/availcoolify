<?php

use App\Http\Controllers\PreviewGuardController;
use App\Jobs\AvailGuestAccessExpiryJob;
use App\Livewire\Project\GuestAccess as ProjectGuestAccess;
use App\Livewire\Settings\GuestAccess as SettingsGuestAccess;
use App\Livewire\Team\InviteLink;
use App\Livewire\Team\Member;
use App\Livewire\Team\RemovedMembers;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\OauthSetting;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TransactionalEmails\AvailGuestAccessNotice;
use App\Services\Auth\OauthLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Avail: guests see only the projects ticked for them, read-only, until their access ends.
 * Only company emails auto-join; everyone else needs an invitation. Removals stick.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config(['avail.auto_join_domains' => 'avail.test']);
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0, 'is_registration_enabled' => true]));
    $this->owner = User::factory()->create(['id' => 0, 'email' => 'owner@avail.test']);
    $this->rootTeam = Team::find(0) ?? Team::unguarded(fn () => Team::create(['id' => 0, 'name' => 'Avail Team', 'personal_team' => true]));
    if (! $this->owner->teams()->whereKey(0)->exists()) {
        $this->owner->teams()->attach(0, ['role' => 'owner']);
    }
    $this->clerk = OauthSetting::updateOrCreate(['provider' => 'clerk'], ['enabled' => true, 'auto_join_root_team' => true, 'base_url' => 'https://example.clerk.accounts.dev']);

    $this->admin = User::factory()->create(['email' => 'admin@avail.test']);
    $this->admin->teams()->attach(0, ['role' => 'admin']);

    $this->shared = Project::factory()->create(['team_id' => 0, 'name' => 'Shared']);
    $this->sharedEnvironment = Environment::factory()->create(['project_id' => $this->shared->id]);
    $this->hidden = Project::factory()->create(['team_id' => 0, 'name' => 'Hidden']);
    $this->hiddenEnvironment = Environment::factory()->create(['project_id' => $this->hidden->id]);

    $this->guest = User::factory()->create(['email' => 'guest@partner.test']);
    $this->guest->teams()->detach();
    $this->guest->teams()->attach(0, ['role' => 'guest', 'guest_expires_at' => now()->addDays(30)]);
    availGrantGuestProject($this->guest, $this->shared->id);
});

function guestActAs(User $user): void
{
    test()->actingAs($user->fresh());
    session(['currentTeam' => Team::find(0)]);
}

function guestTestApplication(Environment $environment, string $name): Application
{
    $server = Server::where('team_id', 0)->first() ?? Server::factory()->create(['team_id' => 0]);
    $destination = $server->standaloneDockers()->firstOrFail();

    return Application::create([
        'name' => $name,
        'git_repository' => 'anuj-3398/autobot',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

function guestClerkUser(string $email, string $id): object
{
    return (object) [
        'id' => $id,
        'email' => $email,
        'name' => 'Clerk Person',
        'user' => ['email_verified' => true, 'sub' => $id],
    ];
}

test('company emails auto-join as members; other emails get an account without a team', function () {
    $service = app(OauthLoginService::class);

    $colleague = $service->login('clerk', guestClerkUser('colleague@avail.test', 'user_colleague'), $this->clerk);
    $outsider = $service->login('clerk', guestClerkUser('someone@elsewhere.test', 'user_outsider'), $this->clerk);

    expect($colleague->teams()->whereKey(0)->first()?->pivot->role)->toBe('member')
        ->and($outsider->teams()->count())->toBe(0)
        ->and(Team::where('name', "Clerk Person's Team")->exists())->toBeFalse();
});

test('a user without a team sees the waiting page but can open their invitation', function () {
    $outsider = User::factory()->create(['email' => 'someone@elsewhere.test']);
    $outsider->teams()->detach();
    $invitation = TeamInvitation::create([
        'team_id' => 0, 'uuid' => 'invite-outsider', 'email' => $outsider->email, 'role' => 'guest',
        'link' => 'https://example.test/invitations/invite-outsider', 'via' => 'link',
        'avail_project_ids' => [$this->shared->id], 'avail_access_days' => 30,
    ]);
    $this->actingAs($outsider);

    $this->get(route('dashboard'))->assertForbidden()->assertSee('Waiting for an invitation');
    $this->get(route('team.invitation.show', $invitation->uuid))->assertOk()
        ->assertSee('Shared')
        ->assertSee('30 days after accepting');
    expect($outsider->fresh()->teams()->count())->toBe(0);
});

test('accepting a guest invitation grants its projects for 30 days from acceptance', function () {
    $invitee = User::factory()->create(['email' => 'invitee@elsewhere.test']);
    $invitee->teams()->detach();
    TeamInvitation::create([
        'team_id' => 0, 'uuid' => 'invite-30', 'email' => $invitee->email, 'role' => 'guest',
        'link' => 'https://example.test/invitations/invite-30', 'via' => 'link',
        'avail_project_ids' => [$this->shared->id], 'avail_access_days' => 30,
    ]);
    $this->actingAs($invitee);

    $this->post(route('team.invitation.accept', 'invite-30'))->assertRedirect(route('dashboard'));

    $membership = DB::table('team_user')->where('team_id', 0)->where('user_id', $invitee->id)->first();
    expect($membership->role)->toBe('guest')
        ->and(abs(now()->addDays(30)->diffInMinutes($membership->guest_expires_at)))->toBeLessThan(2)
        ->and(DB::table('project_guest_access')->where('user_id', $invitee->id)->pluck('project_id')->all())->toBe([$this->shared->id]);
});

test('a custom end date lasts until the end of that day and no expiry stays open', function () {
    $until = TeamInvitation::make(['avail_access_until' => now()->addDays(10)->toDateString()]);
    $none = TeamInvitation::make([]);

    expect(availGuestExpiryFromInvitation($until)->toDateTimeString())->toBe(now()->addDays(10)->endOfDay()->toDateTimeString())
        ->and(availGuestExpiryFromInvitation($none))->toBeNull()
        ->and(availInvitationAccessLabel($none))->toBe('No expiry');
});

test('an admin removing a company-email user sticks until they are let back in', function () {
    $colleague = User::factory()->create(['email' => 'colleague@avail.test']);
    $colleague->teams()->attach(0, ['role' => 'member']);
    guestActAs($this->admin);

    Livewire::test(Member::class, ['member' => $colleague])->call('remove');
    app(OauthLoginService::class)->login('clerk', guestClerkUser('colleague@avail.test', 'user_colleague'), $this->clerk);

    expect($colleague->fresh()->teams()->whereKey(0)->exists())->toBeFalse()
        ->and($colleague->fresh()->avail_removed_from_root_at)->not->toBeNull();

    guestActAs($this->admin);
    Livewire::test(RemovedMembers::class)->assertSee('colleague@avail.test')->call('allowBack', $colleague->id);
    app(OauthLoginService::class)->login('clerk', guestClerkUser('colleague@avail.test', 'user_colleague'), $this->clerk);

    expect($colleague->fresh()->teams()->whereKey(0)->first()?->pivot->role)->toBe('member');
});

test('guests only get their projects and resources from team-scoped queries', function () {
    $sharedApp = guestTestApplication($this->sharedEnvironment, 'shared-app');
    guestTestApplication($this->hiddenEnvironment, 'hidden-app');
    guestActAs($this->guest);

    expect(Project::ownedByCurrentTeam()->pluck('id')->all())->toBe([$this->shared->id])
        ->and(Environment::ownedByCurrentTeam()->pluck('project_id')->unique()->values()->all())->toBe([$this->shared->id])
        ->and(Application::ownedByCurrentTeam()->pluck('id')->all())->toBe([$sharedApp->id]);

    guestActAs($this->admin);
    expect(Project::ownedByCurrentTeam()->count())->toBe(2)
        ->and(Application::ownedByCurrentTeam()->count())->toBe(2);
});

test('guests may view their projects and nothing else', function () {
    $sharedApp = guestTestApplication($this->sharedEnvironment, 'shared-app');
    $hiddenApp = guestTestApplication($this->hiddenEnvironment, 'hidden-app');
    guestActAs($this->guest);
    $guest = auth()->user();

    expect($guest->can('view', $this->shared))->toBeTrue()
        ->and($guest->can('view', $sharedApp))->toBeTrue()
        ->and($guest->can('view', $this->hidden))->toBeFalse()
        ->and($guest->can('view', $hiddenApp))->toBeFalse()
        ->and($guest->can('deploy', $sharedApp))->toBeFalse()
        ->and($guest->can('update', $this->shared))->toBeFalse()
        ->and($guest->can('createApplication'))->toBeFalse()
        ->and($guest->can('view', Server::where('team_id', 0)->first()))->toBeFalse()
        ->and($guest->isMember())->toBeTrue()
        ->and($guest->isGuest())->toBeTrue();
});

test('guests are kept on the pages of their own projects', function () {
    $sharedApp = guestTestApplication($this->sharedEnvironment, 'shared-app');
    $hiddenApp = guestTestApplication($this->hiddenEnvironment, 'hidden-app');
    $shared = ['project_uuid' => $this->shared->uuid, 'environment_uuid' => $this->sharedEnvironment->uuid, 'application_uuid' => $sharedApp->uuid];
    $hidden = ['project_uuid' => $this->hidden->uuid, 'environment_uuid' => $this->hiddenEnvironment->uuid, 'application_uuid' => $hiddenApp->uuid];
    guestActAs($this->guest);

    $this->get(route('dashboard'))->assertOk()->assertSee('Shared')->assertDontSee('Hidden');
    $this->get(route('project.resource.index', $shared))->assertOk()->assertSee('shared-app');
    $this->get(route('project.application.configuration', $shared))->assertOk();
    $this->get(route('project.application.deployment.index', $shared))->assertOk();
    $this->get(route('project.application.configuration', $hidden))->assertNotFound();
    $this->get(route('project.application.environment-variables', $shared))->assertRedirect(route('dashboard'));
    $this->get(route('project.application.logs', $shared))->assertRedirect(route('dashboard'));
    $this->get(route('project.show', $this->shared->uuid))->assertOk();
    $this->get(route('project.show', $this->hidden->uuid))->assertNotFound();
    $this->get(route('server.index'))->assertRedirect(route('dashboard'));
    $this->get(route('team.member.index'))->assertRedirect(route('dashboard'));
    $this->get(route('settings.index'))->assertRedirect(route('dashboard'));
    $this->get(route('project.edit', $this->shared->uuid))->assertRedirect(route('dashboard'));
});

test('an expired guest sees the ended page until an admin extends their access', function () {
    availSetGuestExpiry($this->guest, 0, now()->subDay());
    guestActAs($this->guest);

    $this->get(route('dashboard'))->assertForbidden()->assertSee('Your guest access has ended');
    expect(availGuestProjectIds($this->guest->fresh(), 0))->toBe([]);

    guestActAs($this->admin);
    Livewire::test(SettingsGuestAccess::class)->call('extend', $this->guest->id, 30);

    guestActAs($this->guest);
    $this->get(route('dashboard'))->assertOk();
    expect(availGuestProjectIds($this->guest->fresh(), 0))->toBe([$this->shared->id]);
});

test('Settings > Guest access ticks projects, sets end dates and removes guests', function () {
    guestActAs($this->admin);

    $component = Livewire::test(SettingsGuestAccess::class)
        ->assertSee('guest@partner.test')
        ->call('toggle', $this->guest->id, $this->hidden->id);
    expect(availGuestProjectIds($this->guest->fresh(), 0))->toEqualCanonicalizing([$this->shared->id, $this->hidden->id]);

    $component->call('toggle', $this->guest->id, $this->shared->id)
        ->call('setExpiry', $this->guest->id, now()->addDays(5)->toDateString());
    expect(availGuestProjectIds($this->guest->fresh(), 0))->toBe([$this->hidden->id])
        ->and(availGuestExpiresAt($this->guest, 0)->toDateString())->toBe(now()->addDays(5)->toDateString());

    $component->call('removeExpiry', $this->guest->id);
    expect(availGuestExpiresAt($this->guest, 0))->toBeNull();

    $component->call('removeGuest', $this->guest->id);
    expect($this->guest->fresh()->teams()->whereKey(0)->exists())->toBeFalse()
        ->and(DB::table('project_guest_access')->where('user_id', $this->guest->id)->exists())->toBeFalse();
});

test('Settings > Guest access is for admins only', function () {
    $member = User::factory()->create(['email' => 'member@avail.test']);
    $member->teams()->attach(0, ['role' => 'member']);
    guestActAs($member);

    $this->get(route('settings.guest-access'))->assertRedirect(route('dashboard'));

    guestActAs($this->admin);
    $component = Livewire::test(SettingsGuestAccess::class);
    guestActAs($member);
    $component->call('toggle', $this->guest->id, $this->hidden->id);
    expect(availGuestCanSeeProject($this->guest->fresh(), $this->hidden->id, 0))->toBeFalse();
});

test('a project lists its guests and admins add or remove them there', function () {
    guestActAs($this->admin);

    Livewire::test(ProjectGuestAccess::class, ['project' => $this->hidden])
        ->set('selectedGuestId', (string) $this->guest->id)
        ->call('add');
    expect(availGuestCanSeeProject($this->guest->fresh(), $this->hidden->id, 0))->toBeTrue();

    Livewire::test(ProjectGuestAccess::class, ['project' => $this->hidden])
        ->assertSee('guest@partner.test')
        ->call('remove', $this->guest->id);
    expect(availGuestCanSeeProject($this->guest->fresh(), $this->hidden->id, 0))->toBeFalse();
});

test('the invite form defaults to Guest for 30 days and saves the ticked projects', function () {
    guestActAs($this->admin);

    Livewire::test(InviteLink::class)
        ->assertSet('role', 'guest')
        ->assertSet('accessDuration', '30')
        ->set('email', 'new@elsewhere.test')
        ->set('guestProjectIds', [(string) $this->shared->id])
        ->call('viaLink');

    $invitation = TeamInvitation::where('email', 'new@elsewhere.test')->firstOrFail();
    expect($invitation->role)->toBe('guest')
        ->and($invitation->avail_access_days)->toBe(30)
        ->and($invitation->avail_project_ids)->toBe([$this->shared->id]);

    Livewire::test(InviteLink::class)
        ->set('email', 'custom@elsewhere.test')
        ->set('accessDuration', 'custom')
        ->call('viaLink')
        ->assertHasErrors(['accessUntil' => 'required']);
});

test('Preview Guard lets guests into apps of their projects only', function () {
    $sharedApp = guestTestApplication($this->sharedEnvironment, 'shared-app');
    $hiddenApp = guestTestApplication($this->hiddenEnvironment, 'hidden-app');
    $isMember = new ReflectionMethod(PreviewGuardController::class, 'isMember');
    $controller = app(PreviewGuardController::class);

    expect($isMember->invoke($controller, $sharedApp->load('environment.project'), $this->guest->id))->toBeTrue()
        ->and($isMember->invoke($controller, $hiddenApp->load('environment.project'), $this->guest->id))->toBeFalse()
        ->and($isMember->invoke($controller, $hiddenApp, $this->admin->id))->toBeTrue();

    availSetGuestExpiry($this->guest, 0, now()->subMinute());
    cache()->flush();
    expect($isMember->invoke($controller, $sharedApp, $this->guest->id))->toBeFalse();
});

test('guests cannot use the API', function () {
    session(['currentTeam' => Team::find(0)]);
    $token = $this->guest->fresh()->createToken('guest-token', ['read']);

    $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
        ->getJson('/api/v1/projects')
        ->assertForbidden();
});

test('expiry emails wait for email to be configured, then go out once', function () {
    Notification::fake();
    availSetGuestExpiry($this->guest, 0, now()->addDays(3));
    $expired = User::factory()->create(['email' => 'expired@partner.test']);
    $expired->teams()->attach(0, ['role' => 'guest', 'guest_expires_at' => now()->subDay()]);

    (new AvailGuestAccessExpiryJob)->handle();
    Notification::assertNothingSent();

    InstanceSettings::find(0)->update(['smtp_enabled' => true]);
    (new AvailGuestAccessExpiryJob)->handle();
    (new AvailGuestAccessExpiryJob)->handle();

    Notification::assertSentToTimes($this->guest, AvailGuestAccessNotice::class, 1);
    Notification::assertSentTo($this->guest, AvailGuestAccessNotice::class, fn ($notice) => $notice->kind === 'ending' && $notice->projectNames === ['Shared']);
    Notification::assertSentTo($expired, AvailGuestAccessNotice::class, fn ($notice) => $notice->kind === 'ended');

    // Extending access re-arms the reminder for the new date.
    availSetGuestExpiry($this->guest, 0, now()->addDays(6));
    (new AvailGuestAccessExpiryJob)->handle();
    Notification::assertSentToTimes($this->guest, AvailGuestAccessNotice::class, 2);
});

test('the empty projects page tells guests and members who to ask', function () {
    Project::query()->delete();

    guestActAs($this->guest);
    Livewire::test(\App\Livewire\Project\Index::class)
        ->assertSee('No projects have been shared with you yet. Ask an admin for access.')
        ->assertDontSee('Create a project');

    $member = User::factory()->create(['email' => 'member@avail.test']);
    $member->teams()->attach(0, ['role' => 'member']);
    guestActAs($member);
    Livewire::test(\App\Livewire\Project\Index::class)->assertSee('Ask an admin to create one.');

    guestActAs($this->admin);
    Livewire::test(\App\Livewire\Project\Index::class)->assertSee('Create a project to organize your environments and resources.');
});

test('making a member a guest gives the default 30 days and no projects', function () {
    $member = User::factory()->create(['email' => 'contractor@elsewhere.test']);
    $member->teams()->attach(0, ['role' => 'member']);
    guestActAs($this->admin);

    Livewire::test(Member::class, ['member' => $member])->call('makeGuest');

    expect($member->fresh()->roleInTeam(0))->toBe('guest')
        ->and(availGuestExpiresAt($member, 0)->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and(availGuestProjectIds($member->fresh(), 0))->toBe([]);
});
