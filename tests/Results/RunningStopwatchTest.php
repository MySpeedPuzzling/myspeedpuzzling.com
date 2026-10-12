<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\RunningStopwatch;

/**
 * The running-stopwatch pill's state (docs/features/navigation README D7, ADD-03): fresh = the running lap started less
 * than 24 hours ago, decided here with the clock; the elapsed time and its two formats.
 */
final class RunningStopwatchTest extends TestCase
{
    private const string ID = '018d000d-0000-0000-0000-00000000aa01';

    private const string NOW = '2026-10-12 12:00:00';

    public function testAFreshRunningStopwatch(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $stopwatch = RunningStopwatch::fromJson(self::json('2026-10-12 11:50:00', 900), $now);

        self::assertNotNull($stopwatch);
        self::assertSame(self::ID, $stopwatch->stopwatchId);
        self::assertSame('2026-10-12 11:50:00', $stopwatch->lapStartedAt->format('Y-m-d H:i:s'));
        self::assertSame(900, $stopwatch->closedSeconds);
        self::assertSame($now, $stopwatch->readAt);
        // 15 closed minutes + 10 running
        self::assertSame(1500, $stopwatch->elapsedSeconds($now));
        self::assertSame(1560, $stopwatch->elapsedSeconds($now->modify('+1 minute')));
        self::assertSame('0:25:00', $stopwatch->textAtRead(false));
        self::assertSame('25:00', $stopwatch->textAtRead(true));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideLapStarts(): iterable
    {
        yield '10 minutes ago' => ['2026-10-12 11:50:00', true];
        yield '23 hours 59 minutes ago' => ['2026-10-11 12:01:00', true];
        yield 'one second short of 24 hours' => ['2026-10-11 12:00:01', true];
        yield 'exactly 24 hours ago' => ['2026-10-11 12:00:00', false];
        yield '24 hours 1 minute ago' => ['2026-10-11 11:59:00', false];
        yield 'a month ago' => ['2026-09-12 12:00:00', false];
        // A clock running behind the server's: still running, counted from 0
        yield 'in the future' => ['2026-10-12 12:00:30', true];
    }

    #[DataProvider('provideLapStarts')]
    public function testFreshForLessThan24Hours(string $lapStartedAt, bool $fresh): void
    {
        $stopwatch = RunningStopwatch::fromJson(self::json($lapStartedAt, 0), new DateTimeImmutable(self::NOW));

        self::assertSame($fresh, $stopwatch !== null);
    }

    public function testALapInTheFutureCountsFromZero(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $stopwatch = RunningStopwatch::fromJson(self::json('2026-10-12 12:00:30', 60), $now);

        self::assertNotNull($stopwatch);
        self::assertSame(60, $stopwatch->elapsedSeconds($now));
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function provideInvalidRows(): iterable
    {
        yield 'no running stopwatch' => [null];
        yield 'not JSON' => ['{"id":'];
        yield 'not an object' => ['"running"'];
        yield 'no id' => ['{"lap_started_at": "2026-10-12 11:50:00", "closed_seconds": 0}'];
        yield 'not a uuid' => ['{"id": "abc", "lap_started_at": "2026-10-12 11:50:00", "closed_seconds": 0}'];
        yield 'no lap start (no laps)' => ['{"id": "' . self::ID . '", "lap_started_at": null, "closed_seconds": 0}'];
        yield 'another date format' => ['{"id": "' . self::ID . '", "lap_started_at": "2026-10-12T11:50:00+00:00", "closed_seconds": 0}'];
        yield 'an impossible date' => ['{"id": "' . self::ID . '", "lap_started_at": "2026-10-12 25:61:00", "closed_seconds": 0}'];
        yield 'closed seconds as text' => ['{"id": "' . self::ID . '", "lap_started_at": "2026-10-12 11:50:00", "closed_seconds": "900"}'];
    }

    #[DataProvider('provideInvalidRows')]
    public function testNothingFromAnInvalidRow(null|string $json): void
    {
        self::assertNull(RunningStopwatch::fromJson($json, new DateTimeImmutable(self::NOW)));
    }

    public function testClosedSecondsAsPostgresWritesThem(): void
    {
        // EXTRACT(EPOCH FROM interval) is numeric: json_build_object() writes 1800.000000
        $json = '{"id": "' . self::ID . '", "lap_started_at": "2026-10-12 11:50:00", "closed_seconds": 1800.000000}';
        $stopwatch = RunningStopwatch::fromJson($json, new DateTimeImmutable(self::NOW));

        self::assertNotNull($stopwatch);
        self::assertSame(1800, $stopwatch->closedSeconds);
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function provideFormats(): iterable
    {
        yield 'zero' => [0, '0:00:00', '0:00'];
        yield 'one second' => [1, '0:00:01', '0:01'];
        yield 'a minute' => [60, '0:01:00', '1:00'];
        yield 'last short m:ss' => [599, '0:09:59', '9:59'];
        yield 'ten minutes' => [600, '0:10:00', '10:00'];
        yield 'an hour less a second' => [3599, '0:59:59', '59:59'];
        yield 'an hour - minutes go on' => [3600, '1:00:00', '60:00'];
        yield '99:59' => [5999, '1:39:59', '99:59'];
        yield '100:00' => [6000, '1:40:00', '100:00'];
        yield '999:59' => [59999, '16:39:59', '999:59'];
        yield '1000:00' => [60000, '16:40:00', '1000:00'];
        yield 'a day less a second' => [86399, '23:59:59', '1439:59'];
        yield 'hours uncapped' => [360000, '100:00:00', '6000:00'];
        yield 'never negative' => [-5, '0:00:00', '0:00'];
    }

    #[DataProvider('provideFormats')]
    public function testFormats(int $seconds, string $full, string $compact): void
    {
        self::assertSame($full, RunningStopwatch::format($seconds, false));
        self::assertSame($compact, RunningStopwatch::format($seconds, true));
    }

    public function testTheCompactIconOnlyNextToAShortTime(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        // 99:59 at render: the icon shows below 360 px (and stays when it turns 100:00 - decided once)
        self::assertTrue(RunningStopwatch::fromJson(self::json('2026-10-12 10:20:01', 0), $now)?->showsCompactIcon());
        // 100:00 at render: no icon, so the pill stays within 70 px
        self::assertFalse(RunningStopwatch::fromJson(self::json('2026-10-12 10:20:00', 0), $now)?->showsCompactIcon());
        // Closed laps count too
        self::assertFalse(RunningStopwatch::fromJson(self::json('2026-10-12 11:59:00', 6000), $now)?->showsCompactIcon());
    }

    public function testTheRoomIsTheTextPlusOneDigit(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $short = RunningStopwatch::fromJson(self::json('2026-10-12 11:50:03', 0), $now);
        self::assertNotNull($short);
        self::assertSame('9:57', $short->textAtRead(true));
        self::assertSame('00:00', $short->roomAtRead(true));
        self::assertSame('0:09:57', $short->textAtRead(false));
        self::assertSame('00:00:00', $short->roomAtRead(false));

        $long = RunningStopwatch::fromJson(self::json('2026-10-12 11:00:00', 82800), $now);
        self::assertNotNull($long);
        self::assertSame('1440:00', $long->textAtRead(true));
        self::assertSame('00000:00', $long->roomAtRead(true));
        self::assertSame('24:00:00', $long->textAtRead(false));
        self::assertSame('000:00:00', $long->roomAtRead(false));
    }

    private static function json(string $lapStartedAt, int $closedSeconds): string
    {
        return (string) json_encode(['id' => self::ID, 'lap_started_at' => $lapStartedAt, 'closed_seconds' => $closedSeconds]);
    }
}
