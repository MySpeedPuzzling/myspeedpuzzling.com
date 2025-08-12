<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionFolder;
use SpeedPuzzling\Web\Message\CreateCollectionFolder;
use SpeedPuzzling\Web\Repository\CollectionFolderRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CreateCollectionFolderHandler
{
    public function __construct(
        private CollectionFolderRepository $collectionFolderRepository,
        private PlayerRepository $playerRepository,
    ) {
    }

    public function __invoke(CreateCollectionFolder $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $folder = new CollectionFolder(
            Uuid::uuid7(),
            $player,
            $message->name,
            false, // not a system folder
            $message->color,
            $message->description,
        );

        $this->collectionFolderRepository->save($folder);
    }
}