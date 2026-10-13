<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

/**
 * Who is there in an online round right now (docs/features/online-competitions/README.md §5.6 "Presence"): the last
 * heartbeat of every participant's phone - device, camera, detector, clock quality, visibility. Telemetry, never
 * application state: it lives in redis-state, never in Postgres, and expires on its own.
 */
interface OnlinePresence
{
    /**
     * @param array<string, mixed> $data the heartbeat as the phone sent it (deviceId, visible, camera, detector, clock, outbox)
     */
    public function beat(string $roundId, string $participantId, array $data, int $nowMs): void;

    /**
     * @return array<string, array<string, mixed>> lower-case participant id => their last heartbeat, with `lastSeenMs`
     */
    public function forRound(string $roundId): array;
}
