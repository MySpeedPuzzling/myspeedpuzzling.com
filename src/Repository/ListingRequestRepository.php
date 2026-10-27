<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ListingRequest;
use SpeedPuzzling\Web\Entity\ListingRequestItem;
use SpeedPuzzling\Web\Entity\ListingRequestOffer;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Exceptions\ListingRequestNotFound;
use SpeedPuzzling\Web\Value\ListingRequestItemStatus;

readonly final class ListingRequestRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ListingRequest $request): void
    {
        $this->entityManager->persist($request);
    }

    public function saveItem(ListingRequestItem $item): void
    {
        $this->entityManager->persist($item);
    }

    public function saveOffer(ListingRequestOffer $offer): void
    {
        $this->entityManager->persist($offer);
    }

    /**
     * @throws ListingRequestNotFound
     */
    public function get(string $requestId): ListingRequest
    {
        $request = Uuid::isValid($requestId) ? $this->entityManager->find(ListingRequest::class, $requestId) : null;

        if ($request === null) {
            throw new ListingRequestNotFound();
        }

        return $request;
    }

    /**
     * The requests one send of the buyer created - a resent form finds them.
     *
     * @return list<ListingRequest>
     */
    public function findBySend(string $buyerId, string $sendId): array
    {
        if (Uuid::isValid($buyerId) === false || Uuid::isValid($sendId) === false) {
            return [];
        }

        /** @var list<ListingRequest> */
        return $this->entityManager->getRepository(ListingRequest::class)->findBy([
            'buyer' => $buyerId,
            'sendId' => $sendId,
        ]);
    }

    /**
     * @return list<ListingRequestItem>
     */
    public function itemsOf(ListingRequest $request): array
    {
        /** @var list<ListingRequestItem> */
        return $this->entityManager->getRepository(ListingRequestItem::class)->findBy(
            ['request' => $request],
            ['id' => 'ASC'],
        );
    }

    /**
     * @return list<ListingRequestOffer>
     */
    public function offersOf(ListingRequest $request): array
    {
        /** @var list<ListingRequestOffer> */
        return $this->entityManager->getRepository(ListingRequestOffer::class)->findBy(
            ['request' => $request],
            ['id' => 'ASC'],
        );
    }

    /**
     * Every ask of this listing, open or closed - when the listing is sold or leaves the list, the open ones close and
     * all of them let go of it.
     *
     * @return list<ListingRequestItem>
     */
    public function itemsForListing(SellSwapListItem $listing): array
    {
        /** @var list<ListingRequestItem> */
        return $this->entityManager->getRepository(ListingRequestItem::class)->findBy(['listing' => $listing], ['id' => 'ASC']);
    }

    /**
     * Every offer of this listing (the buyer's own) - they let go of it when it leaves the list.
     *
     * @return list<ListingRequestOffer>
     */
    public function offersForListing(SellSwapListItem $listing): array
    {
        /** @var list<ListingRequestOffer> */
        return $this->entityManager->getRepository(ListingRequestOffer::class)->findBy(['listing' => $listing]);
    }

    /**
     * Open asks whose listing is gone without ListingRequestCloser having closed them - the daily safety net.
     *
     * @return list<ListingRequestItem>
     */
    public function openItemsWithoutListing(): array
    {
        /** @var list<ListingRequestItem> */
        return $this->entityManager->createQueryBuilder()
            ->select('item')
            ->from(ListingRequestItem::class, 'item')
            ->where('item.listing IS NULL')
            ->andWhere('item.status IN (:open)')
            ->setParameter('open', [ListingRequestItemStatus::Waiting, ListingRequestItemStatus::Yes])
            ->orderBy('item.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Requests past their end that still hold a waiting listing - the daily expiry.
     *
     * @return list<ListingRequest>
     */
    public function expiredWithWaitingItems(DateTimeImmutable $now): array
    {
        /** @var list<ListingRequest> */
        return $this->entityManager->createQueryBuilder()
            ->select('request')
            ->from(ListingRequest::class, 'request')
            ->where('request.expiresAt <= :now')
            ->andWhere('EXISTS (SELECT item.id FROM ' . ListingRequestItem::class . ' item WHERE item.request = request AND item.status = :waiting)')
            ->setParameter('now', $now)
            ->setParameter('waiting', ListingRequestItemStatus::Waiting)
            ->getQuery()
            ->getResult();
    }
}
