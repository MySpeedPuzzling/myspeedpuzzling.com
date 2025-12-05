<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemoveFromWishListWhenPuzzleSolved
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private WishListItemRepository $wishListItemRepository,
    ) {
    }

    public function __invoke(PuzzleSolved $event): void
    {
        $puzzleSolvingTime = $this->puzzleSolvingTimeRepository->get($event->puzzleSolvingTimeId->toString());

        $wishListItem = $this->wishListItemRepository->findByPlayerIdAndPuzzleIdWithRemoveFlag(
            $puzzleSolvingTime->player->id->toString(),
            $puzzleSolvingTime->puzzle->id->toString(),
        );

        if ($wishListItem === null) {
            return;
        }

        $this->wishListItemRepository->delete($wishListItem);
    }
}
