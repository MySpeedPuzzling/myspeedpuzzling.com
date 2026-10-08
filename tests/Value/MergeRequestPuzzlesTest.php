<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\MergeRequestPuzzles;
use SpeedPuzzling\Web\Value\PuzzleReportOutdatedReason;

final class MergeRequestPuzzlesTest extends TestCase
{
    private const string A = '018d0003-0000-0000-0000-00000000000a';
    private const string B = '018d0003-0000-0000-0000-00000000000b';
    private const string C = '018d0003-0000-0000-0000-00000000000c';
    private const string D = '018d0003-0000-0000-0000-00000000000d';

    public function testPuzzlesThatAllStillExistAreMergedAsReported(): void
    {
        $puzzles = MergeRequestPuzzles::resolve([self::A, self::B], [self::A => self::A, self::B => self::B]);

        self::assertSame([self::A, self::B], $puzzles->currentIds());
        self::assertSame([], $puzzles->mergedMeanwhile());
        self::assertSame([], $puzzles->gone());
        self::assertNull($puzzles->outdatedReason());
    }

    public function testAPuzzleMergedMeanwhileIsThePuzzleItWasMergedInto(): void
    {
        $puzzles = MergeRequestPuzzles::resolve([self::A, self::B], [self::A => self::A, self::B => self::C]);

        self::assertSame([self::A, self::C], $puzzles->currentIds());
        self::assertSame([self::B => self::C], $puzzles->mergedMeanwhile());
        self::assertNull($puzzles->outdatedReason());
    }

    public function testPuzzlesOtherMergesJoinedLeaveNothingToMerge(): void
    {
        $puzzles = MergeRequestPuzzles::resolve([self::A, self::B, self::C], [self::A => self::D, self::B => self::D, self::C => self::D]);

        self::assertSame([self::D], $puzzles->currentIds());
        self::assertSame(PuzzleReportOutdatedReason::AlreadyMerged, $puzzles->outdatedReason());
        self::assertSame(self::D, $puzzles->onlyCurrentId());
    }

    public function testAPuzzleDeletedWithoutAMergeIsGone(): void
    {
        $puzzles = MergeRequestPuzzles::resolve([self::A, self::B], [self::A => self::A, self::B => null]);

        self::assertSame([self::A], $puzzles->currentIds());
        self::assertSame([self::B], $puzzles->gone());
        self::assertSame(PuzzleReportOutdatedReason::PuzzlesGone, $puzzles->outdatedReason());
        self::assertSame(self::A, $puzzles->onlyCurrentId());
    }

    public function testNothingLeftAtAll(): void
    {
        $puzzles = MergeRequestPuzzles::resolve([self::A, self::B], []);

        self::assertSame([], $puzzles->currentIds());
        self::assertSame(PuzzleReportOutdatedReason::PuzzlesGone, $puzzles->outdatedReason());
        self::assertNull($puzzles->onlyCurrentId());
    }

    public function testTheMergeRunningNowCountsBeforeItsRedirectsAreWritten(): void
    {
        $puzzles = MergeRequestPuzzles::resolve([self::A, self::B], [self::A => self::A, self::B => self::B], [self::B => self::A]);

        self::assertSame(PuzzleReportOutdatedReason::AlreadyMerged, $puzzles->outdatedReason());
        self::assertSame(self::A, $puzzles->onlyCurrentId());
    }

    public function testAnOlderMergeLeadingToAPuzzleMergedNowLeadsOnToItsSurvivor(): void
    {
        // D was merged into B earlier; B is merged into C right now
        $puzzles = MergeRequestPuzzles::resolve([self::A, self::D], [self::A => self::A, self::D => self::B], [self::B => self::C]);

        self::assertSame([self::A, self::C], $puzzles->currentIds());
        self::assertSame([self::D => self::C], $puzzles->mergedMeanwhile());
    }

    public function testIdsAreComparedInLowerCase(): void
    {
        $puzzles = MergeRequestPuzzles::resolve([strtoupper(self::A), self::A, strtoupper(self::B)], [self::A => self::A, self::B => strtoupper(self::A)]);

        self::assertSame([self::A], $puzzles->currentIds());
        self::assertSame(PuzzleReportOutdatedReason::AlreadyMerged, $puzzles->outdatedReason());
    }
}
