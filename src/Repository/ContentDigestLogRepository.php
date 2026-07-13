<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\ContentDigestLog;

readonly class ContentDigestLogRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ContentDigestLog $log): void
    {
        $this->entityManager->persist($log);
    }
}
