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
// Same for the hardest / easiest puzzle lists, built from the rated puzzles a
// test seeds and DAMA rolls back, for the global improvement ratio snapshots, and for
// the marketplace's events with sellers going (built from rows a test adds) and the achievement rarity.
//
// ParaTest workers (TEST_TOKEN 1..N) run side by side: each keeps its own filesystem
// pools, so one worker's cached stats never leak into another worker's tests.
return App::config([
    'framework' => [
        'cache' => [
            'app' => 'cache.adapter.filesystem',
            'directory' => '%kernel.cache_dir%/pools%env(default::TEST_TOKEN)%',
            'pools' => [
                'solve_time_distribution_cache' => [
                    'adapters' => ['cache.adapter.array'],
                ],
                'difficulty_rankings_cache' => [
                    'adapters' => ['cache.adapter.array'],
                ],
                'achievement_rarity_cache' => [
                    'adapters' => ['cache.adapter.array'],
                ],
                'global_improvement_ratio_snapshot_cache' => [
                    'adapters' => ['cache.adapter.array'],
                ],
                'marketplace_events_cache' => [
                    'adapters' => ['cache.adapter.array'],
                ],
                // A test flips the XP launch switch in its rolled-back transaction
                'xp_launch_readiness_cache' => [
                    'adapters' => ['cache.adapter.array'],
                ],
                // Built from the times a test seeds; switched off in tests anyway (config/packages/test/services.php)
                'times_board_guest_cache' => [
                    'adapters' => ['cache.adapter.array'],
                ],
                // The online competitions' participant API's rate limits (production: an atomic Redis limiter,
                // config/services.php; here framework limiters stand in - config/packages/test/rate_limiter.php) must
                // survive the kernel reboots between BrowserKit requests, like the login limiter's (see above) - on disk
                // like cache.app, never on a Redis CI does not have
                'online_participant_rate_limits' => [
                    'adapters' => ['cache.app'],
                ],
                // The watch page's token limit stands in the same way (online_watch_ip)
                'online_watch_rate_limits' => [
                    'adapters' => ['cache.app'],
                ],
                // A studio's spectator count comes from the LiveKit list a test queues - never one an earlier test left
                'online_watch_spectators' => [
                    'adapters' => ['cache.adapter.array'],
                ],
            ],
        ],
    ],
]);
