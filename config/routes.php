<?php

declare(strict_types=1);

use SpeedPuzzling\Web\Controller\LoginController;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
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

    // The path is load-bearing (bookmarks, the base.html.twig sign-in button, the
    // Auth0 entry point): LoginController answers on it either with the native form
    // or with the Auth0 redirect, depending on the native_login flag. POST /login is
    // intercepted by LoginFormAuthenticator before routing, so both methods must
    // stay allowed here - a GET-only route would 405 the login submit at routing time.
    $routingConfigurator->add('login', '/login')
        ->controller(LoginController::class)
        ->defaults([NativeAuthPageSubscriber::ROUTE_DEFAULT => true]);

    $routingConfigurator->add('callback', '/auth/callback')
        ->controller('Auth0\Symfony\Controllers\AuthenticationController::callback');

    $routingConfigurator->add('logout', '/logout')
        ->controller('Auth0\Symfony\Controllers\AuthenticationController::logout');

    $routingConfigurator->add('app_logout', '/app-logout')
        ->methods(['GET']);
};
