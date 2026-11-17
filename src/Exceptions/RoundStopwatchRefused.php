<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\RoundStopwatchRefusal;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A round stopwatch action refused - by CompetitionRound, or by the controller before dispatching - nothing changed
 * (docs/features/competitions-management/README.md "Round Stopwatch"). A ConflictHttpException, so it reaches the
 * controller as itself through the command bus (UnwrapHttpExceptionMiddleware).
 */
final class RoundStopwatchRefused extends ConflictHttpException
{
    public function __construct(
        readonly public RoundStopwatchRefusal $reason,
    ) {
        parent::__construct(match ($reason) {
            RoundStopwatchRefusal::InvalidStart => 'The start is not a moment of the round\'s time zone. Nothing was changed.',
            RoundStopwatchRefusal::StartInThePast => 'The scheduled start has already passed. Nothing was changed.',
            RoundStopwatchRefusal::AlreadyStarted => 'The round stopwatch has already started - reset it before scheduling a start. Nothing was changed.',
            RoundStopwatchRefusal::TooLateToCancel => 'The round has already started - its start can no longer be cancelled. Nothing was changed.',
            RoundStopwatchRefusal::StartScheduled => 'A start is scheduled - cancel it before starting the stopwatch now. Nothing was changed.',
            RoundStopwatchRefusal::ResultsConfirmed => 'The round has official results - clear them at the results desk before resetting its stopwatch. Nothing was changed.',
            RoundStopwatchRefusal::EndedUseReset => 'This online round has ended - reset it to run it again. Nothing was changed.',
            RoundStopwatchRefusal::RevealChangedMeanwhile => 'The puzzles this start would reveal changed meanwhile - check the list again. Nothing was changed.',
            RoundStopwatchRefusal::RevealNotAllowed => 'No puzzle of this round waits for the stopwatch start to be revealed. Nothing was changed.',
        });
    }
}
