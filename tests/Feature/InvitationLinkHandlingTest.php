<?php

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    Once::flush();
    Config::set('app.maintenance.driver', 'file');
    Config::set('cache.default', 'array');
    Config::set('session.driver', 'array');

    if (! InstanceSettings::find(0)) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->saveQuietly();
    }
});

function createInvitationLinkFixture(array $invitationAttributes = []): array
{
    $team = Team::factory()->create();
    $password = 'temporary-password-123';
    $user = User::factory()->create([
        'email' => $invitationAttributes['email'] ?? 'invitee@example.com',
        'password' => Hash::make($password),
        'force_password_reset' => true,
        'email_verified_at' => null,
    ]);
    $uuid = (string) new Cuid2(32);
    $token = Crypt::encryptString("{$user->email}@@@{$uuid}@@@{$password}");
    $link = route('auth.link', ['token' => $token]);

    $invitation = TeamInvitation::create(array_merge([
        'team_id' => $team->id,
        'uuid' => $uuid,
        'email' => $user->email,
        'role' => 'member',
        'link' => $link,
        'via' => 'link',
    ], $invitationAttributes));

    return [$team, $user, $password, $token, $invitation];
}

// Avail: Clerk is the only login. A magic link never signs anyone in or redeems the
// invitation; it only forwards to the invitation page, which requires a Clerk login.
it('forwards a valid magic link to the invitation page without signing in or consuming it', function () {
    [$team, $user, $password, $token, $invitation] = createInvitationLinkFixture();

    $this->get(route('auth.link', ['token' => $token]))
        ->assertRedirect(route('team.invitation.show', $invitation->uuid));

    $this->assertGuest();
    $this->assertDatabaseHas('team_invitations', ['email' => $user->email]);
    expect($user->teams()->where('team_id', $team->id)->exists())->toBeFalse();

    $user->refresh();
    expect(Hash::check($password, $user->password))->toBeTrue();
});

it('finds the matching invitation for a legacy token when the email has multiple invitations', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create([
        'email' => 'legacy-invitee@example.com',
        'password' => Hash::make($password = 'temporary-password-123'),
    ]);
    $legacyToken = Crypt::encryptString("{$user->email}@@@{$password}");

    TeamInvitation::create([
        'team_id' => Team::factory()->create()->id,
        'uuid' => (string) new Cuid2(32),
        'email' => $user->email,
        'role' => 'member',
        'link' => route('auth.link', ['token' => Crypt::encryptString("{$user->email}@@@another-password")]),
        'via' => 'link',
    ]);

    $matching = TeamInvitation::create([
        'team_id' => $team->id,
        'uuid' => (string) new Cuid2(32),
        'email' => $user->email,
        'role' => 'member',
        'link' => route('auth.link', ['token' => $legacyToken]),
        'via' => 'link',
    ]);

    $this->get(route('auth.link', ['token' => $legacyToken]))
        ->assertRedirect(route('team.invitation.show', $matching->uuid));
});

it('does not sign in or redeem the invitation when a magic link is posted', function () {
    [$team, $user, $password, $token, $invitation] = createInvitationLinkFixture();

    $this->post(route('auth.link.accept'), ['token' => $token])
        ->assertRedirect(route('team.invitation.show', $invitation->uuid));

    $this->assertGuest();
    $this->assertDatabaseHas('team_invitations', ['uuid' => $invitation->uuid]);
    expect($user->teams()->where('team_id', $team->id)->exists())->toBeFalse();

    $user->refresh();
    expect(Hash::check($password, $user->password))->toBeTrue();
});

it('sends an invalid posted magic link to the login page', function () {
    $this->post(route('auth.link.accept'), ['token' => 'not-a-valid-token'])
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('rejects a magic link when the stored invitation token differs', function () {
    [, $user, , $token, $invitation] = createInvitationLinkFixture();
    $differentToken = Crypt::encryptString("{$user->email}@@@{$invitation->uuid}@@@different-password");

    $invitation->forceFill([
        'link' => route('auth.link', ['token' => $differentToken]),
    ])->save();

    $this->get(route('auth.link', ['token' => $token]))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('rejects a magic link when the invitation was revoked', function () {
    [, $user, , $token, $invitation] = createInvitationLinkFixture();
    $invitation->delete();

    $this->get(route('auth.link', ['token' => $token]))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->teams()->where('personal_team', false)->exists())->toBeFalse();
});

it('rejects a magic link when another invitation exists for the same email', function () {
    [, $user, , $token, $invitation] = createInvitationLinkFixture();
    $invitation->delete();

    $otherTeam = Team::factory()->create();
    TeamInvitation::create([
        'team_id' => $otherTeam->id,
        'uuid' => (string) new Cuid2(32),
        'email' => $user->email,
        'role' => 'admin',
        'link' => url('/invitations/other-invitation'),
        'via' => 'link',
    ]);

    $this->get(route('auth.link', ['token' => $token]))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->teams()->where('team_id', $otherTeam->id)->exists())->toBeFalse();
});

it('rejects a magic link when the invitation expired', function () {
    [, $user, , $token, $invitation] = createInvitationLinkFixture();
    $invitation->forceFill([
        'created_at' => now()->subDays(config('constants.invitation.link.expiration_days') + 1),
        'updated_at' => now()->subDays(config('constants.invitation.link.expiration_days') + 1),
    ])->save();

    $this->get(route('auth.link', ['token' => $token]))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
});

it('rejects a malformed magic link token', function () {
    $this->get(route('auth.link', ['token' => 'not-a-valid-token']))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('declares the magic link method contracts', function () {
    $linkReturnType = (new ReflectionMethod(Controller::class, 'link'))->getReturnType();
    $acceptLinkReturnType = (new ReflectionMethod(Controller::class, 'acceptLink'))->getReturnType();
    $credentialsMethod = new ReflectionMethod(Controller::class, 'magicLinkCredentials');
    $isValidReturnType = (new ReflectionMethod(TeamInvitation::class, 'isValid'))->getReturnType();

    expect((string) $linkReturnType)->toContain('Illuminate\\Contracts\\View\\View')
        ->and((string) $linkReturnType)->toContain('Illuminate\\Http\\RedirectResponse')
        ->and((string) $acceptLinkReturnType)->toBe('Illuminate\\Http\\RedirectResponse')
        ->and($credentialsMethod->getDocComment())->toContain('@return array{0: User, 1: TeamInvitation}|null')
        ->and((string) $isValidReturnType)->toBe('bool');
});
