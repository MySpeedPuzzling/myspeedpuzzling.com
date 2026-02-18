<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\RequestNotificationLog;

readonly final class RequestNotificationLogRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(RequestNotificationLog $log): void
    {
        $this->entityManager->persist($log);
    }
}
