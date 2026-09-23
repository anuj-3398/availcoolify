<?php

/**
 * Applies patches under patches/ to their target vendor packages via `git apply`.
 *
 * Run automatically after `composer install`/`update` (see composer.json).
 * Deliberately avoids the system `patch` binary, which is missing from
 * Coolify's runtime image -- `git` is present there instead, and `git apply`
 * works fine even outside a git repository (verified against a plain
 * composer-installed vendor/ dir with no .git present).
 *
 * Idempotent: an already-applied or inapplicable patch is skipped silently
 * (via `git apply --check` first) rather than failing the whole install.
 */

$root = dirname(__DIR__);

$patches = [
    'patches/socialiteproviders-clerk-name-fallback.patch' => 'vendor/socialiteproviders/clerk',
];

foreach ($patches as $patch => $targetDir) {
    $patchPath = $root.'/'.$patch;
    $targetPath = $root.'/'.$targetDir;

    if (! is_file($patchPath) || ! is_dir($targetPath)) {
        continue;
    }

    exec(sprintf(
        'git apply --check --directory=%s %s 2>&1',
        escapeshellarg($targetDir),
        escapeshellarg($patch)
    ), $checkOutput, $checkExit);

    if ($checkExit !== 0) {
        // Already applied, or doesn't apply cleanly against this version -- skip.
        continue;
    }

    exec(sprintf(
        'git apply --directory=%s %s 2>&1',
        escapeshellarg($targetDir),
        escapeshellarg($patch)
    ), $applyOutput, $applyExit);

    if ($applyExit === 0) {
        echo "Applied vendor patch: {$patch}\n";
    } else {
        fwrite(STDERR, "Warning: vendor patch {$patch} failed to apply despite passing --check:\n".implode("\n", $applyOutput)."\n");
    }
}
