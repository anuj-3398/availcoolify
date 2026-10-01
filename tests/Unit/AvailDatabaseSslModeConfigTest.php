<?php

use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Support\Env;

/**
 * Avail: the Postgres SSL mode and CA bundle come from DB_SSLMODE / DB_SSLROOTCERT, so a
 * remote database can require verify-full. Without them the connection stays on "prefer".
 */
function availPgsqlConfig(array $overrides): array
{
    $keys = ['DB_SSLMODE', 'DB_SSLROOTCERT'];
    $repository = Env::getRepository();
    $original = [];

    foreach ($keys as $key) {
        $original[$key] = env($key);
        $repository->clear($key);
    }

    try {
        foreach ($overrides as $key => $value) {
            $repository->set($key, (string) $value);
        }

        return (require __DIR__.'/../../config/database.php')['connections']['pgsql'];
    } finally {
        foreach ($keys as $key) {
            $repository->clear($key);
            if ($original[$key] !== null) {
                $repository->set($key, (string) $original[$key]);
            }
        }
    }
}

function availPgsqlDsn(array $config): string
{
    $method = new ReflectionMethod(PostgresConnector::class, 'getDsn');

    return $method->invoke(new PostgresConnector, $config);
}

it('keeps sslmode prefer and no CA when nothing is set', function () {
    $config = availPgsqlConfig([]);

    expect($config['sslmode'])->toBe('prefer')
        ->and($config['sslrootcert'])->toBeNull()
        ->and(availPgsqlDsn($config))->toContain('sslmode=prefer')
        ->not->toContain('sslrootcert');
});

it('passes verify-full and the CA bundle to the connection', function () {
    $config = availPgsqlConfig([
        'DB_SSLMODE' => 'verify-full',
        'DB_SSLROOTCERT' => '/var/www/html/storage/app/ssh/db-ca.pem',
    ]);

    expect($config['sslmode'])->toBe('verify-full')
        ->and(availPgsqlDsn($config))
        ->toContain('sslmode=verify-full')
        ->toContain('sslrootcert=/var/www/html/storage/app/ssh/db-ca.pem');
});
