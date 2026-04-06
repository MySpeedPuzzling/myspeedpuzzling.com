<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class JoinCompetitionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private CompetitionParticipantRepository $participantRepository;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->participantRepository = self::getContainer()->get(CompetitionParticipantRepository::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testSelfJoinCreatesParticipantWithCorrectSource(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            playerId: PlayerFixture::PLAYER_ADMIN,
        ));

        /** @var array{source: string, player_id: string|null} $row */
        $row = $this->database->executeQuery(
            'SELECT source, player_id FROM competition_participant WHERE competition_id = :cid AND player_id = :pid AND deleted_at IS NULL',
            ['cid' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, 'pid' => PlayerFixture::PLAYER_ADMIN],
        )->fetchAssociative();

        self::assertSame('self_joined', $row['source']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $row['player_id']);
    }

    public function testJoinByPickingParticipantConnectsPlayer(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_ADMIN,
            participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED,
        ));

        $participant = $this->participantRepository->get(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED);

        self::assertNotNull($participant->player);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $participant->player->id->toString());
    }
}
