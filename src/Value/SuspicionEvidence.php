<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The facts behind the explanations of a raised time (Query\GetSuspiciousTimeEvidence) - looked up only for the
 * times that are raised, the classifier decides what they mean.
 */
readonly final class SuspicionEvidence
{
    /**
     * @param list<array{time_id: string, seconds: int, puzzling_type: string}> $sameDayGroupResults pair/team results of the puzzle that other players saved on the same solved day
     * @param list<array{pieces: int, seconds: int}> $groupResults the person's own pair/team results (tracked or as a registered member)
     * @param list<array{puzzle_id: string, name: string, pieces: int, similarity: float}> $otherEditions puzzles of the same brand with another piece count, with the best trigram similarity of any two of their names
     */
    public function __construct(
        public null|string $comment = null,
        // The player's other solo results with a time - "new player" below the classifier's minimum
        public int $otherSoloResults = 0,
        public array $sameDayGroupResults = [],
        public array $groupResults = [],
        public array $otherEditions = [],
        // Other players' and the player's other solo results of the puzzle, not suspicious
        public int $otherResultsOnPuzzle = 0,
        public null|int $fastestOtherSeconds = null,
        // suspicious_time_confirmation: the expectation the form showed when the player answered "Yes, it's right"
        public null|int $confirmedExpectedSeconds = null,
    ) {
    }
}
