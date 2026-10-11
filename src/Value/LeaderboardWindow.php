<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which rows of a ranked list a board shows - "where am I" on every leaderboard
 * (docs/features/puzzle-leaderboard-chart.md, docs/features/xp-levels/ux-polish/design-spec.md §4).
 *
 * INTERFACE (stable - the puzzle page, the progress boards, MSP Rating and the Times boards share it):
 *   $window = new LeaderboardWindow(totalRows: 1718, ownPosition: 599, limitAsked: 100, belowOwnRowAsked: 2);
 *   // positions are 0-based places in the list's stable order (value, then id), never ranks
 *   The boards (design-spec §8.4) - top 25 rows, the gap row and "Show more" reveal at most 50 rows per tap:
 *   $window = LeaderboardWindow::board(totalRows, ownPosition, limitAsked (null = 25), belowOwnRowAsked (null = 2));
 *   The /ladder overview tables (top 10, no neighbours): LeaderboardWindow::board(..., topRows: LeaderboardWindow::OVERVIEW_TOP_ROWS, neighbours: 0)
 *   The puzzle page keeps new LeaderboardWindow(...) = top 100, the gap row reveals all of its rows, "Show more" 100.
 *   $window->revealGap() / ->showMore() / ->showAll()   new windows after the gap row / "Show more" / "Show all"
 *   $window->gapReveal(), ->showMoreReveal()           array{from, to} 1-based list positions the next tap shows (null = none):
 *                                                      the gap button's "Show ranks 26–75 of 1,289", the focus + announcement
 *                                                      after the tap (templates/leaderboards/_window_announcer.html.twig)
 *   $window->limit(), ->belowOwnRow()                  what to keep (LiveProps, or the next request's query)
 *   $window->blocks()                                  list<array{first: int, last: int, hiddenBefore: int}>
 *   $window->gapsBefore()                              array<position, hidden count> - a gap row above that position
 *   $window->isVisible($position), ->visiblePositions()
 *   $window->lastShownPosition(), ->rowsBelow(), ->showMoreCount()
 *   $window->ownRowBeyondLimit(), ->ownRowBelowFirstScreen()
 *   $window->rowsToLoad()                              array{limit, above, below} - what one SQL statement loads before
 *                                                      it knows the viewer's position (build the window with totalRows 0 first)
 *
 * What it shows: the top $limit rows; the viewer's own row with NEIGHBOURS rows above it and $belowOwnRow rows below it
 * (at least NEIGHBOURS), after a gap row when it lies further down. Two buttons, two things: the gap row reveals every row
 * it counts (revealGap()), "Show more" continues after the last row shown - after the viewer's rows while they stand apart
 * from the top rows, after the top rows otherwise (showMore()).
 */
final readonly class LeaderboardWindow
{
    public const int DEFAULT_LIMIT = 100;

    public const int NEIGHBOURS = 2;

    // Boards (design-spec §8.4): 25 rows tell who leads and keep the viewer's own row a few screens away
    public const int BOARD_TOP_ROWS = 25;

    // The /ladder overview tables
    public const int OVERVIEW_TOP_ROWS = 10;

    // Boards: the gap row and "Show more" reveal at most this many rows per tap
    public const int BOARD_STEP = 50;

    // "Jump to me" is offered when the viewer's row is further down than this many rows - below the first screen
    public const int FIRST_SCREEN_ROWS = 10;

    public null|int $ownPosition;

    public function __construct(
        public int $totalRows,
        null|int $ownPosition,
        // As stored or asked for - limit() / belowOwnRow() are the values in effect
        private int $limitAsked = self::DEFAULT_LIMIT,
        private int $belowOwnRowAsked = self::NEIGHBOURS,
        public int $neighbours = self::NEIGHBOURS,
        // Rows "Show more" adds per tap
        public int $step = self::DEFAULT_LIMIT,
        // Rows the gap row reveals per tap - null = every row it counts (the puzzle page)
        public null|int $gapStep = null,
    ) {
        $this->ownPosition = $ownPosition !== null && $ownPosition >= 0 && $ownPosition < $totalRows ? $ownPosition : null;
    }

    /**
     * A leaderboard's window (design-spec §8.4): the top 25 rows, the gap row and "Show more" reveal at most 50 rows per tap
     */
    public static function board(
        int $totalRows,
        null|int $ownPosition,
        null|int $limitAsked = null,
        null|int $belowOwnRowAsked = null,
        int $topRows = self::BOARD_TOP_ROWS,
        int $neighbours = self::NEIGHBOURS,
    ): self {
        return new self(
            totalRows: $totalRows,
            ownPosition: $ownPosition,
            limitAsked: $limitAsked ?? $topRows,
            belowOwnRowAsked: $belowOwnRowAsked ?? $neighbours,
            neighbours: $neighbours,
            step: self::BOARD_STEP,
            gapStep: self::BOARD_STEP,
        );
    }

    /**
     * The 1-based list positions revealGap() adds - null when there is no gap
     *
     * @return null|array{from: int, to: int}
     */
    public function gapReveal(): null|array
    {
        foreach ($this->blocks() as $block) {
            if ($block['hiddenBefore'] > 0) {
                $from = $block['first'] - $block['hiddenBefore'] + 1;
                $count = $this->gapStep === null ? $block['hiddenBefore'] : min($this->gapStep, $block['hiddenBefore']);

                return ['from' => $from, 'to' => $from + $count - 1];
            }
        }

        return null;
    }

    /**
     * The 1-based list positions showMore() adds - null when nothing is left below
     *
     * @return null|array{from: int, to: int}
     */
    public function showMoreReveal(): null|array
    {
        $count = $this->showMoreCount();

        if ($count < 1) {
            return null;
        }

        $from = $this->lastShownPosition() + 2;

        return ['from' => $from, 'to' => $from + $count - 1];
    }

    /**
     * The gap row between the top rows and the viewer's own rows: every row it counts, so the ranking runs on without a
     * break down to the viewer - or, on the boards ($gapStep), the next $gapStep rows after the top rows
     */
    public function revealGap(): self
    {
        if ($this->ownPosition === null) {
            return $this;
        }

        $gapEnd = $this->ownPosition - $this->neighbours;
        $limit = $this->gapStep === null ? $gapEnd : min($gapEnd, max(1, $this->limitAsked) + $this->gapStep);

        return $this->with(max($this->limitAsked, $limit), $this->belowOwnRowAsked);
    }

    /**
     * The button under the list: the next rows after the last one shown. While the viewer's own rows stand apart from the
     * top rows, that is after them - the gap above them stays, it has its own row (revealGap()).
     */
    public function showMore(null|int $step = null): self
    {
        $step ??= $this->step;

        if ($this->ownPosition !== null && $this->ownPosition >= $this->limitAsked) {
            return $this->with($this->limitAsked, $this->belowOwnRowAsked + $step);
        }

        return $this->with($this->limitAsked + $step, $this->belowOwnRowAsked);
    }

    public function showAll(): self
    {
        return $this->with($this->totalRows, $this->belowOwnRowAsked);
    }

    public function limit(): int
    {
        return max(1, $this->limitAsked);
    }

    public function belowOwnRow(): int
    {
        return max($this->neighbours, $this->belowOwnRowAsked);
    }

    /**
     * The shown positions as blocks in list order, each with the rows left out right above it (0 for the first block,
     * and for a block that follows the one before it without a break)
     *
     * @return list<array{first: int, last: int, hiddenBefore: int}>
     */
    public function blocks(): array
    {
        if ($this->totalRows < 1) {
            return [];
        }

        $topLast = min($this->limit(), $this->totalRows) - 1;

        if ($this->ownPosition === null) {
            return [['first' => 0, 'last' => $topLast, 'hiddenBefore' => 0]];
        }

        $ownFirst = max(0, $this->ownPosition - $this->neighbours);
        $ownLast = min($this->totalRows - 1, $this->ownPosition + $this->belowOwnRow());

        if ($ownFirst <= $topLast + 1) {
            return [['first' => 0, 'last' => max($topLast, $ownLast), 'hiddenBefore' => 0]];
        }

        return [
            ['first' => 0, 'last' => $topLast, 'hiddenBefore' => 0],
            ['first' => $ownFirst, 'last' => $ownLast, 'hiddenBefore' => $ownFirst - $topLast - 1],
        ];
    }

    /**
     * A gap row ("⋯ N more") goes right above each of these positions
     *
     * @return array<int, int> position => rows left out above it
     */
    public function gapsBefore(): array
    {
        $gaps = [];

        foreach ($this->blocks() as $block) {
            if ($block['hiddenBefore'] > 0) {
                $gaps[$block['first']] = $block['hiddenBefore'];
            }
        }

        return $gaps;
    }

    public function isVisible(int $position): bool
    {
        foreach ($this->blocks() as $block) {
            if ($position >= $block['first'] && $position <= $block['last']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    public function visiblePositions(): array
    {
        $positions = [];

        foreach ($this->blocks() as $block) {
            foreach (range($block['first'], $block['last']) as $position) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    // -1 when the list is empty
    public function lastShownPosition(): int
    {
        $blocks = $this->blocks();

        return $blocks === [] ? -1 : $blocks[array_key_last($blocks)]['last'];
    }

    // Rows after the last row shown - what "Show more" reveals
    public function rowsBelow(): int
    {
        return $this->totalRows - 1 - $this->lastShownPosition();
    }

    public function showMoreCount(null|int $step = null): int
    {
        return min($step ?? $this->step, $this->rowsBelow());
    }

    // The viewer's row lies beyond the top rows and is shown with its neighbours, so "Jump to me" always has a target
    public function ownRowBeyondLimit(): bool
    {
        return $this->ownPosition !== null && $this->ownPosition >= $this->limit();
    }

    public function ownRowBelowFirstScreen(): bool
    {
        return $this->ownPosition !== null && $this->ownPosition >= self::FIRST_SCREEN_ROWS;
    }

    /**
     * For a board loading its rows in one statement before it knows the viewer's position: the top rows, and how many
     * rows above and below the viewer's own row (wherever the statement finds it) - see GetXpLeaderboard::board()
     *
     * @return array{limit: int, above: int, below: int}
     */
    public function rowsToLoad(): array
    {
        return [
            'limit' => $this->limit(),
            'above' => $this->neighbours,
            'below' => $this->belowOwnRow(),
        ];
    }

    private function with(int $limit, int $belowOwnRow): self
    {
        return new self($this->totalRows, $this->ownPosition, $limit, $belowOwnRow, $this->neighbours, $this->step, $this->gapStep);
    }
}
