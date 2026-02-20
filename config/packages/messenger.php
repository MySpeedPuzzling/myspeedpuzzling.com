<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Liip\ImagineBundle\Message\WarmupCache;
use SpeedPuzzling\Web\Message\PrepareDigestEmailForPlayer;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;

return App::config([
    'framework' => [
        'messenger' => [
            'buses' => [
                'command_bus' => [
                    'middleware' => [
                        'SpeedPuzzling\Web\Services\MessengerMiddleware\ClearEntityManagerMiddleware',
                        'doctrine_transaction',
                    ],
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
                PrepareDigestEmailForPlayer::class => 'async',
                // Events that must run synchronously for immediate UI updates (Turbo Streams)
                'SpeedPuzzling\Web\Events\PuzzleBorrowed' => 'sync',
                'SpeedPuzzling\Web\Events\PuzzleAddedToCollection' => 'sync',
                'SpeedPuzzling\Web\Events\LendingTransferCompleted' => 'sync',
                // Events that must run synchronously for statistics recalculation
                'SpeedPuzzling\Web\Events\PuzzleSolved' => 'sync',
                'SpeedPuzzling\Web\Events\PuzzleSolvingTimeModified' => 'sync',
                'SpeedPuzzling\Web\Events\PuzzleSolvingTimeDeleted' => 'sync',
                // Events that must run synchronously for proper transaction ordering
                'SpeedPuzzling\Web\Events\PuzzleMergeApproved' => 'sync',
                // All other events can run asynchronously
                'SpeedPuzzling\Web\Events\*' => 'async',
            ],
        ],
    ],
]);
