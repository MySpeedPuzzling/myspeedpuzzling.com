<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Auth0\Symfony\Security\UserProvider;
use SpeedPuzzling\Web\Security\Auth0EntryPoint;
use SpeedPuzzling\Web\Security\InternalApiAuthenticator;
use SpeedPuzzling\Web\Security\OAuth2UserProvider;
use SpeedPuzzling\Web\Security\PatAuthenticator;
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
            // Required by Symfony because the internal_api firewall must declare a provider,
            // but never actually invoked: InternalApiAuthenticator returns a SelfValidatingPassport
            // whose UserBadge closure produces the user inline. Kept as a dedicated dummy provider
            // so the config reads honestly — this firewall has its own user universe.
            'internal_api_provider' => [
                'memory' => [
                    'users' => [
                        InternalApiAuthenticator::USER_IDENTIFIER => [
                            'roles' => [InternalApiAuthenticator::ROLE],
                        ],
                    ],
                ],
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
                'custom_authenticators' => [PatAuthenticator::class],
            ],
            'internal_api' => [
                'pattern' => '^/internal-api/',
                'stateless' => true,
                'provider' => 'internal_api_provider',
                'custom_authenticators' => [InternalApiAuthenticator::class],
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
                'path' => '^/api/docs',
                'roles' => [AuthenticatedVoter::PUBLIC_ACCESS],
            ],
            [
                'path' => '^/internal-api/',
                'roles' => [InternalApiAuthenticator::ROLE],
            ],
            [
                'path' => '^/api/v1/me',
                'roles' => [AuthenticatedVoter::IS_AUTHENTICATED_FULLY],
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
                'path' => '^/api/v1/players/.*/collections',
                'roles' => ['ROLE_OAUTH2_COLLECTIONS:READ'],
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
