<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromWishList;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Takes the selected puzzles off the player's wishlist; ids not on it (removed meanwhile) are counted as skipped.
 */
#[AsMessageHandler]
readonly final class RemovePuzzlesFromWishListHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private WishListItemRepository $wishListItemRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(RemovePuzzlesFromWishList $message): SelectedPuzzlesOutcome
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzleIds = array_values(array_unique($message->puzzleIds));
        $items = $this->wishListItemRepository->findByPlayerAndPuzzles($player, $puzzleIds);

        foreach ($items as $item) {
            $this->wishListItemRepository->delete($item);
        }

        return new SelectedPuzzlesOutcome(
            changed: count($items),
            skipped: count($puzzleIds) - count($items),
        );
    }
}
