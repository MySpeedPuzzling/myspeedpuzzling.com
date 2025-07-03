<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;

readonly final class CompetitionParticipantRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound
     */
    public function get(string $participantId): CompetitionParticipant
    {
        if (!Uuid::isValid($participantId)) {
            throw new CompetitionParticipantNotFound();
        }

        $participant = $this->entityManager->find(CompetitionParticipant::class, $participantId);

        return $participant ?? throw new CompetitionParticipantNotFound();
    }

    public function save(CompetitionParticipant $participant): void
    {
        $this->entityManager->persist($participant);
    }

    public function delete(CompetitionParticipant $participant): void
    {
        $this->entityManager->remove($participant);
    }
}
