<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Value\NotificationType;

readonly final class NotificationRepository
{
    private const int INSERT_CHUNK_SIZE = 1000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function save(Notification $notification): void
    {
        $this->entityManager->persist($notification);
    }

    /**
     * The same rows persist() of one Notification per player would write (other targets and
     * read_at stay NULL), as one multi-row INSERT instead of one INSERT per player: a solving
     * time fans out to every follower of every puzzler - hundreds for the most followed players.
     *
     * Runs immediately in the current transaction, like the flush of the persisted entities would.
     *
     * @param array<string> $playerIds
     */
    public function addForSolvingTime(
        array $playerIds,
        NotificationType $type,
        UuidInterface $solvingTimeId,
        DateTimeImmutable $notifiedAt,
    ): void {
        foreach (array_chunk($playerIds, self::INSERT_CHUNK_SIZE) as $chunk) {
            $rows = [];
            $params = [];
            $types = [];

            foreach ($chunk as $playerId) {
                $rows[] = '(?, ?, ?, ?, ?)';

                $params[] = Uuid::uuid7()->toString();
                $params[] = $playerId;
                $params[] = $type->value;
                $params[] = $notifiedAt;
                $params[] = $solvingTimeId->toString();

                $types[] = ParameterType::STRING;
                $types[] = ParameterType::STRING;
                $types[] = ParameterType::STRING;
                $types[] = Types::DATETIME_IMMUTABLE;
                $types[] = ParameterType::STRING;
            }

            $this->entityManager->getConnection()->executeStatement(
                'INSERT INTO notification (id, player_id, type, notified_at, target_solving_time_id) VALUES ' . implode(', ', $rows),
                $params,
                $types,
            );
        }
    }

    public function markNotificationAsReadForPlayer(string $playerId): void
    {
        $qb = $this->entityManager->createQueryBuilder();

        $qb->update(Notification::class, 'n')
            ->set('n.readAt', ':time')
            ->where('n.player = :playerId')
            ->setParameter('time', $this->clock->now())
            ->setParameter('playerId', $playerId)
            ->getQuery()
            ->execute();
    }
}
