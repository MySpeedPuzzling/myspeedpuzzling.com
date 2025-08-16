<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\UpdateCollectionFolder;
use SpeedPuzzling\Web\Repository\CollectionFolderRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UpdateCollectionFolderHandler
{
    public function __construct(
        private CollectionFolderRepository $collectionFolderRepository,
    ) {
    }

    public function __invoke(UpdateCollectionFolder $message): void
    {
        $folder = $this->collectionFolderRepository->get($message->folderId);

        $folder->changeName($message->name);
        $folder->changeColor($message->color);
        $folder->changeDescription($message->description);

        $this->collectionFolderRepository->save($folder);
    }
}