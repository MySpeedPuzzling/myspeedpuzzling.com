<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\MovePuzzleToCollection;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MovePuzzleToCollectionHandler
{
    public function __construct(
        private CollectionItemRepository $collectionItemRepository,
        private CollectionRepository $collectionRepository,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
    ) {
    }

    /**
     * @throws CollectionNotFound
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     */
    public function __invoke(MovePuzzleToCollection $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        $sourceCollection = null;
        if ($message->sourceCollectionId !== null) {
            $sourceCollection = $this->collectionRepository->get($message->sourceCollectionId);
        }

        $targetCollection = null;
        if ($message->targetCollectionId !== null) {
            $targetCollection = $this->collectionRepository->get($message->targetCollectionId);
        }

        // Find and delete item from source collection
        $sourceItem = $this->collectionItemRepository->findByCollectionPlayerAndPuzzle($sourceCollection, $player, $puzzle);

        if ($sourceItem !== null) {
            $this->collectionItemRepository->delete($sourceItem);
        }

        // Check if item already exists in target collection
        $existingTargetItem = $this->collectionItemRepository->findByCollectionPlayerAndPuzzle($targetCollection, $player, $puzzle);

        if ($existingTargetItem !== null) {
            // Already exists in target, just update comment if provided
            if ($message->comment !== null) {
                $existingTargetItem->changeComment($message->comment);
                $this->collectionItemRepository->save($existingTargetItem);
            }
            return;
        }

        // Create new item in target collection
        $collectionItem = new CollectionItem(
            id: Uuid::uuid7(),
            collection: $targetCollection,
            player: $player,
            puzzle: $puzzle,
            comment: $message->comment,
            addedAt: new DateTimeImmutable(),
        );

        $this->collectionItemRepository->save($collectionItem);
    }
}
