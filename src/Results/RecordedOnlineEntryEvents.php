<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What RecordOnlineEntryEventsHandler made of a batch of a phone's events - the answer of
 * `POST /api/compete/{token}/events`: every acknowledged id (stored now, or stored before - an event sent again), every
 * refused event with its reason (`unknown_type`, `invalid_fields`, `wrong_round`, `not_entered`). An event in neither
 * list is sent again by the phone. `activeDeviceChanged`: a `device.ready` handed the entry to another device - the
 * heartbeat's cached lookup (OnlineAccessLight) must be read again.
 */
readonly final class RecordedOnlineEntryEvents
{
    public function __construct(
        /** @var list<string> lower case */
        public array $acks,
        /** @var list<array{id: null|string, reason: string}> */
        public array $refused,
        public bool $activeDeviceChanged,
    ) {
    }
}
