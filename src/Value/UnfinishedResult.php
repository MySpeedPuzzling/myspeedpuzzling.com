<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Exceptions\InvalidPiecesPlaced;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedTotalTimeWithinLimit;

/**
 * An unfinished competition result - the time of a round with a limit ran out (docs/features/competitions-management/
 * unfinished-results-plan.md, "Refined 2026-11-16"). The one rule for every writer (the unfinished add / edit handlers,
 * the event results import, later the API): built only from a round's limit, so it can never exist without one.
 *
 *  - a round with a time limit resolved for the result (no round, no limit: UnfinishedResultNeedsTimeLimit);
 *  - 1 ≤ pieces placed < the puzzle's pieces (InvalidPiecesPlaced - all of them placed is a finished solve);
 *  - a total time, when they finished afterwards, is over the limit (UnfinishedTotalTimeWithinLimit);
 *  - time spent = that total, else the limit.
 *
 * Built before any entity changes - a refused handler must leave nothing behind for a later flush.
 */
final readonly class UnfinishedResult
{
    private function __construct(
        public int $piecesPlaced,
        // The total when they kept going and finished afterwards - display only, never ranked
        public null|int $finishedLaterSeconds,
        // What "time spent puzzling" sums for it (CountsAsSolve::secondsPuzzled())
        public int $secondsSpent,
    ) {
    }

    /**
     * @throws UnfinishedResultNeedsTimeLimit
     * @throws InvalidPiecesPlaced
     * @throws UnfinishedTotalTimeWithinLimit
     */
    public static function fromInput(
        int $piecesPlaced,
        null|int $totalSeconds,
        int $puzzlePiecesCount,
        null|int $roundMinutesLimit,
    ): self {
        if ($roundMinutesLimit === null || $roundMinutesLimit < 1) {
            throw new UnfinishedResultNeedsTimeLimit();
        }

        if ($piecesPlaced < 1 || $piecesPlaced >= $puzzlePiecesCount) {
            throw new InvalidPiecesPlaced($piecesPlaced, $puzzlePiecesCount);
        }

        $limitSeconds = $roundMinutesLimit * 60;

        if ($totalSeconds !== null && $totalSeconds <= $limitSeconds) {
            throw new UnfinishedTotalTimeWithinLimit($totalSeconds, $roundMinutesLimit);
        }

        return new self($piecesPlaced, $totalSeconds, $totalSeconds ?? $limitSeconds);
    }

    public function equals(self $other): bool
    {
        return $this->piecesPlaced === $other->piecesPlaced
            && $this->finishedLaterSeconds === $other->finishedLaterSeconds
            && $this->secondsSpent === $other->secondsSpent;
    }
}
