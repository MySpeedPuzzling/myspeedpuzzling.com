<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\MovePuzzleToFolder;
use SpeedPuzzling\Web\Repository\CollectionFolderRepository;
use SpeedPuzzling\Web\Repository\PlayerPuzzleCollectionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MovePuzzleToFolderHandler
{
    public function __construct(
        private PlayerPuzzleCollectionRepository $playerPuzzleCollectionRepository,
        private CollectionFolderRepository $collectionFolderRepository,
    ) {
    }

    public function __invoke(MovePuzzleToFolder $message): void
    {
        $collection = $this->playerPuzzleCollectionRepository->findByPlayerAndPuzzle(
            $message->playerId,
            $message->puzzleId
        );

        if ($collection === null) {
            throw new \DomainException('Puzzle not found in player collection');
        }

        $folder = null;
        if ($message->folderId !== null) {
            $folder = $this->collectionFolderRepository->get($message->folderId);
        }

        $collection->moveToFolder($folder);

        $this->playerPuzzleCollectionRepository->save($collection);
    }
}