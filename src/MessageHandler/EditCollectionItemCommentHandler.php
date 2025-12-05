<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CollectionItemNotFound;
use SpeedPuzzling\Web\Message\EditCollectionItemComment;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditCollectionItemCommentHandler
{
    public function __construct(
        private CollectionItemRepository $collectionItemRepository,
    ) {
    }

    /**
     * @throws CollectionItemNotFound
     */
    public function __invoke(EditCollectionItemComment $message): void
    {
        $collectionItem = $this->collectionItemRepository->get($message->collectionItemId);

        // Verify ownership
        if ($collectionItem->player->id->toString() !== $message->playerId) {
            throw new CollectionItemNotFound();
        }

        $collectionItem->changeComment($message->comment);
        $this->collectionItemRepository->save($collectionItem);
    }
}
