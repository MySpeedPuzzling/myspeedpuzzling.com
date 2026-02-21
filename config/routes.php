<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routingConfigurator): void {
    $routingConfigurator->import(__DIR__ . '/../src/Controller', 'attribute');

    // OAuth2 Server Routes
    // Note: We don't import the bundle's authorize route because we have our own
    // AuthorizationController that handles login redirect before delegating to the bundle.
    // Only import the token endpoint from the bundle.
    $routingConfigurator->add('oauth2_token', '/oauth2/token')
        ->controller(['league.oauth2_server.controller.token', 'indexAction'])
        ->methods(['POST']);

    $routingConfigurator->add('login', '/login')
        ->controller('Auth0\Symfony\Controllers\AuthenticationController::login');

    $routingConfigurator->add('callback', '/auth/callback')
        ->controller('Auth0\Symfony\Controllers\AuthenticationController::callback');

    $routingConfigurator->add('logout', '/logout')
        ->controller('Auth0\Symfony\Controllers\AuthenticationController::logout');

    $routingConfigurator->add('app_logout', '/app-logout')
        ->methods(['GET']);
};
