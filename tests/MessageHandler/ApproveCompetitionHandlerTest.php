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

    public function testTheApproverWhoCreatedItIsToldTooUnlessLeftOut(): void
    {
        // The approval queue tells every creator, also an admin approving his own competition
        $ownCompetition = $this->addCompetitionAsAdmin();
        $this->messageBus->dispatch(new ApproveCompetition(
            competitionId: $ownCompetition,
            approvedByPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));

        self::assertQueuedEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage() ?? self::fail('No e-mail'), 'To', PlayerFixture::PLAYER_ADMIN_EMAIL);

        // The internal API leaves it out when its reviewer player created the competition
        $apiCompetition = $this->addCompetitionAsAdmin();
        $this->messageBus->dispatch(new ApproveCompetition(
            competitionId: $apiCompetition,
            approvedByPlayerId: PlayerFixture::PLAYER_ADMIN,
            notifyCreator: false,
        ));

        self::assertQueuedEmailCount(1);
        self::assertTrue($this->competitionRepository->get($apiCompetition)->isApproved());
    }

    private function addCompetitionAsAdmin(): string
    {
        $competitionId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddCompetition(
            competitionId: $competitionId,
            playerId: PlayerFixture::PLAYER_ADMIN,
            name: 'Admin Event ' . $competitionId->toString(),
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

        return $competitionId->toString();
    }
}
