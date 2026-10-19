<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PredictionWindowFormatter;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Value\PredictionWindow;

final class PredictionWindowFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int, string}>
     */
    public static function ranges(): iterable
    {
        // raw low, raw high, centre => text
        yield 'minutes under an hour' => [3440, 3820, 3600, '57–64 min'];
        yield 'minutes past the hour' => [3000, 4320, 3500, '50–70 min'];
        yield 'ending on the hour' => [3000, 3610, 3300, '50–60 min'];
        yield 'starting on the hour' => [3600, 4320, 3900, '1h – 1h 12min'];
        yield 'minutes while the high end is under 90 min' => [3000, 5200, 3500, '50–85 min'];
        yield 'hours from a high end of 90 min on' => [3000, 5340, 3500, '50min – 1h 30min'];
        yield 'a wide first-try window past 90 min' => [3000, 8400, 4800, '50min – 2h 20min'];
        yield 'hours' => [6420, 8160, 7200, '1h 50min – 2h 20min'];
        yield 'tiny, whole minutes' => [64, 116, 90, '1–2 min'];
        yield 'tiny, seconds' => [32, 58, 45, '30s – 1min'];
        yield 'tiny, minutes and seconds' => [68, 132, 100, '1min 10s – 2min 10s'];
    }

    #[DataProvider('ranges')]
    public function testRange(int $rawLow, int $rawHigh, int $centre, string $expected): void
    {
        self::assertSame($expected, self::formatter()->range(PredictionWindow::rounded($rawLow, $rawHigh, $centre)));
    }

    /**
     * The 50 % window and the 80 % range shown together share one style (the sheet's "1h – 1h 9min" next to
     * "57–74 min", docs/features/insights/estimates.md)
     */
    public function testTwoWindowsShownTogetherShareOneStyle(): void
    {
        $formatter = self::formatter();

        // Apart: one in hours, one in minutes
        $window = PredictionWindow::rounded(3600, 4140, 3800);
        $range80 = PredictionWindow::rounded(3420, 4440, 3800);
        self::assertSame('1h – 1h 9min', $formatter->range($window));
        self::assertSame('57–74 min', $formatter->range($range80));

        // Together: both in hours
        self::assertSame(['1h – 1h 9min', '57min – 1h 14min'], $formatter->ranges($window, $range80));

        // Both under an hour and 90 min: both in minutes
        self::assertSame(['57–64 min', '52–70 min'], $formatter->ranges(PredictionWindow::rounded(3440, 3820, 3600), PredictionWindow::rounded(3120, 4200, 3600)));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function margins(): iterable
    {
        yield 'seconds' => [20, '20s'];
        yield 'either sign' => [-20, '20s'];
        yield 'rounds to the minute' => [89, '1 min'];
        yield 'halfway rounds up' => [90, '2 min'];
        yield 'minutes' => [-180, '3 min'];
        yield 'rounding into the hour' => [3570, '1h'];
        yield 'hours' => [3900, '1h 5min'];
    }

    #[DataProvider('margins')]
    public function testMargin(int $seconds, string $expected): void
    {
        self::assertSame($expected, self::formatter()->margin($seconds));
    }

    private static function formatter(): PredictionWindowFormatter
    {
        return new PredictionWindowFormatter(new PuzzlingTimeFormatter());
    }
}
