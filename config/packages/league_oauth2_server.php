<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Security\OAuth2\ScopeAwareBearerTokenResponse;
use SpeedPuzzling\Web\Value\OAuth2Scope;

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
            'response_type_class' => ScopeAwareBearerTokenResponse::class,
        ],
        'resource_server' => [
            'public_key' => '%env(OAUTH2_PUBLIC_KEY)%',
        ],
        'scopes' => [
            // Single source of truth is the OAuth2Scope enum - which scopes exist,
            // and which of them are user-context only (stripped from
            // client_credentials tokens by OAuth2ClientCredentialsScopeSubscriber).
            'available' => OAuth2Scope::values(),
            'default' => [
                OAuth2Scope::ProfileRead->value,
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
