<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ParticipantSheetChangeReceipt;

readonly final class ParticipantSheetChangeReceiptRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The receipt of a change set id, whichever event it belongs to (the caller refuses another event's).
     */
    public function find(string $changesetId): null|ParticipantSheetChangeReceipt
    {
        if (!Uuid::isValid($changesetId)) {
            return null;
        }

        return $this->entityManager->find(ParticipantSheetChangeReceipt::class, strtolower($changesetId));
    }

    public function save(ParticipantSheetChangeReceipt $receipt): void
    {
        $this->entityManager->persist($receipt);
    }

    /**
     * A bulk prune of old receipts (a cron) - rows that must not be loaded one by one.
     */
    public function deleteReceivedBefore(DateTimeImmutable $before): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(ParticipantSheetChangeReceipt::class, 'receipt')
            ->where('receipt.receivedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
