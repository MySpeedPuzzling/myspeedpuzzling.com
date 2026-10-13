<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Value\OnlineEventType;

/**
 * The latest stored moment (`client_ms`) of an online entry's events of one type - RecordOnlineEntryEventsHandler lets
 * a report replace the stored one only when it is newer on the phone's clock, so a report that arrives late (sent
 * offline, sent again) never overwrites a later one.
 */
readonly final class GetLatestOnlineEntryEventMoment
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function of(string $onlineEntryId, OnlineEventType $type): null|int
    {
        if (!Uuid::isValid($onlineEntryId)) {
            return null;
        }

        $latest = $this->database->fetchOne(
            'SELECT MAX(client_ms) FROM online_entry_event WHERE online_entry_id = :onlineEntryId AND type = :type',
            ['onlineEntryId' => $onlineEntryId, 'type' => $type->value],
        );

        return is_numeric($latest) ? (int) $latest : null;
    }
}
