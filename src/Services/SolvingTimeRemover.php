<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;

/**
 * Removes a solving time while keeping the unit of work consistent: notifications
 * referencing the time are detached first (mirroring the DB-level ON DELETE SET NULL),
 * otherwise a managed Notification pointing at the removed entity fails the flush.
 */
readonly final class SolvingTimeRemover
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function remove(PuzzleSolvingTime $solvingTime): void
    {
        $notifications = $this->entityManager->getRepository(Notification::class)->findBy([
            'targetSolvingTime' => $solvingTime,
        ]);

        foreach ($notifications as $notification) {
            $notification->targetSolvingTime = null;
        }

        $this->entityManager->remove($solvingTime);
    }
}
