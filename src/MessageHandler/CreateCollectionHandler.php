<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Collection;
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
     */
    public function __invoke(CreateCollection $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $collection = new Collection(
            id: Uuid::uuid7(),
            player: $player,
            name: $message->name,
            description: $message->description,
            visibility: $message->visibility,
            createdAt: new DateTimeImmutable(),
        );

        $this->collectionRepository->save($collection);
    }
}
