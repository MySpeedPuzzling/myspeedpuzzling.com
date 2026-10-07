<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use DateTimeImmutable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A puzzle a competition keeps secret takes no time, collection, wishlist, sell/swap listing or loan before its reveal
 * - from anybody, its organisers and admins too (SecretPuzzleAccess::assertWritableBy()). Somebody it is hidden from
 * gets PuzzleNotFound instead and never learns it exists; this one tells an organiser when it opens.
 *
 * An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself; the web pages show it as
 * a message (SecretPuzzleWriteRefusedSubscriber), the APIs answer 409.
 */
final class PuzzleNotRevealedYet extends ConflictHttpException
{
    public function __construct(
        readonly public string $puzzleId,
        // Null: revealed when the organiser clicks "Reveal now" (manual) - no moment yet
        readonly public null|DateTimeImmutable $revealsAt,
        // The zone of the round whose reveal it waits for
        readonly public string $timezone,
        // $timezone names no place the event is known to be in (RoundTimezone::isAssumed())
        readonly public bool $timezoneAssumed = false,
    ) {
        parent::__construct($revealsAt !== null
            ? sprintf('This puzzle is still secret until %s UTC - you can add it after the reveal.', $revealsAt->format('Y-m-d H:i'))
            : 'This puzzle is still secret until the organiser reveals it - you can add it after the reveal.');
    }
}
