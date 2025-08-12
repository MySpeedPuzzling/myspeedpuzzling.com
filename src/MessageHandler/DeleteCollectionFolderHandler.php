<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\DeleteCollectionFolder;
use SpeedPuzzling\Web\Repository\CollectionFolderRepository;
use SpeedPuzzling\Web\Repository\PlayerPuzzleCollectionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteCollectionFolderHandler
{
    public function __construct(
        private CollectionFolderRepository $collectionFolderRepository,
        private PlayerPuzzleCollectionRepository $playerPuzzleCollectionRepository,
    ) {
    }

    public function __invoke(DeleteCollectionFolder $message): void
    {
        $folder = $this->collectionFolderRepository->get($message->folderId);

        // Move all puzzles in this folder to the root (no folder)
        $this->playerPuzzleCollectionRepository->moveAllPuzzlesFromFolderToRoot($folder);

        $this->collectionFolderRepository->delete($folder);
    }
}