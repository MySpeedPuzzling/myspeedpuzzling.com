<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Events\LendingTransferCompleted;
use SpeedPuzzling\Web\Repository\BorrowRequestRepository;
use SpeedPuzzling\Web\Value\TransferType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The puzzle changed hands - "Lend to …" by the owner or "I've got it" by the asker - so the asker's open borrow request
 * for it at that owner is done (docs/features/wishlist-availability.md). Also one still waiting: the two agreed some
 * other way. Runs in the lending handler's transaction (LendingTransferCompleted is routed sync).
 */
#[AsMessageHandler]
readonly final class CloseBorrowRequestWhenLent
{
    public function __construct(
        private BorrowRequestRepository $borrowRequestRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LendingTransferCompleted $event): void
    {
        if ($event->transferType === TransferType::Return || $event->toPlayerId === null || $event->ownerPlayerId === null) {
            return;
        }

        $items = $this->borrowRequestRepository->openItemsFor(
            $event->toPlayerId->toString(),
            $event->ownerPlayerId->toString(),
            $event->puzzleId->toString(),
        );

        foreach ($items as $item) {
            $item->markLent($this->clock->now());
        }
    }
}
