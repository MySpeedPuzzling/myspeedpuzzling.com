<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ClaimAnnouncementModalImpression;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ClaimAnnouncementModalImpressionHandler
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * True for exactly one caller per player and modal, ever: two tabs loading at the same moment, a
     * retried request, a double click - they all meet on the unique index and one of them wins.
     * Doctrine has no ON CONFLICT, hence the SQL.
     */
    public function __invoke(ClaimAnnouncementModalImpression $message): bool
    {
        $insertedRows = $this->database->executeStatement(
            <<<SQL
INSERT INTO player_modal_impression (id, player_id, modal, displayed_at)
VALUES (:id, :playerId, :modal, :displayedAt)
ON CONFLICT (player_id, modal) DO NOTHING
SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'playerId' => $message->playerId,
                'modal' => $message->modal->value,
                'displayedAt' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );

        return $insertedRows === 1;
    }
}
