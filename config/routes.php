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

    // The path is load-bearing (bookmarks, the base.html.twig sign-in button,
    // LoginEntryPoint). POST /login is intercepted by LoginFormAuthenticator before
    // routing, so both methods must stay allowed here - a GET-only route would 405
    // the login submit at routing time.
    $routingConfigurator->add('login', '/login')
        ->controller(LoginController::class)
        ->defaults([NativeAuthPageSubscriber::ROUTE_DEFAULT => true]);

    // No controller: the main firewall's logout listener answers on this path
    // before routing reaches a controller (config/packages/security.php). The
    // path is the one the sign-out link has always pointed at - it used to be
    // Auth0's logout controller, which bounced on to /app-logout.
    $routingConfigurator->add('logout', '/logout')
        ->methods(['GET']);
};
