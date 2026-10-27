<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ListingRequest;
use SpeedPuzzling\Web\Entity\ListingRequestItem;
use SpeedPuzzling\Web\Entity\ListingRequestOffer;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Exceptions\ListingRequestLimitReached;
use SpeedPuzzling\Web\Exceptions\ListingRequestTooLarge;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\AskForListings;
use SpeedPuzzling\Web\Message\SendListingRequestEmail;
use SpeedPuzzling\Web\Query\GetRequestableListings;
use SpeedPuzzling\Web\Repository\ListingRequestRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Value\ListingAskOutcome;
use SpeedPuzzling\Web\Value\ListingAskSkipReason;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * "Could I have these?" (docs/features/marketplace/12-listing-requests.md). Everything is checked here, whatever the
 * sheet showed: each listing is asked for only while GetRequestableListings still lists it (it exists, no block either
 * way, never a secret puzzle), it belongs to the seller it is grouped under, is not the buyer's own, not reserved for
 * somebody else and not already asked for by the buyer. The rest is skipped with a reason - every failure of the first
 * rule is "unavailable", so a block can never be told apart from a listing that left the list.
 *
 * Offers in exchange are the buyer's own listings only, and only to a seller asked for at least one listing; anything
 * else is left out. A resent form (the same send id) finds what it created and creates nothing. The rate limit counts
 * sends, not requests. A buyer whose messaging is muted cannot ask - asking is messaging.
 */
#[AsMessageHandler]
readonly final class AskForListingsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private ListingRequestRepository $listingRequestRepository,
        private NotificationRepository $notificationRepository,
        private GetRequestableListings $getRequestableListings,
        private RateLimiterFactoryInterface $listingRequestLimiter,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws SellSwapListItemNotFound
     * @throws ListingRequestTooLarge
     * @throws ListingRequestLimitReached
     * @throws MessagingMuted
     */
    public function __invoke(AskForListings $message): ListingAskOutcome
    {
        $buyer = $this->playerRepository->get($message->buyerId);
        $buyerId = $buyer->id->toString();

        $listingsBySeller = $this->normalized($message->listingsBySeller);
        $offersBySeller = $this->normalized($message->offersBySeller, perGroupUnique: true);
        $listingCount = array_sum(array_map(count(...), $listingsBySeller));
        $tooManyOffers = array_filter($offersBySeller, static fn (array $offers): bool => count($offers) > AskForListings::MAX_OFFERS_PER_SELLER) !== [];

        if (count($listingsBySeller) > AskForListings::MAX_SELLERS || $listingCount > AskForListings::MAX_LISTINGS || $tooManyOffers) {
            throw new ListingRequestTooLarge();
        }

        $alreadySent = $this->listingRequestRepository->findBySend($buyerId, $message->sendId);

        if ($alreadySent !== []) {
            $items = array_sum(array_map(fn (ListingRequest $request): int => count($this->listingRequestRepository->itemsOf($request)), $alreadySent));

            return new ListingAskOutcome(requestsSent: count($alreadySent), listingsAsked: $items);
        }

        if ($listingsBySeller === []) {
            return new ListingAskOutcome(requestsSent: 0, listingsAsked: 0);
        }

        $now = $this->clock->now();

        if ($buyer->isMessagingMuted($now)) {
            throw new MessagingMuted();
        }

        if ($this->listingRequestLimiter->create($buyerId)->consume()->isAccepted() === false) {
            throw new ListingRequestLimitReached();
        }

        $requestable = $this->getRequestableListings->forListings($buyerId, array_merge(...array_values($listingsBySeller)));
        $skipped = [];
        $requestsSent = 0;
        $listingsAsked = 0;

        foreach ($listingsBySeller as $sellerId => $listingIds) {
            $asked = [];

            foreach ($listingIds as $listingId) {
                $listing = $requestable[$listingId] ?? null;

                if ($listing === null || $listing->sellerId !== $sellerId) {
                    $skipped[$listingId] = ListingAskSkipReason::Unavailable;
                    continue;
                }

                $reason = $listing->skipReason();

                if ($reason !== null) {
                    $skipped[$listingId] = $reason;
                    continue;
                }

                $asked[] = $listingId;
            }

            if ($asked === []) {
                continue;
            }

            $seller = $this->playerRepository->get($sellerId);
            $request = new ListingRequest(
                id: Uuid::uuid7(),
                buyer: $buyer,
                seller: $seller,
                sendId: Uuid::fromString($message->sendId),
                note: $message->notes[$sellerId] ?? null,
                createdAt: $now,
            );
            $this->listingRequestRepository->save($request);

            foreach ($asked as $listingId) {
                $this->listingRequestRepository->saveItem(new ListingRequestItem(
                    Uuid::uuid7(),
                    $request,
                    $this->sellSwapListItemRepository->get($listingId),
                ));
            }

            foreach ($offersBySeller[$sellerId] ?? [] as $offeredListingId) {
                $offered = $this->findOwnListing($offeredListingId, $buyerId);

                if ($offered !== null) {
                    $this->listingRequestRepository->saveOffer(new ListingRequestOffer(Uuid::uuid7(), $request, $offered));
                }
            }

            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $seller,
                NotificationType::ListingRequested,
                $now,
                actorPlayer: $buyer,
                targetListingRequest: $request,
            ));

            $this->messageBus->dispatch(
                new SendListingRequestEmail($request->id->toString()),
                [new DispatchAfterCurrentBusStamp()],
            );

            $requestsSent++;
            $listingsAsked += count($asked);
        }

        return new ListingAskOutcome($requestsSent, $listingsAsked, $skipped);
    }

    /**
     * The buyer's own listing - offers are never somebody else's.
     */
    private function findOwnListing(string $listingId, string $buyerId): null|SellSwapListItem
    {
        try {
            $listing = $this->sellSwapListItemRepository->get($listingId);
        } catch (SellSwapListItemNotFound) {
            return null;
        }

        return $listing->player->id->toString() === $buyerId ? $listing : null;
    }

    /**
     * Lower-case valid ids, empty groups dropped. A listing goes with the first seller it is grouped under (or, for
     * offers, once per seller).
     *
     * @param array<string, list<string>> $idsBySeller
     * @return array<string, list<string>>
     */
    private function normalized(array $idsBySeller, bool $perGroupUnique = false): array
    {
        $normalized = [];
        $seen = [];

        foreach ($idsBySeller as $sellerId => $ids) {
            $sellerId = strtolower((string) $sellerId);

            if (Uuid::isValid($sellerId) === false) {
                continue;
            }

            foreach ($ids as $id) {
                $id = strtolower($id);
                $key = $perGroupUnique ? $sellerId . '|' . $id : $id;

                if (Uuid::isValid($id) === false || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $normalized[$sellerId][] = $id;
            }
        }

        return $normalized;
    }
}
