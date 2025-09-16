<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Exceptions\CollectionAlreadyExists;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CreateCollectionHandler
{
    public function __construct(
        private CollectionRepository $collectionRepository,
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws CollectionAlreadyExists
     */
    public function __invoke(CreateCollection $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        // Check if collection with same name already exists for this player
        $existingCollection = $this->collectionRepository->findByNameAndPlayer($message->name, $message->playerId);
        if ($existingCollection !== null) {
            throw new CollectionAlreadyExists($existingCollection->id->toString());
        }

        $collection = new Collection(
            id: Uuid::fromString($message->collectionId),
            player: $player,
            name: $message->name,
            description: $message->description,
            visibility: $message->visibility,
            createdAt: new DateTimeImmutable(),
        );

        $this->collectionRepository->save($collection);
    }
}
