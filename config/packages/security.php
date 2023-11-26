<?php

declare(strict_types=1);

use Auth0\Symfony\Security\UserProvider;
use Symfony\Config\SecurityConfig;

return static function (SecurityConfig $securityConfig): void {
    $securityConfig
        ->provider('auth0_provider')
        ->id(UserProvider::class);

    $securityConfig->firewall('dev')
        ->pattern('^/(_(profiler|wdt)|css|images|js)/')
        ->security(false);

    $securityConfig->firewall('main')
        ->pattern('/(muj-profil|upravit-profil|pridat-cas|stopky|upravit-cas|smazat-cas|ulozit-stopky)')
        ->provider('auth0_provider')
        ->customAuthenticators(['auth0.authenticator']);
};
