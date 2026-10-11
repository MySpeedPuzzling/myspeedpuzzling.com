<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\LeaderboardPosition;
use SpeedPuzzling\Web\Value\LeaderboardWindow;

final class LeaderboardWindowTest extends TestCase
{
    public function testWithoutAnOwnRowTheTopRowsShow(): void
    {
        $window = new LeaderboardWindow(totalRows: 250, ownPosition: null);

        self::assertSame([['first' => 0, 'last' => 99, 'hiddenBefore' => 0]], $window->blocks());
        self::assertSame([], $window->gapsBefore());
        self::assertSame(150, $window->rowsBelow());
        self::assertSame(100, $window->showMoreCount());
        self::assertFalse($window->ownRowBeyondLimit());
        self::assertFalse($window->ownRowBelowFirstScreen());
    }

    public function testAShortListShowsEverythingAndNothingBelow(): void
    {
        $window = new LeaderboardWindow(totalRows: 7, ownPosition: 6);

        self::assertSame([['first' => 0, 'last' => 6, 'hiddenBefore' => 0]], $window->blocks());
        self::assertSame(0, $window->rowsBelow());
        self::assertSame(0, $window->showMoreCount());
    }

    public function testAnEmptyList(): void
    {
        $window = new LeaderboardWindow(totalRows: 0, ownPosition: null);

        self::assertSame([], $window->blocks());
        self::assertSame(-1, $window->lastShownPosition());
        self::assertSame(0, $window->rowsBelow());
        self::assertSame([], $window->visiblePositions());
    }

    public function testTheOwnRowFarDownComesWithTwoNeighboursEachSideAfterAGap(): void
    {
        // 1 … 100, ⋯ 497 more, 598, 599, 600 (you), 601, 602
        $window = new LeaderboardWindow(totalRows: 1718, ownPosition: 599);

        self::assertSame([
            ['first' => 0, 'last' => 99, 'hiddenBefore' => 0],
            ['first' => 597, 'last' => 601, 'hiddenBefore' => 497],
        ], $window->blocks());
        self::assertSame([597 => 497], $window->gapsBefore());
        self::assertSame(1718 - 602, $window->rowsBelow());
        self::assertTrue($window->ownRowBeyondLimit());
        self::assertTrue($window->ownRowBelowFirstScreen());
        self::assertTrue($window->isVisible(599));
        self::assertFalse($window->isVisible(100));
        self::assertCount(105, $window->visiblePositions());
    }

    public function testANeighbourhoodTouchingTheTopRowsJoinsThemWithoutAGap(): void
    {
        // You are 102nd: 1 … 100 run straight on to 101, 102 (you), 103, 104
        $window = new LeaderboardWindow(totalRows: 500, ownPosition: 101);

        self::assertSame([['first' => 0, 'last' => 103, 'hiddenBefore' => 0]], $window->blocks());
        self::assertSame([], $window->gapsBefore());
        self::assertTrue($window->ownRowBeyondLimit());
    }

    public function testAViewerInsideTheTopRowsAlsoSeesTheTwoRowsBelowThem(): void
    {
        $window = new LeaderboardWindow(totalRows: 500, ownPosition: 99);

        self::assertSame([['first' => 0, 'last' => 101, 'hiddenBefore' => 0]], $window->blocks());
        self::assertFalse($window->ownRowBeyondLimit());
    }

    public function testTheOwnRowAtTheEndHasNoRowsBelowIt(): void
    {
        $window = new LeaderboardWindow(totalRows: 300, ownPosition: 299);

        self::assertSame(['first' => 297, 'last' => 299, 'hiddenBefore' => 197], $window->blocks()[1]);
        self::assertSame(0, $window->rowsBelow());
    }

    public function testTheGapRowRevealsExactlyTheRowsItCounts(): void
    {
        $window = (new LeaderboardWindow(totalRows: 1718, ownPosition: 599))->revealGap();

        self::assertSame(597, $window->limit());
        self::assertSame([['first' => 0, 'last' => 601, 'hiddenBefore' => 0]], $window->blocks());
        self::assertSame(1718 - 602, $window->rowsBelow());
    }

    public function testShowMoreContinuesAfterTheOwnRowsWhileTheyStandApart(): void
    {
        $window = (new LeaderboardWindow(totalRows: 1718, ownPosition: 599))->showMore();

        self::assertSame(100, $window->limit(), 'The gap above the viewer stays, it has its own row');
        self::assertSame(102, $window->belowOwnRow());
        self::assertSame([597 => 497], $window->gapsBefore());
        self::assertSame(701, $window->lastShownPosition());

        $twice = $window->showMore();
        self::assertSame(202, $twice->belowOwnRow());
        self::assertSame(801, $twice->lastShownPosition());
    }

    public function testShowMoreContinuesAfterTheTopRowsOtherwise(): void
    {
        $window = (new LeaderboardWindow(totalRows: 1718, ownPosition: 50))->showMore();

        self::assertSame(200, $window->limit());
        self::assertSame(2, $window->belowOwnRow());
        self::assertSame(199, $window->lastShownPosition());

        $noViewer = (new LeaderboardWindow(totalRows: 150, ownPosition: null))->showMore();
        self::assertSame(149, $noViewer->lastShownPosition());
        self::assertSame(0, $noViewer->rowsBelow());
    }

    public function testShowMoreAfterTheGapWasRevealedContinuesAfterTheOwnRows(): void
    {
        $window = (new LeaderboardWindow(totalRows: 1718, ownPosition: 599))->revealGap()->showMore();

        // The ranking runs on without a break to the viewer, and "Show more" continues after the viewer's rows
        self::assertSame(597, $window->limit());
        self::assertSame(102, $window->belowOwnRow());
        self::assertSame([['first' => 0, 'last' => 701, 'hiddenBefore' => 0]], $window->blocks());
    }

    public function testShowAll(): void
    {
        $window = (new LeaderboardWindow(totalRows: 1718, ownPosition: 599))->showAll();

        self::assertSame(1718, $window->limit());
        self::assertSame(0, $window->rowsBelow());
        self::assertSame([], $window->gapsBefore());
    }

    public function testKeptValuesAreNormalised(): void
    {
        $window = new LeaderboardWindow(totalRows: 10, ownPosition: 3, limitAsked: 0, belowOwnRowAsked: -5);

        self::assertSame(1, $window->limit());
        self::assertSame(2, $window->belowOwnRow());
        self::assertSame(['limit' => 1, 'above' => 2, 'below' => 2], $window->rowsToLoad());
    }

    public function testAnOwnPositionOutsideTheListIsNoOwnRow(): void
    {
        self::assertNull((new LeaderboardWindow(totalRows: 5, ownPosition: 5))->ownPosition);
        self::assertNull((new LeaderboardWindow(totalRows: 5, ownPosition: -1))->ownPosition);
    }

    public function testThePositionLineOnABoardWhereHigherIsBetter(): void
    {
        $position = LeaderboardPosition::describe(
            rank: 1289,
            total: 7878,
            ownValue: 185,
            milestoneValues: [1 => 3160, 1000 => 231, 2500 => 120],
            higherIsBetter: true,
        );

        self::assertSame(1289, $position->rank);
        self::assertSame(17, $position->topPercent);
        self::assertNull($position->percentile, 'Not asked for (no rowsBehind)');
        self::assertSame(1000, $position->targetRank);
        self::assertSame(46, $position->targetGap);
    }

    public function testThePositionLineOnATimeBoard(): void
    {
        $position = LeaderboardPosition::describe(
            rank: 600,
            total: 1718,
            ownValue: 3000,
            milestoneValues: [1 => 1500, 500 => 2844],
            higherIsBetter: false,
            rowsBehind: 1118,
        );

        self::assertSame(65, $position->percentile);
        self::assertSame(35, $position->topPercent);
        self::assertSame(500, $position->targetRank);
        self::assertSame(156, $position->targetGap);
    }

    public function testTheFirstPlaceAndSmallBoardsSayLess(): void
    {
        $first = LeaderboardPosition::describe(rank: 1, total: 500, ownValue: 900, milestoneValues: [1 => 900], higherIsBetter: true);
        self::assertNull($first->topPercent);
        self::assertNull($first->targetRank);

        $small = LeaderboardPosition::describe(rank: 3, total: 5, ownValue: 10, milestoneValues: [1 => 30, 3 => 10], higherIsBetter: true, rowsBehind: 2);
        self::assertNull($small->topPercent);
        self::assertNull($small->percentile);
        self::assertSame(1, $small->targetRank);
        self::assertSame(20, $small->targetGap);
    }

    public function testTheLowerHalfIsNotToldAsAShare(): void
    {
        $position = LeaderboardPosition::describe(rank: 80, total: 100, ownValue: 10, milestoneValues: [50 => 40], higherIsBetter: true);

        self::assertNull($position->topPercent);
        self::assertSame(50, $position->targetRank);
        self::assertSame(30, $position->targetGap);
    }

    public function testABoardShowsTheTop25AndRevealsAtMost50RowsPerTap(): void
    {
        // You are #1289: 1 … 25, ⋯ 1261 more, 1287 … 1291
        $window = LeaderboardWindow::board(totalRows: 7878, ownPosition: 1288);

        self::assertSame(25, $window->limit());
        self::assertSame([1286 => 1261], $window->gapsBefore());
        self::assertSame(['from' => 26, 'to' => 75], $window->gapReveal());
        self::assertSame(['from' => 1292, 'to' => 1341], $window->showMoreReveal());

        $revealed = $window->revealGap();
        self::assertSame(75, $revealed->limit());
        self::assertSame([1286 => 1211], $revealed->gapsBefore(), 'The gap row stays with the rows still left out');
        self::assertSame(['from' => 76, 'to' => 125], $revealed->gapReveal());

        $more = $window->showMore();
        self::assertSame(52, $more->belowOwnRow());
        self::assertSame(1340, $more->lastShownPosition());
    }

    public function testTheLastRevealOfABoardClosesTheGap(): void
    {
        $window = LeaderboardWindow::board(totalRows: 200, ownPosition: 60);

        self::assertSame(['from' => 26, 'to' => 58], $window->gapReveal());
        self::assertSame([['first' => 0, 'last' => 62, 'hiddenBefore' => 0]], $window->revealGap()->blocks());
    }

    public function testThePuzzlePageGapRevealNamesEveryRowItCounts(): void
    {
        self::assertSame(['from' => 101, 'to' => 597], (new LeaderboardWindow(totalRows: 1718, ownPosition: 599))->gapReveal());
        self::assertNull((new LeaderboardWindow(totalRows: 50, ownPosition: 10))->gapReveal());
    }

    public function testTheTopShareForThePercentFormat(): void
    {
        self::assertSame(0.17, LeaderboardPosition::describe(rank: 1289, total: 7878, ownValue: 1, milestoneValues: [], higherIsBetter: true)->topFraction);
        self::assertSame(0.033, LeaderboardPosition::describe(rank: 259, total: 7878, ownValue: 1, milestoneValues: [], higherIsBetter: true)->topFraction);
        self::assertNull(LeaderboardPosition::describe(rank: 5000, total: 7878, ownValue: 1, milestoneValues: [], higherIsBetter: true)->topFraction);
    }
}
