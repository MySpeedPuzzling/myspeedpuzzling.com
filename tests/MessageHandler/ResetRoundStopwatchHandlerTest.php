<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\ResetRoundStopwatch;
use SpeedPuzzling\Web\Message\StartRoundStopwatch;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ResetRoundStopwatchHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRoundRepository $competitionRoundRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRoundRepository = self::getContainer()->get(CompetitionRoundRepository::class);
    }

    public function testResetClearsEverything(): void
    {
        $this->messageBus->dispatch(new StartRoundStopwatch(
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
        ));

        $this->messageBus->dispatch(new ResetRoundStopwatch(
            roundId: CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION,
        ));

        $round = $this->competitionRoundRepository->get(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);

        self::assertNull($round->stopwatchStartedAt);
        self::assertNull($round->stopwatchStatus);
    }
}
