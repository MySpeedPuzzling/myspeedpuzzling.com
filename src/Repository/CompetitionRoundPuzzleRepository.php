<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;

readonly final class CompetitionRoundPuzzleRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(string $id): CompetitionRoundPuzzle
    {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException('Invalid competition round puzzle ID');
        }

        $roundPuzzle = $this->entityManager->find(CompetitionRoundPuzzle::class, $id);

        if ($roundPuzzle === null) {
            throw new \InvalidArgumentException('Competition round puzzle not found');
        }

        return $roundPuzzle;
    }

    public function save(CompetitionRoundPuzzle $roundPuzzle): void
    {
        $this->entityManager->persist($roundPuzzle);
    }

    public function delete(CompetitionRoundPuzzle $roundPuzzle): void
    {
        $this->entityManager->remove($roundPuzzle);
    }
}
