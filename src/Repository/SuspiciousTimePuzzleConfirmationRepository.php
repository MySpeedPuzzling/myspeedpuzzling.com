<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\SuspiciousTimePuzzleConfirmation;

readonly final class SuspiciousTimePuzzleConfirmationRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SuspiciousTimePuzzleConfirmation $confirmation): void
    {
        $this->entityManager->persist($confirmation);
    }

    public function find(string $puzzleId): null|SuspiciousTimePuzzleConfirmation
    {
        if (!Uuid::isValid($puzzleId)) {
            return null;
        }

        return $this->entityManager->find(SuspiciousTimePuzzleConfirmation::class, $puzzleId);
    }
}
