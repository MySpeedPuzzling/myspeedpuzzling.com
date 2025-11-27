<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\RemovePuzzleFromWishList;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemovePuzzleFromWishListHandler
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
    public function __invoke(RemovePuzzleFromWishList $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        $existingItem = $this->wishListItemRepository->findByPlayerAndPuzzle($player, $puzzle);

        if ($existingItem === null) {
            return;
        }

        $this->wishListItemRepository->delete($existingItem);
    }
}
