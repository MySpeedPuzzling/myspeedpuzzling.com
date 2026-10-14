<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntryEvent;
use SpeedPuzzling\Web\Exceptions\OnlineEntryEventNotFound;

readonly final class OnlineEntryEventRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws OnlineEntryEventNotFound
     */
    public function get(string $eventId): OnlineEntryEvent
    {
        if (!Uuid::isValid($eventId)) {
            throw new OnlineEntryEventNotFound();
        }

        $event = $this->entityManager->find(OnlineEntryEvent::class, $eventId);

        return $event ?? throw new OnlineEntryEventNotFound();
    }

    /**
     * Which of the given event ids are stored already (any entry) - an event sent again is acknowledged, never stored
     * twice. Ids that are no UUID are left out.
     *
     * @param list<string> $eventIds
     * @return list<string> lower-case
     */
    public function existingIds(array $eventIds): array
    {
        $eventIds = array_values(array_unique(array_map(
            strtolower(...),
            array_filter($eventIds, static fn (string $id): bool => Uuid::isValid($id)),
        )));

        if ($eventIds === []) {
            return [];
        }

        /** @var list<string> $existing */
        $existing = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT id::text FROM online_entry_event WHERE id IN (:ids)',
            ['ids' => $eventIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return $existing;
    }

    /**
     * How many events of each type an online entry has from a moment on (its round's current run: from the start of the
     * round clock - an earlier run's events stay stored and are not counted)
     *
     * @return array<string, int> OnlineEventType value => count, types without an event left out
     */
    public function typeCounts(string $onlineEntryId, int $fromMs): array
    {
        if (!Uuid::isValid($onlineEntryId)) {
            return [];
        }

        /** @var array<string, int|string> $counts */
        $counts = $this->entityManager->getConnection()->fetchAllKeyValue(
            'SELECT type, COUNT(*) FROM online_entry_event WHERE online_entry_id = :onlineEntryId AND client_ms >= :fromMs GROUP BY type',
            ['onlineEntryId' => $onlineEntryId, 'fromMs' => $fromMs],
            ['fromMs' => ParameterType::INTEGER],
        );

        return array_map(static fn (int|string $count): int => (int) $count, $counts);
    }

    public function save(OnlineEntryEvent $event): void
    {
        $this->entityManager->persist($event);
    }

    public function delete(OnlineEntryEvent $event): void
    {
        $this->entityManager->remove($event);
    }
}
