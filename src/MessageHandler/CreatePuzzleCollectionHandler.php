<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PuzzleCollection;
use SpeedPuzzling\Web\Message\CreatePuzzleCollection;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CreatePuzzleCollectionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
    ) {
    }

    public function __invoke(CreatePuzzleCollection $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $collection = new PuzzleCollection(
            $message->collectionId,
            $player,
            $message->name,
            new DateTimeImmutable(),
        );

        $collection->description = $message->description;
        $collection->isPublic = $message->isPublic;

        $this->entityManager->persist($collection);
        $this->entityManager->flush();
    }
}