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

    public function testChosenColourIsNormalisedAndTheOldFormDefaultIsNone(): void
    {
        self::assertSame('#0d6efd', RoundBadgeColor::chosen('#0D6EFD'));
        self::assertSame('#aabbcc', RoundBadgeColor::chosen(' abc '));
        self::assertNull(RoundBadgeColor::chosen(null));
        self::assertNull(RoundBadgeColor::chosen(''));
        self::assertNull(RoundBadgeColor::chosen('red'));
        self::assertNull(RoundBadgeColor::chosen('#FE696A'));
    }

    public function testAutomaticColourIsThePaletteColourOfTheSchedulePosition(): void
    {
        self::assertSame(RoundBadgeColor::PALETTE[3], RoundBadgeColor::automatic(3));
        self::assertSame(RoundBadgeColor::background(null, 5), RoundBadgeColor::automatic(5));
    }

    public function testStoredTextColourFollowsTheChosenColourOnly(): void
    {
        self::assertSame('#000000', RoundBadgeColor::textForChosen('#ffc107'));
        self::assertSame('#ffffff', RoundBadgeColor::textForChosen('#000075'));
        // Without a colour of its own the background depends on the schedule - nothing to store
        self::assertNull(RoundBadgeColor::textForChosen(null));
        self::assertNull(RoundBadgeColor::textForChosen('#fe696a'));
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
        // APCA: white on saturated mid-tones, where WCAG 2's ratio picked black
        yield 'mid blue' => ['#3d6cf2', '#ffffff'];
        yield 'bootstrap blue' => ['#007bff', '#ffffff'];
        yield 'form default coral' => ['#fe696a', '#ffffff'];
        yield 'palette red' => ['#e6194b', '#ffffff'];
        // Yellows and pastels stay black
        yield 'bootstrap warning yellow' => ['#ffc107', '#000000'];
        yield 'palette yellow' => ['#ffe119', '#000000'];
        yield 'palette pink' => ['#fabebe', '#000000'];
        yield 'navy' => ['#000075', '#ffffff'];
        yield 'maroon' => ['#800000', '#ffffff'];
        yield 'palette purple' => ['#911eb4', '#ffffff'];
    }

    public function testEveryPaletteColourHasStrongContrastWithItsTextColour(): void
    {
        foreach (RoundBadgeColor::PALETTE as $color) {
            $text = RoundBadgeColor::text($color);
            $contrast = RoundBadgeColor::apcaContrast(
                RoundBadgeColor::apcaLuminance($text),
                RoundBadgeColor::apcaLuminance($color),
            );

            self::assertGreaterThanOrEqual(60, abs($contrast), sprintf('%s with %s text: Lc %.1f', $color, $text, $contrast));
            self::assertGreaterThanOrEqual(4.5, self::wcagRatio($color, $text), sprintf('%s with %s text by WCAG 2', $color, $text));
        }
    }

    private static function wcagRatio(string $background, string $text): float
    {
        $luminance = static function (string $hex): float {
            $channel = static function (string $component): float {
                $value = hexdec($component) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));
        };

        $lighter = max($luminance($background), $luminance($text));
        $darker = min($luminance($background), $luminance($text));

        return ($lighter + 0.05) / ($darker + 0.05);
    }
}
