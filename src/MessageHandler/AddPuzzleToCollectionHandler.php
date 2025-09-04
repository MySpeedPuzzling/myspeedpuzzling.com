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

        // For system collections, check if already in THIS specific collection
        if ($collection !== null && $collection->isSystemCollection()) {
            $existingInThisCollection = $this->collectionItemRepository->findByCollectionAndPuzzle($collection, $puzzle);
            if ($existingInThisCollection !== null) {
                // Update existing item in same system collection
                if ($message->comment !== null) {
                    $existingInThisCollection->updateComment($message->comment);
                }
                if ($message->price !== null || $message->currency !== null || $message->condition !== null) {
                    $existingInThisCollection->updateForSale($message->price, $message->currency, $message->condition);
                }
                $this->entityManager->flush();
                return;
            }
            // If not in this system collection, create new entry (puzzle can be in multiple system collections)
        } else {
            // For custom collections, check if puzzle exists anywhere and move it
            $existingItem = $this->collectionItemRepository->findByPlayerAndPuzzle($player, $puzzle);

            if ($existingItem !== null) {
                // Check if it's already in a non-system collection
                if ($existingItem->collection === null || !$existingItem->collection->isSystemCollection()) {
                    // If puzzle is in same collection, just update it
                    if (
                        $existingItem->collection === $collection ||
                        ($existingItem->collection === null && $collection === null)
                    ) {
                        // Update existing item
                        if ($message->comment !== null) {
                            $existingItem->updateComment($message->comment);
                        }
                        if ($message->price !== null || $message->currency !== null || $message->condition !== null) {
                            $existingItem->updateForSale($message->price, $message->currency, $message->condition);
                        }
                        $this->entityManager->flush();
                        return;
                    }

                    // Move between custom collections
                    $this->entityManager->remove($existingItem);
                    $this->entityManager->flush();
                }
                // If it was in a system collection, we don't remove it, just create new entry
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

        if ($message->price !== null || $message->currency !== null || $message->condition !== null) {
            $item->updateForSale($message->price, $message->currency, $message->condition);
        }

        $this->entityManager->persist($item);
        $this->entityManager->flush();
    }
}
