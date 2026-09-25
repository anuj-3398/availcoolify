<?php

use App\Livewire\Settings\AccessProtection;
use App\Models\Application;
use App\Models\Environment;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * Avail: environment-wide Clerk login (Settings -> Access protection).
 */
test('an app follows its environment switch', function () {
    $application = new Application;
    $application->setRelation('environment', new Environment(['name' => 'staging']));

    expect(previewGuardEnabledFor($application, 0))->toBeFalse();

    $application->environment->preview_guard_enabled = true;

    expect(previewGuardEnabledFor($application, 0))->toBeTrue();
});

test('PR previews are always protected, even in a public environment', function () {
    $application = new Application;
    $application->setRelation('environment', new Environment(['name' => 'production']));

    expect(previewGuardEnabledFor($application, 0))->toBeFalse()
        ->and(previewGuardEnabledFor($application, 3))->toBeTrue();
});

test('the old per-app scope no longer affects protection', function () {
    $application = new Application;
    $application->preview_guard_scope = 'both';
    $application->setRelation('environment', new Environment(['name' => 'production']));

    expect(previewGuardEnabledFor($application, 0))->toBeFalse();
});

test('the app security section has no per-app Clerk option', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/general.blade.php'));

    expect($view)
        ->not->toContain("'value' => 'clerk'")
        ->not->toContain('previewGuardScope')
        ->toContain("route('settings.access-protection')");
});

test('the access protection page is registered for instance settings', function () {
    $route = Route::getRoutes()->getByName('settings.access-protection');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('settings/access-protection')
        ->and($route->getActionName())->toContain(AccessProtection::class)
        ->and($route->gatherMiddleware())->toContain('auth');

    $layout = file_get_contents(resource_path('views/components/settings/layout.blade.php'));
    expect($layout)->toContain("'route' => 'settings.access-protection'");
});

test('the access protection page renders an accessible switch per environment', function () {
    $view = file_get_contents(resource_path('views/livewire/settings/access-protection.blade.php'));

    expect($view)
        ->toContain('role="switch"')
        ->toContain('aria-checked=')
        ->toContain('wire:click="toggle({{ $environment->id }})"');
});

test('only instance admins can toggle environment protection', function () {
    $source = file_get_contents(app_path('Livewire/Settings/AccessProtection.php'));

    expect(substr_count($source, 'isInstanceAdmin()'))->toBeGreaterThanOrEqual(2);
});
