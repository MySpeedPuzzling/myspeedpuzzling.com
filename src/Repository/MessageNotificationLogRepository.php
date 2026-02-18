<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\MessageNotificationLog;

readonly final class MessageNotificationLogRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(MessageNotificationLog $log): void
    {
        $this->entityManager->persist($log);
    }
}
