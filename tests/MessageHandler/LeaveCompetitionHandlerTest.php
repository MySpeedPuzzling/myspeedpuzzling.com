<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class LeaveCompetitionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionParticipantRepository $participantRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->participantRepository = self::getContainer()->get(CompetitionParticipantRepository::class);
    }

    public function testLeaveSelfJoinedSoftDeletes(): void
    {
        // PARTICIPANT_SELF_JOINED is linked to PLAYER_WITH_FAVORITES
        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED);
        self::assertFalse($participant->isDeleted());

        $this->messageBus->dispatch(new LeaveCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        ));

        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED);

        self::assertTrue($participant->isDeleted());
    }

    public function testLeaveImportedDisconnectsOnly(): void
    {
        // PARTICIPANT_CONNECTED is imported, linked to PLAYER_REGULAR
        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_CONNECTED);
        self::assertNotNull($participant->player);

        $this->messageBus->dispatch(new LeaveCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_CONNECTED);

        self::assertNull($participant->player);
        self::assertFalse($participant->isDeleted());
    }

    public function testLeaveHandlesEveryRowOfThePlayer(): void
    {
        $database = self::getContainer()->get(Connection::class);

        // A duplicate from before joining was idempotent
        $database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, player_id, connected_at, source)
             VALUES (:id, 'Michael Johnson', 'de', :cid, :pid, now(), 'self_joined')",
            [
                'id' => '018d0006-0000-0000-0000-0000000000ff',
                'cid' => CompetitionFixture::COMPETITION_WJPC_2024,
                'pid' => PlayerFixture::PLAYER_WITH_FAVORITES,
            ],
        );

        $this->messageBus->dispatch(new LeaveCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        ));

        $activeCount = $database->fetchOne(
            'SELECT count(*) FROM competition_participant WHERE competition_id = :cid AND player_id = :pid AND deleted_at IS NULL',
            ['cid' => CompetitionFixture::COMPETITION_WJPC_2024, 'pid' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );

        self::assertSame(0, $activeCount);
    }
}
