<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

// The test environment must not depend on Redis: CI's tests job runs no Redis
// service, and a dead Redis silently degrades every cache operation to a miss
// (Symfony swallows connection errors) - state that must persist across the
// kernel reboots between BrowserKit requests, like the login rate limiter's
// sliding window (cache.rate_limiter inherits cache.app), would silently reset
// per request on CI while working locally against the dev compose Redis.
// Filesystem behaves identically in both places and keeps tests off dev Redis.
return App::config([
    'framework' => [
        'cache' => [
            'app' => 'cache.adapter.filesystem',
        ],
    ],
]);
