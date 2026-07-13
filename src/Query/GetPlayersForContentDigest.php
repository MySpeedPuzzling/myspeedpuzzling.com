<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\ContentDigestLog;

/**
 * Weekly-digest eligibility (content-digest README §7/§8 + §1.10 deltas):
 *
 *  - has email + global email kill-switch on
 *  - frequency daily OR weekly (daily subscribes to both digests)
 *  - experience-system opt-outs excluded entirely (locked §1.10 delta)
 *  - not already logged for this period (re-run safe)
 *  - never two no-activity digests in a row: skip players whose most recent weekly
 *    digest was the no-activity variant AND who have not solved anything since
 *  - one player per email address (family accounts sharing an inbox)
 */
readonly class GetPlayersForContentDigest
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string>
     */
    public function weekly(string $periodKey): array
    {
        $sql = <<<SQL
SELECT id FROM (
    SELECT DISTINCT ON (LOWER(p.email)) p.id, p.email
    FROM player p
    WHERE p.email IS NOT NULL
      AND p.email_notifications_enabled = true
      AND p.experience_system_opted_out = false
      AND p.content_digest_frequency IN ('daily', 'weekly')
      AND NOT EXISTS (
        SELECT 1 FROM content_digest_log l
        WHERE l.player_id = p.id
          AND l.digest_type = 'weekly'
          AND l.period_key = :periodKey
      )
      AND NOT EXISTS (
        SELECT 1 FROM content_digest_log last_log
        WHERE last_log.player_id = p.id
          AND last_log.digest_type = 'weekly'
          AND last_log.status = :sentStatus
          AND last_log.had_activity = false
          AND last_log.sent_at = (
            SELECT MAX(l2.sent_at) FROM content_digest_log l2
            WHERE l2.player_id = p.id
              AND l2.digest_type = 'weekly'
              AND l2.status = :sentStatus
          )
          AND NOT EXISTS (
            SELECT 1 FROM puzzle_solving_time pst
            WHERE pst.player_id = p.id
              AND pst.tracked_at >= last_log.sent_at
          )
      )
    ORDER BY LOWER(p.email), p.registered_at ASC
) eligible
ORDER BY id
SQL;

        /** @var list<string> $ids */
        $ids = $this->database
            ->executeQuery($sql, [
                'periodKey' => $periodKey,
                'sentStatus' => ContentDigestLog::STATUS_SENT,
            ])
            ->fetchFirstColumn();

        return $ids;
    }
}
