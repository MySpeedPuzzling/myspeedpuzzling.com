<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PuzzleCollectionItem;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCollection;
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

        // Check if puzzle already exists in a custom collection
        if ($collection === null || !$collection->isSystemCollection()) {
            $existsInCustomCollection = $this->collectionItemRepository->existsInCustomCollection($player, $puzzle);
            if ($existsInCustomCollection) {
                throw new PuzzleAlreadyInCollection('Puzzle is already in another collection');
            }
        }

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
