<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * One entry of a lend/borrow list: a puzzle the player lent out
 * (direction "lent", counterparty = the current holder) or is borrowing
 * (direction "borrowed", counterparty = the owner), with the lend date and
 * the note. The four trailing objects follow WishlistItemResponse.
 */
final class LentPuzzleResponse
{
    public const string DIRECTION_LENT = 'lent';

    public const string DIRECTION_BORROWED = 'borrowed';

    public function __construct(
        public string $lent_puzzle_id,
        public string $direction,
        public string $puzzle_id,
        public string $puzzle_name,
        public null|string $manufacturer_name,
        public int $pieces_count,
        public null|string $image,
        public LentPuzzleCounterpartyResponse $counterparty,
        public string $lent_at,
        public null|string $notes,
        public PuzzleStatisticsResponse $statistics,
        public null|PuzzleDifficultyResponse $difficulty,
        public null|TimePredictionResponse $prediction,
        public null|PlayerSolvesResponse $solves,
    ) {
    }
}
