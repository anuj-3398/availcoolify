<?php

/**
 * AvailCoolify trims upstream items that don't apply to a Clerk-only, self-hosted install.
 */
test('account menu no longer offers whats new, feedback or sponsoring', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($menu)
        ->not->toContain('trigger="account-menu"')
        ->not->toContain('<livewire:help />')
        ->not->toContain('Sponsor us')
        ->toContain('Log out');
});

test('profile page has no password change form', function () {
    $profile = file_get_contents(resource_path('views/livewire/profile/index.blade.php'));

    expect($profile)
        ->not->toContain('wire:submit="resetPassword"')
        ->not->toContain('new_password_confirmation');
});

test('coolify two factor authentication is removed in favour of clerk', function () {
    $profile = file_get_contents(resource_path('views/livewire/profile/index.blade.php'));
    $members = file_get_contents(resource_path('views/livewire/team/member/index.blade.php'));
    $memberRow = file_get_contents(resource_path('views/livewire/team/member.blade.php'));

    expect($profile)->not->toContain('Two-factor authentication')->not->toContain('two-factor-authentication')
        ->and($members)->not->toContain('<span>2FA</span>')->not->toContain('membersWithoutTwoFactorCount')
        ->and($memberRow)->not->toContain('x-two-factor-badge')
        ->and(\Laravel\Fortify\Features::enabled(\Laravel\Fortify\Features::twoFactorAuthentication()))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('two-factor.login'))->toBeFalse();
});
