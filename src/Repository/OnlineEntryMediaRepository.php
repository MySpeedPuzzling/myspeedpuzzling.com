<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Exceptions\OnlineEntryMediaNotFound;

readonly final class OnlineEntryMediaRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws OnlineEntryMediaNotFound
     */
    public function get(string $mediaId): OnlineEntryMedia
    {
        if (!Uuid::isValid($mediaId)) {
            throw new OnlineEntryMediaNotFound();
        }

        $media = $this->entityManager->find(OnlineEntryMedia::class, $mediaId);

        return $media ?? throw new OnlineEntryMediaNotFound();
    }

    public function save(OnlineEntryMedia $media): void
    {
        $this->entityManager->persist($media);
    }

    public function delete(OnlineEntryMedia $media): void
    {
        $this->entityManager->remove($media);
    }
}
