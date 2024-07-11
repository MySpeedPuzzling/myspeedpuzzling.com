<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator->import(__DIR__ . '/../src/Controller', 'attribute');

    $routingConfigurator->add('login', '/login')
        ->controller('Auth0\Symfony\Controllers\AuthenticationController::login');

    $routingConfigurator->add('callback', '/auth/callback')
        ->controller('Auth0\Symfony\Controllers\AuthenticationController::callback');

    $routingConfigurator->add('logout', '/logout')
        ->controller('Auth0\Symfony\Controllers\AuthenticationController::logout');

    $routingConfigurator->add('app_logout', '/app-logout')
        ->methods(['GET']);
};
