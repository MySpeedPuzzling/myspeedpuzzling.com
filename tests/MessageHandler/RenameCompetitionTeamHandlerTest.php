<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\CompetitionTeamNameTooLong;
use SpeedPuzzling\Web\Message\RenameCompetitionTeam;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RenameCompetitionTeamHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    private string $teamId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $round = $entityManager->find(CompetitionRound::class, CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);
        self::assertNotNull($round);

        $team = new CompetitionTeam(Uuid::uuid7(), $round, 'Edge Lords');
        $entityManager->persist($team);
        $entityManager->flush();
        $entityManager->clear();

        $this->teamId = $team->id->toString();
    }

    public function testTeamGetsTheNewNameTidiedUp(): void
    {
        $this->messageBus->dispatch(new RenameCompetitionTeam($this->teamId, "  Corner   Crew \n"));

        self::assertSame('Corner Crew', $this->teamName());
    }

    public function testEmptyNameLeavesTheTeamUnnamed(): void
    {
        $this->messageBus->dispatch(new RenameCompetitionTeam($this->teamId, '   '));

        self::assertNull($this->teamName());
    }

    public function testUnnamedTeamGetsAName(): void
    {
        $this->messageBus->dispatch(new RenameCompetitionTeam($this->teamId, null));
        $this->messageBus->dispatch(new RenameCompetitionTeam($this->teamId, 'Piece Seekers'));

        self::assertSame('Piece Seekers', $this->teamName());
    }

    public function testTooLongNameIsRefusedAndTheNameStays(): void
    {
        try {
            $this->messageBus->dispatch(new RenameCompetitionTeam($this->teamId, str_repeat('x', CompetitionTeam::NAME_MAX_LENGTH + 1)));
            self::fail('A team name longer than the column must be refused.');
        } catch (CompetitionTeamNameTooLong) {
        }

        self::assertSame('Edge Lords', $this->teamName());
    }

    private function teamName(): null|string
    {
        /** @var false|null|string $name */
        $name = $this->database->fetchOne('SELECT name FROM competition_team WHERE id = :id', ['id' => $this->teamId]);
        self::assertNotFalse($name);

        return $name;
    }
}
