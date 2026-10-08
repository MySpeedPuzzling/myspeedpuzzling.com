<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Message\MovePuzzleToCollection;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MovePuzzleToCollectionHandler
{
    public function __construct(
        private CollectionItemRepository $collectionItemRepository,
        private CollectionRepository $collectionRepository,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private SecretPuzzleAccess $secretPuzzleAccess,
    ) {
    }

    /**
     * @throws CollectionNotFound
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     * @throws PuzzleNotRevealedYet
     */
    public function __invoke(MovePuzzleToCollection $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        // Both collections must be the player's own - the form field is free text, so any id can arrive here
        $sourceCollection = $this->collectionRepository->getOwnedBy($message->sourceCollectionId, $player);
        $targetCollection = $this->collectionRepository->getOwnedBy($message->targetCollectionId, $player);

        $sourceItem = $this->collectionItemRepository->findByCollectionPlayerAndPuzzle($sourceCollection, $player, $puzzle);
        $existingTargetItem = $this->collectionItemRepository->findByCollectionPlayerAndPuzzle($targetCollection, $player, $puzzle);

        if ($existingTargetItem !== null) {
            // Already in the target: it only leaves the source, the comment goes to the target item
            if ($sourceItem !== null && $sourceItem !== $existingTargetItem) {
                $this->collectionItemRepository->delete($sourceItem);
            }

            if ($message->comment !== null) {
                $existingTargetItem->changeComment($message->comment);
            }

            return;
        }

        if ($sourceItem !== null) {
            // Moving is not re-adding: the item keeps its id and added date
            $sourceItem->moveTo($targetCollection);
            $sourceItem->changeComment($message->comment);

            return;
        }

        // Nothing to move: this adds the puzzle - a secret competition puzzle takes nothing personal before its
        // reveal, from anybody (SecretPuzzleAccess)
        $this->secretPuzzleAccess->assertPuzzleWritableBy($puzzle, $message->playerId);

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
