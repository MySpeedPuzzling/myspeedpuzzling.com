<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\PlayerActivityDay;

readonly final class PlayerActivityDayRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Raw upsert instead of persist(): the (player, day) row usually already
     * exists - ON CONFLICT DO NOTHING keeps the hot path a single statement
     * and guarantees one-row-per-day even when the Redis dedup marker is gone.
     * Day boundaries are UTC.
     */
    public function recordActivity(UuidInterface $playerId, DateTimeImmutable $now): void
    {
        $utcNow = $now->setTimezone(new DateTimeZone('UTC'));

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO player_activity_day (id, player_id, day, first_seen_at)
             VALUES (:id, :playerId, :day, :firstSeenAt)
             ON CONFLICT (player_id, day) DO NOTHING',
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId->toString(),
                'day' => $utcNow->format('Y-m-d'),
                'firstSeenAt' => $utcNow->format('Y-m-d H:i:sP'),
            ],
        );
    }

    public function deleteOlderThan(DateTimeImmutable $before): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(PlayerActivityDay::class, 'a')
            ->where('a.day < :before')
            ->setParameter('before', $before->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'))
            ->getQuery()
            ->execute();
    }
}
