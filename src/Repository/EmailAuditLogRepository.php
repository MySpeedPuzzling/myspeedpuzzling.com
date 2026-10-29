<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\EmailAuditLog;
use SpeedPuzzling\Web\Exceptions\EmailAuditLogNotFound;
use SpeedPuzzling\Web\Value\SesEvent;

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

    /**
     * Null when the row is gone - audit rows are pruned (CleanupEmailAuditLogs) and SES events can arrive late
     */
    public function find(UuidInterface $id): null|EmailAuditLog
    {
        return $this->entityManager->find(EmailAuditLog::class, $id);
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
     * An e-mail to the address bounced, at or before $before, because its domain could not be found in DNS
     */
    public function hasDomainNotFoundBounce(string $recipientEmail, \DateTimeImmutable $before): bool
    {
        /** @var list<string> $reasons */
        $reasons = $this->entityManager->createQueryBuilder()
            ->select('log.bounceReason')
            ->from(EmailAuditLog::class, 'log')
            // The address as SES reported it or lowercased - an IN keeps the recipient_email index usable
            ->where('log.recipientEmail IN (:emails)')
            ->andWhere('log.bouncedAt <= :before')
            ->andWhere('log.bounceReason IS NOT NULL')
            ->setParameter('emails', array_values(array_unique([trim($recipientEmail), mb_strtolower(trim($recipientEmail))])))
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleColumnResult();

        foreach ($reasons as $reason) {
            if (preg_match(SesEvent::DOMAIN_NOT_FOUND_PATTERN, $reason) === 1) {
                return true;
            }
        }

        return false;
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
