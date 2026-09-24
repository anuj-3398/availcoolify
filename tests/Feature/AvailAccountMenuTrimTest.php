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
