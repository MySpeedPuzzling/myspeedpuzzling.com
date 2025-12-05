<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleToWishList;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleToWishListHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private WishListItemRepository $wishListItemRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     */
    public function __invoke(AddPuzzleToWishList $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        $existingItem = $this->wishListItemRepository->findByPlayerAndPuzzle($player, $puzzle);

        if ($existingItem !== null) {
            $existingItem->changeRemoveOnCollectionAdd($message->removeOnCollectionAdd);
            return;
        }

        $wishListItem = new WishListItem(
            Uuid::uuid7(),
            $player,
            $puzzle,
            $message->removeOnCollectionAdd,
            new DateTimeImmutable(),
        );

        $this->wishListItemRepository->save($wishListItem);
    }
}
