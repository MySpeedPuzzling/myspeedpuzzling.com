<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use DateTimeImmutable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * "Automatic" chosen for a round puzzle's reveal, but not for the moment the round has now: the organiser saw another
 * automatic reveal (the round's start or reveal delay changed after the page was loaded - a page left open while the
 * delay was set from 25 to 5 minutes would otherwise save "Automatic" for a reveal 20 minutes earlier than the one
 * shown), or the caller said no moment at all (ChangeRoundPuzzleReveal::$shownAutomaticRevealAt). Nothing was changed.
 *
 * An HTTP exception, so UnwrapHttpExceptionMiddleware hands it to the controller as itself.
 */
final class AutomaticRevealChangedMeanwhile extends ConflictHttpException
{
    public function __construct(
        readonly public DateTimeImmutable $automaticRevealAt,
        readonly public null|DateTimeImmutable $shownAutomaticRevealAt,
    ) {
        parent::__construct(sprintf(
            'The round\'s automatic reveal is %s, not %s. Nothing was changed.',
            $automaticRevealAt->format(DATE_ATOM),
            $shownAutomaticRevealAt?->format(DATE_ATOM) ?? 'the one given (none)',
        ));
    }
}
