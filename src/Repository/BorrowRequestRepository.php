<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\BorrowRequest;
use SpeedPuzzling\Web\Entity\BorrowRequestItem;
use SpeedPuzzling\Web\Exceptions\BorrowRequestNotFound;
use SpeedPuzzling\Web\Value\BorrowRequestItemStatus;

readonly final class BorrowRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(BorrowRequest $request): void
    {
        $this->entityManager->persist($request);
    }

    public function saveItem(BorrowRequestItem $item): void
    {
        $this->entityManager->persist($item);
    }

    /**
     * @throws BorrowRequestNotFound
     */
    public function get(string $requestId): BorrowRequest
    {
        $request = Uuid::isValid($requestId) ? $this->entityManager->find(BorrowRequest::class, $requestId) : null;

        if ($request === null) {
            throw new BorrowRequestNotFound();
        }

        return $request;
    }

    /**
     * The requests one send of the asker created - a resent form finds them.
     *
     * @return list<BorrowRequest>
     */
    public function findBySend(string $askerId, string $sendId): array
    {
        if (Uuid::isValid($askerId) === false || Uuid::isValid($sendId) === false) {
            return [];
        }

        /** @var list<BorrowRequest> */
        return $this->entityManager->getRepository(BorrowRequest::class)->findBy([
            'asker' => $askerId,
            'sendId' => $sendId,
        ]);
    }

    /**
     * @return list<BorrowRequestItem>
     */
    public function itemsOf(BorrowRequest $request): array
    {
        /** @var list<BorrowRequestItem> */
        return $this->entityManager->getRepository(BorrowRequestItem::class)->findBy(
            ['request' => $request],
            ['id' => 'ASC'],
        );
    }

    /**
     * The asker's waiting asks of these owners - the handler skips a puzzle already waiting for the same owner.
     *
     * @param list<string> $ownerIds
     * @return list<BorrowRequestItem>
     */
    public function waitingItemsOf(string $askerId, array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        /** @var list<BorrowRequestItem> */
        return $this->entityManager->createQueryBuilder()
            ->select('item')
            ->from(BorrowRequestItem::class, 'item')
            ->where('item.asker = :askerId')
            ->andWhere('item.owner IN (:ownerIds)')
            ->andWhere('item.status = :waiting')
            ->setParameter('askerId', $askerId)
            ->setParameter('ownerIds', $ownerIds)
            ->setParameter('waiting', BorrowRequestItemStatus::Waiting)
            ->getQuery()
            ->getResult();
    }

    /**
     * The open asks (waiting or yes) of this asker for this puzzle at this owner - closed as lent when the box changes
     * hands.
     *
     * @return list<BorrowRequestItem>
     */
    public function openItemsFor(string $askerId, string $ownerId, string $puzzleId): array
    {
        /** @var list<BorrowRequestItem> */
        return $this->entityManager->createQueryBuilder()
            ->select('item')
            ->from(BorrowRequestItem::class, 'item')
            ->where('item.asker = :askerId')
            ->andWhere('item.owner = :ownerId')
            ->andWhere('item.puzzle = :puzzleId')
            ->andWhere('item.status IN (:open)')
            ->setParameter('askerId', $askerId)
            ->setParameter('ownerId', $ownerId)
            ->setParameter('puzzleId', $puzzleId)
            ->setParameter('open', [BorrowRequestItemStatus::Waiting, BorrowRequestItemStatus::Yes])
            ->getQuery()
            ->getResult();
    }

    /**
     * Requests past their end that still hold an open puzzle - the daily expiry.
     *
     * @return list<BorrowRequest>
     */
    public function expiredWithOpenItems(DateTimeImmutable $now): array
    {
        /** @var list<BorrowRequest> */
        return $this->entityManager->createQueryBuilder()
            ->select('request')
            ->from(BorrowRequest::class, 'request')
            ->where('request.expiresAt <= :now')
            ->andWhere('EXISTS (SELECT item.id FROM ' . BorrowRequestItem::class . ' item WHERE item.request = request AND item.status IN (:open))')
            ->setParameter('now', $now)
            ->setParameter('open', [BorrowRequestItemStatus::Waiting, BorrowRequestItemStatus::Yes])
            ->getQuery()
            ->getResult();
    }
}
