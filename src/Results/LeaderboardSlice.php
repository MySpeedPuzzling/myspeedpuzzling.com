<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\LeaderboardPosition;

/**
 * The rows of a board one window statement loaded (Value\LeaderboardWindow::rowsToLoad()): the top rows and the viewer's
 * own rows, with what the window and the position line need about the whole list - the MSP Rating and the Times boards
 * (docs/features/xp-levels/ux-polish/design-spec.md §4).
 *
 * @template T of object
 */
readonly final class LeaderboardSlice
{
    /**
     * @param list<T> $rows in list order
     * @param list<int> $ranks the shared rank of each row (RANK(): equal values share the place)
     * @param list<int> $positions the 0-based place of each row in the list on screen
     * @param int $total rows of the list on screen
     * @param null|int $ownPosition the viewer's 0-based place in the list on screen (their best pair/team on those boards)
     * @param null|LeaderboardPosition $viewerPosition the viewer's position line - null when they are not on the board
     * @param list<null|int> $closestBetter per row the closest strictly better value of the whole list (Times boards: the
     *                                      closest faster time, also for a row right after a gap) - empty where unused
     */
    public function __construct(
        public array $rows,
        public array $ranks,
        public array $positions,
        public int $total,
        public null|int $ownPosition,
        public null|LeaderboardPosition $viewerPosition,
        public array $closestBetter = [],
    ) {
    }
}
