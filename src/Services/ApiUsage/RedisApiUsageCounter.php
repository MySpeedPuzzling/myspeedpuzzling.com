<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ApiUsage;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Redis;
use RuntimeException;
use SpeedPuzzling\Web\Value\ApiCaller;
use SpeedPuzzling\Web\Value\ApiCallerActivity;
use SpeedPuzzling\Web\Value\ApiRequestRecord;
use SpeedPuzzling\Web\Value\ApiStatusClass;
use SpeedPuzzling\Web\Value\ApiUsageCount;
use SpeedPuzzling\Web\Value\ApiUsageSnapshot;

/**
 * API usage counters in the `redis-state` instance (noeviction + AOF - never the
 * cache Redis, whose LRU could drop them). One Lua script call per request, so
 * the request path costs one atomic round-trip and Postgres never sees it.
 *
 * Keys (all UTC; day keys expire DAY_TTL_SECONDS after their last write):
 *   {prefix}{Ymd}:requests          hash  "{callerKey}|{operation}|{statusClass}" => requests
 *   {prefix}{Ymd}:duration_ms       hash  same field => summed duration in ms
 *   {prefix}{Ymd}:peak_per_minute   hash  "{callerKey}" => busiest minute of the day
 *   {prefix}{Ymd}:last_request_at   hash  "{callerKey}" => unix time of the latest request
 *   {prefix}minute:{YmdHi}:{callerKey}    requests in that minute, TTL MINUTE_TTL_SECONDS
 *
 * Stateless between requests apart from the connection (FrankenPHP worker mode):
 * the connection is lazy, with short timeouts, and phpredis reconnects on the next
 * command after Redis restarted.
 */
final readonly class RedisApiUsageCounter implements ApiUsageCounter
{
    public const int DAY_TTL_SECONDS = 3 * 86400;
    public const int MINUTE_TTL_SECONDS = 120;

    private const string FIELD_SEPARATOR = '|';

    private const string RECORD_SCRIPT = <<<'LUA'
redis.call('HINCRBY', KEYS[1], ARGV[1], 1)
redis.call('HINCRBY', KEYS[2], ARGV[1], ARGV[2])
local minute = redis.call('INCR', KEYS[5])
if minute == 1 then
    redis.call('EXPIRE', KEYS[5], ARGV[6])
end
local peak = tonumber(redis.call('HGET', KEYS[3], ARGV[3]) or '0')
if minute > peak then
    redis.call('HSET', KEYS[3], ARGV[3], minute)
end
local last = tonumber(redis.call('HGET', KEYS[4], ARGV[3]) or '0')
if tonumber(ARGV[4]) > last then
    redis.call('HSET', KEYS[4], ARGV[3], ARGV[4])
end
for i = 1, 4 do
    redis.call('EXPIRE', KEYS[i], ARGV[5])
end
return minute
LUA;

    public function __construct(
        private Redis $redis,
        private string $keyPrefix = 'api_usage:',
    ) {
    }

    public function record(ApiRequestRecord $record): void
    {
        $at = $record->at->setTimezone(new DateTimeZone('UTC'));
        $dayPrefix = $this->keyPrefix . $at->format('Ymd');
        $callerKey = $record->caller->key();

        $arguments = [
            // KEYS
            $dayPrefix . ':requests',
            $dayPrefix . ':duration_ms',
            $dayPrefix . ':peak_per_minute',
            $dayPrefix . ':last_request_at',
            $this->keyPrefix . 'minute:' . $at->format('YmdHi') . ':' . $callerKey,
            // ARGV
            $callerKey . self::FIELD_SEPARATOR . $record->operation . self::FIELD_SEPARATOR . $record->statusClass->value,
            (string) max(0, $record->durationMs),
            $callerKey,
            (string) $at->getTimestamp(),
            (string) self::DAY_TTL_SECONDS,
            (string) self::MINUTE_TTL_SECONDS,
        ];

        $result = $this->redis->evalSha(sha1(self::RECORD_SCRIPT), $arguments, 5);

        if ($result === false && str_starts_with((string) $this->redis->getLastError(), 'NOSCRIPT')) {
            // Redis restarted (or was never asked before) - EVAL loads the script into its cache
            $this->redis->clearLastError();
            $result = $this->redis->eval(self::RECORD_SCRIPT, $arguments, 5);
        }

        if ($result === false) {
            $error = $this->redis->getLastError();
            $this->redis->clearLastError();

            throw new RuntimeException('Recording API usage failed: ' . ($error ?? 'unknown Redis error'));
        }
    }

    public function snapshot(DateTimeImmutable $day): ApiUsageSnapshot
    {
        $utcDay = $day->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $dayPrefix = $this->keyPrefix . $utcDay->format('Ymd');

        $requests = $this->hashOfIntegers($dayPrefix . ':requests');
        $durations = $this->hashOfIntegers($dayPrefix . ':duration_ms');
        $peaks = $this->hashOfIntegers($dayPrefix . ':peak_per_minute');
        $lastRequests = $this->hashOfIntegers($dayPrefix . ':last_request_at');

        $counts = [];

        foreach ($requests as $field => $requestCount) {
            $parts = explode(self::FIELD_SEPARATOR, $field);

            if (count($parts) !== 3) {
                continue;
            }

            $statusClass = ApiStatusClass::tryFrom($parts[2]);
            $caller = $this->callerOrNull($parts[0]);

            if ($statusClass === null || $caller === null) {
                continue;
            }

            $counts[] = new ApiUsageCount(
                caller: $caller,
                operation: $parts[1],
                statusClass: $statusClass,
                requests: $requestCount,
                durationMsTotal: $durations[$field] ?? 0,
            );
        }

        $callers = [];

        foreach ($lastRequests as $callerKey => $lastRequestTimestamp) {
            $caller = $this->callerOrNull($callerKey);

            if ($caller === null) {
                continue;
            }

            $callers[] = new ApiCallerActivity(
                caller: $caller,
                peakRequestsPerMinute: $peaks[$callerKey] ?? 0,
                lastRequestAt: new DateTimeImmutable('@' . $lastRequestTimestamp),
            );
        }

        return new ApiUsageSnapshot($utcDay, $counts, $callers);
    }

    /**
     * @return array<string, int>
     */
    private function hashOfIntegers(string $key): array
    {
        $hash = $this->redis->hGetAll($key);

        if (!is_array($hash)) {
            throw new RuntimeException('Reading API usage failed: ' . ($this->redis->getLastError() ?? 'unknown Redis error'));
        }

        $integers = [];

        foreach ($hash as $field => $value) {
            if (is_numeric($value)) {
                $integers[(string) $field] = (int) $value;
            }
        }

        return $integers;
    }

    private function callerOrNull(string $key): null|ApiCaller
    {
        try {
            return ApiCaller::fromKey($key);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
