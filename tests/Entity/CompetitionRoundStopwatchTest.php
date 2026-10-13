<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Value\RoundStopwatchRefusal;
use SpeedPuzzling\Web\Value\RoundStopwatchStatus;

/**
 * The round stopwatch on CompetitionRound (docs/features/competitions-management/README.md "Round Stopwatch"): a start
 * in the future is the countdown (`scheduled`), it counts as running once its moment has passed, a cancel calls it off
 * only before that; start / stop / reset keep their in-person meaning (stop = pause, start = resume).
 */
final class CompetitionRoundStopwatchTest extends TestCase
{
    private const string NOW = '2026-11-10 18:00:00.250';

    public function testAStartIsScheduledOnAWholeSecondAhead(): void
    {
        $round = self::round();
        $round->stopStopwatch(self::at('-1 hour'));

        $round->scheduleStopwatchStart(self::at('+10 seconds', whole: true), self::at());

        self::assertSame(RoundStopwatchStatus::SCHEDULED, $round->stopwatchStatus);
        self::assertEquals(self::at('+10 seconds', whole: true), $round->stopwatchStartedAt);
        self::assertNull($round->stopwatchStoppedAt);
    }

    public function testAScheduledStartIsNotRunningBeforeItsMomentAndRunningFromIt(): void
    {
        $round = self::round();
        $startsAt = self::at('+10 seconds', whole: true);
        $round->scheduleStopwatchStart($startsAt, self::at());

        self::assertSame(RoundStopwatchStatus::SCHEDULED, $round->stopwatchStatusAt(self::at()));
        self::assertSame(RoundStopwatchStatus::SCHEDULED, $round->stopwatchStatusAt($startsAt->modify('-1 second')));
        self::assertSame(RoundStopwatchStatus::RUNNING, $round->stopwatchStatusAt($startsAt));
        self::assertSame(RoundStopwatchStatus::RUNNING, $round->stopwatchStatusAt($startsAt->modify('+1 hour')));
        // Nothing was written at that moment
        self::assertSame(RoundStopwatchStatus::SCHEDULED, $round->stopwatchStatus);
    }

    public function testAStartInThePastIsRefused(): void
    {
        $round = self::round();

        $this->assertRefused(RoundStopwatchRefusal::StartInThePast, static fn () => $round->scheduleStopwatchStart(self::at('-1 second', whole: true), self::at()));

        self::assertNull($round->stopwatchStatus);
        self::assertNull($round->stopwatchStartedAt);
    }

    public function testAStartIsAlwaysOnAWholeSecond(): void
    {
        $round = self::round();

        $this->expectException(\InvalidArgumentException::class);
        $round->scheduleStopwatchStart(self::at('+10 seconds'), self::at());
    }

    public function testACountdownMayBeMovedButARunningOrStoppedClockIsResetFirst(): void
    {
        $round = self::round();
        $round->scheduleStopwatchStart(self::at('+1 hour', whole: true), self::at());

        // Moved while it counts down
        $round->scheduleStopwatchStart(self::at('+10 seconds', whole: true), self::at());
        self::assertEquals(self::at('+10 seconds', whole: true), $round->stopwatchStartedAt);

        // Its moment has passed - it runs
        $this->assertRefused(RoundStopwatchRefusal::AlreadyStarted, static fn () => $round->scheduleStopwatchStart(self::at('+1 hour', whole: true), self::at('+11 seconds')));

        $running = self::round();
        $running->startStopwatch(self::at());
        $this->assertRefused(RoundStopwatchRefusal::AlreadyStarted, static fn () => $running->scheduleStopwatchStart(self::at('+1 hour', whole: true), self::at()));

        $running->stopStopwatch(self::at('+5 minutes'));
        $this->assertRefused(RoundStopwatchRefusal::AlreadyStarted, static fn () => $running->scheduleStopwatchStart(self::at('+1 hour', whole: true), self::at('+6 minutes')));

        $running->resetStopwatch();
        $running->scheduleStopwatchStart(self::at('+1 hour', whole: true), self::at('+6 minutes'));
        self::assertSame(RoundStopwatchStatus::SCHEDULED, $running->stopwatchStatus);
    }

    public function testACountdownIsCancelledBeforeItsMomentOnly(): void
    {
        $round = self::round();
        $round->scheduleStopwatchStart(self::at('+10 seconds', whole: true), self::at());

        $round->cancelScheduledStart(self::at('+9 seconds'));

        self::assertNull($round->stopwatchStatus);
        self::assertNull($round->stopwatchStartedAt);
        self::assertNull($round->stopwatchStoppedAt);

        // Nothing scheduled any more - a second tap changes nothing
        $round->cancelScheduledStart(self::at('+9 seconds'));
        self::assertNull($round->stopwatchStatus);
    }

    public function testACancelArrivingAfterTheStartNeverResetsTheRunningRound(): void
    {
        $round = self::round();
        $startsAt = self::at('+10 seconds', whole: true);
        $round->scheduleStopwatchStart($startsAt, self::at());

        $this->assertRefused(RoundStopwatchRefusal::TooLateToCancel, static fn () => $round->cancelScheduledStart($startsAt));

        self::assertSame(RoundStopwatchStatus::SCHEDULED, $round->stopwatchStatus);
        self::assertEquals($startsAt, $round->stopwatchStartedAt);

        $running = self::round();
        $running->startStopwatch(self::at());
        $this->assertRefused(RoundStopwatchRefusal::TooLateToCancel, static fn () => $running->cancelScheduledStart(self::at('+1 second')));
        self::assertSame(RoundStopwatchStatus::RUNNING, $running->stopwatchStatus);
    }

    public function testStartingNowWhileACountdownRunsIsRefused(): void
    {
        $round = self::round();
        $startsAt = self::at('+10 seconds', whole: true);
        $round->scheduleStopwatchStart($startsAt, self::at());

        $this->assertRefused(RoundStopwatchRefusal::StartScheduled, static fn () => $round->startStopwatch(self::at('+2 seconds')));

        self::assertEquals($startsAt, $round->stopwatchStartedAt);
    }

    public function testASecondStartNeverRestartsARunningClock(): void
    {
        $round = self::round();
        $round->startStopwatch(self::at());
        $startedAt = $round->stopwatchStartedAt;

        $round->startStopwatch(self::at('+3 minutes'));

        self::assertEquals($startedAt, $round->stopwatchStartedAt);
        self::assertSame(RoundStopwatchStatus::RUNNING, $round->stopwatchStatus);

        // A scheduled start whose moment passed runs as well
        $scheduled = self::round();
        $startsAt = self::at('+10 seconds', whole: true);
        $scheduled->scheduleStopwatchStart($startsAt, self::at());
        $scheduled->startStopwatch(self::at('+1 minute'));
        self::assertEquals($startsAt, $scheduled->stopwatchStartedAt);
    }

    public function testStopAndStartStillPauseAndResumeInPerson(): void
    {
        $round = self::round();
        $round->startStopwatch(self::at());
        $round->stopStopwatch(self::at('+10 minutes'));

        self::assertSame(RoundStopwatchStatus::STOPPED, $round->stopwatchStatus);
        self::assertEquals(self::at('+10 minutes', whole: true), $round->stopwatchStoppedAt);

        // Resumed 2 minutes later: the start moves forward by the pause, the time goes on from 10:00
        $round->startStopwatch(self::at('+12 minutes'));

        self::assertSame(RoundStopwatchStatus::RUNNING, $round->stopwatchStatus);
        self::assertEquals(self::at('+2 minutes', whole: true), $round->stopwatchStartedAt);
        self::assertNull($round->stopwatchStoppedAt);
    }

    public function testTheMomentsAreKeptInWholeSecondsLikeTheColumns(): void
    {
        $round = self::round();
        $round->startStopwatch(self::at());
        $round->stopStopwatch(self::at('+1 minute'));

        self::assertSame('000000', $round->stopwatchStartedAt?->format('u'));
        self::assertSame('000000', $round->stopwatchStoppedAt?->format('u'));
    }

    public function testASecondStopNeverMovesTheEnd(): void
    {
        $round = self::round();
        $round->startStopwatch(self::at());
        $round->stopStopwatch(self::at('+45 minutes'));

        $round->stopStopwatch(self::at('+46 minutes'));

        self::assertEquals(self::at('+45 minutes', whole: true), $round->stopwatchStoppedAt);
    }

    public function testStoppingAClockThatNeverRanChangesNothing(): void
    {
        $round = self::round();

        $round->stopStopwatch(self::at());

        self::assertNull($round->stopwatchStatus);
        self::assertNull($round->stopwatchStoppedAt);
    }

    public function testStoppingACountdownCallsItOff(): void
    {
        $round = self::round();
        $round->scheduleStopwatchStart(self::at('+10 seconds', whole: true), self::at());

        $round->stopStopwatch(self::at('+5 seconds'));

        self::assertNull($round->stopwatchStatus);
        self::assertNull($round->stopwatchStartedAt);
        self::assertNull($round->stopwatchStoppedAt);
    }

    public function testStoppingAScheduledStartThatRunsEndsTheRound(): void
    {
        $round = self::round();
        $startsAt = self::at('+10 seconds', whole: true);
        $round->scheduleStopwatchStart($startsAt, self::at());

        $round->stopStopwatch(self::at('+30 minutes'));

        self::assertSame(RoundStopwatchStatus::STOPPED, $round->stopwatchStatus);
        self::assertEquals($startsAt, $round->stopwatchStartedAt);
        self::assertEquals(self::at('+30 minutes', whole: true), $round->stopwatchStoppedAt);
    }

    /**
     * @param \Closure(): void $action
     */
    private function assertRefused(RoundStopwatchRefusal $reason, \Closure $action): void
    {
        try {
            $action();
            self::fail('Expected the stopwatch to refuse: ' . $reason->value);
        } catch (RoundStopwatchRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    private static function at(string $modifier = '+0 seconds', bool $whole = false): DateTimeImmutable
    {
        $moment = new DateTimeImmutable(self::NOW)->modify($modifier);

        return $whole ? $moment->setTimestamp($moment->getTimestamp()) : $moment;
    }

    private static function round(): CompetitionRound
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
            location: 'Online',
            locationCountryCode: null,
            dateFrom: null,
            dateTo: null,
            tag: null,
        );

        return new CompetitionRound(
            id: Uuid::uuid7(),
            competition: $competition,
            name: 'Final',
            minutesLimit: 60,
            startsAt: new DateTimeImmutable('2026-11-10 18:00:00'),
        );
    }
}
