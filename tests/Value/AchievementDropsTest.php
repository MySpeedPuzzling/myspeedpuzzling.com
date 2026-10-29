<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\AchievementDrops;
use SpeedPuzzling\Web\Value\BadgeType;

final class AchievementDropsTest extends TestCase
{
    public function testDropsAreInOrderAndAnAchievementComesWithOneDropAtMost(): void
    {
        $seen = [];
        $previous = null;

        foreach (AchievementDrops::all() as $drop) {
            self::assertNotSame([], $drop->types);

            if ($previous !== null) {
                self::assertGreaterThan($previous, $drop->releasedAt, 'Oldest first');
            }

            foreach ($drop->types as $type) {
                self::assertArrayNotHasKey($type->value, $seen, $type->value . ' is in two drops');
                $seen[$type->value] = true;
            }

            $previous = $drop->releasedAt;
        }
    }

    public function testADropsAchievementsAreNewForThirtyDays(): void
    {
        $releasedAt = new DateTimeImmutable('2026-10-29 14:20:00 UTC');

        self::assertFalse(AchievementDrops::isNew(BadgeType::NightOwl, $releasedAt->modify('-1 second')));
        self::assertTrue(AchievementDrops::isNew(BadgeType::NightOwl, $releasedAt));
        self::assertTrue(AchievementDrops::isNew(BadgeType::NightOwl, $releasedAt->modify('+29 days')));
        self::assertFalse(AchievementDrops::isNew(BadgeType::NightOwl, $releasedAt->modify('+30 days')));
        // The launch's achievements came with no drop
        self::assertFalse(AchievementDrops::isNew(BadgeType::PuzzlesSolved, $releasedAt));
    }

    public function testTheDigestOfThePeriodHoldingTheDropAnnouncesIt(): void
    {
        $week = new DateTimeImmutable('2026-10-26 00:00:00 Europe/Prague');

        $drop = AchievementDrops::releasedBetween($week, $week->modify('+7 days'));
        self::assertNotNull($drop);
        self::assertTrue($drop->contains(BadgeType::MuscleMemory));

        self::assertNull(AchievementDrops::releasedBetween($week->modify('-7 days'), $week));
        self::assertNull(AchievementDrops::releasedBetween($week->modify('+7 days'), $week->modify('+14 days')));
    }
}
