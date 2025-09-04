<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleCollection;
use SpeedPuzzling\Web\Message\CreatePuzzleCollection;
use SpeedPuzzling\Web\Repository\PuzzleCollectionRepository;
use Symfony\Component\Messenger\MessageBusInterface;

final class EnsurePlayerSystemCollections
{
    private const SYSTEM_COLLECTIONS = [
        PuzzleCollection::SYSTEM_COMPLETED => ['name' => 'Completed', 'public' => true],
        PuzzleCollection::SYSTEM_WISHLIST => ['name' => 'Wishlist', 'public' => false],
        PuzzleCollection::SYSTEM_TODO => ['name' => 'To Do List', 'public' => false],
        PuzzleCollection::SYSTEM_BORROWED_TO => ['name' => 'Borrowed To Others', 'public' => false],
        PuzzleCollection::SYSTEM_BORROWED_FROM => ['name' => 'Borrowed From Others', 'public' => false],
        PuzzleCollection::SYSTEM_FOR_SALE => ['name' => 'For Sale', 'public' => true],
        PuzzleCollection::SYSTEM_MY_COLLECTION => ['name' => 'My Collection', 'public' => false],
    ];

    public function __construct(
        private readonly PuzzleCollectionRepository $collectionRepository,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function ensureForPlayer(Player $player): void
    {
        foreach (self::SYSTEM_COLLECTIONS as $systemType => $config) {
            $existingCollection = $this->collectionRepository->findSystemCollection($player, $systemType);

            if ($existingCollection === null) {
                $this->messageBus->dispatch(new CreatePuzzleCollection(
                    collectionId: Uuid::uuid7(),
                    playerId: $player->id->toString(),
                    name: $config['name'],
                    description: null,
                    isPublic: $config['public'],
                    systemType: $systemType,
                ));
            }
        }
    }
}
