<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'mercure' => [
        'hubs' => [
            'default' => [
                'url' => '%env(MERCURE_URL)%',
                'public_url' => '%env(MERCURE_PUBLIC_URL)%',
                'jwt' => [
                    'secret' => '%env(MERCURE_JWT_SECRET)%',
                    'publish' => '*',
                    'subscribe' => '*',
                ],
            ],
        ],
    ],
]);
