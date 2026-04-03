<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\ApproveCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApproveCompetitionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRepository $competitionRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
    }

    public function testApproveSetsFieldsOnCompetition(): void
    {
        $this->messageBus->dispatch(new ApproveCompetition(
            competitionId: CompetitionFixture::COMPETITION_UNAPPROVED,
            approvedByPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));

        $competition = $this->competitionRepository->get(CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertNotNull($competition->approvedAt);
        self::assertNotNull($competition->approvedByPlayer);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $competition->approvedByPlayer->id->toString());
    }
}
