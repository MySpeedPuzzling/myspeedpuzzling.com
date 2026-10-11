<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\LevelTable;

final class LevelTableTest extends TestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function provideXpToLevel(): array
    {
        return [
            'zero xp is level 1' => [0, 1],
            'just below level 2' => [4, 1],
            'exactly level 2' => [5, 2],
            'between levels' => [18, 2],
            'exactly level 3' => [19, 3],
            'exactly level 10' => [142, 10],
            'mid curve' => [600, 20],
            'exactly level 25' => [999, 25],
            'just below max level' => [6999, 49],
            'exactly max level' => [7000, 50],
            'far beyond max level' => [99999, 50],
            'negative xp clamps to level 1' => [-10, 1],
        ];
    }

    #[DataProvider('provideXpToLevel')]
    public function testLevelForXp(int $xp, int $expectedLevel): void
    {
        self::assertSame($expectedLevel, LevelTable::levelForXp($xp));
    }

    public function testXpForLevelBoundaries(): void
    {
        self::assertSame(0, LevelTable::xpForLevel(1));
        self::assertSame(5, LevelTable::xpForLevel(2));
        self::assertSame(573, LevelTable::xpForLevel(20));
        self::assertSame(3648, LevelTable::xpForLevel(40));
        self::assertSame(7000, LevelTable::xpForLevel(50));
    }

    public function testXpForLevelRejectsLevelZero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LevelTable::xpForLevel(0);
    }

    public function testXpForLevelRejectsLevelAboveMax(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LevelTable::xpForLevel(51);
    }

    public function testProgressToNextAtLevelStart(): void
    {
        self::assertSame(0.0, LevelTable::progressToNext(0));
        self::assertSame(0.0, LevelTable::progressToNext(5));
    }

    public function testProgressToNextMidLevel(): void
    {
        // Level 2 spans 5..19 — 12 XP is half of the way to level 3.
        self::assertSame(0.5, LevelTable::progressToNext(12));
    }

    public function testProgressToNextJustBeforeLevelUp(): void
    {
        $progress = LevelTable::progressToNext(6999);

        self::assertNotNull($progress);
        self::assertGreaterThan(0.99, $progress);
        self::assertLessThan(1.0, $progress);
    }

    public function testProgressToNextIsNullAtMaxLevel(): void
    {
        self::assertNull(LevelTable::progressToNext(7000));
        self::assertNull(LevelTable::progressToNext(99999));
    }

    public function testCurveIsStrictlyIncreasing(): void
    {
        $previous = LevelTable::xpForLevel(1);

        for ($level = 2; $level <= LevelTable::MAX_LEVEL; $level++) {
            $current = LevelTable::xpForLevel($level);
            self::assertGreaterThan($previous, $current, "Level {$level} threshold must exceed level " . ($level - 1));
            $previous = $current;
        }
    }

    /**
     * Curve v6 shape (LevelTable docblock): no level is ever cheaper than the one before it
     */
    public function testNoStepIsSmallerThanThePreviousOne(): void
    {
        for ($level = 3; $level <= LevelTable::MAX_LEVEL; $level++) {
            $step = LevelTable::xpForLevel($level) - LevelTable::xpForLevel($level - 1);
            $previousStep = LevelTable::xpForLevel($level - 1) - LevelTable::xpForLevel($level - 2);
            self::assertGreaterThanOrEqual($previousStep, $step, "Level {$level}");
        }
    }
}
