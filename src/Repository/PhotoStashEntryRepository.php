<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PhotoStashEntry;

readonly final class PhotoStashEntryRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(PhotoStashEntry $entry): void
    {
        $this->entityManager->persist($entry);
    }

    public function delete(PhotoStashEntry $entry): void
    {
        $this->entityManager->remove($entry);
    }

    /**
     * Only the player's own - a token of somebody else is no entry of theirs
     */
    public function find(string $token, string $playerId): null|PhotoStashEntry
    {
        $entry = $this->entityManager->find(PhotoStashEntry::class, $token);

        return $entry !== null && $entry->player->id->toString() === strtolower($playerId) ? $entry : null;
    }

    /**
     * @return list<PhotoStashEntry> the newest first
     */
    public function ofPlayer(string $playerId): array
    {
        /** @var list<PhotoStashEntry> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(PhotoStashEntry::class, 'e')
            ->where('e.player = :player')
            ->setParameter('player', $playerId)
            ->orderBy('e.touchedAt', 'DESC')
            ->addOrderBy('e.token', 'DESC')
            ->getQuery()
            ->getResult();

        return $entries;
    }

    /**
     * The daily prune: entries nobody can use any more (PhotoStash::KEEP_HOURS). A bulk delete on purpose - every
     * expired entry of every player, none of them needs loading
     */
    public function deleteStoredBefore(DateTimeImmutable $before): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(PhotoStashEntry::class, 'e')
            ->where('e.storedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
