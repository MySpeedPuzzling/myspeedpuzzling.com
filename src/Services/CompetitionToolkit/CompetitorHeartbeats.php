<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Value\CompetitorHeartbeat;
use SpeedPuzzling\Web\Value\CompetitorHeartbeatRecord;

/**
 * What the server last heard from a competitor's own page, per round entry and signed-in player (reliability hardening
 * T2, docs/features/competitions-management/competitor-pages.md "Timing you can trust"): the last heartbeat and the
 * last clean one (no unsent tap on the phone). Telemetry, never application state - redis-state with an expiry, never
 * Postgres (RedisCompetitorHeartbeats; the test environment keeps InMemoryCompetitorHeartbeats).
 */
interface CompetitorHeartbeats
{
    public function beat(string $roundId, string $entry, string $playerId, CompetitorHeartbeat $heartbeat, int $heardAtMs): void;

    public function of(string $roundId, string $entry, string $playerId): null|CompetitorHeartbeatRecord;
}
