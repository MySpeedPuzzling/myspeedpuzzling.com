<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\WjpcParticipant;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\WjpcParticipantNotFound;

readonly final class WjpcParticipantRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WjpcParticipantNotFound
     */
    public function get(string $participantId): WjpcParticipant
    {
        if (!Uuid::isValid($participantId)) {
            throw new WjpcParticipantNotFound();
        }

        $participant = $this->entityManager->find(WjpcParticipant::class, $participantId);

        return $participant ?? throw new WjpcParticipantNotFound();
    }

    public function save(WjpcParticipant $participant): void
    {
        $this->entityManager->persist($participant);
    }

    public function delete(WjpcParticipant $participant): void
    {
        $this->entityManager->remove($participant);
    }
}
