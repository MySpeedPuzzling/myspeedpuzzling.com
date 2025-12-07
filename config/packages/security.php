<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Auth0\Symfony\Security\UserProvider;
use SpeedPuzzling\Web\Security\Auth0EntryPoint;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;

return App::config([
    'security' => [
        'providers' => [
            'auth0_provider' => [
                'id' => UserProvider::class,
            ],
        ],
        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_(profiler|wdt)|css|images|js)/',
                'security' => false,
            ],
            'stateless' => [
                'pattern' => '^(/-/health-check|/media/cache|/sitemap)',
                'stateless' => true,
                'security' => false,
            ],
            'main' => [
                'pattern' => '^/',
                'provider' => 'auth0_provider',
                'custom_authenticators' => ['auth0.authenticator'],
                'entry_point' => Auth0EntryPoint::class,
                'logout' => [
                    'path' => 'app_logout',
                    'target' => '/',
                ],
            ],
        ],
        'access_control' => [
            [
                'path' => '^/admin',
                'roles' => [AuthenticatedVoter::IS_AUTHENTICATED_FULLY],
            ],
            [
                'path' => '^/',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
        ],
    ],
]);
