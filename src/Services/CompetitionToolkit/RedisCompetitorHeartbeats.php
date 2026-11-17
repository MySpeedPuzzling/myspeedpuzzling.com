<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Redis;
use RuntimeException;
use SpeedPuzzling\Web\Value\CompetitorHeartbeat;
use SpeedPuzzling\Web\Value\CompetitorHeartbeatRecord;

/**
 * Competitor heartbeats in the `redis-state` instance (reliability hardening T2): one hash per round,
 * `{prefix}{roundId}`, field `{entry}|{playerId}` = the last heartbeat as JSON, field `{entry}|{playerId}#clean` = the
 * last clean one's evidence moment and receipt. One Lua call per heartbeat (HSET, the clean HSET when clean, EXPIRE -
 * one atomic round trip), one HMGET per proposal. Postgres never sees a heartbeat.
 *
 * redis-state is capped with noeviction, so nothing lives there without an expiry: a round's hash goes TTL_SECONDS
 * after its last heartbeat (a round of 300 competitors is ~200 KB). Stateless between requests apart from the shared
 * connection (FrankenPHP worker mode), like RedisOnlinePresence.
 */
final readonly class RedisCompetitorHeartbeats implements CompetitorHeartbeats
{
    public const int TTL_SECONDS = 6 * 3600;
    public const string CLEAN_SUFFIX = '#clean';

    private const string BEAT_SCRIPT = <<<'LUA'
redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
if ARGV[3] ~= '' then
    redis.call('HSET', KEYS[1], ARGV[1] .. '#clean', ARGV[3])
end
redis.call('EXPIRE', KEYS[1], ARGV[4])
return 1
LUA;

    public function __construct(
        private Redis $redis,
        private string $keyPrefix = 'competitor:heartbeat:',
    ) {
    }

    public function beat(string $roundId, string $entry, string $playerId, CompetitorHeartbeat $heartbeat, int $heardAtMs): void
    {
        $clean = $heartbeat->isClean() ? $heartbeat->cleanRecord($heardAtMs) : null;
        $arguments = [
            // KEYS
            $this->key($roundId),
            // ARGV
            self::field($entry, $playerId),
            json_encode($heartbeat->stored($heardAtMs), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            $clean !== null ? json_encode($clean, JSON_THROW_ON_ERROR) : '',
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

            throw new RuntimeException('Recording a competitor heartbeat failed: ' . ($error ?? 'unknown Redis error'));
        }
    }

    public function of(string $roundId, string $entry, string $playerId): null|CompetitorHeartbeatRecord
    {
        $field = self::field($entry, $playerId);
        $values = $this->redis->hMGet($this->key($roundId), [$field, $field . self::CLEAN_SUFFIX]);

        if (!is_array($values)) {
            throw new RuntimeException('Reading a competitor heartbeat failed: ' . ($this->redis->getLastError() ?? 'unknown Redis error'));
        }

        $last = self::decode($values[$field] ?? false);
        $clean = self::decode($values[$field . self::CLEAN_SUFFIX] ?? false);

        return $last === [] && $clean === [] ? null : CompetitorHeartbeatRecord::fromStored($last, $clean);
    }

    public static function field(string $entry, string $playerId): string
    {
        return strtolower($entry) . '|' . strtolower($playerId);
    }

    /**
     * @return array<mixed>
     */
    private static function decode(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function key(string $roundId): string
    {
        return $this->keyPrefix . strtolower($roundId);
    }
}
