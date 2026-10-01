<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;

final class PuzzlingTimeFormatterTest extends TestCase
{
    #[DataProvider('provideGaps')]
    public function testGapTime(int $seconds, string $expected): void
    {
        self::assertSame($expected, (new PuzzlingTimeFormatter())->gapTime($seconds));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function provideGaps(): iterable
    {
        yield 'seconds only' => [7, '+00:07'];
        yield 'minutes' => [725, '+12:05'];
        yield 'hours' => [3725, '+01:02:05'];
        yield 'three-digit hours' => [652738, '+181:18:58'];
    }
}
