<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\RoundStopwatchState;

/**
 * The stopwatch payload (RoundStopwatchState): the status at the moment the row was read, the moments in milliseconds,
 * and a hash of the stored state only.
 */
final class RoundStopwatchStateTest extends TestCase
{
    private const string ROUND = '018d0005-0000-0000-0000-000000000001';

    public function testAScheduledStartIsScheduledUntilItsMomentAndTheHashStaysTheSame(): void
    {
        $startsAt = new DateTimeImmutable('2026-11-10 18:00:10');
        $before = self::state('scheduled', $startsAt, new DateTimeImmutable('2026-11-10 18:00:00.250'));
        $after = self::state('scheduled', $startsAt, new DateTimeImmutable('2026-11-10 18:00:10.000'));

        self::assertSame('scheduled', $before->jsonSerialize()['status']);
        self::assertTrue($before->isCountingDown());
        self::assertSame('running', $after->jsonSerialize()['status']);
        self::assertFalse($after->isCountingDown());

        self::assertSame($before->stateHash(), $after->stateHash(), 'nothing stored changed when the moment passed');
    }

    public function testTheMomentsInMilliseconds(): void
    {
        $state = new RoundStopwatchState(
            roundId: self::ROUND,
            storedStatus: 'stopped',
            startedAt: new DateTimeImmutable('2026-11-10 18:00:00+00:00'),
            stoppedAt: new DateTimeImmutable('2026-11-10 18:45:30+00:00'),
            minutesLimit: 60,
            asOf: new DateTimeImmutable('2026-11-10 19:00:00.123+00:00'),
        );

        $json = $state->jsonSerialize();

        self::assertSame(1_794_333_600_000, $json['startedAtMs']);
        self::assertSame(1_794_333_600_000 + 2_730_000, $json['stoppedAtMs']);
        self::assertSame(1_794_337_200_123, $json['asOfMs']);
        self::assertSame('2026-11-10T18:00:00+00:00', $json['startedAt']);
        self::assertSame('stopped', $json['status']);
    }

    public function testEveryStoredChangeChangesTheHash(): void
    {
        $at = new DateTimeImmutable('2026-11-10 18:00:00');
        $hashes = [
            self::state(null, null, $at)->stateHash(),
            self::state('scheduled', $at->modify('+10 seconds'), $at)->stateHash(),
            self::state('scheduled', $at->modify('+11 seconds'), $at)->stateHash(),
            self::state('running', $at->modify('+10 seconds'), $at)->stateHash(),
            self::state('running', $at->modify('+10 seconds'), $at, minutesLimit: 90)->stateHash(),
        ];

        self::assertSame($hashes, array_values(array_unique($hashes)));
        self::assertSame(16, strlen($hashes[0]));
    }

    private static function state(null|string $status, null|DateTimeImmutable $startedAt, DateTimeImmutable $asOf, int $minutesLimit = 60): RoundStopwatchState
    {
        return new RoundStopwatchState(
            roundId: self::ROUND,
            storedStatus: $status,
            startedAt: $startedAt,
            stoppedAt: null,
            minutesLimit: $minutesLimit,
            asOf: $asOf,
        );
    }
}
