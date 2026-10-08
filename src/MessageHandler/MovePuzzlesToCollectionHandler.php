<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\MovePuzzlesToCollection;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Two statements for any selection: the selected items of the source, then which of their puzzles the target holds.
 * A moved item keeps its id, added date and comment; one the target already holds only leaves the source. A puzzle no
 * longer in the source (moved from another tab) is skipped, not an error - the whole move is one transaction.
 */
#[AsMessageHandler]
readonly final class MovePuzzlesToCollectionHandler
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
    public function __invoke(MovePuzzlesToCollection $message): SelectedPuzzlesOutcome
    {
        $player = $this->playerRepository->get($message->playerId);
        $source = $this->collectionRepository->getOwnedBy($message->sourceCollectionId, $player);
        $target = $this->collectionRepository->getOwnedBy($message->targetCollectionId, $player);
        $puzzleIds = array_unique($message->puzzleIds);

        if ($source === $target) {
            return new SelectedPuzzlesOutcome(changed: 0, skipped: count($puzzleIds));
        }

        $sourceItems = $this->collectionItemRepository->findByCollectionPlayerAndPuzzles($source, $player, $puzzleIds);
        $targetItems = $this->collectionItemRepository->findByCollectionPlayerAndPuzzles($target, $player, array_keys($sourceItems));

        foreach ($sourceItems as $puzzleId => $item) {
            if (isset($targetItems[$puzzleId])) {
                $this->collectionItemRepository->delete($item);
                continue;
            }

            $item->moveTo($target);
        }

        return new SelectedPuzzlesOutcome(
            changed: count($sourceItems) - count($targetItems),
            alreadyThere: count($targetItems),
            skipped: count($puzzleIds) - count($sourceItems),
        );
    }
}
