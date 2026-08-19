<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * The puzzle card: catalog list item and the base of the puzzle detail.
 *
 * The three trailing insight objects are always present in the JSON: null
 * means "not available to this token" (difficulty: not a member / machine
 * token; prediction: not a member, opted out, machine token or no results:read;
 * solves: machine token or no results:read), never an error - one client code
 * path works for every kind of token. See docs/features/api/README.md, Puzzles.
 */
final class PuzzleResponse
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $alternative_name,
        public PuzzleManufacturerResponse $manufacturer,
        public int $pieces_count,
        public null|string $image,
        public null|string $ean,
        public null|string $identification_number,
        public bool $is_available,
        public bool $is_approved,
        public PuzzleStatisticsResponse $statistics,
        public null|PuzzleDifficultyResponse $difficulty,
        public null|TimePredictionResponse $prediction,
        public null|PlayerSolvesResponse $solves,
    ) {
    }
}
