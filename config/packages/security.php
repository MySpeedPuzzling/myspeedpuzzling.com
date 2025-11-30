<?php

declare(strict_types=1);

use Auth0\Symfony\Security\UserProvider;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Config\SecurityConfig;

return static function (SecurityConfig $securityConfig): void {
    $securityConfig
        ->provider('auth0_provider')
        ->id(UserProvider::class);

    $securityConfig->firewall('dev')
        ->pattern('^/(_(profiler|wdt)|css|images|js)/')
        ->security(false);

    $securityConfig->firewall('stateless')
        ->pattern('^(/-/health-check|/media/cache|/sitemap)')
        ->stateless(true)
        ->security(false);

    $securityConfig->firewall('main')
        ->pattern('^/')
        ->provider('auth0_provider')
        ->customAuthenticators(['auth0.authenticator'])
        ->logout()
            ->path('app_logout')
            ->target('/');

    $securityConfig->accessControl()
        ->path('^/admin')
        ->roles([AuthenticatedVoter::IS_AUTHENTICATED_FULLY]);

    $securityConfig->accessControl()
        ->path('^/')
        ->roles([AuthenticatedVoter::PUBLIC_ACCESS]);
};
