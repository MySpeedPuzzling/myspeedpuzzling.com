<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

/**
 * Presence kept in the process - the test environment's OnlinePresence (config/services_test.php), and every
 * environment's until the redis-state implementation is bound.
 *
 * Deliberately not reset between requests of one kernel (like InMemoryApiUsageCounter): a test's heartbeat must be
 * visible to the control room request that follows it. It holds one beat per participant and round, so a long-running
 * worker keeps no more than the latest state of the rounds it saw.
 */
final class InMemoryOnlinePresence implements OnlinePresence
{
    /** @var array<string, array<string, array<string, mixed>>> round id => participant id => last beat */
    private array $beats = [];

    public function beat(string $roundId, string $participantId, array $data, int $nowMs): void
    {
        $this->beats[strtolower($roundId)][strtolower($participantId)] = [...$data, 'lastSeenMs' => $nowMs];
    }

    public function forRound(string $roundId): array
    {
        return $this->beats[strtolower($roundId)] ?? [];
    }
}
