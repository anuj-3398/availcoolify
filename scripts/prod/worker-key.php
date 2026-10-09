<?php

/*
 * Prints the public key Coolify uses to manage the app servers, on one line, creating the key first if it
 * does not exist. Runs inside the coolify container:
 *
 *   docker exec -i coolify php < scripts/prod/worker-key.php
 *
 * The key is the private key named "avail-workers" (AVAIL_WORKER_KEY_NAME) in team 0. Its private half
 * never leaves the control plane; the release workflow feeds the public half to ssh-authorized-keys.sh on
 * every app server. Re-runs return the same key. To rotate it, rename the old key in the UI (Keys &
 * Tokens) and run deploy again: a new key is made and installed, and the old one is removed from the app
 * servers. Servers that still use the old key must be switched to the new one in the UI.
 *
 * Not PrivateKey::generateNewKeyPair(): it is rate-limited per logged-in user.
 */

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PrivateKey;

$name = getenv('AVAIL_WORKER_KEY_NAME') ?: 'avail-workers';

$key = PrivateKey::where('team_id', 0)->where('name', $name)->orderBy('id')->first();
if (! $key) {
    $pair = generateSSHKey('ed25519');
    $key = PrivateKey::createAndStore([
        'name' => $name,
        'description' => 'Coolify to the app servers (made by the release workflow)',
        'private_key' => $pair['private'],
        'team_id' => 0,
        'is_git_related' => false,
    ]);
    fwrite(STDERR, "Created private key \"$name\" (id {$key->id})\n");
} else {
    fwrite(STDERR, "Using private key \"$name\" (id {$key->id})\n");
}

echo trim($key->getPublicKey()), "\n";
