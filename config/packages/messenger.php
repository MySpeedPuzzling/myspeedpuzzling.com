<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Message\DeleteObsoletePuzzleImage;
use SpeedPuzzling\Web\Message\DeleteOnlineReferenceImage;
use SpeedPuzzling\Web\Message\DeletePageSectionImages;
use SpeedPuzzling\Web\Message\AwardXpForSolvingTime;
use SpeedPuzzling\Web\Message\CheckOnlineFinishPhoto;
use SpeedPuzzling\Web\Message\CompensateXpForDeletedSolve;
use SpeedPuzzling\Web\Message\DeletePlayerStoredFiles;
use SpeedPuzzling\Web\Message\PrepareDigestEmailForPlayer;
use SpeedPuzzling\Web\Message\ProcessSesEvent;
use SpeedPuzzling\Web\Message\PushNewsletterSubscriberToListmonk;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateDerivedMetricsForPuzzle;
use SpeedPuzzling\Web\Message\RecalculateKarma;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Message\RecalculateXpForPlayer;
use SpeedPuzzling\Web\Message\RemoveNewsletterSubscriberFromListmonk;
use SpeedPuzzling\Web\Message\SendPartnerListingReminder;
use SpeedPuzzling\Web\Message\SendPartnerRequestEmail;
use SpeedPuzzling\Web\Message\SendPartnerTeamedUpEmail;
use SpeedPuzzling\Web\Message\SendPlayerContentDigest;
use SpeedPuzzling\Web\Message\SettleXpBonuses;
use SpeedPuzzling\Web\Message\VerifyOnlineMedia;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;

return App::config([
    'framework' => [
        'messenger' => [
            'buses' => [
                'command_bus' => [
                    'middleware' => [
                        // OUTERMOST on purpose: it must see HandlerFailedException
                        // only after doctrine_transaction has rolled back, so a
                        // handler's 404-flavoured exception reaches the controller
                        // as itself (→ real 404) instead of a wrapper (→ silent 500
                        // that Sentry discards). See the class docblock.
                        'SpeedPuzzling\Web\Services\MessengerMiddleware\UnwrapHttpExceptionMiddleware',
                        'SpeedPuzzling\Web\Services\MessengerMiddleware\ClearEntityManagerMiddleware',
                        // Outside doctrine_transaction on purpose: the per-key lock of a
                        // SerializedByLock message is released only after the commit.
                        'SpeedPuzzling\Web\Services\MessengerMiddleware\LockUntilCommittedMiddleware',
                        // doctrine_transaction for every message but a HandledOutsideTransaction one (its handler
                        // waits for a slow external call - its write is a message of its own)
                        'SpeedPuzzling\Web\Services\MessengerMiddleware\TransactionMiddleware',
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
                'content_digest_emails' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%?auto_setup=false&queue_name=content_digest_emails',
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
                // The partner finder's "I'd like to puzzle with you" e-mail - never in the sender's request
                // (docs/features/partner-finder.md D6)
                SendPartnerRequestEmail::class => 'async',
                // Its "Still looking?" reminder, planned by the daily myspeedpuzzling:remind-partner-listings
                SendPartnerListingReminder::class => 'async',
                // "<name> says you teamed up" beside the PartnerTeamedUp notification
                SendPartnerTeamedUpEmail::class => 'async',
                RecalculateDerivedMetricsForPuzzle::class => 'async',
                // Listmonk API calls must not block or fail the user-facing request
                PushNewsletterSubscriberToListmonk::class => 'async',
                RemoveNewsletterSubscriberFromListmonk::class => 'async',
                // Amazon SES events: the endpoint answers SNS at once (docs/features/mass-mailing/README.md)
                ProcessSesEvent::class => 'async',
                // Object storage calls after an account deletion - never in the way of the deletion
                DeletePlayerStoredFiles::class => 'async',
                DeleteObsoletePuzzleImage::class => 'async',
                DeletePageSectionImages::class => 'async',
                // An online round's replaced or removed reference image, after the commit (OnlineReferenceImages)
                DeleteOnlineReferenceImage::class => 'async',
                RecalculateBadgesForPlayer::class => 'async',
                RecalculateXpForPlayer::class => 'async',
                // Karma after a moderation decision - never in the way of the decision (docs/features/karma.md)
                RecalculateKarma::class => 'async',
                AwardXpForSolvingTime::class => 'async',
                RecalculateXpChainForSolve::class => 'async',
                CompensateXpForDeletedSolve::class => 'async',
                SettleXpBonuses::class => 'async',
                // A HEAD request to object storage after a phone reported an evidence upload - never in the phone's
                // request; a missing object is tried again by the transport (docs/features/online-competitions/)
                VerifyOnlineMedia::class => 'async',
                // The completeness check of a finish photo: the CV worker downloads and checks it - seconds, never in a
                // request, nor in a transaction (HandledOutsideTransaction - its row is written by RecordOnlineMediaCheck,
                // not routed: handled at once); a busy worker is asked again by a delayed message of the handler's own
                // (Phase 3)
                CheckOnlineFinishPhoto::class => 'async',
                SendPlayerContentDigest::class => 'content_digest_emails',
                // Events that must run synchronously for immediate UI updates (Turbo Streams)
                'SpeedPuzzling\Web\Events\PuzzleBorrowed' => 'sync',
                'SpeedPuzzling\Web\Events\PuzzleAddedToCollection' => 'sync',
                'SpeedPuzzling\Web\Events\LendingTransferCompleted' => 'sync',
                // Events that must run synchronously for statistics recalculation
                'SpeedPuzzling\Web\Events\PuzzleSolved' => 'sync',
                'SpeedPuzzling\Web\Events\PuzzleSolvingTimeModified' => 'sync',
                'SpeedPuzzling\Web\Events\PuzzleSolvingTimeDeleted' => 'sync',
                'SpeedPuzzling\Web\Events\PuzzleSolvingTimeMovedToOtherPuzzle' => 'sync',
                // Events that must run synchronously for proper transaction ordering
                'SpeedPuzzling\Web\Events\PuzzleMergeApproved' => 'sync',
                // Reconciles solving times to rounds on postFlush, inside the transaction that changed the rounds
                'SpeedPuzzling\Web\Events\CompetitionRoundsChanged' => 'sync',
                // Re-matches series picks to the series' editions on postFlush, in the same transaction
                // (docs/features/events-page/high-frequency-series.md)
                'SpeedPuzzling\Web\Events\SeriesEditionsChanged' => 'sync',
                // All other events can run asynchronously
                'SpeedPuzzling\Web\Events\*' => 'async',
            ],
        ],
    ],
]);
