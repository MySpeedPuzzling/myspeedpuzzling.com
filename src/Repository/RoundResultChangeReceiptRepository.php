<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\RoundResultChangeReceipt;

readonly final class RoundResultChangeReceiptRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The receipts of the given change ids that exist (any round).
     *
     * @param array<string> $changeIds
     * @return array<string, RoundResultChangeReceipt> lower-cased change id => receipt
     */
    public function findByIds(array $changeIds): array
    {
        $changeIds = array_values(array_filter($changeIds, static fn (string $id): bool => Uuid::isValid($id)));

        if ($changeIds === []) {
            return [];
        }

        /** @var list<RoundResultChangeReceipt> $receipts */
        $receipts = $this->entityManager->createQueryBuilder()
            ->select('receipt')
            ->from(RoundResultChangeReceipt::class, 'receipt')
            ->where('receipt.id IN (:ids)')
            ->setParameter('ids', $changeIds)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($receipts as $receipt) {
            $byId[strtolower($receipt->id->toString())] = $receipt;
        }

        return $byId;
    }

    public function save(RoundResultChangeReceipt $receipt): void
    {
        $this->entityManager->persist($receipt);
    }

    /**
     * A bulk prune of old receipts (a cron) - rows that must not be loaded one by one.
     */
    public function deleteOlderThan(DateTimeImmutable $before): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(RoundResultChangeReceipt::class, 'receipt')
            ->where('receipt.receivedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
