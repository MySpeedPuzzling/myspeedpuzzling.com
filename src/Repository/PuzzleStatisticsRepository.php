<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\PuzzleStatistics;

readonly final class PuzzleStatisticsRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByPuzzleId(UuidInterface $puzzleId): null|PuzzleStatistics
    {
        return $this->entityManager->find(PuzzleStatistics::class, $puzzleId->toString());
    }

    public function save(PuzzleStatistics $statistics): void
    {
        $this->entityManager->persist($statistics);
    }
}
