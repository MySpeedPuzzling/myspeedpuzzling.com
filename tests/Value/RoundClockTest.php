<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * The online rounds' reading of the round stopwatch (docs/features/online-competitions/README.md §5.1) - every status
 * and its edges, in milliseconds.
 */
final class RoundClockTest extends TestCase
{
    private const string START = '2026-11-10 18:00:00';
    private const int START_MS = 1_794_333_600_000;

    public function testNoStartIsWaiting(): void
    {
        $clock = RoundClock::fromRound(self::round(), self::at('2026-11-10 17:00:00'));

        self::assertSame(RoundClock::WAITING, $clock->status);
        self::assertTrue($clock->isWaiting());
        self::assertFalse($clock->isLive());
        self::assertFalse($clock->hasEnded());
        self::assertNull($clock->startedAtMs);
        self::assertNull($clock->stoppedAtMs);
        self::assertNull($clock->endsAtMs);
        self::assertSame(60, $clock->minutesLimit);
    }

    public function testAStoppedStatusWithoutAStartIsStillWaiting(): void
    {
        $round = self::round();
        $round->stopwatchStatus = 'stopped';

        self::assertSame(RoundClock::WAITING, RoundClock::fromRound($round, self::at('2026-11-10 17:00:00'))->status);
    }

    public function testAStartInTheFutureIsScheduled(): void
    {
        $round = self::round(status: 'scheduled', startedAt: self::START);
        $clock = RoundClock::fromRound($round, self::at('2026-11-10 17:59:59.999'));

        self::assertSame(RoundClock::SCHEDULED, $clock->status);
        self::assertTrue($clock->isLive());
        self::assertSame(self::START_MS, $clock->startedAtMs);
        self::assertSame(self::START_MS + 3_600_000, $clock->endsAtMs);

        // A reader that sets `running` with a future start is a countdown too - never a negative time
        self::assertSame(RoundClock::SCHEDULED, RoundClock::fromRound(self::round(status: 'running', startedAt: self::START), self::at('2026-11-10 17:59:50'))->status);
    }

    public function testTheStartMomentRunsWhateverTheStoredStatus(): void
    {
        self::assertSame(RoundClock::RUNNING, RoundClock::fromRound(self::round(status: 'scheduled', startedAt: self::START), self::at(self::START))->status);
        self::assertSame(RoundClock::RUNNING, RoundClock::fromRound(self::round(status: 'running', startedAt: self::START), self::at(self::START))->status);
        self::assertTrue(RoundClock::fromRound(self::round(status: 'running', startedAt: self::START), self::at(self::START))->isLive());
    }

    public function testTheTimeLimitEndsTheRound(): void
    {
        $round = self::round(status: 'running', startedAt: self::START);

        self::assertSame(RoundClock::RUNNING, RoundClock::fromRound($round, self::at('2026-11-10 18:59:59.999'))->status);

        $ended = RoundClock::fromRound($round, self::at('2026-11-10 19:00:00'));
        self::assertSame(RoundClock::ENDED, $ended->status);
        self::assertTrue($ended->hasEnded());
        self::assertFalse($ended->isLive());
        self::assertSame(self::START_MS + 3_600_000, $ended->endsAtMs);
        self::assertNull($ended->stoppedAtMs);
    }

    public function testStoppingEndsTheRoundAtTheStop(): void
    {
        $round = self::round(status: 'stopped', startedAt: self::START, stoppedAt: '2026-11-10 18:40:00');
        $clock = RoundClock::fromRound($round, self::at('2026-11-10 18:40:01'));

        self::assertSame(RoundClock::ENDED, $clock->status);
        self::assertSame(self::START_MS + 2_400_000, $clock->stoppedAtMs);
        self::assertSame(self::START_MS + 2_400_000, $clock->endsAtMs);

        // Stopped after the time limit: the limit was the end
        $late = RoundClock::fromRound(self::round(status: 'stopped', startedAt: self::START, stoppedAt: '2026-11-10 19:30:00'), self::at('2026-11-10 19:30:00'));
        self::assertSame(self::START_MS + 3_600_000, $late->endsAtMs);
    }

    public function testMomentsAreWholeSecondsLikeTheColumns(): void
    {
        $round = self::round(status: 'running', startedAt: '2026-11-10 18:00:00.750');

        self::assertSame(self::START_MS, RoundClock::fromRound($round, self::at('2026-11-10 18:10:00'))->startedAtMs);
        self::assertSame(self::START_MS + 123, RoundClock::milliseconds(self::at('2026-11-10 18:00:00.123')));
    }

    /**
     * The countdown reaching zero and the time limit running out are no writes - every phone's heartbeat at the start
     * moment must not make all of them fetch the state in the same second
     */
    public function testTheStateHashChangesOnlyWithAWrite(): void
    {
        $round = self::round(status: 'scheduled', startedAt: self::START);
        $hash = RoundClock::fromRound($round, self::at('2026-11-10 17:59:50'))->stateHash();

        self::assertSame($hash, RoundClock::fromRound($round, self::at(self::START))->stateHash(), 'the start moment');
        self::assertSame($hash, RoundClock::fromRound($round, self::at('2026-11-10 18:30:00'))->stateHash(), 'time passing while it runs');
        self::assertSame($hash, RoundClock::fromRound($round, self::at('2026-11-10 19:00:00'))->stateHash(), 'the time limit running out');
        self::assertSame($hash, RoundClock::fromRound(self::round(status: 'scheduled', startedAt: self::START, roundId: '018d0050-0000-0000-0000-00000000aaaa'), self::at(self::START))->stateHash(), 'another round in the same stored state');
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $hash);
    }

    /**
     * The one formula of a round's stopwatch state hash - every place that publishes one uses it (RoundClock::stateHash())
     */
    public function testTheStateHashIsTheSharedFormula(): void
    {
        self::assertSame(
            substr(sha1('["running",1794333600000,null,60]'), 0, 12),
            RoundClock::fromRound(self::round(status: 'running', startedAt: self::START), self::at('2026-11-10 18:10:00'))->stateHash(),
        );
        self::assertSame(
            substr(sha1('["stopped",1794333600000,1794335400000,90]'), 0, 12),
            RoundClock::fromRound(self::round(status: 'stopped', startedAt: self::START, stoppedAt: '2026-11-10 18:30:00', minutesLimit: 90), self::at('2026-11-10 18:31:00'))->stateHash(),
        );
        self::assertSame(substr(sha1('[null,null,null,60]'), 0, 12), RoundClock::fromRound(self::round(), self::at(self::START))->stateHash());
    }

    public function testEveryWriteChangesTheStateHash(): void
    {
        $hashes = [
            'not started' => RoundClock::fromRound(self::round(), self::at('2026-11-10 17:00:00'))->stateHash(),
            'scheduled' => RoundClock::fromRound(self::round(status: 'scheduled', startedAt: self::START), self::at('2026-11-10 17:59:50'))->stateHash(),
            'started' => RoundClock::fromRound(self::round(status: 'running', startedAt: self::START), self::at('2026-11-10 18:05:00'))->stateHash(),
            'another start' => RoundClock::fromRound(self::round(status: 'running', startedAt: '2026-11-10 18:00:01'), self::at('2026-11-10 18:05:00'))->stateHash(),
            'stopped' => RoundClock::fromRound(self::round(status: 'stopped', startedAt: self::START, stoppedAt: '2026-11-10 18:30:00'), self::at('2026-11-10 18:31:00'))->stateHash(),
            'stopped later' => RoundClock::fromRound(self::round(status: 'stopped', startedAt: self::START, stoppedAt: '2026-11-10 18:31:00'), self::at('2026-11-10 18:32:00'))->stateHash(),
            'another time limit' => RoundClock::fromRound(self::round(status: 'running', startedAt: self::START, minutesLimit: 90), self::at('2026-11-10 18:05:00'))->stateHash(),
        ];

        self::assertCount(count($hashes), array_unique($hashes), 'every stored state has its own hash: ' . json_encode($hashes));
    }

    public function testToArrayCarriesEveryClockField(): void
    {
        $clock = RoundClock::fromRound(self::round(status: 'running', startedAt: self::START), self::at('2026-11-10 18:10:00'));

        self::assertSame([
            'status' => 'running',
            'startedAtMs' => self::START_MS,
            'stoppedAtMs' => null,
            'endsAtMs' => self::START_MS + 3_600_000,
            'minutesLimit' => 60,
            'stateHash' => $clock->stateHash(),
        ], $clock->toArray());
    }

    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }

    private static function round(
        null|string $status = null,
        null|string $startedAt = null,
        null|string $stoppedAt = null,
        int $minutesLimit = 60,
        string $roundId = '018d0050-0000-0000-0000-00000000bbbb',
    ): CompetitionRound {
        $competition = new Competition(
            id: Uuid::fromString('018d0050-0000-0000-0000-00000000cccc'),
            name: 'Clock Cup',
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
            isOnline: true,
        );

        return new CompetitionRound(
            id: Uuid::fromString($roundId),
            competition: $competition,
            name: 'Round',
            minutesLimit: $minutesLimit,
            startsAt: self::at(self::START),
            stopwatchStartedAt: $startedAt === null ? null : self::at($startedAt),
            stopwatchStatus: $status,
            stopwatchStoppedAt: $stoppedAt === null ? null : self::at($stoppedAt),
        );
    }
}
