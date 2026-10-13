<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Value\RoundStopwatchRefusal;
use SpeedPuzzling\Web\Value\ScheduledRoundStart;

/**
 * The moment a scheduled start asks for (ScheduledRoundStart): always a whole second as its UTC moment - "Start in
 * 10 s" the next whole second at least that far ahead, "Start at" a moment with its offset or a wall time of the
 * round's zone that exists there.
 */
final class ScheduledRoundStartTest extends TestCase
{
    public function testACountdownStartsOnTheNextWholeSecondAtLeastThatFarAhead(): void
    {
        self::assertSame('2026-11-10T18:00:11+00:00', ScheduledRoundStart::inSeconds('10', new DateTimeImmutable('2026-11-10T18:00:00.250Z'))->format(DATE_ATOM));
        self::assertSame('2026-11-10T18:00:10+00:00', ScheduledRoundStart::inSeconds('10', new DateTimeImmutable('2026-11-10T18:00:00.000Z'))->format(DATE_ATOM));
        self::assertSame('2026-11-10T19:00:00+00:00', ScheduledRoundStart::inSeconds('3600', new DateTimeImmutable('2026-11-10T18:00:00Z'))->format(DATE_ATOM));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCountdowns(): iterable
    {
        yield 'zero' => ['0'];
        yield 'more than an hour' => ['3601'];
        yield 'negative' => ['-10'];
        yield 'not a number' => ['ten'];
        yield 'a fraction' => ['9.5'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidCountdowns')]
    public function testACountdownIsASecondToAnHour(string $seconds): void
    {
        $this->expectRefusal(RoundStopwatchRefusal::InvalidStart);

        ScheduledRoundStart::inSeconds($seconds, new DateTimeImmutable('2026-11-10T18:00:00Z'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function moments(): iterable
    {
        yield 'an offset' => ['2026-11-10T19:00:00+01:00', 'Europe/Prague', '2026-11-10T18:00:00+00:00'];
        yield 'an offset without a colon' => ['2026-11-10T19:00:00+0100', 'Europe/Prague', '2026-11-10T18:00:00+00:00'];
        yield 'UTC' => ['2026-11-10T18:00:00Z', 'America/Toronto', '2026-11-10T18:00:00+00:00'];
        yield 'an offset without seconds' => ['2026-11-10T13:00-05:00', 'Europe/Prague', '2026-11-10T18:00:00+00:00'];
        yield 'JavaScript\'s toISOString, a fraction - the next whole second' => ['2026-11-10T18:00:00.001Z', 'Europe/Prague', '2026-11-10T18:00:01+00:00'];
        yield 'JavaScript\'s toISOString, no fraction' => ['2026-11-10T18:00:00.000Z', 'Europe/Prague', '2026-11-10T18:00:00+00:00'];
        yield 'a wall time of the round\'s zone' => ['2026-11-10T19:00', 'Europe/Prague', '2026-11-10T18:00:00+00:00'];
        yield 'a wall time with seconds' => ['2026-11-10T13:00:30', 'America/Toronto', '2026-11-10T18:00:30+00:00'];
        yield 'surrounding spaces' => [' 2026-11-10T19:00 ', 'Europe/Prague', '2026-11-10T18:00:00+00:00'];
    }

    #[DataProvider('moments')]
    public function testStartAt(string $value, string $timezone, string $expected): void
    {
        $startsAt = ScheduledRoundStart::at($value, $timezone);

        self::assertSame($expected, $startsAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM));
        self::assertSame('000000', $startsAt->format('u'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function noMoments(): iterable
    {
        yield 'words' => ['tomorrow at seven', 'Europe/Prague'];
        yield 'empty' => ['', 'Europe/Prague'];
        yield 'a date only' => ['2026-11-10', 'Europe/Prague'];
        yield 'a day that does not exist' => ['2026-02-31T19:00', 'Europe/Prague'];
        yield 'a day that does not exist, with an offset' => ['2026-02-31T19:00:00+01:00', 'Europe/Prague'];
        yield 'skipped by the clocks going forward' => ['2026-03-29T02:30', 'Europe/Prague'];
        yield 'repeated by the clocks going back' => ['2026-10-25T02:30', 'Europe/Prague'];
        yield 'a time of day only' => ['19:00', 'Europe/Prague'];
    }

    #[DataProvider('noMoments')]
    public function testWhatIsNoMomentIsRefused(string $value, string $timezone): void
    {
        $this->expectRefusal(RoundStopwatchRefusal::InvalidStart);

        ScheduledRoundStart::at($value, $timezone);
    }

    private function expectRefusal(RoundStopwatchRefusal $reason): void
    {
        $this->expectException(RoundStopwatchRefused::class);
        $this->expectExceptionMessage((new RoundStopwatchRefused($reason))->getMessage());
    }
}
