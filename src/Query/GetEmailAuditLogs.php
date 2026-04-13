<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\EmailAuditLogDetail;
use SpeedPuzzling\Web\Results\EmailAuditLogOverview;
use SpeedPuzzling\Web\Value\BounceType;
use SpeedPuzzling\Web\Value\EmailAuditStatus;

readonly final class GetEmailAuditLogs
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<EmailAuditLogOverview>
     */
    public function list(
        int $limit = 50,
        int $offset = 0,
        null|string $recipient = null,
        null|string $status = null,
        null|string $emailType = null,
    ): array {
        $conditions = [];
        $params = [];

        if ($recipient !== null && $recipient !== '') {
            $conditions[] = 'recipient_email ILIKE :recipient';
            $params['recipient'] = '%' . $recipient . '%';
        }

        if ($status !== null && $status !== '') {
            $conditions[] = 'status = :status';
            $params['status'] = $status;
        }

        if ($emailType !== null && $emailType !== '') {
            $conditions[] = 'email_type = :emailType';
            $params['emailType'] = $emailType;
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $query = <<<SQL
SELECT
    id,
    sent_at,
    recipient_email,
    subject,
    transport_name,
    status,
    email_type,
    bounce_type
FROM email_audit_log
{$where}
ORDER BY sent_at DESC
LIMIT :limit OFFSET :offset
SQL;

        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $data = $this->database
            ->executeQuery($query, $params)
            ->fetchAllAssociative();

        return array_map(static function (array $row): EmailAuditLogOverview {
            /** @var array{
             *     id: string,
             *     sent_at: string,
             *     recipient_email: string,
             *     subject: string,
             *     transport_name: string,
             *     status: string,
             *     email_type: null|string,
             *     bounce_type: null|string,
             * } $row
             */

            return new EmailAuditLogOverview(
                id: $row['id'],
                sentAt: new DateTimeImmutable($row['sent_at']),
                recipientEmail: $row['recipient_email'],
                subject: $row['subject'],
                transportName: $row['transport_name'],
                status: EmailAuditStatus::from($row['status']),
                emailType: $row['email_type'],
                bounceType: $row['bounce_type'] !== null ? BounceType::from($row['bounce_type']) : null,
            );
        }, $data);
    }

    public function count(
        null|string $recipient = null,
        null|string $status = null,
        null|string $emailType = null,
    ): int {
        $conditions = [];
        $params = [];

        if ($recipient !== null && $recipient !== '') {
            $conditions[] = 'recipient_email ILIKE :recipient';
            $params['recipient'] = '%' . $recipient . '%';
        }

        if ($status !== null && $status !== '') {
            $conditions[] = 'status = :status';
            $params['status'] = $status;
        }

        if ($emailType !== null && $emailType !== '') {
            $conditions[] = 'email_type = :emailType';
            $params['emailType'] = $emailType;
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $query = <<<SQL
SELECT COUNT(*) FROM email_audit_log {$where}
SQL;

        /** @var int|string $result */
        $result = $this->database
            ->executeQuery($query, $params)
            ->fetchOne();

        return (int) $result;
    }

    public function byId(string $id): EmailAuditLogDetail
    {
        $query = <<<SQL
SELECT
    id,
    sent_at,
    recipient_email,
    subject,
    transport_name,
    status,
    email_type,
    message_id,
    error_message,
    smtp_debug_log,
    bounce_type,
    bounced_at,
    bounce_reason
FROM email_audit_log
WHERE id = :id
SQL;

        $row = $this->database
            ->executeQuery($query, ['id' => $id])
            ->fetchAssociative();

        if ($row === false) {
            throw new \RuntimeException('Email audit log not found');
        }

        /** @var array{
         *     id: string,
         *     sent_at: string,
         *     recipient_email: string,
         *     subject: string,
         *     transport_name: string,
         *     status: string,
         *     email_type: null|string,
         *     message_id: null|string,
         *     error_message: null|string,
         *     smtp_debug_log: null|string,
         *     bounce_type: null|string,
         *     bounced_at: null|string,
         *     bounce_reason: null|string,
         * } $row
         */

        return new EmailAuditLogDetail(
            id: $row['id'],
            sentAt: new DateTimeImmutable($row['sent_at']),
            recipientEmail: $row['recipient_email'],
            subject: $row['subject'],
            transportName: $row['transport_name'],
            status: EmailAuditStatus::from($row['status']),
            emailType: $row['email_type'],
            messageId: $row['message_id'],
            errorMessage: $row['error_message'],
            smtpDebugLog: $row['smtp_debug_log'],
            bounceType: $row['bounce_type'] !== null ? BounceType::from($row['bounce_type']) : null,
            bouncedAt: $row['bounced_at'] !== null ? new DateTimeImmutable($row['bounced_at']) : null,
            bounceReason: $row['bounce_reason'],
        );
    }

    /**
     * @return array<string>
     */
    public function distinctEmailTypes(): array
    {
        $query = <<<SQL
SELECT DISTINCT email_type FROM email_audit_log WHERE email_type IS NOT NULL ORDER BY email_type
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchFirstColumn();

        /** @var array<string> $data */
        return $data;
    }

    /**
     * @return array{sent: int, failed: int, all: int}
     */
    public function countByStatus(): array
    {
        $query = <<<SQL
SELECT
    COUNT(*) FILTER (WHERE status = 'sent') AS sent,
    COUNT(*) FILTER (WHERE status = 'failed') AS failed,
    COUNT(*) AS all_count
FROM email_audit_log
SQL;

        /** @var array{sent: int|string, failed: int|string, all_count: int|string}|false $row */
        $row = $this->database
            ->executeQuery($query)
            ->fetchAssociative();

        return [
            'sent' => (int) ($row !== false ? $row['sent'] : 0),
            'failed' => (int) ($row !== false ? $row['failed'] : 0),
            'all' => (int) ($row !== false ? $row['all_count'] : 0),
        ];
    }
}
