<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromAllCollections;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Takes the selected puzzles out of every collection of the player, the system one included. Puzzles in none of them
 * (a borrowed puzzle on the unsolved page) are counted as skipped.
 */
#[AsMessageHandler]
readonly final class RemovePuzzlesFromAllCollectionsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private CollectionItemRepository $collectionItemRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(RemovePuzzlesFromAllCollections $message): SelectedPuzzlesOutcome
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzleIds = array_values(array_unique($message->puzzleIds));
        $removed = [];

        foreach ($this->collectionItemRepository->findByPlayerAndPuzzles($player, $puzzleIds) as $item) {
            $removed[$item->puzzle->id->toString()] = true;
            $this->collectionItemRepository->delete($item);
        }

        return new SelectedPuzzlesOutcome(
            changed: count($removed),
            skipped: count($puzzleIds) - count($removed),
        );
    }
}
