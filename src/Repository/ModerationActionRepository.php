<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\ModerationAction;

readonly final class ModerationActionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ModerationAction $action): void
    {
        $this->entityManager->persist($action);
    }
}
