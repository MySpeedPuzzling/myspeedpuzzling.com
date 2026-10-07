<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Message\DeleteCompetitionTeam;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * WEB-D5: deleting a team its members still pointed at failed on the foreign key.
 */
final class DeleteCompetitionTeamHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testMembersGoBackToUnassignedAndTheTeamIsDeleted(): void
    {
        $round = $this->entityManager->find(CompetitionRound::class, CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);
        self::assertNotNull($round);

        $team = new CompetitionTeam(Uuid::uuid7(), $round, 'Edge Lords');
        $this->entityManager->persist($team);

        $active = $this->participantInTeam($round->competition, $round, $team, 'Active Member');
        // A removed participant is hidden on the page but still pointed at the team - the case that failed on production
        $removed = $this->participantInTeam($round->competition, $round, $team, 'Removed Member');
        $removed->participant->softDelete(new \DateTimeImmutable());

        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->messageBus->dispatch(new DeleteCompetitionTeam(competitionId: CompetitionSeriesFixture::EDITION_OFFLINE_1, teamId: $team->id->toString()));

        self::assertFalse($this->database->fetchOne('SELECT id FROM competition_team WHERE id = :id', ['id' => $team->id->toString()]));

        foreach ([$active, $removed] as $participantRound) {
            /** @var array{round_id: string, team_id: null|string, name: string}|false $row */
            $row = $this->database->fetchAssociative(
                'SELECT cpr.round_id, cpr.team_id, cp.name FROM competition_participant_round cpr
                 INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
                 WHERE cpr.id = :id',
                ['id' => $participantRound->id->toString()],
            );

            self::assertIsArray($row, 'The member stays in the round.');
            self::assertSame(CompetitionSeriesFixture::ROUND_OFFLINE_TEAM, $row['round_id']);
            self::assertNull($row['team_id']);
            self::assertSame($participantRound->participant->name, $row['name']);
        }
    }

    public function testEmptyTeamIsDeleted(): void
    {
        $round = $this->entityManager->find(CompetitionRound::class, CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);
        self::assertNotNull($round);

        $team = new CompetitionTeam(Uuid::uuid7(), $round);
        $this->entityManager->persist($team);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->messageBus->dispatch(new DeleteCompetitionTeam(competitionId: CompetitionSeriesFixture::EDITION_OFFLINE_1, teamId: $team->id->toString()));

        self::assertFalse($this->database->fetchOne('SELECT id FROM competition_team WHERE id = :id', ['id' => $team->id->toString()]));
    }

    private function participantInTeam(Competition $competition, CompetitionRound $round, CompetitionTeam $team, string $name): CompetitionParticipantRound
    {
        $participant = new CompetitionParticipant(Uuid::uuid7(), $name, 'us', $competition);
        $this->entityManager->persist($participant);

        $participantRound = new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round, $team);
        $this->entityManager->persist($participantRound);

        return $participantRound;
    }
}
