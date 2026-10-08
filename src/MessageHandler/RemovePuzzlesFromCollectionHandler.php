<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromCollection;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Removes the selected puzzles from this one collection - the player's other collections keep theirs.
 */
#[AsMessageHandler]
readonly final class RemovePuzzlesFromCollectionHandler
{
    public function __construct(
        private CollectionItemRepository $collectionItemRepository,
        private CollectionRepository $collectionRepository,
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws CollectionNotFound
     * @throws PlayerNotFound
     */
    public function __invoke(RemovePuzzlesFromCollection $message): SelectedPuzzlesOutcome
    {
        $player = $this->playerRepository->get($message->playerId);
        $collection = $this->collectionRepository->getOwnedBy($message->collectionId, $player);
        $puzzleIds = array_unique($message->puzzleIds);

        $items = $this->collectionItemRepository->findByCollectionPlayerAndPuzzles($collection, $player, $puzzleIds);

        foreach ($items as $item) {
            $this->collectionItemRepository->delete($item);
        }

        return new SelectedPuzzlesOutcome(
            changed: count($items),
            skipped: count($puzzleIds) - count($items),
        );
    }
}
