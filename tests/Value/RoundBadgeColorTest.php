<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\RoundBadgeColor;

final class RoundBadgeColorTest extends TestCase
{
    public function testRoundsWithoutAColourGetDistinctPaletteColoursInScheduleOrder(): void
    {
        self::assertSame(RoundBadgeColor::PALETTE[0], RoundBadgeColor::background(null, 0));
        self::assertSame(RoundBadgeColor::PALETTE[1], RoundBadgeColor::background(null, 1));
        self::assertNotSame(RoundBadgeColor::background(null, 0), RoundBadgeColor::background(null, 1));
    }

    public function testPaletteCyclesForEventsWithManyRounds(): void
    {
        $count = count(RoundBadgeColor::PALETTE);

        self::assertSame(RoundBadgeColor::PALETTE[1], RoundBadgeColor::background(null, $count + 1));
    }

    public function testOrganisersColourIsKept(): void
    {
        self::assertSame('#0d6efd', RoundBadgeColor::background('#0D6EFD', 3));
        self::assertSame('#aabbcc', RoundBadgeColor::background('abc', 3));
    }

    public function testRoundFormDefaultAndGarbageCountAsNotChosen(): void
    {
        self::assertSame(RoundBadgeColor::PALETTE[2], RoundBadgeColor::background('#FE696A', 2));
        self::assertSame(RoundBadgeColor::PALETTE[2], RoundBadgeColor::background('red', 2));
    }

    #[DataProvider('provideTextColors')]
    public function testTextColourIsTheOneWithMoreContrast(string $background, string $expectedText): void
    {
        self::assertSame($expectedText, RoundBadgeColor::text($background));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTextColors(): iterable
    {
        // The combinations production had with white text, all below 4.5:1
        yield 'form default coral' => ['#fe696a', '#000000'];
        yield 'bootstrap warning yellow' => ['#ffc107', '#000000'];
        yield 'palette green' => ['#3cb44b', '#000000'];
        yield 'navy' => ['#000075', '#ffffff'];
        yield 'maroon' => ['#800000', '#ffffff'];
        yield 'palette purple' => ['#911eb4', '#ffffff'];
    }
}
