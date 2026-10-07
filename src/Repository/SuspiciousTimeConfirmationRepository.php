<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\SuspiciousTimeConfirmation;

readonly final class SuspiciousTimeConfirmationRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SuspiciousTimeConfirmation $confirmation): void
    {
        $this->entityManager->persist($confirmation);
    }
}
