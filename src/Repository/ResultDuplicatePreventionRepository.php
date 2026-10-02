<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\ResultDuplicatePrevention;

readonly final class ResultDuplicatePreventionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ResultDuplicatePrevention $prevention): void
    {
        $this->entityManager->persist($prevention);
    }
}
