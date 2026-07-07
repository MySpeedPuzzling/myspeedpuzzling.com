<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Message\DeleteCollection;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeleteCollectionHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
    ) {
    }

    /**
     * @throws CollectionNotFound
     */
    public function __invoke(DeleteCollection $message): void
    {
        $collection = $this->collectionRepository->get($message->collectionId);

        if ($collection->player->id->toString() !== $message->playerId) {
            throw new CollectionNotFound();
        }

        $this->collectionRepository->delete($collection);
    }
}
