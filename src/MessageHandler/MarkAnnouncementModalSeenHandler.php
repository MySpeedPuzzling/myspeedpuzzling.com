<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\MarkAnnouncementModalSeen;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MarkAnnouncementModalSeenHandler
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The browser reporting that the modal really opened. Measurement only - it can confirm an
     * impression that was claimed, never create one, and the first report stands.
     */
    public function __invoke(MarkAnnouncementModalSeen $message): void
    {
        $this->database->executeStatement(
            <<<SQL
UPDATE player_modal_impression
SET seen_at = :seenAt
WHERE player_id = :playerId AND modal = :modal AND seen_at IS NULL
SQL,
            [
                'seenAt' => $this->clock->now()->format('Y-m-d H:i:s'),
                'playerId' => $message->playerId,
                'modal' => $message->modal->value,
            ],
        );
    }
}
