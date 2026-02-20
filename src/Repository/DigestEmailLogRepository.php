<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\DigestEmailLog;

readonly final class DigestEmailLogRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(DigestEmailLog $log): void
    {
        $this->entityManager->persist($log);
    }
}
