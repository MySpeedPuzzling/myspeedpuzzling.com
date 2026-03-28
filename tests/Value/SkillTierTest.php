<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\SkillTier;

final class SkillTierTest extends TestCase
{
    public function testFromPercentile(): void
    {
        self::assertSame(SkillTier::Legend, SkillTier::fromPercentile(99.5));
        self::assertSame(SkillTier::Master, SkillTier::fromPercentile(96.0));
        self::assertSame(SkillTier::Expert, SkillTier::fromPercentile(85.0));
        self::assertSame(SkillTier::Advanced, SkillTier::fromPercentile(72.0));
        self::assertSame(SkillTier::Proficient, SkillTier::fromPercentile(50.0));
        self::assertSame(SkillTier::Enthusiast, SkillTier::fromPercentile(30.0));
        self::assertSame(SkillTier::Casual, SkillTier::fromPercentile(10.0));
    }

    public function testNextTier(): void
    {
        self::assertSame(SkillTier::Enthusiast, SkillTier::Casual->nextTier());
        self::assertSame(SkillTier::Proficient, SkillTier::Enthusiast->nextTier());
        self::assertSame(SkillTier::Advanced, SkillTier::Proficient->nextTier());
        self::assertSame(SkillTier::Expert, SkillTier::Advanced->nextTier());
        self::assertSame(SkillTier::Master, SkillTier::Expert->nextTier());
        self::assertSame(SkillTier::Legend, SkillTier::Master->nextTier());
        self::assertNull(SkillTier::Legend->nextTier());
    }

    public function testMinimumPercentile(): void
    {
        self::assertSame(0.0, SkillTier::Casual->minimumPercentile());
        self::assertSame(25.0, SkillTier::Enthusiast->minimumPercentile());
        self::assertSame(50.0, SkillTier::Proficient->minimumPercentile());
        self::assertSame(70.0, SkillTier::Advanced->minimumPercentile());
        self::assertSame(85.0, SkillTier::Expert->minimumPercentile());
        self::assertSame(95.0, SkillTier::Master->minimumPercentile());
        self::assertSame(99.0, SkillTier::Legend->minimumPercentile());
    }

    public function testMinimumPercentileMatchesFromPercentile(): void
    {
        // The minimum percentile for each tier should produce that tier when passed to fromPercentile
        foreach (SkillTier::cases() as $tier) {
            self::assertSame($tier, SkillTier::fromPercentile($tier->minimumPercentile()));
        }
    }
}
