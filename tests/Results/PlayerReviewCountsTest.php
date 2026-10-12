<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PlayerReviewCounts;

/**
 * The Menu's Review your results number (MENU-01, menu_counts) is everything the banner counts.
 */
final class PlayerReviewCountsTest extends TestCase
{
    public function testTheTotalIsEverythingTheBannerCounts(): void
    {
        self::assertSame(0, PlayerReviewCounts::none()->total());
        self::assertTrue(PlayerReviewCounts::none()->isEmpty());

        $counts = new PlayerReviewCounts(duplicates: 2, autoRemoved: 1, firstTryConflicts: 3, suspiciousTimes: 4);
        self::assertSame(10, $counts->total());
        self::assertFalse($counts->isEmpty());

        foreach ([[1, 0, 0, 0], [0, 1, 0, 0], [0, 0, 1, 0], [0, 0, 0, 1]] as [$duplicates, $autoRemoved, $firstTries, $verification]) {
            $one = new PlayerReviewCounts($duplicates, $autoRemoved, $firstTries, $verification);
            self::assertSame(1, $one->total());
            self::assertFalse($one->isEmpty());
        }
    }
}
