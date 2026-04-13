<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\EmailAuditLog;
use SpeedPuzzling\Web\Exceptions\EmailAuditLogNotFound;

readonly final class EmailAuditLogRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(EmailAuditLog $log): void
    {
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    public function get(UuidInterface $id): EmailAuditLog
    {
        $log = $this->entityManager->find(EmailAuditLog::class, $id);

        if ($log === null) {
            throw new EmailAuditLogNotFound();
        }

        return $log;
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->entityManager->createQueryBuilder()
            ->delete(EmailAuditLog::class, 'e')
            ->where('e.sentAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
