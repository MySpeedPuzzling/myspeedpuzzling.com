<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Results\RoundStopwatchState;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * The stopwatch payload (RoundStopwatchState): the status at the moment the row was read, the moments in milliseconds,
 * and RoundClock's hash of the stored state - one hash for the stopwatch pages and the online participants' heartbeats.
 */
final class RoundStopwatchStateTest extends TestCase
{
    private const string ROUND = '018d0005-0000-0000-0000-000000000001';

    public function testAScheduledStartIsScheduledUntilItsMomentAndTheHashStaysTheSame(): void
    {
        $startsAt = new DateTimeImmutable('2026-11-10 18:00:10');
        $before = self::state('scheduled', $startsAt, new DateTimeImmutable('2026-11-10 18:00:09.999'));
        $after = self::state('scheduled', $startsAt, new DateTimeImmutable('2026-11-10 18:00:10.000'));

        self::assertSame('scheduled', $before->jsonSerialize()['status']);
        self::assertSame('running', $after->jsonSerialize()['status']);
        self::assertSame($before->jsonSerialize()['stateHash'], $after->jsonSerialize()['stateHash'], 'nothing stored changed when the moment passed');
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

    /**
     * One formula: the stopwatch payload's hash is the online clock's, for the same stored state
     */
    public function testTheHashIsTheRoundClocksHash(): void
    {
        $asOf = new DateTimeImmutable('2026-11-10 18:10:00');
        $round = self::round('running', new DateTimeImmutable('2026-11-10 18:00:00'));

        $state = RoundStopwatchState::ofRound($round, $asOf);

        self::assertSame(RoundClock::fromRound($round, $asOf)->stateHash(), $state->jsonSerialize()['stateHash']);
        self::assertSame(substr(sha1('["running",1794333600000,null,60]'), 0, 12), $state->jsonSerialize()['stateHash']);
        self::assertSame(substr(sha1('[null,null,null,60]'), 0, 12), self::state(null, null, $asOf)->jsonSerialize()['stateHash']);
    }

    public function testEveryStoredChangeChangesTheHash(): void
    {
        $at = new DateTimeImmutable('2026-11-10 18:00:00');
        $hashes = [
            self::state(null, null, $at)->jsonSerialize()['stateHash'],
            self::state('scheduled', $at->modify('+10 seconds'), $at)->jsonSerialize()['stateHash'],
            self::state('scheduled', $at->modify('+11 seconds'), $at)->jsonSerialize()['stateHash'],
            self::state('running', $at->modify('+10 seconds'), $at)->jsonSerialize()['stateHash'],
            self::state('running', $at->modify('+10 seconds'), $at, minutesLimit: 90)->jsonSerialize()['stateHash'],
        ];

        self::assertSame($hashes, array_values(array_unique($hashes)));
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $hashes[0]);
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

    private static function round(null|string $status, null|DateTimeImmutable $startedAt): CompetitionRound
    {
        $competition = new Competition(
            id: Uuid::uuid7(),
            name: 'Online Test Cup',
            slug: null,
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: null,
            locationCountryCode: null,
            dateFrom: null,
            dateTo: null,
            tag: null,
        );

        return new CompetitionRound(
            id: Uuid::fromString(self::ROUND),
            competition: $competition,
            name: 'Final',
            minutesLimit: 60,
            startsAt: new DateTimeImmutable('2026-11-10 18:00:00'),
            stopwatchStartedAt: $startedAt,
            stopwatchStatus: $status,
        );
    }
}
