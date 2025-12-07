<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'auth0' => [
        'sdk' => [
            'domain' => '%env(trim:string:AUTH0_DOMAIN)%',
            'client_id' => '%env(trim:string:AUTH0_CLIENT_ID)%',
            'client_secret' => '%env(trim:string:AUTH0_CLIENT_SECRET)%',
            'cookie_secret' => '%kernel.secret%',
            'scopes' => ['openid', 'profile', 'email', 'offline_access'],
            'token_cache' => 'auth0_token_cache',
            'management_token_cache' => 'auth0_management_token_cache',
            'http_client' => 'psr18.http_client',
        ],
        'authenticator' => [
            'routes' => [
                'callback' => '%env(string:AUTH0_ROUTE_CALLBACK)%',
                'success' => '%env(string:AUTH0_ROUTE_SUCCESS)%',
                'failure' => '%env(string:AUTH0_ROUTE_FAILURE)%',
                'login' => '%env(string:AUTH0_ROUTE_LOGIN)%',
                'logout' => '%env(string:AUTH0_ROUTE_LOGOUT)%',
            ],
        ],
    ],
]);
