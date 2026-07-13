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
            'between levels' => [9, 2],
            'exactly level 3' => [10, 3],
            'exactly level 10' => [64, 10],
            'mid curve' => [300, 22],
            'exactly level 25' => [363, 25],
            'just below max level' => [3159, 49],
            'exactly max level' => [3160, 50],
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
        self::assertSame(223, LevelTable::xpForLevel(20));
        self::assertSame(1376, LevelTable::xpForLevel(40));
        self::assertSame(3160, LevelTable::xpForLevel(50));
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
        // Level 2 spans 5..10 — 8 XP is 3/5 of the way to level 3.
        self::assertSame(0.6, LevelTable::progressToNext(8));
    }

    public function testProgressToNextJustBeforeLevelUp(): void
    {
        $progress = LevelTable::progressToNext(3159);

        self::assertNotNull($progress);
        self::assertGreaterThan(0.99, $progress);
        self::assertLessThan(1.0, $progress);
    }

    public function testProgressToNextIsNullAtMaxLevel(): void
    {
        self::assertNull(LevelTable::progressToNext(3160));
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
}
