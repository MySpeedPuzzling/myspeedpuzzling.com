<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'mercure' => [
        'hubs' => [
            'default' => [
                'url' => '%env(MERCURE_URL)%',
                'public_url' => '%env(MERCURE_PUBLIC_URL)%',
                // Mercure protocol 1.0 (hub dunglas/mercure 1.x): `match=` subscriptions, RFC 9068 access tokens
                // (`at+jwt`, `authorization_details`) and the `__Secure-mercure_access_token` cookie - the bundle names
                // it `mercure_access_token` in debug mode (plain-HTTP dev hub, configured to match in compose.yml)
                'protocol_version' => '1.0',
                'jwt' => [
                    'secret' => '%env(MERCURE_JWT_SECRET)%',
                    'publish' => '*',
                    'subscribe' => '*',
                    // RFC 9068 claims. `iss` must equal the hub's trusted issuer (MERCURE_TRUSTED_ISSUERS on every
                    // hub - dev, sandbox and production alike; the key, not the issuer, tells them apart). `aud`
                    // defaults to public_url (plus url for publishing), production's hub pins that as its
                    // resource_identifier
                    'claims' => [
                        'iss' => 'https://myspeedpuzzling.com',
                        'sub' => 'myspeedpuzzling',
                        'client_id' => 'myspeedpuzzling',
                    ],
                ],
            ],
        ],
    ],
]);
