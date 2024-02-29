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
        ->customAuthenticators(['auth0.authenticator']);

    $securityConfig->accessControl()
        ->path('^/(muj-profil|upravit-profil|pridat-cas|puzzle-stopky|zapnout-stopky|stopky|upravit-cas|smazat-cas|ulozit-stopky|porovnat-s-puzzlerem|pridat-hrace-k-oblibenym|odebrat-hrace-z-oblibenych)|(en/(save-stopwatch|add-time|compare-with-puzzler|delete-time|edit-profile|edit-time|my-profile|stopwatch|start-stopwatch|puzzle-stopwatch|add-player-to-favorites|remove-player-from-favorites))')
        ->roles([AuthenticatedVoter::IS_AUTHENTICATED_FULLY]);

    $securityConfig->accessControl()
        ->path('^/')
        ->roles([AuthenticatedVoter::PUBLIC_ACCESS]);
};
