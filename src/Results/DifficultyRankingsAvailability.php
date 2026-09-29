<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Which hardest / easiest lists exist right now: piece counts and brands with
 * enough rated puzzles. The single source for the pages (404 otherwise), their
 * cross-links and the difficulty sitemap.
 */
readonly final class DifficultyRankingsAvailability
{
    /**
     * @param array<int, int> $ratedPuzzlesPerPieces piece count => rated puzzles, ascending piece count
     * @param array<string, DifficultyRankingBrand> $brands keyed by slug, most rated puzzles first
     */
    public function __construct(
        public array $ratedPuzzlesPerPieces,
        public array $brands,
    ) {
    }

    public function ratedPuzzlesForPieces(int $piecesCount): null|int
    {
        return $this->ratedPuzzlesPerPieces[$piecesCount] ?? null;
    }

    public function brand(string $slug): null|DifficultyRankingBrand
    {
        return $this->brands[$slug] ?? null;
    }

    /**
     * @return list<int>
     */
    public function piecesCounts(): array
    {
        return array_keys($this->ratedPuzzlesPerPieces);
    }
}
