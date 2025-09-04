<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleCollection;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Repository\PuzzleCollectionItemRepository;
use SpeedPuzzling\Web\Repository\PuzzleCollectionRepository;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemoveFromWishlistAndTodoWhenPuzzleSolved
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PuzzleCollectionRepository $collectionRepository,
        private PuzzleCollectionItemRepository $collectionItemRepository,
    ) {
    }

    public function __invoke(PuzzleSolved $event): void
    {
        $solvingTime = $this->puzzleSolvingTimeRepository->get($event->puzzleSolvingTimeId->toString());
        $puzzle = $solvingTime->puzzle;

        // Get all players who solved this puzzle (including team members)
        $playersToProcess = [];

        // Player is always set (non-nullable in entity)
        $playersToProcess[] = $solvingTime->player;

        if ($solvingTime->team !== null) {
            foreach ($solvingTime->team->puzzlers as $puzzler) {
                if ($puzzler->playerId !== null) {
                    $player = $this->entityManager->find(Player::class, $puzzler->playerId);
                    if ($player !== null) {
                        $playersToProcess[] = $player;
                    }
                }
            }
        }

        // Remove puzzle from wishlist and todolist for each player
        foreach ($playersToProcess as $player) {
            // Remove from wishlist
            $wishlistCollection = $this->collectionRepository->findSystemCollection($player, PuzzleCollection::SYSTEM_WISHLIST);
            if ($wishlistCollection !== null) {
                $item = $this->collectionItemRepository->findByCollectionAndPuzzle($wishlistCollection, $puzzle);
                if ($item !== null) {
                    $this->entityManager->remove($item);
                }
            }

            // Remove from todolist
            $todolistCollection = $this->collectionRepository->findSystemCollection($player, PuzzleCollection::SYSTEM_TODO);
            if ($todolistCollection !== null) {
                $item = $this->collectionItemRepository->findByCollectionAndPuzzle($todolistCollection, $puzzle);
                if ($item !== null) {
                    $this->entityManager->remove($item);
                }
            }
        }

        $this->entityManager->flush();
    }
}
