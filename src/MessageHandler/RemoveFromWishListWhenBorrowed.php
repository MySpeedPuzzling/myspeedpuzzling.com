<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\PuzzleBorrowed;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemoveFromWishListWhenBorrowed
{
    public function __construct(
        private WishListItemRepository $wishListItemRepository,
    ) {
    }

    public function __invoke(PuzzleBorrowed $event): void
    {
        $wishListItem = $this->wishListItemRepository->findByPlayerIdAndPuzzleIdWithRemoveFlag(
            $event->borrowerPlayerId,
            $event->puzzleId,
        );

        if ($wishListItem === null) {
            return;
        }

        $this->wishListItemRepository->delete($wishListItem);
    }
}
