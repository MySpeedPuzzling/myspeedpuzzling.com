<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator->import('@LiveComponentBundle/config/routes.php')
        ->prefix('/{_locale}/_components')
        ->requirements([
            '_locale' => 'en|cs',
        ]);;
};
