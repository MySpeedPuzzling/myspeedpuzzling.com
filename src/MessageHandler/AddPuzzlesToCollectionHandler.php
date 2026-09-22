<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AddPuzzlesToCollection;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Value\MultiscanAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzlesToCollectionHandler
{
    public function __construct(
        private MultiscanBatchGuard $guard,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private CollectionRepository $collectionRepository,
        private AddPuzzleToCollectionHandler $addPuzzleToCollection,
    ) {
    }

    /**
     * @throws MultiscanBatchRejected
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     * @throws CollectionNotFound
     */
    public function __invoke(AddPuzzlesToCollection $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        if ($message->collectionId !== null) {
            $collection = $this->collectionRepository->get($message->collectionId);

            if ($collection->player->id->equals($player->id) === false) {
                throw new CollectionNotFound();
            }
        }

        // Validate everything first, write afterwards
        foreach ($message->puzzleIds as $puzzleId) {
            $this->puzzleRepository->get($puzzleId);
        }

        $report = $this->guard->check(MultiscanAction::AddToLibrary, $message->playerId, $message->puzzleIds, $message->collectionId);

        foreach ($report->eligible as $puzzleId) {
            ($this->addPuzzleToCollection)(new AddPuzzleToCollection(
                playerId: $message->playerId,
                puzzleId: $puzzleId,
                collectionId: $message->collectionId,
                comment: $message->comment,
            ));
        }
    }
}
