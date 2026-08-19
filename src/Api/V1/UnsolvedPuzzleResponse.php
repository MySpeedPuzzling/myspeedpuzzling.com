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
        public string $puzzleId,
        public string $puzzleName,
        public null|string $manufacturerName,
        public int $piecesCount,
        public null|string $image,
        public string $addedAt,
        public bool $isBorrowed,
        public PuzzleStatisticsResponse $statistics,
        public null|PuzzleDifficultyResponse $difficulty,
        public null|TimePredictionResponse $prediction,
        public null|PlayerSolvesResponse $solves,
    ) {
    }
}
