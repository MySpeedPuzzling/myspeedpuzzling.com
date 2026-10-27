<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Message\ExpireListingRequests;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Repository\ListingRequestRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Services\ListingRequest\ListingRequestCloser;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Listing requests past ListingRequest::EXPIRES_AFTER_DAYS close their waiting listings - nobody answered
 * (docs/features/marketplace/12-listing-requests.md). A yes never expires: it holds a reservation the seller manages.
 * The buyer is told, unless a block came between the two meanwhile. Each request is loaded and changed as an object -
 * there are few of them a day.
 *
 * Also the safety net of ListingRequestCloser: open asks whose listing is gone without it having run are closed as
 * unavailable, with a warning - something deleted a listing around the closer.
 */
#[AsMessageHandler]
readonly final class ExpireListingRequestsHandler
{
    public function __construct(
        private ListingRequestRepository $listingRequestRepository,
        private NotificationRepository $notificationRepository,
        private ListingRequestCloser $listingRequestCloser,
        private GetUserBlocks $getUserBlocks,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ExpireListingRequests $message): int
    {
        $orphans = $this->listingRequestCloser->closeOrphans();

        if ($orphans > 0) {
            $this->logger->warning('Listing request asks were open for listings that no longer exist - a listing was deleted around ListingRequestCloser', [
                'count' => $orphans,
            ]);
        }

        $now = $this->clock->now();
        $expired = 0;

        foreach ($this->listingRequestRepository->expiredWithWaitingItems($now) as $request) {
            $closed = false;

            foreach ($this->listingRequestRepository->itemsOf($request) as $item) {
                $closed = $item->expire($now) || $closed;
            }

            if ($closed === false) {
                continue;
            }

            $expired++;
            $buyerId = $request->buyer->id->toString();
            $sellerId = $request->seller->id->toString();

            if ($this->getUserBlocks->isBlocked($buyerId, $sellerId) || $this->getUserBlocks->isBlocked($sellerId, $buyerId)) {
                continue;
            }

            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $request->buyer,
                NotificationType::ListingRequestExpired,
                $now,
                actorPlayer: $request->seller,
                targetListingRequest: $request,
            ));
        }

        return $expired;
    }
}
