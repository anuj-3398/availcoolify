<?php

use App\Models\Application;

/**
 * Avail: new resources deploy (applications) or start (databases) right after creation.
 */
test('every application creation flow hands off to the auto-deploy redirect', function (string $component) {
    $source = file_get_contents(app_path("Livewire/Project/New/{$component}.php"));

    expect($source)
        ->toContain('availRedirectAfterApplicationCreated($application, [')
        ->not->toContain("'project.application.configuration'");
})->with([
    'PublicGitRepository',
    'GithubPrivateRepository',
    'GithubPrivateRepositoryDeployKey',
    'GitlabPrivateRepository',
    'SimpleDockerfile',
    'DockerImage',
]);

test('new standalone databases are started right after creation', function () {
    $source = file_get_contents(app_path('Livewire/Project/Resource/Create.php'));

    expect($source)->toContain('availAutoStartDatabase($database);');
});

test('no automatic deploy without deploy permission', function () {
    auth()->logout();

    expect(availAutoDeployApplication(new Application))->toBeNull();
});

test('compose applications without a loaded compose file wait for the user', function () {
    $user = Mockery::mock(\App\Models\User::class)->makePartial();
    $user->shouldReceive('can')->with('deploy', Mockery::any())->andReturnTrue();
    auth()->setUser($user);

    $application = new Application;
    $application->build_pack = 'dockercompose';
    $application->docker_compose_raw = null;

    expect(availAutoDeployApplication($application))->toBeNull();
});

test('without a queued deployment the user lands on the configuration page', function () {
    auth()->logout();
    $application = new Application;
    $application->uuid = 'app-uuid';

    $response = availRedirectAfterApplicationCreated($application, [
        'project_uuid' => 'p',
        'environment_uuid' => 'e',
        'application_uuid' => 'app-uuid',
    ]);

    expect($response->getTargetUrl())->toBe(route('project.application.configuration', [
        'project_uuid' => 'p',
        'environment_uuid' => 'e',
        'application_uuid' => 'app-uuid',
    ]));
});
