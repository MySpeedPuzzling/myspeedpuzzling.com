<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\ListingRequestNotFound;
use SpeedPuzzling\Web\Message\RemoveListingReservation;
use SpeedPuzzling\Web\Message\WithdrawListingRequest;
use SpeedPuzzling\Web\Repository\ListingRequestRepository;
use SpeedPuzzling\Web\Value\ListingRequestItemStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Only the buyer may withdraw - anybody else gets the same 404 as for a request that does not exist
 * (docs/features/marketplace/12-listing-requests.md). Closed listings stay as they are; the seller is not told by a
 * notification. A yes whose reservation this request made - and that is still the buyer's - is released through the
 * existing RemoveListingReservation (the "reservation removed" system message tells the conversations).
 */
#[AsMessageHandler]
readonly final class WithdrawListingRequestHandler
{
    public function __construct(
        private ListingRequestRepository $listingRequestRepository,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ListingRequestNotFound
     */
    public function __invoke(WithdrawListingRequest $message): void
    {
        $request = $this->listingRequestRepository->get($message->requestId);

        if ($request->buyer->id->toString() !== strtolower($message->buyerId)) {
            throw new ListingRequestNotFound();
        }

        $now = $this->clock->now();

        foreach ($this->listingRequestRepository->itemsOf($request) as $item) {
            if ($message->itemId !== null && $item->id->toString() !== strtolower($message->itemId)) {
                continue;
            }

            $wasReservedByRequest = $item->status === ListingRequestItemStatus::Yes && $item->reservedByRequest;

            if ($item->withdraw($now) === false || $wasReservedByRequest === false) {
                continue;
            }

            $listing = $item->listing;

            if ($listing !== null && $listing->reserved && $listing->reservedForPlayerId?->equals($request->buyer->id) === true) {
                $this->messageBus->dispatch(new RemoveListingReservation(
                    sellSwapListItemId: $listing->id->toString(),
                    playerId: $request->seller->id->toString(),
                ));
            }
        }
    }
}
