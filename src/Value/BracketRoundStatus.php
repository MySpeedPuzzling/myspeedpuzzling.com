<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * Where a round of the bracket is (competition toolkit A9) - what its box says: upcoming, running now, over with its
 * results still the organiser's, or published (only then does the box name entrants).
 */
enum BracketRoundStatus: string
{
    case Upcoming = 'upcoming';
    case Live = 'live';
    case Finished = 'finished';
    case Published = 'published';

    /**
     * @param null|string $stopwatchStatus as stored - read through RoundStopwatchStatus::at()
     */
    public static function of(
        bool $resultsPublished,
        null|string $stopwatchStatus,
        null|DateTimeImmutable $stopwatchStartedAt,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $now,
    ): self {
        if ($resultsPublished) {
            return self::Published;
        }

        $stopwatch = RoundStopwatchStatus::at($stopwatchStatus, $stopwatchStartedAt, $now);

        if ($stopwatch === RoundStopwatchStatus::RUNNING) {
            return self::Live;
        }

        // A stopwatch stopped or never used: the round is over once its start has passed (or its clock started)
        if ($stopwatch === RoundStopwatchStatus::STOPPED || $startsAt->getTimestamp() <= $now->getTimestamp()) {
            return self::Finished;
        }

        return self::Upcoming;
    }
}
