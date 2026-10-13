<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\PiecesRange;
use SpeedPuzzling\Web\Value\PlayerResultsCriteria;
use SpeedPuzzling\Web\Value\PlayerResultsSort;
use SpeedPuzzling\Web\Value\PlayerResultsView;
use SpeedPuzzling\Web\Value\Puzzler;

/**
 * One rendering of a player's results (PlayerResultsBuilder, docs/features/navigation PROF-05a): the rows of every tab
 * after the filters and the sort, the shown slice, what the filters offer and what a row shows besides itself (ranks,
 * difficulty tiers).
 *
 * A row is a non-empty list of results: By puzzle = one puzzle (one puzzle and pair/team on the Pairs and Teams tabs)
 * led by the attempt the row shows, its other attempts after it; Every result = one result.
 */
readonly final class PlayerResultsPage
{
    public const int PAGE_SIZE = 50;

    /**
     * @param array<array-key, non-empty-list<SolvedPuzzle>> $soloRows
     * @param array<array-key, non-empty-list<SolvedPuzzle>> $duoRows
     * @param array<array-key, non-empty-list<SolvedPuzzle>> $teamRows
     * @param array<string, PlayerRanking> $ranking the player's rank by puzzle id, for the shown solo By-puzzle rows
     * @param array<string, DifficultyTier> $difficultyTiers by puzzle id, members only (a missing puzzle = not rated yet)
     * @param array<string, int> $availableBrands brand name => the player's puzzles of the brand, most first
     * @param list<array{teamId: string, name: null|string, isPair: bool, players: array<Puzzler>, count: int}> $availableTeams
     * @param list<PiecesRange> $availablePiecePresets
     * @param list<array{value: string, tier: null|DifficultyTier}> $difficultyOptions
     */
    public function __construct(
        public string $playerId,
        public PlayerResultsCriteria $criteria,
        public string $category,
        public string $defaultCategory,
        public PlayerResultsView $view,
        public PlayerResultsSort $sort,
        public array $soloRows,
        public array $duoRows,
        public array $teamRows,
        public int $unfilteredRowsCount,
        public int $shownRowsCount,
        public int $page,
        public array $ranking,
        public bool $withDifficulty,
        public array $difficultyTiers,
        public array $availableBrands,
        public array $availableTeams,
        public array $availablePiecePresets,
        public array $difficultyOptions,
        public int $resultsCount,
    ) {
    }

    /**
     * The rows of the shown tab, after the filters and the sort
     *
     * @return array<array-key, non-empty-list<SolvedPuzzle>>
     */
    public function rows(): array
    {
        return $this->rowsOf($this->category);
    }

    /**
     * @return array<array-key, non-empty-list<SolvedPuzzle>>
     */
    public function rowsOf(string $category): array
    {
        return match ($category) {
            PlayerResultsCriteria::CATEGORY_DUO => $this->duoRows,
            PlayerResultsCriteria::CATEGORY_GROUP => $this->teamRows,
            default => $this->soloRows,
        };
    }

    public function rowsCount(): int
    {
        return count($this->rows());
    }

    /**
     * The rows rendered: the first rows of the shown tab
     *
     * @return array<array-key, non-empty-list<SolvedPuzzle>>
     */
    public function shownRows(): array
    {
        return array_slice($this->rows(), 0, $this->shownRowsCount, preserve_keys: true);
    }

    /**
     * What a tab's label counts: its rows - but distinct puzzles on the By-puzzle Pairs and Teams tabs, whose rows are
     * per puzzle and pair/team (as on the profile)
     */
    public function countOf(string $category): int
    {
        $rows = $this->rowsOf($category);

        if ($category === PlayerResultsCriteria::CATEGORY_SOLO || $this->view === PlayerResultsView::Every) {
            return count($rows);
        }

        return count(array_unique(array_map(static fn (array $row): string => $row[0]->puzzleId, $rows)));
    }

    public function hasMore(): bool
    {
        return $this->rowsCount() > $this->shownRowsCount;
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->rowsCount() / self::PAGE_SIZE));
    }

    /**
     * Rows a "Show more" adds
     */
    public function moreCount(): int
    {
        return min(self::PAGE_SIZE, max(0, $this->rowsCount() - $this->shownRowsCount));
    }

    public function isFiltered(): bool
    {
        return $this->criteria->hasFilters();
    }

    /**
     * The criteria of a link to another tab - the default tab's link carries no category ($defaultCategory is the
     * history's default: Solo, else the first tab with results)
     */
    public function categoryCriteria(string $category): PlayerResultsCriteria
    {
        return $this->criteria->withCategory($category === $this->defaultCategory ? null : $category);
    }

    /**
     * The criteria of the shown list's links (view, sort, chips): its tab named only when it is not the default one
     */
    public function listCriteria(): PlayerResultsCriteria
    {
        return $this->categoryCriteria($this->category);
    }
}
