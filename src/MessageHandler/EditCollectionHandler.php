<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Message\EditCollection;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditCollectionHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
    ) {
    }

    /**
     * @throws CollectionNotFound
     */
    public function __invoke(EditCollection $message): void
    {
        $collection = $this->collectionRepository->get($message->collectionId);

        $collection->changeName($message->name);
        $collection->changeDescription($message->description);
        $collection->changeVisibility($message->visibility);

        $this->collectionRepository->save($collection);
    }
}
