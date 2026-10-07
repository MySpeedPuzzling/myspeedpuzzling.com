<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\CompetitionTeamOfAnotherRound;
use SpeedPuzzling\Web\Message\AssignParticipantToTeam;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class AssignParticipantToTeamHandlerTest extends KernelTestCase
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

    public function testParticipantJoinsATeamOfTheirRound(): void
    {
        [$participantRoundId, $teamId] = $this->participantAndTeam(CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);

        $this->messageBus->dispatch(new AssignParticipantToTeam($participantRoundId, $teamId));

        self::assertSame($teamId, $this->teamOf($participantRoundId));
    }

    public function testTeamOfAnotherRoundIsRefused(): void
    {
        [$participantRoundId, $teamId] = $this->participantAndTeam(CompetitionSeriesFixture::ROUND_OFFLINE_SOLO);

        try {
            $this->messageBus->dispatch(new AssignParticipantToTeam($participantRoundId, $teamId));
            self::fail('A team of another round must be refused.');
        } catch (CompetitionTeamOfAnotherRound) {
        }

        self::assertNull($this->teamOf($participantRoundId));
    }

    /**
     * @return array{string, string} the participant's entry in $participantRoundId's round, a team of the team round
     */
    private function participantAndTeam(string $participantRoundId): array
    {
        $teamRound = $this->entityManager->find(CompetitionRound::class, CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);
        $participantsRound = $this->entityManager->find(CompetitionRound::class, $participantRoundId);
        self::assertNotNull($teamRound);
        self::assertNotNull($participantsRound);

        $team = new CompetitionTeam(Uuid::uuid7(), $teamRound, 'Edge Lords');
        $participant = new CompetitionParticipant(Uuid::uuid7(), 'Alex Example', 'cz', $teamRound->competition);
        $participantRound = new CompetitionParticipantRound(Uuid::uuid7(), $participant, $participantsRound);

        $this->entityManager->persist($team);
        $this->entityManager->persist($participant);
        $this->entityManager->persist($participantRound);
        $this->entityManager->flush();
        $this->entityManager->clear();

        return [$participantRound->id->toString(), $team->id->toString()];
    }

    private function teamOf(string $participantRoundId): null|string
    {
        /** @var false|null|string $teamId */
        $teamId = $this->database->fetchOne('SELECT team_id FROM competition_participant_round WHERE id = :id', ['id' => $participantRoundId]);
        self::assertNotFalse($teamId);

        return $teamId;
    }
}
