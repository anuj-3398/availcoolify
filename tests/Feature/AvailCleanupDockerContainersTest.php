<?php

use App\Actions\Server\CleanupDocker;

/**
 * Avail: the scheduled Docker cleanup removes stopped helper containers but keeps stopped
 * application, database and service containers and the proxy (upstream's prune ORed its
 * label!= filters and deleted them all).
 */
function availRunContainerPrune(string $psOutput): array
{
    $dir = sys_get_temp_dir().'/avail-prune-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.'/ps.txt', $psOutput);
    file_put_contents($dir.'/docker', "#!/bin/sh\nif [ \"\$1\" = ps ]; then cat {$dir}/ps.txt; else echo \"\$@\" >> {$dir}/calls.txt; fi\n");
    chmod($dir.'/docker', 0755);

    $command = (new CleanupDocker)->buildContainerPruneCommand();
    exec('PATH='.escapeshellarg($dir).':$PATH sh -c '.escapeshellarg($command), $output, $exitCode);

    $calls = file_exists($dir.'/calls.txt') ? array_filter(explode("\n", file_get_contents($dir.'/calls.txt'))) : [];
    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);

    return [$exitCode, array_values($calls)];
}

test('only stopped helper containers are removed', function () {
    [$exitCode, $calls] = availRunContainerPrune(implode("\n", [
        'aaa111|application|',
        'bbb222|database|',
        'ccc333|service|',
        'ddd444||true',
        'eee555||',
        'fff666|helper|',
    ])."\n");

    expect($exitCode)->toBe(0)
        ->and($calls)->toBe(['rm eee555 fff666']);
});

test('nothing is removed when only kept containers are stopped', function () {
    [$exitCode, $calls] = availRunContainerPrune("aaa111|application|\nddd444||true\n");

    expect($exitCode)->toBe(0)->and($calls)->toBe([]);
});

test('the cleanup no longer uses docker container prune', function () {
    $source = file_get_contents(app_path('Actions/Server/CleanupDocker.php'));

    expect($source)->not->toContain('docker container prune');
});
