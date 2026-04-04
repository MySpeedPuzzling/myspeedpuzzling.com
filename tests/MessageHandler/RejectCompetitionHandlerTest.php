<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\RejectCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RejectCompetitionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionRepository $competitionRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->competitionRepository = self::getContainer()->get(CompetitionRepository::class);
    }

    public function testRejectSetsFieldsOnCompetition(): void
    {
        $this->messageBus->dispatch(new RejectCompetition(
            competitionId: CompetitionFixture::COMPETITION_UNAPPROVED,
            rejectedByPlayerId: PlayerFixture::PLAYER_ADMIN,
            reason: 'Duplicate event',
        ));

        $competition = $this->competitionRepository->get(CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertNotNull($competition->rejectedAt);
        self::assertNotNull($competition->rejectedByPlayer);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $competition->rejectedByPlayer->id->toString());
        self::assertSame('Duplicate event', $competition->rejectionReason);
        self::assertNull($competition->approvedAt);
    }
}
