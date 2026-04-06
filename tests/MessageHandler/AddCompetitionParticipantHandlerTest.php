<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\AddCompetitionParticipant;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddCompetitionParticipantHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAddsParticipantWithManualSource(): void
    {
        $this->messageBus->dispatch(new AddCompetitionParticipant(
            competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            name: 'New Participant',
            country: 'cz',
            externalId: 'EXT-999',
            playerId: null,
        ));

        /** @var array{source: string, external_id: string|null, player_id: string|null} $row */
        $row = $this->database->executeQuery(
            "SELECT source, external_id, player_id FROM competition_participant WHERE name = 'New Participant' AND competition_id = :id",
            ['id' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024],
        )->fetchAssociative();

        self::assertSame('manual', $row['source']);
        self::assertSame('EXT-999', $row['external_id']);
        self::assertNull($row['player_id']);
    }

    public function testAddsParticipantWithPlayerLink(): void
    {
        $this->messageBus->dispatch(new AddCompetitionParticipant(
            competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            name: 'Linked Participant',
            country: 'us',
            externalId: null,
            playerId: PlayerFixture::PLAYER_ADMIN,
        ));

        /** @var array{player_id: string|null} $row */
        $row = $this->database->executeQuery(
            "SELECT player_id FROM competition_participant WHERE name = 'Linked Participant' AND competition_id = :id",
            ['id' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024],
        )->fetchAssociative();

        self::assertSame(PlayerFixture::PLAYER_ADMIN, $row['player_id']);
    }
}
