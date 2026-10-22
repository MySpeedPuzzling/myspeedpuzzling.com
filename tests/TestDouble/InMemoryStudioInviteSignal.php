<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use SpeedPuzzling\Web\Services\LiveKit\StudioInviteSignal;

/**
 * StudioInviteSignal without Redis - the test environment's (config/services_test.php; the functional tests run no
 * Redis). STATIC on purpose: the organiser's invitation and the participant's next heartbeat are two requests, and the
 * WebTestCase browser reboots the kernel between them. Call reset() in setUp().
 */
final class InMemoryStudioInviteSignal implements StudioInviteSignal
{
    /** @var array<string, array{inviteId: string, expiresAt: int}> */
    private static array $signals = [];

    public function raise(string $roundId, string $participantId, string $inviteId, int $ttlSeconds): void
    {
        self::$signals[self::key($roundId, $participantId)] = [
            'inviteId' => strtolower($inviteId),
            // Real time on purpose: it stands in for a Redis TTL, which Redis counts on its own clock
            'expiresAt' => time() + max(1, $ttlSeconds),
        ];
    }

    public function open(string $roundId, string $participantId): null|string
    {
        $signal = self::$signals[self::key($roundId, $participantId)] ?? null;

        return $signal === null || $signal['expiresAt'] <= time() ? null : $signal['inviteId'];
    }

    public function clear(string $roundId, string $participantId, string $inviteId): void
    {
        $key = self::key($roundId, $participantId);

        if ((self::$signals[$key]['inviteId'] ?? null) === strtolower($inviteId)) {
            unset(self::$signals[$key]);
        }
    }

    public static function reset(): void
    {
        self::$signals = [];
    }

    private static function key(string $roundId, string $participantId): string
    {
        return strtolower($roundId) . ':' . strtolower($participantId);
    }
}
