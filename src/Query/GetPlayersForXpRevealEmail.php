<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

/**
 * Launch reveal-email audience: everyone reachable who has not opted out of the
 * experience system and has not received the reveal yet (idempotency anchor =
 * content_digest_log rows with digest_type 'xp_reveal').
 */
readonly class GetPlayersForXpRevealEmail
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string>
     */
    public function execute(): array
    {
        $sql = <<<SQL
SELECT id FROM (
    SELECT DISTINCT ON (LOWER(p.email)) p.id
    FROM player p
    WHERE p.email IS NOT NULL
      AND p.email_notifications_enabled = true
      AND p.experience_system_opted_out = false
      AND NOT EXISTS (
        SELECT 1 FROM content_digest_log l
        WHERE l.player_id = p.id AND l.digest_type = 'xp_reveal'
      )
    ORDER BY LOWER(p.email), p.registered_at ASC
) eligible
ORDER BY id
SQL;

        /** @var list<string> $ids */
        $ids = $this->database->executeQuery($sql)->fetchFirstColumn();

        return $ids;
    }
}
