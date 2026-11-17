<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\ParticipantLinkLog;

/**
 * Append only - a log row is never changed or removed by the application (account deletion erases a deleted
 * account's name in SQL, together with the participants it unlinks).
 */
readonly final class ParticipantLinkLogRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ParticipantLinkLog $log): void
    {
        $this->entityManager->persist($log);
    }
}
