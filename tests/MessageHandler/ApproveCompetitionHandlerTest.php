<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddCompetition;
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

    public function testTheCreatorIsToldWhenSomebodyElseApproves(): void
    {
        $this->messageBus->dispatch(new ApproveCompetition(
            competitionId: CompetitionFixture::COMPETITION_UNAPPROVED,
            approvedByPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));

        self::assertQueuedEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage() ?? self::fail('No e-mail'), 'To', PlayerFixture::PLAYER_REGULAR_EMAIL);
    }

    public function testNobodyIsToldAboutTheirOwnApproval(): void
    {
        $competitionId = Uuid::uuid7();

        // An admin creating a competition does not ask himself to review it either
        $this->messageBus->dispatch(new AddCompetition(
            competitionId: $competitionId,
            playerId: PlayerFixture::PLAYER_ADMIN,
            name: 'Admin Event',
            shortcut: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: null,
            locationCountryCode: null,
            dateFrom: null,
            dateTo: null,
            isOnline: true,
            logo: null,
            maintainerIds: [],
            notifyAdmin: false,
        ));

        $this->messageBus->dispatch(new ApproveCompetition(
            competitionId: $competitionId->toString(),
            approvedByPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));

        self::assertQueuedEmailCount(0);
        self::assertTrue($this->competitionRepository->get($competitionId->toString())->isApproved());
    }
}
