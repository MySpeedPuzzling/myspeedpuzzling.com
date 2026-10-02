<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A pair of puzzle records the same person logged the same time on, on the same day (GetDuplicatePuzzleSignalCandidates).
 */
readonly final class DuplicatePuzzleSignalCandidate
{
    public function __construct(
        // The lower id of the two - pairs are always ordered
        public string $puzzleAId,
        public string $puzzleBId,
        public int $matchingResults,
        public int $matchingPeople,
        public string $examplePlayerId,
        public int $exampleSeconds,
        public DateTimeImmutable $exampleDay,
    ) {
    }
}
