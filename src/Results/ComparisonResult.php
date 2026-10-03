<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ComparisonCriteria;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

/**
 * The comparison view model (ComparisonBuilder): the shown puzzles sorted, the page window to render, and the summary
 * blocks. Everything is counted over the shown puzzles (after filters and "puzzles to show"), so the summary always
 * describes the list below it - except in the Duel view of 3+ subjects ($highlightedPairOnly), whose list narrows down
 * to the puzzles both highlighted subjects solved while the summaries keep describing the whole line-up.
 */
readonly final class ComparisonResult
{
    /**
     * @param list<ComparisonSubjectRef> $subjects available subjects in line-up order
     * @param list<ComparisonPuzzleRow> $rows every listed puzzle, sorted
     * @param list<ComparisonPuzzleRow> $page the paging window of $rows
     * @param list<string> $pagePuzzleIds the window's puzzle ids - hand them to GetComparisonPuzzles::byIds()
     * @param list<ComparisonLeagueRow> $league by position
     * @param array<string, array<string, int>> $beats [row ref][column ref] = puzzles where row was faster than column
     * @param array<string, array<string, int>> $shared [row ref][column ref] = puzzles both solved
     */
    public function __construct(
        public ComparisonCriteria $criteria,
        public array $subjects,
        public null|ComparisonSubjectRef $self,
        public null|ComparisonSubjectRef $highlightA,
        public null|ComparisonSubjectRef $highlightB,
        public array $rows,
        public array $page,
        public array $pagePuzzleIds,
        public int $total,
        public bool $hasMore,
        public int $remaining,
        public array $league,
        public null|ComparisonHeadToHead $headToHead,
        public array $beats,
        public array $shared,
        // The list holds only the puzzles both highlighted subjects solved (Duel view, 3+ subjects)
        public bool $highlightedPairOnly = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    public function isDuel(): bool
    {
        return count($this->subjects) === 2;
    }
}
