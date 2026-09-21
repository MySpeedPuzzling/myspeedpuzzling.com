<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PlayerCodeChange;

readonly final class PlayerCodeChangeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PlayerCodeChange $change): void
    {
        $this->entityManager->persist($change);
    }
}
