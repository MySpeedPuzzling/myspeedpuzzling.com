<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PlayerStatsSnapshot
{
    public function __construct(
        public string $playerId,
        public int $distinctPuzzlesSolved,
        public int $totalPiecesSolved,
        public null|int $best500PieceSoloSeconds,
        public int $allTimeLongestStreakDays,
        public int $teamSolvesCount,
    ) {
    }
}
