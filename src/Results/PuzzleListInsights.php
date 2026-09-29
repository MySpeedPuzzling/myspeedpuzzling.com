<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Difficulty tier and solve count per puzzle for the puzzle-list pages
 * (collections, wishlist, unsolved, solved, sell/swap, lend/borrow). The tier
 * is only loaded for a viewer with an active membership - everyone else gets
 * the locked icon, and `withDifficulty` is the one switch templates read for
 * the icon, the item's data-difficulty-tier and the filter chips alike.
 */
readonly final class PuzzleListInsights
{
    /**
     * @param array<string, PuzzleListInsight> $byPuzzle
     */
    public function __construct(
        public array $byPuzzle,
        public bool $withDifficulty,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function templateParameters(): array
    {
        return [
            'puzzle_insights' => $this->byPuzzle,
            'insights_with_difficulty' => $this->withDifficulty,
        ];
    }
}
