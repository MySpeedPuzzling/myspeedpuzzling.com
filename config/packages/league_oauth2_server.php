<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'league_oauth2_server' => [
        'authorization_server' => [
            'private_key' => '%env(OAUTH2_PRIVATE_KEY)%',
            'private_key_passphrase' => '%env(default::OAUTH2_PASSPHRASE)%',
            'encryption_key' => '%env(OAUTH2_ENCRYPTION_KEY)%',
            'access_token_ttl' => 'PT1H',
            'refresh_token_ttl' => 'P1M',
            'auth_code_ttl' => 'PT10M',
            'enable_client_credentials_grant' => true,
            'enable_password_grant' => false,
            'enable_refresh_token_grant' => true,
            'enable_auth_code_grant' => true,
            'enable_implicit_grant' => false,
            'require_code_challenge_for_public_clients' => true,
        ],
        'resource_server' => [
            'public_key' => '%env(OAUTH2_PUBLIC_KEY)%',
        ],
        'scopes' => [
            'available' => [
                'profile:read',
                'results:read',
                'statistics:read',
                'collections:read',
            ],
            'default' => [
                'profile:read',
            ],
        ],
        'persistence' => [
            'doctrine' => [
                'entity_manager' => 'default',
                'table_prefix' => 'oauth2_',
            ],
        ],
    ],
]);
