<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * How much one online entry holds already - the per-entry caps of a personal link (OnlineEntryQuota): its events, its
 * media rows, the bytes they declared and the uploads presigned lately that the phone has not reported done yet. An
 * upload the phone reported (`media.uploaded`) no longer counts as pending while the async VerifyOnlineMedia has not
 * checked it - a slow verifier never holds a phone up - nor does one marked abandoned (the phone gave its URL up: during
 * a store outage every retry is a new row).
 */
readonly final class GetOnlineEntryUsage
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function eventCount(string $onlineEntryId): int
    {
        if (!Uuid::isValid($onlineEntryId)) {
            return 0;
        }

        $count = $this->database->fetchOne('SELECT COUNT(*) FROM online_entry_event WHERE online_entry_id = :id', ['id' => $onlineEntryId]);

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return array{media: int, bytes: int, pending: int} pending = not reported uploaded, not abandoned, made after $pendingSince
     */
    public function media(string $onlineEntryId, DateTimeImmutable $pendingSince): array
    {
        if (!Uuid::isValid($onlineEntryId)) {
            return ['media' => 0, 'bytes' => 0, 'pending' => 0];
        }

        $row = $this->database->fetchAssociative(
            'SELECT COUNT(*) AS media,
                    COALESCE(SUM(declared_bytes), 0) AS bytes,
                    COUNT(*) FILTER (WHERE uploaded_at_ms IS NULL AND abandoned_at IS NULL AND created_at > :since) AS pending
             FROM online_entry_media
             WHERE online_entry_id = :id',
            ['id' => $onlineEntryId, 'since' => $pendingSince->format('Y-m-d H:i:s')],
        );

        return [
            'media' => is_array($row) && is_numeric($row['media']) ? (int) $row['media'] : 0,
            'bytes' => is_array($row) && is_numeric($row['bytes']) ? (int) $row['bytes'] : 0,
            'pending' => is_array($row) && is_numeric($row['pending']) ? (int) $row['pending'] : 0,
        ];
    }
}
