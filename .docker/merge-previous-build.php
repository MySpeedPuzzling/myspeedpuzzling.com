<?php

declare(strict_types=1);

/**
 * Carries recent releases' hashed build assets into the current image.
 *
 * A browser keeps the HTML it loaded until the user navigates — a tab left open
 * overnight still references the build hashes that were current when it loaded.
 * Without those files the next request 404s them, the page renders unstyled and
 * the asset-failure self-heal has to purge and reload.
 *
 * RETENTION IS BY AGE, NOT BY BUILD COUNT. Carrying only the immediately
 * previous build looks sufficient but is not: retention then lasts until the
 * *next* build, not for any wall-clock span. On 2026-07-30 seven releases
 * shipped within an hour, so each generation stayed resolvable for ~10 minutes
 * while real clients were still holding hours-old HTML — 8k asset 404s in two
 * days. Age-based retention decouples how long an old asset survives from how
 * often we happen to deploy.
 *
 * Chaining works because the ledger written into each image records WHEN each
 * carried file stopped being current; the next build reads that ledger from the
 * previous image and preserves the original timestamp, so a file is dropped
 * RETENTION_DAYS after it went stale regardless of how many builds happened in
 * between. Files the current build produced itself are never touched.
 *
 * Usage: php merge-previous-build.php <previous-build-dir> <target-build-dir>
 */

/**
 * How long a superseded asset stays resolvable. Long enough to cover a tab left
 * open over a weekend; short enough that the image does not accumulate
 * indefinitely. Only files that CHANGED between builds cost anything — stable
 * hashes (flags, fonts) are regenerated identically and skipped.
 */
const RETENTION_DAYS = 7;

/** Filename of the ledger, kept inside the build dir so it ships with the image. */
const LEDGER_FILENAME = '.carried-assets.json';

if (!isset($argv[1], $argv[2])) {
    fwrite(STDERR, "Usage: php merge-previous-build.php <previous-build-dir> <target-build-dir>\n");
    exit(1);
}

[, $previousDir, $targetDir] = $argv;

if (!is_dir($previousDir)) {
    echo "No previous build directory — nothing to carry over.\n";
    exit(0);
}

if (!is_dir($targetDir)) {
    fwrite(STDERR, "Target build directory {$targetDir} does not exist.\n");
    exit(1);
}

$now = time();
$retentionSeconds = RETENTION_DAYS * 86400;

/**
 * Ledger from the previous image: relative path => unix timestamp at which the
 * file stopped being part of a current build. Absent on the first build after
 * this script changed, which simply means everything stale starts ageing now.
 *
 * @var array<string, int> $previousLedger
 */
$previousLedger = [];
$previousLedgerPath = $previousDir . '/' . LEDGER_FILENAME;

if (is_file($previousLedgerPath)) {
    $decoded = json_decode((string) file_get_contents($previousLedgerPath), associative: true);

    if (is_array($decoded)) {
        foreach ($decoded as $relativePath => $staleSince) {
            if (is_string($relativePath) && is_int($staleSince)) {
                $previousLedger[$relativePath] = $staleSince;
            }
        }
    }
}

/** @var array<string, int> $ledger */
$ledger = [];
$copied = 0;
$pruned = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($previousDir, FilesystemIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    $relativePath = substr($file->getPathname(), strlen($previousDir) + 1);

    // The ledger describes the carry-over, it is not itself a carried asset.
    if ($relativePath === LEDGER_FILENAME) {
        continue;
    }

    // The current build produced this file — it is current, not carried. This
    // also covers manifest.json/entrypoints.json and every unchanged asset.
    if (file_exists($targetDir . '/' . $relativePath)) {
        continue;
    }

    // Either it went stale in an earlier build (keep its original timestamp so
    // the clock does not reset on every deploy) or it goes stale right now.
    $staleSince = $previousLedger[$relativePath] ?? $now;

    if ($now - $staleSince > $retentionSeconds) {
        $pruned++;

        continue;
    }

    $destination = $targetDir . '/' . $relativePath;
    $directory = dirname($destination);

    if (!is_dir($directory)) {
        mkdir($directory, recursive: true);
    }

    if (!copy($file->getPathname(), $destination)) {
        fwrite(STDERR, "Failed to carry over {$relativePath}.\n");
        exit(1);
    }

    $ledger[$relativePath] = $staleSince;
    $copied++;
}

ksort($ledger);

$encoded = json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

if (file_put_contents($targetDir . '/' . LEDGER_FILENAME, $encoded) === false) {
    fwrite(STDERR, "Failed to write the carry-over ledger.\n");
    exit(1);
}

printf(
    "Carried over %d asset files from previous releases (%d pruned, retention %d days).\n",
    $copied,
    $pruned,
    RETENTION_DAYS,
);
