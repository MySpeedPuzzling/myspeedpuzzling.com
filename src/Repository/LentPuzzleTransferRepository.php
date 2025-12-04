<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\LentPuzzleTransfer;
use SpeedPuzzling\Web\Exceptions\LentPuzzleTransferNotFound;

readonly final class LentPuzzleTransferRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws LentPuzzleTransferNotFound
     */
    public function get(string $transferId): LentPuzzleTransfer
    {
        $transfer = $this->entityManager->find(LentPuzzleTransfer::class, $transferId);

        if ($transfer === null) {
            throw new LentPuzzleTransferNotFound();
        }

        return $transfer;
    }

    public function save(LentPuzzleTransfer $transfer): void
    {
        $this->entityManager->persist($transfer);
    }
}
