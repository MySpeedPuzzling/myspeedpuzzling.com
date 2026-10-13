<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Redis;
use RuntimeException;

/**
 * Presence in the `redis-state` instance (docs/features/online-competitions/README.md §5.6): one hash per round,
 * `{prefix}{roundId}`, field = the participant id, value = the last heartbeat of the device holding their entry as JSON
 * with `lastSeenMs`; another device's beat goes to the field `{participantId}#other` (OTHER_DEVICE_SUFFIX), never over
 * the holder's. One Lua call per heartbeat (HSET + EXPIRE, one atomic round trip) - Postgres never sees a heartbeat.
 *
 * Every beat renews the hash's TTL_SECONDS: redis-state is capped at 64 MB with noeviction, so nothing may live there
 * without an expiry - a round's presence goes six hours after its last heartbeat. Stateless between requests apart from
 * the shared connection (FrankenPHP worker mode), like RedisApiUsageCounter.
 */
final readonly class RedisOnlinePresence implements OnlinePresence
{
    public const int TTL_SECONDS = 6 * 3600;
    public const string OTHER_DEVICE_SUFFIX = '#other';

    private const string BEAT_SCRIPT = <<<'LUA'
redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
redis.call('EXPIRE', KEYS[1], ARGV[3])
return 1
LUA;

    public function __construct(
        private Redis $redis,
        private string $keyPrefix = 'online:presence:',
    ) {
    }

    public function beat(string $roundId, string $participantId, array $data, int $nowMs, bool $holdsEntry = true): void
    {
        $value = json_encode([...$data, 'lastSeenMs' => $nowMs], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $arguments = [
            // KEYS
            $this->key($roundId),
            // ARGV
            strtolower($participantId) . ($holdsEntry ? '' : self::OTHER_DEVICE_SUFFIX),
            $value,
            (string) self::TTL_SECONDS,
        ];

        $result = $this->redis->evalSha(sha1(self::BEAT_SCRIPT), $arguments, 1);

        if ($result === false && str_starts_with((string) $this->redis->getLastError(), 'NOSCRIPT')) {
            // Redis restarted (or was never asked before) - EVAL loads the script into its cache
            $this->redis->clearLastError();
            $result = $this->redis->eval(self::BEAT_SCRIPT, $arguments, 1);
        }

        if ($result === false) {
            $error = $this->redis->getLastError();
            $this->redis->clearLastError();

            throw new RuntimeException('Recording online presence failed: ' . ($error ?? 'unknown Redis error'));
        }
    }

    public function forRound(string $roundId): array
    {
        $hash = $this->redis->hGetAll($this->key($roundId));

        if (!is_array($hash)) {
            throw new RuntimeException('Reading online presence failed: ' . ($this->redis->getLastError() ?? 'unknown Redis error'));
        }

        $holders = [];
        $others = [];

        foreach ($hash as $field => $value) {
            $beat = json_decode($value, true);

            if (!is_array($beat) || ($beat !== [] && array_is_list($beat))) {
                continue;
            }

            /** @var array<string, mixed> $beat */
            $field = strtolower((string) $field);

            if (str_ends_with($field, self::OTHER_DEVICE_SUFFIX)) {
                $others[substr($field, 0, -strlen(self::OTHER_DEVICE_SUFFIX))] = $beat;
            } else {
                $holders[$field] = $beat;
            }
        }

        return OnlinePresenceRecords::of($holders, $others);
    }

    private function key(string $roundId): string
    {
        return $this->keyPrefix . strtolower($roundId);
    }
}
