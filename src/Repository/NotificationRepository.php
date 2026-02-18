<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Notification;

readonly final class NotificationRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function save(Notification $notification): void
    {
        $this->entityManager->persist($notification);
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
