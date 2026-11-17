<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Value\CompetitorHeartbeat;
use SpeedPuzzling\Web\Value\CompetitorHeartbeatRecord;

/**
 * Competitor heartbeats kept in the process - the test environment's CompetitorHeartbeats (config/services_test.php).
 * Deliberately not reset between requests of one kernel (like InMemoryOnlinePresence): a test's heartbeat must be seen
 * by the proposal request that follows it. One record per round, entry and player.
 */
final class InMemoryCompetitorHeartbeats implements CompetitorHeartbeats
{
    /** @var array<string, array{last: array<string, mixed>, clean: array<string, mixed>}> */
    private array $records = [];

    public function beat(string $roundId, string $entry, string $playerId, CompetitorHeartbeat $heartbeat, int $heardAtMs): void
    {
        $key = strtolower($roundId) . ' ' . RedisCompetitorHeartbeats::field($entry, $playerId);
        $this->records[$key] = [
            'last' => $heartbeat->stored($heardAtMs),
            'clean' => $heartbeat->isClean() ? $heartbeat->cleanRecord($heardAtMs) : ($this->records[$key]['clean'] ?? []),
        ];
    }

    public function of(string $roundId, string $entry, string $playerId): null|CompetitorHeartbeatRecord
    {
        $record = $this->records[strtolower($roundId) . ' ' . RedisCompetitorHeartbeats::field($entry, $playerId)] ?? null;

        return $record === null ? null : CompetitorHeartbeatRecord::fromStored($record['last'], $record['clean']);
    }

    public function forget(): void
    {
        $this->records = [];
    }
}
