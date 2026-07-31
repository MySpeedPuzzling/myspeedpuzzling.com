<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\AuthAuditEvent;

readonly final class AuthAuditEventRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(AuthAuditEvent $event): void
    {
        $this->entityManager->persist($event);
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(AuthAuditEvent::class, 'e')
            ->where('e.occurredAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
