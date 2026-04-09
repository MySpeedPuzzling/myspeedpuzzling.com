<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;

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

    public function save(CompetitionParticipantRound $participantRound): void
    {
        $this->entityManager->persist($participantRound);
    }
}
