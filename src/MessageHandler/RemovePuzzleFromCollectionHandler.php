<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PuzzleCollection;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCollection;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleCollectionRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleCollectionItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemovePuzzleFromCollectionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PuzzleRepository $puzzleRepository,
        private PuzzleCollectionRepository $collectionRepository,
        private PuzzleCollectionItemRepository $collectionItemRepository,
        private PlayerRepository $playerRepository,
    ) {
    }

    public function __invoke(RemovePuzzleFromCollection $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $player = $this->playerRepository->get($message->playerId);

        $collection = null;
        if ($message->collectionId !== null) {
            $collection = $this->collectionRepository->get($message->collectionId);
        } else {
            // When no collection ID is provided, use the player's "My Collection" system collection
            $collection = $this->collectionRepository->findSystemCollection($player, PuzzleCollection::SYSTEM_MY_COLLECTION);
        }

        $item = null;
        if ($collection !== null) {
            $item = $this->collectionItemRepository->findByCollectionAndPuzzle($collection, $puzzle);
        }

        if ($item !== null) {
            $item->remove();

            $this->entityManager->remove($item);
            $this->entityManager->flush();
        }
    }
}
