<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\TransactionRating;

readonly final class TransactionRatingRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(TransactionRating $rating): void
    {
        $this->entityManager->persist($rating);
    }

    public function findByTransactionAndReviewer(string $soldSwappedItemId, string $reviewerId): null|TransactionRating
    {
        return $this->entityManager->getRepository(TransactionRating::class)
            ->findOneBy([
                'soldSwappedItem' => $soldSwappedItemId,
                'reviewer' => $reviewerId,
            ]);
    }
}
