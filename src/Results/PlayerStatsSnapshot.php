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
        /** Solves without any tracked time (relax mode). */
        public int $zenPuzzlerSolves = 0,
        /** Solves flagged as the player's first attempt at the puzzle. */
        public int $firstTrySolves = 0,
        /** Solves completed without looking at the box. */
        public int $unboxedSolves = 0,
        /** Distinct manufacturers across all solved puzzles. */
        public int $brandExplorerManufacturers = 0,
        /** Solves of puzzles with 2000+ pieces. */
        public int $marathonerSolves = 0,
        /** Solves with a finished-puzzle photo attached. */
        public int $photographerSolves = 0,
        /** Longest run of consecutive calendar quarters with at least one solve. */
        public int $steadyHandsQuarters = 0,
        /** Approved puzzle change + merge requests reported by the player. */
        public int $librarianApprovedRequests = 0,
        /** Fastest solo 1000-piece timed solve. */
        public null|int $best1000PieceSoloSeconds = null,
        /** Solves finished on a Saturday or Sunday. */
        public int $weekendSolves = 0,
        /** Approved puzzles added to the catalog by the player. */
        public int $catalogerApprovedPuzzles = 0,
    ) {
    }
}
