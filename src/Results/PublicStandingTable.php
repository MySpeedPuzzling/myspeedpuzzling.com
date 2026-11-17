<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\StandingScoreStatus;

/**
 * A published standing as one viewer may see it (competition toolkit A7, A-round-formats.md §3.4): computed only over
 * its rounds whose official results are published, ranked over every entrant, then the rows hidden from the viewer
 * dropped without renumbering. Provisional until every round of it is published (and has a ranked result).
 */
readonly final class PublicStandingTable
{
    /**
     * @param list<PublicStandingRow> $rows in the standing's order - the ones the viewer may see
     */
    public function __construct(
        public StandingTable $table,
        public array $rows,
    ) {
    }

    public function isProvisional(): bool
    {
        return $this->table->isProvisional();
    }

    public function roundsCounted(): int
    {
        return $this->table->roundsCounted();
    }

    public function roundsTotal(): int
    {
        return $this->table->roundsTotal();
    }

    /**
     * Some entrant is listed without a rank - the legend explains "3 of 6 rounds"
     */
    public function hasIncomplete(): bool
    {
        return array_any($this->rows, static fn (PublicStandingRow $row): bool => $row->row->complete === false);
    }

    /**
     * Some round score is a time limit (+ penalty) of an unfinished result - the legend explains the asterisk
     */
    public function hasUnfinishedScores(): bool
    {
        foreach ($this->rows as $row) {
            foreach ($row->row->perRound as $score) {
                if ($score->status === StandingScoreStatus::Unfinished) {
                    return true;
                }
            }
        }

        return false;
    }
}
