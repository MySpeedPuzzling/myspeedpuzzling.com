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
        ->pattern('/(muj-profil|pridat-cas)')
        ->provider('auth0_provider')
        ->customAuthenticators(['auth0.authenticator']);
};
