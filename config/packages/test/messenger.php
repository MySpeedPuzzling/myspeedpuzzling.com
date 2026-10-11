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
                'content_digest_emails' => [
                    'dsn' => 'in-memory://',
                ],
            ],
            'routing' => [
                PrepareDigestEmailForPlayer::class => 'sync',
                // Queued (in memory) so tests see the run's delays; the handler is tested directly
                SendPlayerContentDigest::class => 'content_digest_emails',
            ],
        ],
    ],
]);
