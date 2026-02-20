<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Message\PrepareDigestEmailForPlayer;

return App::config([
    'framework' => [
        'messenger' => [
            'transports' => [
                'async' => [
                    'dsn' => 'in-memory://',
                ],
            ],
            'routing' => [
                PrepareDigestEmailForPlayer::class => 'sync',
            ],
        ],
    ],
]);
