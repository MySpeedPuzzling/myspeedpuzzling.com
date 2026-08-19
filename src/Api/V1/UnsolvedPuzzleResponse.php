<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * One unsolved puzzle of a player: a puzzle in one of their collections
 * (is_borrowed false; added_at = when it first entered a collection) or a
 * puzzle they are currently borrowing (is_borrowed true; added_at = when it
 * was lent to them) that they have not solved yet. The four trailing objects
 * follow WishlistItemResponse.
 */
final class UnsolvedPuzzleResponse
{
    public function __construct(
        public string $puzzle_id,
        public string $puzzle_name,
        public null|string $manufacturer_name,
        public int $pieces_count,
        public null|string $image,
        public string $added_at,
        public bool $is_borrowed,
        public PuzzleStatisticsResponse $statistics,
        public null|PuzzleDifficultyResponse $difficulty,
        public null|TimePredictionResponse $prediction,
        public null|PlayerSolvesResponse $solves,
    ) {
    }
}
