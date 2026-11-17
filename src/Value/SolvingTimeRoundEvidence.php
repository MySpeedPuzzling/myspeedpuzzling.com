<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * What a solving time that does not exist yet will be saved with, as far as the round rule reads it
 * (SolvingTimeRoundMatch - docs/features/competitions-management/shared-puzzles-and-divisions.md §2.3 + A1-A4): its
 * people, its result, its day and when and how it is saved. The unfinished result's handlers hand it to
 * SolvingTimeRoundResolver::resolveNew() before they build the entity - the same values the entity then holds, so the
 * reconciler answers the same for the stored row.
 */
readonly final class SolvingTimeRoundEvidence
{
    public function __construct(
        public null|string $ownerId,
        public null|PuzzlersGroup $team,
        public null|int $seconds,
        public null|int $piecesPlaced,
        // The day the time was solved - null when it is not known to the day
        public null|DateTimeImmutable $solveDay,
        public DateTimeImmutable $trackedAt,
        public null|SolvingTimeSource $createdVia,
    ) {
    }
}
