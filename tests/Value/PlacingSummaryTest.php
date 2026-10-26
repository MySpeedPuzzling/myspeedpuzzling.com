<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PlacingSummary;

final class PlacingSummaryTest extends TestCase
{
    public function testTheMedianOfAnOddCount(): void
    {
        $summary = PlacingSummary::of([
            PlacingFilterTest::ranking(10, 100),
            PlacingFilterTest::ranking(50, 100),
            PlacingFilterTest::ranking(30, 100),
        ]);

        self::assertNotNull($summary);
        self::assertSame(3, $summary->count);
        self::assertSame(0.3, $summary->median->fraction);
        self::assertSame(1, $summary->median->fractionDigits);
    }

    public function testTheMedianOfAnEvenCountIsBetweenTheMiddleTwo(): void
    {
        $summary = PlacingSummary::of([
            PlacingFilterTest::ranking(10, 100),
            PlacingFilterTest::ranking(20, 100),
            PlacingFilterTest::ranking(40, 100),
            PlacingFilterTest::ranking(90, 100),
        ]);

        self::assertNotNull($summary);
        self::assertSame(0.3, $summary->median->fraction);
    }

    public function testBoardsWithoutAShareAreLeftOut(): void
    {
        $summary = PlacingSummary::of([
            PlacingFilterTest::ranking(1, 3),
            PlacingFilterTest::ranking(9, 9),
            PlacingFilterTest::ranking(147, 1000),
        ]);

        self::assertNotNull($summary);
        self::assertSame(1, $summary->count);
        self::assertSame(0.147, $summary->median->fraction);
    }

    public function testNothingToSayWithoutABoardOfTen(): void
    {
        self::assertNull(PlacingSummary::of([PlacingFilterTest::ranking(1, 9)]));
        self::assertNull(PlacingSummary::of([]));
    }

    public function testASmallMedianKeepsTwoDecimals(): void
    {
        $summary = PlacingSummary::of([PlacingFilterTest::ranking(3, 1391)]);

        self::assertNotNull($summary);
        self::assertSame(0.0022, $summary->median->fraction);
        self::assertSame(2, $summary->median->fractionDigits);
    }
}
