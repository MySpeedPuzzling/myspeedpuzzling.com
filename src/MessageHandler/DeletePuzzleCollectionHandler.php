<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\DeletePuzzleCollection;
use SpeedPuzzling\Web\Repository\PuzzleCollectionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class DeletePuzzleCollectionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PuzzleCollectionRepository $collectionRepository,
    ) {
    }

    public function __invoke(DeletePuzzleCollection $message): void
    {
        $collection = $this->collectionRepository->get($message->collectionId);

        $collection->delete();

        // Delete all items in the collection
        $items = $this->entityManager->createQuery(
            'SELECT item FROM SpeedPuzzling\Web\Entity\PuzzleCollectionItem item WHERE item.collection = :collection'
        )
            ->setParameter('collection', $collection)
            ->getResult();

        foreach ($items as $item) {
            $this->entityManager->remove($item);
        }

        $this->entityManager->remove($collection);
        $this->entityManager->flush();
    }
}