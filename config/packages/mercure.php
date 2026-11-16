<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'mercure' => [
        'hubs' => [
            'default' => [
                'url' => '%env(MERCURE_URL)%',
                'public_url' => '%env(MERCURE_PUBLIC_URL)%',
                // The hub (dunglas/mercure 0.x) speaks the pre-1.0 protocol: `topic=` subscriptions, the bare `mercure`
                // JWT claim and the `mercureAuthorization` cookie. mercure-bundle 0.6+ defaults to 1.0 - keep 0.x until
                // the hub, the JS subscribers (`match=`) and turbo_stream_listen() move together
                'protocol_version' => '0.x',
                'jwt' => [
                    'secret' => '%env(MERCURE_JWT_SECRET)%',
                    'publish' => '*',
                    'subscribe' => '*',
                ],
            ],
        ],
    ],
]);
