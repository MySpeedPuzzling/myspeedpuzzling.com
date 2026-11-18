<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The public competitor list of an event with competitor pages on (GetCompetitorList,
 * docs/features/competitions-management/competitor-pages.md "Competitor list") - everybody going and every pair/team
 * line-up, each linking their competitor page. No results, puzzles or tables: those are on the pages.
 */
readonly final class CompetitorListView
{
    /**
     * @param list<CompetitorListPerson> $people by name
     * @param list<CompetitorListLineup> $lineups by name
     */
    public function __construct(
        public string $competitionId,
        public CompetitionReference $competition,
        // Not publicly visible (a draft, waiting for approval, rejected) - only its team sees the list
        public bool $isHidden,
        public array $people,
        public array $lineups,
    ) {
    }

    public function count(): int
    {
        return count($this->people) + count($this->lineups);
    }
}
