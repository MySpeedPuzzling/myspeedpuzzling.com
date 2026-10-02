<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A pair of puzzle records the same person logged the same time on, on the same day (GetDuplicatePuzzleSignalCandidates),
 * with the facts DuplicatePuzzleSignalScoring weighs.
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
        public string $puzzleAName = '',
        public string $puzzleBName = '',
        // As stored: possibly comma-separated lists
        public null|string $puzzleAEan = null,
        public null|string $puzzleACode = null,
        public null|string $puzzleBEan = null,
        public null|string $puzzleBCode = null,
        // Trigram similarity of the closest names (name or alternative name, unaccented, lower case), 0-1
        public float $nameSimilarity = 0.0,
        // One name inside the other ("Umbrella" - "Puzzle Moment: Umbrellas")
        public bool $nameContained = false,
        public bool $sameBrand = false,
        public bool $bothApproved = true,
        // Results of the record with fewer / more of them
        public int $fewerResults = 0,
        public int $moreResults = 0,
        // Null = unknown
        public null|int $addedSecondsApart = null,
        // First matching day minus the day the later of the two records was added (negative = a back-dated result)
        public null|int $daysFromNewerRecordToFirstMatch = null,
    ) {
    }
}
