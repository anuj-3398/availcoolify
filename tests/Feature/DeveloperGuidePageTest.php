<?php

use App\Livewire\DeveloperGuide;
use Livewire\Livewire;

/**
 * Avail: the in-app Developer Guide page and its account menu entry.
 */
test('developer guide renders the markdown runbook', function () {
    Livewire::test(DeveloperGuide::class)
        ->assertSee('Developer Guide')
        ->assertSeeHtml('<h2>Preview deployments</h2>')
        ->assertSeeHtml('<table>')
        ->assertSee('{{team.KEY}}', false);
});

test('developer guide is an authenticated route', function () {
    $route = app('router')->getRoutes()->getByName('developer-guide');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('developer-guide')
        ->and($route->gatherMiddleware())->toContain('auth');
});

test('account menu links to the developer guide', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($menu)
        ->toContain("route('developer-guide')")
        ->toContain('Developer Guide');
});

test('every image in the developer guide exists', function () {
    $markdown = file_get_contents(resource_path('views/docs/developer-guide.md'));
    preg_match_all('/!\[[^\]]*\]\((\/images\/developer-guide\/[^)]+)\)/', $markdown, $matches);

    expect($matches[1])->not->toBeEmpty();
    foreach ($matches[1] as $path) {
        expect(file_exists(public_path(ltrim($path, '/'))))->toBeTrue($path);
    }
});

test('developer guide image urls carry a content version', function () {
    $html = Livewire::test(DeveloperGuide::class)->html();

    expect($html)->toMatch('#/images/developer-guide/login\.jpg\?v=[0-9a-f]{8}#');
});
