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
    }

    public function get(UuidInterface $id): EmailAuditLog
    {
        $log = $this->entityManager->find(EmailAuditLog::class, $id);

        if ($log === null) {
            throw new EmailAuditLogNotFound();
        }

        return $log;
    }

    /**
     * Deletes at most one batch per call (content-digest README §12) — an unbounded DELETE
     * at bulk-email volume produces a WAL burst and blocks vacuum for minutes. The caller
     * loops via message re-dispatch, so every batch commits in its own transaction.
     */
    public function deleteOlderThan(\DateTimeImmutable $before, null|string $emailTypePrefix = null, int $batchSize = 10_000): int
    {
        $typeCondition = $emailTypePrefix !== null ? 'AND email_type LIKE :emailTypePrefix' : '';

        $sql = <<<SQL
DELETE FROM email_audit_log
WHERE id IN (
    SELECT id FROM email_audit_log
    WHERE sent_at < :before {$typeCondition}
    LIMIT :batchSize
)
SQL;

        $params = [
            'before' => $before->format('Y-m-d H:i:s'),
            'batchSize' => $batchSize,
        ];

        if ($emailTypePrefix !== null) {
            $params['emailTypePrefix'] = $emailTypePrefix . '%';
        }

        return (int) $this->entityManager->getConnection()->executeStatement($sql, $params);
    }
}
