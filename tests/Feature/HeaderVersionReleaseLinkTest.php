<?php

use Illuminate\Support\Facades\Blade;

/**
 * AvailCoolify hides the running version (and its GitHub release link) next to the brand name.
 */
test('desktop header does not show the version badge or its release link', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->not->toContain('<x-version')
        ->not->toContain('releases/tag');
});

test('development versions are not linked to nonexistent github releases', function () {
    config(['constants.coolify.version' => '4.3.1-dev.d64cbda3e']);

    $version = Blade::render('<x-version />');

    expect($version)
        ->toContain('v4.3.1-dev.d64cbda3e')
        ->not->toContain('href=')
        ->not->toContain('target="_blank"');
});

test('mobile sidebar brand does not show the version badge', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toContain('data-mobile-sidebar-brand')
        ->not->toContain('<x-version');
});
