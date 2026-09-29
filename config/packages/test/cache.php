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
//
// The solve-time distributions are the exception: a guide test pins a page to a
// known snapshot by writing it into this pool, and nothing it computes from a
// test's rolled-back rows may outlive that test's kernel - so it lives in memory.
return App::config([
    'framework' => [
        'cache' => [
            'app' => 'cache.adapter.filesystem',
            'pools' => [
                'solve_time_distribution_cache' => [
                    'adapters' => ['cache.adapter.array'],
                ],
            ],
        ],
    ],
]);
