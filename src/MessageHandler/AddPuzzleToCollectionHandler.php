<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PuzzleCollectionItem;
use SpeedPuzzling\Web\Message\AddPuzzleToCollection;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleCollectionItemRepository;
use SpeedPuzzling\Web\Repository\PuzzleCollectionRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleToCollectionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private PuzzleCollectionRepository $collectionRepository,
        private PuzzleCollectionItemRepository $collectionItemRepository,
    ) {
    }

    public function __invoke(AddPuzzleToCollection $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        $collection = null;
        if ($message->collectionId !== null) {
            $collection = $this->collectionRepository->get($message->collectionId);
        }

        // Check if puzzle already exists in any collection
        $existingItem = $this->collectionItemRepository->findByPlayerAndPuzzle($player, $puzzle);

        if ($existingItem !== null) {
            // If puzzle is in same collection, just update it
            if (
                $existingItem->collection === $collection ||
                ($existingItem->collection === null && $collection === null)
            ) {
                // Update existing item
                if ($message->comment !== null) {
                    $existingItem->updateComment($message->comment);
                }
                if ($message->price !== null || $message->condition !== null) {
                    $existingItem->updateForSale($message->price, $message->condition);
                }
                $this->entityManager->flush();
                return;
            }

            // For custom collections, move the puzzle instead of throwing error
            if ($collection === null || !$collection->isSystemCollection()) {
                // Store the old collection name for the warning message
                $oldCollectionName = $existingItem->collection?->getDisplayName() ?? 'My Collection';

                // Remove from old collection
                $this->entityManager->remove($existingItem);
                $this->entityManager->flush();

                // Create new item in new collection
                $item = new PuzzleCollectionItem(
                    $message->itemId,
                    $collection,
                    $puzzle,
                    $player,
                    new DateTimeImmutable(),
                );

                if ($message->comment !== null) {
                    $item->updateComment($message->comment);
                }

                if ($message->price !== null || $message->condition !== null) {
                    $item->updateForSale($message->price, $message->condition);
                }

                $this->entityManager->persist($item);
                $this->entityManager->flush();

                // The controller will handle showing the warning message
                return;
            }
        }

        // Create new item
        $item = new PuzzleCollectionItem(
            $message->itemId,
            $collection,
            $puzzle,
            $player,
            new DateTimeImmutable(),
        );

        if ($message->comment !== null) {
            $item->updateComment($message->comment);
        }

        if ($message->price !== null || $message->condition !== null) {
            $item->updateForSale($message->price, $message->condition);
        }

        $this->entityManager->persist($item);
        $this->entityManager->flush();
    }
}
