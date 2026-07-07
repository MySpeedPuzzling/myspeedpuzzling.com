<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CollectionItemNotFound;
use SpeedPuzzling\Web\Message\RemoveCollectionItem;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemoveCollectionItemHandler
{
    public function __construct(
        private CollectionItemRepository $collectionItemRepository,
    ) {
    }

    /**
     * @throws CollectionItemNotFound
     */
    public function __invoke(RemoveCollectionItem $message): void
    {
        $collectionItem = $this->collectionItemRepository->get($message->collectionItemId);

        if ($collectionItem->player->id->toString() !== $message->playerId) {
            throw new CollectionItemNotFound();
        }

        $this->collectionItemRepository->delete($collectionItem);
    }
}
