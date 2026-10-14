<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Exceptions\RoundStopwatchRefused;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\MessageHandler\StartRoundStopwatchHandler;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Value\RoundStopwatchRefusal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

final class StartRoundStopwatchHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRoundRepository $competitionRoundRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRoundRepository = self::getContainer()->get(CompetitionRoundRepository::class);
    }

    public function testStartSetsTimestampAndStatus(): void
    {
        $this->messageBus->dispatch(new StartRoundStopwatch(
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
        ));

        $round = $this->competitionRoundRepository->get(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        self::assertNotNull($round->stopwatchStartedAt);
        self::assertSame('running', $round->stopwatchStatus);
    }

    /**
     * An online round that ended ("End now") is never resumed (docs/features/online-competitions/README.md §5.1): the
     * Start is refused, the start and the end stay where they were - a Reset runs it again
     */
    public function testAnEndedOnlineRoundIsNotStartedAgain(): void
    {
        $startedAt = new DateTimeImmutable('2026-11-10 18:00:00', new DateTimeZone('UTC'));
        $round = $this->competitionRoundRepository->get(OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        $round->startStopwatch($startedAt, onlineTiming: true);
        $round->endStopwatch($startedAt->modify('+20 minutes'));

        try {
            $this->handlerAt($startedAt->modify('+25 minutes'))(new StartRoundStopwatch(roundId: OnlineRoundsFixture::ROUND_ONLINE_SOLO));
            self::fail('An ended online round must not start again');
        } catch (RoundStopwatchRefused $refused) {
            self::assertSame(RoundStopwatchRefusal::EndedUseReset, $refused->reason);
        }

        self::assertSame('stopped', $round->stopwatchStatus);
        self::assertEquals($startedAt, $round->stopwatchStartedAt);
        self::assertEquals($startedAt->modify('+20 minutes'), $round->stopwatchStoppedAt);
    }

    /**
     * In person a Stop is a pause - Start resumes it exactly as before: the start moves forward by the pause
     */
    public function testAStoppedInPersonRoundIsResumed(): void
    {
        $startedAt = new DateTimeImmutable('2026-11-10 18:00:00', new DateTimeZone('UTC'));
        $round = $this->competitionRoundRepository->get(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $round->startStopwatch($startedAt);
        $round->stopStopwatch($startedAt->modify('+20 minutes'));

        $this->handlerAt($startedAt->modify('+25 minutes'))(new StartRoundStopwatch(roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION));

        self::assertSame('running', $round->stopwatchStatus);
        self::assertEquals($startedAt->modify('+5 minutes'), $round->stopwatchStartedAt);
        self::assertNull($round->stopwatchStoppedAt);
    }

    private function handlerAt(DateTimeImmutable $now): StartRoundStopwatchHandler
    {
        return new StartRoundStopwatchHandler(
            $this->competitionRoundRepository,
            self::getContainer()->get(OnlineRoundRepository::class),
            new MockClock($now),
        );
    }
}
