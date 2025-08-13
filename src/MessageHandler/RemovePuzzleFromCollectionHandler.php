<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RemovePuzzleFromCollection;
use SpeedPuzzling\Web\Repository\PlayerPuzzleCollectionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemovePuzzleFromCollectionHandler
{
    public function __construct(
        private PlayerPuzzleCollectionRepository $playerPuzzleCollectionRepository,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     */
    public function __invoke(RemovePuzzleFromCollection $message): void
    {
        $collection = $this->playerPuzzleCollectionRepository->get($message->playerId, $message->puzzleId);

        $this->playerPuzzleCollectionRepository->remove($collection);
    }
}
