<?php

declare(strict_types=1);

use SpeedPuzzling\Web\SymfonyApplicationKernel;

// Panther per-test database override, BEFORE the runtime loads env. This must be
// deterministic on EVERY request even in long-lived test server processes
// (php -S keeps one PHP process across requests): putenv() from a previous
// request leaks into the process env, and the per-process stat cache can serve
// a stale existence check for a file that is deleted and recreated between
// tests. Both bit us in CI on 2026-07-30 - requests were silently served with
// an earlier test's DATABASE_URL, which broke DB-backed native-auth sessions.
// The rule: pin the process-original value once, then derive DATABASE_URL from
// the override file (or the pinned original) fresh on every request.
if (($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev') === 'test') {
    if (getenv('PANTHER_BASE_DATABASE_URL') === false) {
        putenv('PANTHER_BASE_DATABASE_URL=' . (getenv('DATABASE_URL') ?: ''));
    }

    $pantherDbFile = dirname(__DIR__) . '/var/panther_db_url.txt';
    clearstatcache(true, $pantherDbFile);

    $url = @file_get_contents($pantherDbFile);
    $url = $url === false ? '' : trim($url);

    if ($url === '') {
        $url = (string) getenv('PANTHER_BASE_DATABASE_URL');
    }

    if ($url !== '') {
        $_ENV['DATABASE_URL'] = $url;
        $_SERVER['DATABASE_URL'] = $url;
        putenv('DATABASE_URL=' . $url);
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    return new SymfonyApplicationKernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
