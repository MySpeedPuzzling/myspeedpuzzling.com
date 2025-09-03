<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCollection;
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
    ) {
    }

    public function __invoke(RemovePuzzleFromCollection $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $collection = $this->collectionRepository->get($message->collectionId);

        $item = $this->collectionItemRepository->findByCollectionAndPuzzle($collection, $puzzle);

        if ($item !== null) {
            $item->remove();
            
            $this->entityManager->remove($item);
            $this->entityManager->flush();
        }
    }
}