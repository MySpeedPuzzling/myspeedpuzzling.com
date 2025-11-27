<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\PuzzleAddedToCollection;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemoveFromWishListWhenAddedToCollection
{
    public function __construct(
        private WishListItemRepository $wishListItemRepository,
    ) {
    }

    public function __invoke(PuzzleAddedToCollection $event): void
    {
        $wishListItem = $this->wishListItemRepository->findByPlayerIdAndPuzzleIdWithRemoveFlag(
            $event->playerId,
            $event->puzzleId,
        );

        if ($wishListItem === null) {
            return;
        }

        $this->wishListItemRepository->delete($wishListItem);
    }
}
