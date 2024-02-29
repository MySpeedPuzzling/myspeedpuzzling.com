<?php

declare(strict_types=1);

use Symfony\Config\FrameworkConfig;

return static function (FrameworkConfig $framework): void {
    $cacheConfig = $framework->cache();

    $cacheConfig->defaultRedisProvider('%env(REDIS_CACHE_DSN)%');

    $cacheConfig->app('cache.adapter.redis');

    $cacheConfig->pool('cache.flysystem.psr6')
        ->adapters(['cache.app']);

    $cacheConfig->pool('auth0_token_cache')
        ->adapters(['cache.app']);

    $cacheConfig->pool('auth0_management_token_cache')
        ->adapters(['cache.app']);
};
