<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\MarkPuzzlesAsSoldOrSwapped;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Marks every selected listing sold/swapped through the single handler's steps, so each one is recorded, its
 * conversations are told and the puzzle leaves the collections and the wishlist exactly as when marked one by one.
 */
#[AsMessageHandler]
readonly final class MarkPuzzlesAsSoldOrSwappedHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private MarkPuzzleAsSoldOrSwappedHandler $markPuzzleAsSoldOrSwapped,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(MarkPuzzlesAsSoldOrSwapped $message): SelectedPuzzlesOutcome
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzleIds = array_values(array_unique($message->puzzleIds));
        $items = $this->sellSwapListItemRepository->findByPlayerAndPuzzles($player, $puzzleIds);

        // Every sale is recorded (and its conversations told) before any listing is deleted - see recordSale()
        foreach ($items as $item) {
            $this->markPuzzleAsSoldOrSwapped->recordSale($item, null, null);
        }

        foreach ($items as $item) {
            $this->markPuzzleAsSoldOrSwapped->removeEverywhere($item);
        }

        return new SelectedPuzzlesOutcome(
            changed: count($items),
            skipped: count($puzzleIds) - count($items),
        );
    }
}
