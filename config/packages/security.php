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
        ->path('^/(muj-profil|upravit-profil|upravit-kod-hrace|pridat-cas|puzzle-stopky|zapnout-stopky|stopky|upravit-cas|smazat-cas|ulozit-stopky|porovnat-s-puzzlerem|pridat-hrace-k-oblibenym|odebrat-hrace-z-oblibenych|feedback|notifikace|competition-connect|cas-pridan|clenstvi|koupit-clenstvi)|(en/(save-stopwatch|add-time|compare-with-puzzler|delete-time|edit-profile|edit-player-code|edit-time|my-profile|stopwatch|start-stopwatch|puzzle-stopwatch|add-player-to-favorites|remove-player-from-favorites|feedback|notifications|competition-connect|time-added|membership|buy-membership))')
        ->roles([AuthenticatedVoter::IS_AUTHENTICATED_FULLY]);

    $securityConfig->accessControl()
        ->path('^/')
        ->roles([AuthenticatedVoter::PUBLIC_ACCESS]);
};
