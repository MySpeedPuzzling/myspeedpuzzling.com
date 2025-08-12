<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PlayerPuzzleCollection;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Repository\PlayerPuzzleCollectionRepository;
use SpeedPuzzling\Web\Services\CollectionSystemFolders;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
readonly final class ManageSystemCollectionsHandler
{
    public function __construct(
        private PlayerPuzzleCollectionRepository $playerPuzzleCollectionRepository,
        private CollectionSystemFolders $systemFolders,
    ) {
    }

    public function __invoke(PuzzleSolved $event): void
    {
        $this->addToSolvedCollection($event);
    }

    private function addToSolvedCollection(PuzzleSolved $event): void
    {
        $existingCollection = $this->playerPuzzleCollectionRepository->findByPlayerAndPuzzle(
            $event->playerId,
            $event->puzzleId
        );

        // If puzzle is already in user's collection, don't add it again
        if ($existingCollection !== null) {
            return;
        }

        // Add puzzle to solved system collection
        $solvedFolder = $this->systemFolders->getSolvedFolder($event->player);
        
        $collection = new PlayerPuzzleCollection(
            Uuid::uuid7(),
            $event->player,
            $event->puzzle,
            $solvedFolder,
        );

        $this->playerPuzzleCollectionRepository->save($collection);
    }
}