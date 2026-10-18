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
