<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\CompensateXpForDeletedSolve;
use SpeedPuzzling\Web\Message\PrepareDigestEmailForPlayer;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateDerivedMetricsForPuzzle;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Message\SendBadgeNotificationEmail;
use SpeedPuzzling\Web\Message\SendPlayerContentDigest;
use SpeedPuzzling\Web\Message\SettleXpBonuses;
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
                    // Exponential backoff: 30s, 2m, 8m, 30m (capped) — transient failures
                    // like SMTP timeouts need minutes, not the default 1s/2s/4s
                    'retry_strategy' => [
                        'max_retries' => 4,
                        'delay' => 30000,
                        'multiplier' => 4,
                        'max_delay' => 1800000,
                    ],
                ],
                // Dedicated queue on the same Doctrine table, drained by the digest
                // consumer container — SMTP pacing stays isolated from transactional mail.
                'digest_emails' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%?auto_setup=false&queue_name=digest_emails',
                    'retry_strategy' => [
                        'max_retries' => 5,
                        'delay' => 60_000,         // 1m → 4m → 16m → 64m → 4h (capped)
                        'multiplier' => 4,
                        'max_delay' => 14_400_000, // 4h
                    ],
                ],
            ],
            'routing' => [
                SendEmailMessage::class => 'async',
                PrepareDigestEmailForPlayer::class => 'async',
                RecalculateDerivedMetricsForPuzzle::class => 'async',
                RecalculateBadgesForPlayer::class => 'async',
                RecalculateXpForPlayer::class => 'async',
                AwardXpForSolvingTime::class => 'async',
                RecalculateXpChainForSolve::class => 'async',
                CompensateXpForDeletedSolve::class => 'async',
                SettleXpBonuses::class => 'async',
                SendPlayerContentDigest::class => 'digest_emails',
                SendBadgeNotificationEmail::class => 'async',
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
