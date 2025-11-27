<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\LentPuzzleTransfer;

readonly final class LentPuzzleTransferRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(LentPuzzleTransfer $transfer): void
    {
        $this->entityManager->persist($transfer);
    }
}
