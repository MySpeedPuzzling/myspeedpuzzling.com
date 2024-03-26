<?php declare(strict_types=1);

use Symfony\Config\NelmioCorsConfig;

return static function (NelmioCorsConfig $corsConfig): void {
    $corsConfig->defaults()
        ->allowOrigin(['*'])
        ->allowHeaders(['*'])
        ->allowMethods(['GET', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'])
        ->skipSameAsOrigin(true)
        ->maxAge(3600);
};
