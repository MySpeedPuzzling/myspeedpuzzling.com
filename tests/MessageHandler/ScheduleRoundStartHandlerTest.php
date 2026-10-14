<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Message\CancelRoundStart;
use SpeedPuzzling\Web\Message\ScheduleRoundStart;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Message\StopRoundStopwatch;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\RoundStopwatchRefusal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * ScheduleRoundStart / CancelRoundStart (docs/features/online-competitions/README.md §5.1): a start a whole second ahead
 * is stored as `scheduled`, moved while it counts down, refused in the past or for a clock that ran; a cancel calls it
 * off before its moment only. Nothing is published from the handlers - the controllers do that after the commit.
 */
final class ScheduleRoundStartHandlerTest extends KernelTestCase
{
    private const string ROUND = CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAStartAheadIsStoredAsScheduled(): void
    {
        $startsAt = $this->wholeSecond('+10 minutes');

        $this->schedule($startsAt);

        $row = $this->row();
        self::assertSame('scheduled', $row['stopwatch_status']);
        self::assertSame($startsAt->format('Y-m-d H:i:s'), $row['stopwatch_started_at']);
        self::assertNull($row['stopwatch_stopped_at']);
    }

    public function testAStartIsStoredAsItsUtcMomentWhateverZoneItCarries(): void
    {
        $startsAt = $this->wholeSecond('+2 hours')->setTimezone(new DateTimeZone('America/Toronto'));

        $this->schedule($startsAt);

        self::assertSame($startsAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $this->row()['stopwatch_started_at']);
    }

    public function testACountdownIsMovedWhileItRuns(): void
    {
        $this->schedule($this->wholeSecond('+1 hour'));
        $later = $this->wholeSecond('+2 hours');

        $this->schedule($later);

        self::assertSame($later->format('Y-m-d H:i:s'), $this->row()['stopwatch_started_at']);
    }

    public function testAStartInThePastChangesNothing(): void
    {
        $this->assertRefused(RoundStopwatchRefusal::StartInThePast, fn () => $this->schedule($this->wholeSecond('-1 minute')));

        self::assertNull($this->row()['stopwatch_status']);
    }

    public function testARunningClockIsNotScheduledAgain(): void
    {
        $this->messageBus->dispatch(new StartRoundStopwatch(self::ROUND));

        $this->assertRefused(RoundStopwatchRefusal::AlreadyStarted, fn () => $this->schedule($this->wholeSecond('+1 hour')));

        self::assertSame('running', $this->row()['stopwatch_status']);
    }

    /**
     * An online round that ended is never started again - not in 10 s, not at a set time: a Reset runs it again
     * (docs/features/online-competitions/README.md §5.1)
     */
    public function testAnEndedOnlineRoundIsToldToBeResetFirst(): void
    {
        $online = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
        $this->messageBus->dispatch(new StartRoundStopwatch($online));
        $this->messageBus->dispatch(new StopRoundStopwatch($online));
        $before = $this->row($online);

        $this->assertRefused(RoundStopwatchRefusal::EndedUseReset, fn () => $this->schedule($this->wholeSecond('+10 seconds'), $online));

        self::assertSame('stopped', $before['stopwatch_status']);
        self::assertSame($before, $this->row($online));
    }

    /**
     * In person a stopped clock is a pause: scheduling a start still asks for a reset first, as before
     */
    public function testAStoppedInPersonRoundIsStillAskedForAReset(): void
    {
        $this->messageBus->dispatch(new StartRoundStopwatch(self::ROUND));
        $this->messageBus->dispatch(new StopRoundStopwatch(self::ROUND));

        $this->assertRefused(RoundStopwatchRefusal::AlreadyStarted, fn () => $this->schedule($this->wholeSecond('+1 hour')));

        self::assertSame('stopped', $this->row()['stopwatch_status']);
    }

    public function testACancelBeforeTheMomentCallsTheCountdownOff(): void
    {
        $this->schedule($this->wholeSecond('+10 minutes'));

        $this->messageBus->dispatch(new CancelRoundStart(self::ROUND, PlayerFixture::PLAYER_ADMIN));

        self::assertSame(['stopwatch_status' => null, 'stopwatch_started_at' => null, 'stopwatch_stopped_at' => null], $this->row());
    }

    public function testACancelAfterTheMomentNeverResetsTheRunningRound(): void
    {
        $startedAt = $this->wholeSecond('-30 seconds');
        $this->database->executeStatement(
            "UPDATE competition_round SET stopwatch_status = 'scheduled', stopwatch_started_at = :startedAt WHERE id = :id",
            ['startedAt' => $startedAt->format('Y-m-d H:i:s'), 'id' => self::ROUND],
        );

        $this->assertRefused(RoundStopwatchRefusal::TooLateToCancel, fn () => $this->messageBus->dispatch(new CancelRoundStart(self::ROUND, PlayerFixture::PLAYER_ADMIN)));

        self::assertSame('scheduled', $this->row()['stopwatch_status']);
        self::assertSame($startedAt->format('Y-m-d H:i:s'), $this->row()['stopwatch_started_at']);
    }

    private function schedule(DateTimeImmutable $startsAt, string $roundId = self::ROUND): void
    {
        $this->messageBus->dispatch(new ScheduleRoundStart(
            roundId: $roundId,
            startsAt: $startsAt,
            actingPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));
    }

    /**
     * @param \Closure(): mixed $action
     */
    private function assertRefused(RoundStopwatchRefusal $reason, \Closure $action): void
    {
        try {
            $action();
            self::fail('Expected a refusal: ' . $reason->value);
        } catch (RoundStopwatchRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    /**
     * @return array{stopwatch_status: null|string, stopwatch_started_at: null|string, stopwatch_stopped_at: null|string}
     */
    private function row(string $roundId = self::ROUND): array
    {
        $row = $this->database->fetchAssociative(
            'SELECT stopwatch_status, stopwatch_started_at, stopwatch_stopped_at FROM competition_round WHERE id = :id',
            ['id' => $roundId],
        );
        self::assertIsArray($row);

        /** @var array{stopwatch_status: null|string, stopwatch_started_at: null|string, stopwatch_stopped_at: null|string} $row */
        return $row;
    }

    private function wholeSecond(string $modifier): DateTimeImmutable
    {
        $moment = self::getContainer()->get(ClockInterface::class)->now()->modify($modifier);

        return $moment->setTimestamp($moment->getTimestamp())->setTimezone(new DateTimeZone('UTC'));
    }
}
