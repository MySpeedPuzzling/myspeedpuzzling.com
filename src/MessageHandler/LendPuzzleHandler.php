<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PlayerPuzzleCollection;
use SpeedPuzzling\Web\Message\LendPuzzle;
use SpeedPuzzling\Web\Repository\PlayerPuzzleCollectionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CollectionSystemFolders;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class LendPuzzleHandler
{
    public function __construct(
        private PlayerPuzzleCollectionRepository $playerPuzzleCollectionRepository,
        private PlayerRepository $playerRepository,
        private CollectionSystemFolders $systemFolders,
    ) {
    }

    public function __invoke(LendPuzzle $message): void
    {
        $lender = $this->playerRepository->get($message->playerId);
        $borrower = $this->playerRepository->get($message->lendToPlayerId);

        $lenderCollection = $this->playerPuzzleCollectionRepository->findByPlayerAndPuzzle(
            $message->playerId,
            $message->puzzleId
        );

        if ($lenderCollection === null) {
            throw new \DomainException('Puzzle not found in lender collection');
        }

        // Lend the puzzle
        $lenderCollection->lendTo($borrower);

        // Move to "lended out" system folder for lender
        $lendedOutFolder = $this->systemFolders->getLendedOutFolder($lender);
        $lenderCollection->moveToFolder($lendedOutFolder);

        // Add to borrower's collection in "borrowed" system folder
        $borrowedFolder = $this->systemFolders->getLendedFromFolder($borrower);
        $borrowerCollection = new PlayerPuzzleCollection(
            Uuid::uuid7(),
            $borrower,
            $lenderCollection->puzzle,
            $borrowedFolder,
        );

        $this->playerPuzzleCollectionRepository->save($lenderCollection);
        $this->playerPuzzleCollectionRepository->save($borrowerCollection);
    }
}