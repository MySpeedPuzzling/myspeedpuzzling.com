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
        public string $lentPuzzleId,
        public string $direction,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $manufacturerName,
        public int $piecesCount,
        public null|string $image,
        public LentPuzzleCounterpartyResponse $counterparty,
        public string $lentAt,
        public null|string $notes,
        public PuzzleStatisticsResponse $statistics,
        public null|PuzzleDifficultyResponse $difficulty,
        public null|TimePredictionResponse $prediction,
        public null|PlayerSolvesResponse $solves,
    ) {
    }
}
