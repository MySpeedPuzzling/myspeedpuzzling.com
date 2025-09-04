<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\UpdatePuzzleCollection;
use SpeedPuzzling\Web\Repository\PuzzleCollectionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UpdatePuzzleCollectionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PuzzleCollectionRepository $collectionRepository,
    ) {
    }

    public function __invoke(UpdatePuzzleCollection $message): void
    {
        $collection = $this->collectionRepository->get($message->collectionId);

        $collection->update(
            $message->name,
            $message->description,
            $message->isPublic,
        );

        $this->entityManager->flush();
    }
}
