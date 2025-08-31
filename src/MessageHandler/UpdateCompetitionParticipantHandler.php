<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Message\UpdateCompetitionParticipant;
use SpeedPuzzling\Web\Query\FindPlayerByNameAndCountry;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UpdateCompetitionParticipantHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CompetitionParticipantRepository $competitionParticipantRepository,
        private CompetitionRepository $competitionRepository,
        private PlayerRepository $playerRepository,
        private FindPlayerByNameAndCountry $findPlayerByNameAndCountry,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UpdateCompetitionParticipant $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId->toString());

        $participant = $this->findOrCreateParticipant($message, $competition);

        // If participant has no connected player, try to find and connect one
        if ($participant->player === null) {
            $playerId = $this->findPlayerByNameAndCountry->find($message->name, $message->country);

            if ($playerId !== null) {
                $player = $this->playerRepository->get($playerId);
                $participant->connect($player, $this->clock->now());
            }
        }

        $this->handleCompetitionParticipantRound($participant, $message->groupId);
    }

    private function findOrCreateParticipant(UpdateCompetitionParticipant $message, Competition $competition): CompetitionParticipant
    {
        // Try to find existing participant by name and competition
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $existingParticipant = $queryBuilder
            ->select('cp')
            ->from(CompetitionParticipant::class, 'cp')
            ->where('LOWER(cp.name) = LOWER(:name)')
            ->andWhere('cp.competition = :competition')
            ->setParameter('name', $message->name)
            ->setParameter('competition', $competition)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existingParticipant instanceof CompetitionParticipant) {
            return $existingParticipant;
        }

        // Create new participant
        $participant = new CompetitionParticipant(
            Uuid::uuid7(),
            $message->name,
            $message->country->name,
            $competition
        );

        $this->competitionParticipantRepository->save($participant);

        return $participant;
    }

    private function handleCompetitionParticipantRound(CompetitionParticipant $participant, UuidInterface $groupId): void
    {
        $competitionRound = $this->entityManager->find(CompetitionRound::class, $groupId->toString());

        if ($competitionRound === null) {
            return;
        }

        // Look for existing CompetitionParticipantRound
        $queryBuilder = $this->entityManager->createQueryBuilder();
        $existingParticipantRound = $queryBuilder
            ->select('cpr')
            ->from(CompetitionParticipantRound::class, 'cpr')
            ->where('cpr.participant = :participant')
            ->setParameter('participant', $participant)
            ->getQuery()
            ->getOneOrNullResult();

        if ($existingParticipantRound instanceof CompetitionParticipantRound) {
            $existingParticipantRound->changeRound($competitionRound);
        } else {
            $participantRound = new CompetitionParticipantRound(
                Uuid::uuid7(),
                $participant,
                $competitionRound
            );

            $this->entityManager->persist($participantRound);
        }
    }
}
