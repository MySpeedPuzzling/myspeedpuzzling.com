<?php

declare(strict_types=1);

use Symfony\Config\Framework\RouterConfig;
use Symfony\Config\FrameworkConfig;
use function Symfony\Component\DependencyInjection\Loader\Configurator\env;

return static function (FrameworkConfig $frameworkConfig): void {
    /** @var RouterConfig $router */
    $router = $frameworkConfig->router();

    $router->utf8(true)
        ->defaultUri(env('APP_URL'));
};
