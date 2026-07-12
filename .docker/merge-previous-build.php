<?php

declare(strict_types=1);

/**
 * Carries the previous release's hashed build assets into the current image.
 *
 * During blue-green rollout the outgoing container still serves HTML that
 * references the previous build's asset URLs; without these files a request
 * routed to the new container 404s them and the page renders unstyled.
 *
 * Only files listed in the previous build's manifest.json are copied and
 * existing files are never overwritten — the previous image's own carried-over
 * generation is not in its manifest, so retention is capped at exactly one
 * generation back.
 *
 * Usage: php merge-previous-build.php <previous-build-dir> <target-build-dir>
 */

if (!isset($argv[1], $argv[2])) {
    fwrite(STDERR, "Usage: php merge-previous-build.php <previous-build-dir> <target-build-dir>\n");
    exit(1);
}

[, $previousDir, $targetDir] = $argv;

$manifestPath = $previousDir . '/manifest.json';

if (!is_file($manifestPath)) {
    echo "No previous manifest found — nothing to carry over.\n";
    exit(0);
}

$manifest = json_decode((string) file_get_contents($manifestPath), associative: true);

if (!is_array($manifest)) {
    echo "Previous manifest is unreadable — nothing to carry over.\n";
    exit(0);
}

$copied = 0;

foreach ($manifest as $hashedUrl) {
    if (!is_string($hashedUrl)) {
        continue;
    }

    $path = (string) parse_url($hashedUrl, PHP_URL_PATH);

    if (!str_starts_with($path, '/build/')) {
        continue;
    }

    $relativePath = substr($path, strlen('/build/'));

    if (str_contains($relativePath, '..')) {
        continue;
    }

    // Also carry the precompressed siblings Caddy serves via precompressed file_server
    foreach (['', '.br', '.gz'] as $suffix) {
        $from = $previousDir . '/' . $relativePath . $suffix;
        $to = $targetDir . '/' . $relativePath . $suffix;

        if (!is_file($from) || file_exists($to)) {
            continue;
        }

        $directory = dirname($to);

        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        copy($from, $to);
        $copied++;
    }
}

echo "Carried over {$copied} asset files from the previous release.\n";
