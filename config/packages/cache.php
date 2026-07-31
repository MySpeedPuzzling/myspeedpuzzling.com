<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'cache' => [
            'default_redis_provider' => '%env(REDIS_CACHE_DSN)%',
            'app' => 'cache.adapter.redis',
            'pools' => [
                'auth0_token_cache' => [
                    'adapters' => ['cache.app'],
                ],
                'auth0_management_token_cache' => [
                    'adapters' => ['cache.app'],
                ],
                // Per-request dedup markers for daily activity tracking - one
                // marker per user per day so the terminate subscriber costs one
                // cache read per request instead of a DB write
                'player_activity_cache' => [
                    'adapters' => ['cache.app'],
                ],
            ],
        ],
    ],
]);
