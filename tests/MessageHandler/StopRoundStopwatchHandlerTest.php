<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Message\StopRoundStopwatch;
use SpeedPuzzling\Web\MessageHandler\StopRoundStopwatchHandler;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

final class StopRoundStopwatchHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRoundRepository $competitionRoundRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRoundRepository = self::getContainer()->get(CompetitionRoundRepository::class);
    }

    public function testStopPreservesStartedAtAndSetsStatus(): void
    {
        $this->messageBus->dispatch(new StartRoundStopwatch(
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
        ));

        $this->messageBus->dispatch(new StopRoundStopwatch(
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
        ));

        $round = $this->competitionRoundRepository->get(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        self::assertNotNull($round->stopwatchStartedAt);
        self::assertSame('stopped', $round->stopwatchStatus);
    }

    /**
     * "End now" of a round with online timing never ends before the click: 39.926 s after the start is stored as 40 s
     */
    public function testAnOnlineRoundEndsOnTheNextWholeSecond(): void
    {
        $startedAt = new DateTimeImmutable('2026-11-10 18:00:00', new DateTimeZone('UTC'));
        $round = $this->competitionRoundRepository->get(OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        $round->startStopwatch($startedAt);

        $this->handlerAt($startedAt->modify('+39 seconds +926 milliseconds'))(new StopRoundStopwatch(roundId: OnlineRoundsFixture::ROUND_ONLINE_SOLO));

        self::assertSame('stopped', $round->stopwatchStatus);
        self::assertEquals($startedAt->modify('+40 seconds'), $round->stopwatchStoppedAt);
    }

    /**
     * In person a Stop is a pause - it keeps the second it was in, as before
     */
    public function testAnInPersonRoundKeepsTheSecondOfTheStop(): void
    {
        $startedAt = new DateTimeImmutable('2026-11-10 18:00:00', new DateTimeZone('UTC'));
        $round = $this->competitionRoundRepository->get(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $round->startStopwatch($startedAt);

        $this->handlerAt($startedAt->modify('+39 seconds +926 milliseconds'))(new StopRoundStopwatch(roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION));

        self::assertEquals($startedAt->modify('+39 seconds'), $round->stopwatchStoppedAt);
    }

    private function handlerAt(DateTimeImmutable $now): StopRoundStopwatchHandler
    {
        return new StopRoundStopwatchHandler(
            $this->competitionRoundRepository,
            self::getContainer()->get(OnlineRoundRepository::class),
            new MockClock($now),
        );
    }
}
