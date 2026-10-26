<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\BorrowRequestNotFound;
use SpeedPuzzling\Web\Message\WithdrawBorrowRequest;
use SpeedPuzzling\Web\Repository\BorrowRequestRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Only the asker may withdraw - anybody else gets the same 404 as for a request that does not exist. Closed puzzles
 * stay as they are; the owner is not told.
 */
#[AsMessageHandler]
readonly final class WithdrawBorrowRequestHandler
{
    public function __construct(
        private BorrowRequestRepository $borrowRequestRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws BorrowRequestNotFound
     */
    public function __invoke(WithdrawBorrowRequest $message): void
    {
        $request = $this->borrowRequestRepository->get($message->requestId);

        if ($request->asker->id->toString() !== strtolower($message->askerId)) {
            throw new BorrowRequestNotFound();
        }

        $now = $this->clock->now();

        foreach ($this->borrowRequestRepository->itemsOf($request) as $item) {
            if ($message->itemId === null || $item->id->toString() === strtolower($message->itemId)) {
                $item->withdraw($now);
            }
        }
    }
}
