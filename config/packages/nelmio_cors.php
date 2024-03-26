<?php declare(strict_types=1);

use function Symfony\Component\DependencyInjection\Loader\Configurator\env;

return static function (\Symfony\Config\NelmioCorsConfig $corsConfig): void {
    $corsConfig->defaults()
        ->originRegex(true)
        ->allowOrigin(env('CORS_ALLOW_ORIGIN'))
        ->allowMethods(['GET', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'])
        ->allowHeaders(['*'])
        ->skipSameAsOrigin(true)
        ->maxAge(3600);
};
