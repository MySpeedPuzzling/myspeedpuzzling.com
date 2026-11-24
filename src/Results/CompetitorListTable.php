<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One table of a row on the public competitor list (GetCompetitorList, competitor-pages.md "Competitor list - search by
 * table"): a person's table in their solo rounds, a pair's/team's in its rounds - the same tables their competitor page
 * shows (never of a round with table numbers off). One per table name: a table kept for several rounds is one item with
 * those rounds in schedule order.
 */
readonly final class CompetitorListTable
{
    /**
     * @param non-empty-list<string> $rounds the names of its rounds, schedule order
     */
    public function __construct(
        // TableLabels::key() of what it reads as - the browser compares the typed text's key (assets/table_labels.js key())
        public string $key,
        // What it reads as: its label on a round with table names, else the number
        public string $label,
        public array $rounds,
    ) {
    }

    public function firstRound(): string
    {
        return $this->rounds[0];
    }

    public function moreRounds(): int
    {
        return count($this->rounds) - 1;
    }
}
