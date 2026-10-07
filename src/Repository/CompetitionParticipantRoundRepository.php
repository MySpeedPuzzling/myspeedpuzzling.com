<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;

readonly final class CompetitionParticipantRoundRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(string $id): CompetitionParticipantRound
    {
        if (!Uuid::isValid($id)) {
            throw new \RuntimeException('Invalid participant round ID');
        }

        $participantRound = $this->entityManager->find(CompetitionParticipantRound::class, $id);

        return $participantRound ?? throw new \RuntimeException('Participant round not found');
    }

    /**
     * Every round entry in the team - also those of removed (soft-deleted) participants, which the pages hide.
     *
     * @return list<CompetitionParticipantRound>
     */
    public function findByTeam(CompetitionTeam $team): array
    {
        return $this->entityManager->getRepository(CompetitionParticipantRound::class)->findBy(['team' => $team]);
    }

    public function find(string $id): null|CompetitionParticipantRound
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->entityManager->find(CompetitionParticipantRound::class, $id);
    }

    /**
     * Every entry of the round - also those of removed (soft-deleted) participants; callers skip what they hide.
     *
     * @return list<CompetitionParticipantRound>
     */
    public function findByRound(CompetitionRound $round): array
    {
        /** @var list<CompetitionParticipantRound> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('cpr', 'participant')
            ->from(CompetitionParticipantRound::class, 'cpr')
            ->innerJoin('cpr.participant', 'participant')
            ->where('cpr.round = :round')
            ->setParameter('round', $round->id->toString())
            ->getQuery()
            ->getResult();

        return $entries;
    }

    public function save(CompetitionParticipantRound $participantRound): void
    {
        $this->entityManager->persist($participantRound);
    }

    public function delete(CompetitionParticipantRound $participantRound): void
    {
        $this->entityManager->remove($participantRound);
    }
}
