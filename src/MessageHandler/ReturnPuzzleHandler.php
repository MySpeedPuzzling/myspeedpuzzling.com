<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ReturnPuzzle;
use SpeedPuzzling\Web\Repository\PlayerPuzzleCollectionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ReturnPuzzleHandler
{
    public function __construct(
        private PlayerPuzzleCollectionRepository $playerPuzzleCollectionRepository,
    ) {
    }

    public function __invoke(ReturnPuzzle $message): void
    {
        $lenderCollection = $this->playerPuzzleCollectionRepository->findByPlayerAndPuzzle(
            $message->playerId,
            $message->puzzleId
        );

        if ($lenderCollection === null) {
            throw new \DomainException('Puzzle not found in lender collection');
        }

        if (!$lenderCollection->isLent()) {
            throw new \DomainException('Puzzle is not currently lent');
        }

        $borrowerId = $lenderCollection->lentTo->id->toString();

        // Return the puzzle from lender's side
        $lenderCollection->returnFromLend();
        $lenderCollection->moveToFolder(null); // Move back to root folder

        // Remove from borrower's collection
        $borrowerCollection = $this->playerPuzzleCollectionRepository->findByPlayerAndPuzzle(
            $borrowerId,
            $message->puzzleId
        );

        if ($borrowerCollection !== null) {
            $this->playerPuzzleCollectionRepository->delete($borrowerCollection);
        }

        $this->playerPuzzleCollectionRepository->save($lenderCollection);
    }
}