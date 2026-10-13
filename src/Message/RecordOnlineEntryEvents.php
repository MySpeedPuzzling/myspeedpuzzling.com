<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A batch of what a participant's phone reported (`POST /api/compete/{token}/events`, docs/features/online-competitions/
 * implementation-plan.md §3.1): each event with the phone's own UUIDv7, idempotent - an event seen before is
 * acknowledged again, never stored twice; an invalid one is refused, never fatal. Creates the participant's OnlineEntry
 * on first contact. The handler answers `{acks, refused}` (HandledStamp).
 */
readonly final class RecordOnlineEntryEvents
{
    public function __construct(
        public string $participantId,
        public string $roundId,
        public string $deviceId,
        /** @var list<array<string, mixed>> the events as the phone sent them */
        public array $events,
    ) {
    }
}
