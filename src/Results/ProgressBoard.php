<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What one window statement of a progress board brings (GetXpLeaderboard::board()): the rows a LeaderboardWindow may
 * show plus the rows at the milestone positions (LeaderboardPosition::RANK_MILESTONES), the list's size and where the
 * viewer stands in it.
 */
readonly final class ProgressBoard
{
    /**
     * @param array<int, ProgressBoardRow> $rows by 0-based position, in list order
     */
    public function __construct(
        public array $rows,
        public int $total,
        // 0-based, null when the viewer is not on this board (no value, private, opted out, filtered out, AP without membership)
        public null|int $ownPosition,
    ) {
    }

    public function ownRow(): null|ProgressBoardRow
    {
        return $this->ownPosition !== null ? ($this->rows[$this->ownPosition] ?? null) : null;
    }

    /**
     * @return array<int, int> 1-based list position => the value there, for the milestone positions loaded
     */
    public function milestoneValues(): array
    {
        $values = [];

        foreach ($this->rows as $position => $row) {
            $values[$position + 1] = $row->value;
        }

        return $values;
    }
}
