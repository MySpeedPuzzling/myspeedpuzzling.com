<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The sorts of a player's Results (docs/features/navigation README §4.7, D11): Newest (the default, by the date of the
 * row's best time) … Easiest / Hardest for members. The first eight are the profile list's (PlayerSolvedPuzzles) sorts
 * under the same names; Name, Best rank and Attempts are new.
 */
enum PlayerResultsSort: string
{
    case Newest = 'newest';
    case Oldest = 'oldest';
    case Fastest = 'fastest';
    case Slowest = 'slowest';
    case FastestPpm = 'fastest_ppm';
    case SlowestPpm = 'slowest_ppm';
    case Name = 'name';
    case Rank = 'rank';
    case Attempts = 'attempts';
    case Easiest = 'easiest';
    case Hardest = 'hardest';

    public const self DEFAULT = self::Newest;

    public function isMembersOnly(): bool
    {
        return $this === self::Easiest || $this === self::Hardest;
    }

    /**
     * Best rank is the player's solo rank on the puzzle and Attempts counts a puzzle's attempts: both order By-puzzle
     * rows only (Best rank only on the Solo tab). Anywhere else the list is in the default order.
     */
    public function appliesTo(string $category, PlayerResultsView $view): bool
    {
        return match ($this) {
            self::Rank => $view === PlayerResultsView::Puzzles && $category === PlayerResultsCriteria::CATEGORY_SOLO,
            self::Attempts => $view === PlayerResultsView::Puzzles,
            default => true,
        };
    }

    public function translationKey(): string
    {
        return match ($this) {
            self::Newest => 'sorting.newest_times',
            self::Oldest => 'sorting.oldest_times',
            self::Fastest => 'sorting.fastest_times',
            self::Slowest => 'sorting.slowest_times',
            self::FastestPpm => 'sorting.fastest_ppm',
            self::SlowestPpm => 'sorting.slowest_ppm',
            self::Name => 'sorting.name',
            self::Rank => 'sorting.best_rank',
            self::Attempts => 'sorting.attempts',
            self::Easiest => 'sorting.easiest',
            self::Hardest => 'sorting.hardest',
        };
    }
}
