<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleToCollectionHandler
{
    public function __construct(
        private CollectionItemRepository $collectionItemRepository,
        private CollectionRepository $collectionRepository,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private SecretPuzzleAccess $secretPuzzleAccess,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CollectionNotFound
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     * @throws PuzzleNotRevealedYet
     */
    public function __invoke(AddPuzzleToCollection $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        // A secret competition puzzle takes nothing personal before its reveal - from anybody (SecretPuzzleAccess)
        $this->secretPuzzleAccess->assertPuzzleWritableBy($puzzle, $message->playerId);

        $collection = null;
        if ($message->collectionId !== null) {
            $collection = $this->collectionRepository->get($message->collectionId);

            if ($collection->player->id->equals($player->id) === false) {
                throw new CollectionNotFound();
            }
        }

        // Check if the combination already exists
        $existingItem = $this->collectionItemRepository->findByCollectionPlayerAndPuzzle($collection, $player, $puzzle);

        if ($existingItem !== null) {
            // Already exists, just update comment if provided
            if ($message->comment !== null) {
                $existingItem->changeComment($message->comment);
                $this->collectionItemRepository->save($existingItem);
            }
            return;
        }

        $collectionItem = new CollectionItem(
            id: Uuid::uuid7(),
            collection: $collection,
            player: $player,
            puzzle: $puzzle,
            comment: $message->comment,
            addedAt: $this->clock->now(),
        );

        $this->collectionItemRepository->save($collectionItem);
    }
}
