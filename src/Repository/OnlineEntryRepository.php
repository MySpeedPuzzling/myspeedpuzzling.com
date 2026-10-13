<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Exceptions\OnlineEntryNotFound;

readonly final class OnlineEntryRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws OnlineEntryNotFound
     */
    public function get(string $onlineEntryId): OnlineEntry
    {
        if (!Uuid::isValid($onlineEntryId)) {
            throw new OnlineEntryNotFound();
        }

        $onlineEntry = $this->entityManager->find(OnlineEntry::class, $onlineEntryId);

        return $onlineEntry ?? throw new OnlineEntryNotFound();
    }

    /**
     * The online state of a round entry (a CompetitionParticipantRound id) - null until the participant's phone made
     * its first contact.
     */
    public function ofEntry(string $entryId): null|OnlineEntry
    {
        if (!Uuid::isValid($entryId)) {
            return null;
        }

        /** @var null|OnlineEntry $onlineEntry */
        $onlineEntry = $this->entityManager->createQueryBuilder()
            ->select('online_entry')
            ->from(OnlineEntry::class, 'online_entry')
            ->where('online_entry.entry = :entryId')
            ->setParameter('entryId', $entryId)
            ->getQuery()
            ->getOneOrNullResult();

        return $onlineEntry;
    }

    /**
     * Every online entry of a round (its participants whose phone made contact)
     *
     * @return list<OnlineEntry>
     */
    public function ofRound(string $roundId): array
    {
        if (!Uuid::isValid($roundId)) {
            return [];
        }

        /** @var list<OnlineEntry> $onlineEntries */
        $onlineEntries = $this->entityManager->createQueryBuilder()
            ->select('online_entry')
            ->from(OnlineEntry::class, 'online_entry')
            ->innerJoin('online_entry.entry', 'entry')
            ->where('entry.round = :roundId')
            ->setParameter('roundId', strtolower($roundId))
            ->getQuery()
            ->getResult();

        return $onlineEntries;
    }

    public function save(OnlineEntry $onlineEntry): void
    {
        $this->entityManager->persist($onlineEntry);
    }

    public function delete(OnlineEntry $onlineEntry): void
    {
        $this->entityManager->remove($onlineEntry);
    }
}
