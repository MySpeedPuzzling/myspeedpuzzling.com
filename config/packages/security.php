<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Auth0\Symfony\Security\UserProvider;
use SpeedPuzzling\Web\Security\Auth0EntryPoint;
use SpeedPuzzling\Web\Security\OAuth2UserProvider;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;

return App::config([
    'security' => [
        'providers' => [
            'auth0_provider' => [
                'id' => UserProvider::class,
            ],
            'oauth2_provider' => [
                'id' => OAuth2UserProvider::class,
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
            'api' => [
                'pattern' => '^/api/v1/',
                'stateless' => true,
                'provider' => 'oauth2_provider',
                'oauth2' => true,
            ],
            'main' => [
                'pattern' => '^/',
                'lazy' => true,
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
                'path' => '^/api/v1/me',
                'roles' => ['ROLE_OAUTH2_PROFILE:READ'],
            ],
            [
                'path' => '^/api/v1/players/.*/results',
                'roles' => ['ROLE_OAUTH2_RESULTS:READ'],
            ],
            [
                'path' => '^/api/v1/players/.*/statistics',
                'roles' => ['ROLE_OAUTH2_STATISTICS:READ'],
            ],
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
