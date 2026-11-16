<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'http_client' => [
            'scoped_clients' => [
                // The hub's HTTP client (`http_client` of the hub below). Publishing is fail-open everywhere, so
                // these limits only decide how long a request waits for a hub that is up but not answering before it
                // gives up and logs a warning - by default that was 60 s. The hub answers a publish in milliseconds
                // and sits on the same Docker network, so 3 s is plenty. `timeout` = longest silence (connect
                // included), `max_duration` = the whole request. Pooled connections are reused across requests in a
                // worker as before; only a request that times out drops its own connection.
                'mercure_hub.client' => [
                    'base_uri' => '%env(MERCURE_URL)%',
                    'timeout' => 2,
                    'max_duration' => 3,
                ],
            ],
        ],
    ],
    'mercure' => [
        'hubs' => [
            'default' => [
                'url' => '%env(MERCURE_URL)%',
                'public_url' => '%env(MERCURE_PUBLIC_URL)%',
                // Time-limited publishing (scoped client above), pinned by MercureHubTimeoutTest
                'http_client' => 'mercure_hub.client',
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
