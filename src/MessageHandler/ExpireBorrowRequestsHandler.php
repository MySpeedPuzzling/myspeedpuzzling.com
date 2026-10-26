<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Message\ExpireBorrowRequests;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Repository\BorrowRequestRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Borrow requests past BorrowRequest::EXPIRES_AFTER_DAYS close every open puzzle - a waiting one nobody answered, and a
 * "yes" that never changed hands (docs/features/wishlist-availability.md). The asker is told, unless a block came
 * between the two meanwhile. Each request is loaded and changed as an object - there are few of them a day.
 */
#[AsMessageHandler]
readonly final class ExpireBorrowRequestsHandler
{
    public function __construct(
        private BorrowRequestRepository $borrowRequestRepository,
        private NotificationRepository $notificationRepository,
        private GetUserBlocks $getUserBlocks,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ExpireBorrowRequests $message): int
    {
        $now = $this->clock->now();
        $expired = 0;

        foreach ($this->borrowRequestRepository->expiredWithOpenItems($now) as $request) {
            $closed = false;

            foreach ($this->borrowRequestRepository->itemsOf($request) as $item) {
                $closed = $item->expire($now) || $closed;
            }

            if ($closed === false) {
                continue;
            }

            $expired++;
            $askerId = $request->asker->id->toString();
            $ownerId = $request->owner->id->toString();

            if ($this->getUserBlocks->isBlocked($askerId, $ownerId) || $this->getUserBlocks->isBlocked($ownerId, $askerId)) {
                continue;
            }

            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $request->asker,
                NotificationType::BorrowRequestExpired,
                $now,
                actorPlayer: $request->owner,
                targetBorrowRequest: $request,
            ));
        }

        return $expired;
    }
}
