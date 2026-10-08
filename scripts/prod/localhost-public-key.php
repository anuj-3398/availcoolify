<?php

/*
 * Prints the public key of Coolify's "localhost's key" (private key id 0, the one it uses to SSH to its own
 * host) on one line. Read-only. Runs inside the coolify container:
 *
 *   docker exec -i coolify php < scripts/prod/localhost-public-key.php
 *
 * The release workflow feeds the result to ssh-authorized-keys.sh, so the control plane's authorized_keys
 * always holds exactly the key the database holds: after a first install, a re-run, or a rebuild of the VM
 * from an existing database.
 */

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key = App\Models\PrivateKey::find(0);
if (! $key) {
    fwrite(STDERR, "No localhost key (private key id 0) in the database\n");
    exit(1);
}

echo trim($key->getPublicKey()), "\n";
