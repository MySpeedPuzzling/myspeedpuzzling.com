<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\GroupPredictionFit;

readonly final class GroupPredictionFitRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(int $modelVersion): null|GroupPredictionFit
    {
        return $this->entityManager->find(GroupPredictionFit::class, $modelVersion);
    }

    public function save(GroupPredictionFit $fit): void
    {
        $this->entityManager->persist($fit);
    }
}
