<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ListingRequest;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ListingRequest;
use SpeedPuzzling\Web\Entity\ListingRequestItem;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Repository\ListingRequestRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Value\NotificationType;

/**
 * The one place that closes listing request items when their listing goes (docs/features/marketplace/12-listing-requests.md):
 * every handler that sells or deletes a listing calls it BEFORE the delete. Every item and offer of the listing lets go
 * of it here (listingLeft()) - an entity loaded in the same unit of work must not point at a removed one at the flush.
 *
 * - sold or swapped to the item's buyer (named, or - nobody named - the buyer it was reserved for) → sold
 * - sold to somebody else (or nobody named), removed by the seller or an admin, dropped as a duplicate by a puzzle merge →
 *   unavailable, and the buyer is told once per request of the call (ListingRequestUnavailable) unless a block came
 *   between the two meanwhile
 *
 * Only open items (waiting / yes) change. Batch handlers pass all their listings in one call, so a buyer hears once.
 * The daily expiry closes items whose listing is gone without this having run (closeOrphans()).
 */
readonly final class ListingRequestCloser
{
    public function __construct(
        private ListingRequestRepository $listingRequestRepository,
        private NotificationRepository $notificationRepository,
        private GetUserBlocks $getUserBlocks,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<SellSwapListItem> $listings
     * @param bool $isSale sold or swapped (not removed) - with nobody named, to the buyer it was reserved for
     * @param null|Player $soldTo the registered buyer the seller named
     */
    public function listingsGoing(array $listings, bool $isSale = false, null|Player $soldTo = null): void
    {
        $items = [];

        foreach ($listings as $listing) {
            // Sold without naming anybody while reserved for a buyer: that buyer, most likely
            $buyerId = $soldTo !== null ? $soldTo->id : $listing->reservedForPlayerId;

            foreach ($this->listingRequestRepository->itemsForListing($listing) as $item) {
                if ($item->isOpen()) {
                    $items[] = [$item, $isSale && $buyerId !== null && $item->buyer->id->equals($buyerId)];
                }

                // An item loaded in this unit of work must not point at the deleted listing at the flush
                $item->listingLeft();
            }

            foreach ($this->listingRequestRepository->offersForListing($listing) as $offer) {
                $offer->listingLeft();
            }
        }

        $this->close($items);
    }

    /**
     * Open items whose listing is gone without listingsGoing() having run - something went around it. Answers how many.
     */
    public function closeOrphans(): int
    {
        $items = $this->listingRequestRepository->openItemsWithoutListing();
        $this->close(array_map(static fn (ListingRequestItem $item): array => [$item, false], $items));

        return count($items);
    }

    /**
     * @param list<array{ListingRequestItem, bool}> $items each item and whether it went to that item's buyer
     */
    private function close(array $items): void
    {
        $now = $this->clock->now();
        /** @var array<string, ListingRequest> $toTell */
        $toTell = [];

        foreach ($items as [$item, $soldToItsBuyer]) {
            if ($soldToItsBuyer) {
                $item->markSold($now);

                continue;
            }

            if ($item->markUnavailable($now)) {
                $toTell[$item->request->id->toString()] = $item->request;
            }
        }

        foreach ($toTell as $request) {
            $buyerId = $request->buyer->id->toString();
            $sellerId = $request->seller->id->toString();

            if ($this->getUserBlocks->isBlocked($buyerId, $sellerId) || $this->getUserBlocks->isBlocked($sellerId, $buyerId)) {
                continue;
            }

            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $request->buyer,
                NotificationType::ListingRequestUnavailable,
                $now,
                actorPlayer: $request->seller,
                targetListingRequest: $request,
            ));
        }
    }
}
