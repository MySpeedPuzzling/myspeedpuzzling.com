<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\RoundStopwatchStatus;

/**
 * THE rule of a round stopwatch's status at a moment (RoundStopwatchStatus): a start ahead is the countdown, a start
 * that has come runs - whatever was stored, compared in whole seconds - and RoundClock (the online reading) decides
 * "counting down or running" through it, never on its own.
 */
final class RoundStopwatchStatusTest extends TestCase
{
    private const string START = '2026-11-10 18:00:00';

    /**
     * @return iterable<string, array{null|string, null|string, string, null|string}>
     */
    public static function statuses(): iterable
    {
        yield 'never started' => [null, null, '2026-11-10 17:00:00', null];
        yield 'scheduled, a second before' => ['scheduled', self::START, '2026-11-10 17:59:59.999', 'scheduled'];
        yield 'scheduled, at the moment' => ['scheduled', self::START, self::START, 'running'];
        yield 'scheduled, an hour later' => ['scheduled', self::START, '2026-11-10 19:00:00', 'running'];
        yield 'running written ahead of its start' => ['running', self::START, '2026-11-10 17:59:59', 'scheduled'];
        yield 'running' => ['running', self::START, '2026-11-10 18:00:00.001', 'running'];
        yield 'stopped' => ['stopped', self::START, '2026-11-10 18:30:00', 'stopped'];
        yield 'scheduled without its moment' => ['scheduled', null, self::START, 'scheduled'];
        yield 'a start without a status' => [null, self::START, '2026-11-10 18:10:00', null];
    }

    #[DataProvider('statuses')]
    public function testTheStatusAtAMoment(null|string $stored, null|string $startedAt, string $now, null|string $expected): void
    {
        self::assertSame($expected, RoundStopwatchStatus::at($stored, self::moment($startedAt), new DateTimeImmutable($now)));
    }

    public function testAFractionOfTheStartCountsLikeTheColumnKeepsIt(): void
    {
        // The column keeps whole seconds: 18:00:00.750 is 18:00:00
        self::assertSame('running', RoundStopwatchStatus::at('scheduled', new DateTimeImmutable('2026-11-10 18:00:00.750'), new DateTimeImmutable('2026-11-10 18:00:00.500')));
    }

    public function testStartedMeansRunningOrStopped(): void
    {
        $now = new DateTimeImmutable('2026-11-10 18:10:00');

        self::assertFalse(RoundStopwatchStatus::hasStarted(null, null, $now));
        self::assertFalse(RoundStopwatchStatus::hasStarted('scheduled', new DateTimeImmutable('2026-11-10 18:10:01'), $now));
        self::assertTrue(RoundStopwatchStatus::hasStarted('scheduled', new DateTimeImmutable('2026-11-10 18:10:00'), $now));
        self::assertTrue(RoundStopwatchStatus::hasStarted('running', new DateTimeImmutable(self::START), $now));
        self::assertTrue(RoundStopwatchStatus::hasStarted('stopped', new DateTimeImmutable(self::START), $now));
        self::assertFalse(RoundStopwatchStatus::hasStarted(null, new DateTimeImmutable(self::START), $now), 'a start without a status is nothing');
    }

    /**
     * RoundClock counts down exactly when the rule does, and runs exactly when it does - before its time limit
     */
    #[DataProvider('statuses')]
    public function testTheOnlineClockAgreesWithTheRule(null|string $stored, null|string $startedAt, string $now, null|string $expected): void
    {
        $clock = RoundClock::fromStopwatch($stored, self::moment($startedAt), null, 600, new DateTimeImmutable($now));

        $expectedClock = match (true) {
            $startedAt === null => RoundClock::WAITING,
            $expected === 'scheduled' => RoundClock::SCHEDULED,
            $expected === 'running' => RoundClock::RUNNING,
            $expected === 'stopped' => RoundClock::ENDED,
            default => RoundClock::WAITING,
        };

        self::assertSame($expectedClock, $clock->status);
    }

    public function testTheOnlineClockFromTheColumnsIsTheClockOfTheRound(): void
    {
        $startedAt = new DateTimeImmutable(self::START);
        $now = new DateTimeImmutable('2026-11-10 18:20:00');

        $clock = RoundClock::fromStopwatch('stopped', $startedAt, new DateTimeImmutable('2026-11-10 18:15:00'), 60, $now);

        self::assertSame(RoundClock::ENDED, $clock->status);
        self::assertSame(1_794_333_600_000, $clock->startedAtMs);
        self::assertSame(1_794_333_600_000 + 900_000, $clock->endsAtMs);
        self::assertSame(substr(sha1('["stopped",1794333600000,1794334500000,60]'), 0, 12), $clock->stateHash());
    }

    private static function moment(null|string $moment): null|DateTimeImmutable
    {
        return $moment === null ? null : new DateTimeImmutable($moment);
    }
}
