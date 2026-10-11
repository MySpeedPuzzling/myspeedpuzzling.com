<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ContentDigestLog;
use SpeedPuzzling\Web\Value\ContentDigestFrequency;

/**
 * The "one e-mail per player and period" marker of the activity digest (ContentDigestLog). Doctrine has no
 * ON CONFLICT, hence the SQL - the same claim pattern as OfficialResultNoticeRepository.
 */
readonly final class ContentDigestLogRepository
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * True for exactly one caller per player and period: a redelivered message waits on the unique index for the
     * first delivery's transaction and then gets nothing. A `deferred` row (the server asked us to try later) is
     * taken over by the retry. Called inside the handler's transaction before sending, so a crash or a rollback
     * leaves no marker behind.
     */
    public function claim(
        string $playerId,
        ContentDigestFrequency $frequency,
        string $periodKey,
        bool $hadActivity,
        DateTimeImmutable $now,
        string $status = ContentDigestLog::STATUS_SENT,
    ): bool {
        $affectedRows = $this->database->executeStatement(
            <<<SQL
INSERT INTO content_digest_log (id, player_id, frequency, period_key, sent_at, had_activity, status)
VALUES (:id, :playerId, :frequency, :periodKey, :now, :hadActivity, :status)
ON CONFLICT (player_id, period_key) DO UPDATE
    SET status = EXCLUDED.status, sent_at = EXCLUDED.sent_at, had_activity = EXCLUDED.had_activity
    WHERE content_digest_log.status = :deferred
SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId,
                'frequency' => $frequency->value,
                'periodKey' => $periodKey,
                'now' => $now->format(DATE_ATOM),
                'hadActivity' => $hadActivity ? 'true' : 'false',
                'status' => $status,
                'deferred' => ContentDigestLog::STATUS_DEFERRED,
            ],
        );

        return $affectedRows === 1;
    }

    /**
     * The claimed row's outcome when sending did not succeed (STATUS_FAILED_PERMANENT, STATUS_DEFERRED).
     */
    public function markOutcome(string $playerId, string $periodKey, string $status): void
    {
        $this->database->executeStatement(
            'UPDATE content_digest_log SET status = :status WHERE player_id = :playerId AND period_key = :periodKey',
            ['status' => $status, 'playerId' => $playerId, 'periodKey' => $periodKey],
        );
    }
}
