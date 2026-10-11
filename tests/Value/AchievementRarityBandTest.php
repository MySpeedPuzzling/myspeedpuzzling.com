<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\AchievementRarityBand;

final class AchievementRarityBandTest extends TestCase
{
    /**
     * @return iterable<string, array{float, AchievementRarityBand}>
     */
    public static function provideBands(): iterable
    {
        yield 'everybody' => [100.0, AchievementRarityBand::Common];
        yield 'half is common' => [50.0, AchievementRarityBand::Common];
        yield 'just under half' => [49.9, AchievementRarityBand::Rare];
        yield '15 % is rare' => [15.0, AchievementRarityBand::Rare];
        yield 'just under 15 %' => [14.9, AchievementRarityBand::VeryRare];
        yield '5 % is very rare' => [5.0, AchievementRarityBand::VeryRare];
        yield 'just under 5 %' => [4.9, AchievementRarityBand::UltraRare];
        yield 'a tiny share' => [0.0, AchievementRarityBand::UltraRare];
    }

    #[DataProvider('provideBands')]
    public function testThePlayStationBands(float $percent, AchievementRarityBand $expected): void
    {
        self::assertSame($expected, AchievementRarityBand::fromPercent($percent));
        self::assertSame('badges.medal.rarity.' . $expected->value, $expected->translationKey());
    }
}
