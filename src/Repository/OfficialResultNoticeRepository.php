<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * The "this player was told about their official result in this round" marker (OfficialResultNotice).
 */
readonly final class OfficialResultNoticeRepository
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * True for exactly one caller per player and round, ever: a notification run for a republish and one for a late
     * result meet on the unique index - the second waits for the first's transaction and then gets nothing. Called
     * inside the transaction that writes the notification, so a rolled-back run leaves no marker behind.
     * Doctrine has no ON CONFLICT, hence the SQL.
     */
    public function claim(string $playerId, string $roundId, DateTimeImmutable $notifiedAt): bool
    {
        $insertedRows = $this->database->executeStatement(
            <<<SQL
INSERT INTO official_result_notice (id, player_id, round_id, notified_at)
VALUES (:id, :playerId, :roundId, :notifiedAt)
ON CONFLICT (player_id, round_id) DO NOTHING
SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $playerId,
                'roundId' => $roundId,
                'notifiedAt' => $notifiedAt->format('Y-m-d H:i:s'),
            ],
        );

        return $insertedRows === 1;
    }
}
