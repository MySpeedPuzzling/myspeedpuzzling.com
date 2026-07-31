<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\AuthAuditEventOverview;
use SpeedPuzzling\Web\Services\UserAgentLabeler;
use SpeedPuzzling\Web\Value\AuthAuditEventType;

readonly final class GetAuthAuditEvents
{
    /**
     * Only the user-meaningful subset shows on the recent-activity page.
     * Failures are included deliberately - "someone tried to get in" is the
     * point of the page.
     */
    private const array VISIBLE_EVENT_TYPES = [
        'login_success',
        'sign_in_link_used',
        'login_failure',
        'password_changed',
        'password_reset_completed',
        'oauth_login',
        'oauth_identity_linked',
        'oauth_identity_unlinked',
    ];

    public function __construct(
        private Connection $database,
        private UserAgentLabeler $userAgentLabeler,
    ) {
    }

    /**
     * @return array<AuthAuditEventOverview>
     */
    public function recentForUserAccount(string $userAccountId, int $limit = 50): array
    {
        $query = <<<SQL
SELECT event_type, occurred_at, ip_address, user_agent
FROM auth_audit_log
WHERE user_account_id = :userAccountId
    AND event_type IN (:eventTypes)
ORDER BY occurred_at DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery(
                $query,
                [
                    'userAccountId' => $userAccountId,
                    'eventTypes' => self::VISIBLE_EVENT_TYPES,
                    'limit' => $limit,
                ],
                [
                    'eventTypes' => ArrayParameterType::STRING,
                ],
            )
            ->fetchAllAssociative();

        return array_map(function (array $row): AuthAuditEventOverview {
            /** @var array{
             *     event_type: string,
             *     occurred_at: string,
             *     ip_address: null|string,
             *     user_agent: null|string,
             * } $row
             */

            return new AuthAuditEventOverview(
                eventType: AuthAuditEventType::from($row['event_type']),
                occurredAt: new DateTimeImmutable($row['occurred_at']),
                ipAddress: $row['ip_address'],
                deviceLabel: $this->userAgentLabeler->label($row['user_agent']),
            );
        }, $data);
    }
}
