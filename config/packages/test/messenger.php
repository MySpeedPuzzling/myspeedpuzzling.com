<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Message\PrepareDigestEmailForPlayer;
use SpeedPuzzling\Web\Message\SendPlayerContentDigest;

return App::config([
    'framework' => [
        'messenger' => [
            'transports' => [
                'async' => [
                    'dsn' => 'in-memory://',
                ],
                'digest_emails' => [
                    'dsn' => 'in-memory://',
                ],
            ],
            'routing' => [
                PrepareDigestEmailForPlayer::class => 'sync',
                SendPlayerContentDigest::class => 'sync',
            ],
        ],
    ],
]);
