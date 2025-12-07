<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Liip\ImagineBundle\Message\WarmupCache;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;

return App::config([
    'framework' => [
        'messenger' => [
            'buses' => [
                'command_bus' => [
                    'middleware' => ['doctrine_transaction'],
                ],
            ],
            'failure_transport' => 'failed',
            'transports' => [
                'sync' => [
                    'dsn' => 'sync://',
                ],
                'failed' => [
                    'dsn' => 'doctrine://default?queue_name=failed',
                ],
                'async' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%?auto_setup=false',
                ],
            ],
            'routing' => [
                WarmupCache::class => 'async',
                SendEmailMessage::class => 'async',
                // Events that must run synchronously for immediate UI updates (Turbo Streams)
                'SpeedPuzzling\Web\Events\PuzzleBorrowed' => 'sync',
                'SpeedPuzzling\Web\Events\PuzzleAddedToCollection' => 'sync',
                'SpeedPuzzling\Web\Events\LendingTransferCompleted' => 'sync',
                // All other events can run asynchronously
                'SpeedPuzzling\Web\Events\*' => 'async',
            ],
        ],
    ],
]);
