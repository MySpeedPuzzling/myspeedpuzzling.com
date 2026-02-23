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
            ],
        ],
    ],
]);
