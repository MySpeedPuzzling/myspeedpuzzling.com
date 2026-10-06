<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ApiUsage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Redis;
use SpeedPuzzling\Web\Services\ApiUsage\RedisApiUsageCounter;
use SpeedPuzzling\Web\Value\ApiCaller;
use SpeedPuzzling\Web\Value\ApiRequestRecord;
use SpeedPuzzling\Web\Value\ApiStatusClass;
use SpeedPuzzling\Web\Value\ApiUsageCount;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * The Lua script against a real Redis (`redis-state` in compose and in CI). Every
 * test writes under its own key prefix, so parallel ParaTest workers never meet.
 */
final class RedisApiUsageCounterTest extends TestCase
{
    private Redis $redis;
    private string $prefix;
    private RedisApiUsageCounter $counter;

    protected function setUp(): void
    {
        $dsn = $_SERVER['REDIS_STATE_DSN'] ?? $_ENV['REDIS_STATE_DSN'] ?? 'redis://redis-state:6379';
        self::assertIsString($dsn);

        $redis = RedisAdapter::createConnection($dsn, ['timeout' => 2]);
        self::assertInstanceOf(Redis::class, $redis);

        $this->redis = $redis;
        $this->prefix = 'test_api_usage:' . Uuid::uuid7()->toString() . ':';
        $this->counter = new RedisApiUsageCounter($this->redis, $this->prefix);
    }

    protected function tearDown(): void
    {
        $iterator = null;

        do {
            /** @var false|list<string> $keys */
            $keys = $this->redis->scan($iterator, $this->prefix . '*', 1000);

            if (is_array($keys) && $keys !== []) {
                $this->redis->del($keys);
            }
        } while ($iterator > 0);
    }

    public function testCountsRequestsPerCallerRequestTypeAndStatus(): void
    {
        $pat = ApiCaller::personalAccessToken(Uuid::uuid7()->toString(), Uuid::uuid7()->toString());
        $app = ApiCaller::oauth2Client('my-app:1|x');

        $this->counter->record(new ApiRequestRecord($pat, 'GET /api/v1/me', ApiStatusClass::Success, 12, new DateTimeImmutable('2026-10-07 10:00:05 UTC')));
        $this->counter->record(new ApiRequestRecord($pat, 'GET /api/v1/me', ApiStatusClass::Success, 30, new DateTimeImmutable('2026-10-07 10:00:40 UTC')));
        $this->counter->record(new ApiRequestRecord($pat, 'GET /api/v1/me', ApiStatusClass::ClientError, 5, new DateTimeImmutable('2026-10-07 11:30:00 UTC')));
        $this->counter->record(new ApiRequestRecord($app, 'GET /api/v1/puzzles', ApiStatusClass::Success, 100, new DateTimeImmutable('2026-10-07 23:59:59 UTC')));
        // Another UTC day - not in this snapshot
        $this->counter->record(new ApiRequestRecord($app, 'GET /api/v1/puzzles', ApiStatusClass::Success, 100, new DateTimeImmutable('2026-10-08 00:00:01 UTC')));

        $snapshot = $this->counter->snapshot(new DateTimeImmutable('2026-10-07 15:00:00 Europe/Prague'));

        self::assertSame('2026-10-07', $snapshot->day->format('Y-m-d'));

        $counts = [];

        foreach ($snapshot->counts as $count) {
            $counts[$count->caller->key() . ' ' . $count->operation . ' ' . $count->statusClass->value] = $count;
        }

        ksort($counts);

        self::assertCount(3, $counts);
        $this->assertUsage($counts[$pat->key() . ' GET /api/v1/me 2xx'], 2, 42);
        $this->assertUsage($counts[$pat->key() . ' GET /api/v1/me 4xx'], 1, 5);
        $this->assertUsage($counts[$app->key() . ' GET /api/v1/puzzles 2xx'], 1, 100);

        // The client id survives the key encoding
        self::assertSame('my-app:1|x', $counts[$app->key() . ' GET /api/v1/puzzles 2xx']->caller->oauth2ClientIdentifier);

        $callers = [];

        foreach ($snapshot->callers as $activity) {
            $callers[$activity->caller->key()] = $activity;
        }

        self::assertCount(2, $callers);
        // Two requests in the 10:00 minute, one in 11:30
        self::assertSame(2, $callers[$pat->key()]->peakRequestsPerMinute);
        self::assertSame('2026-10-07T11:30:00+00:00', $callers[$pat->key()]->lastRequestAt->format('c'));
        self::assertSame(1, $callers[$app->key()]->peakRequestsPerMinute);
    }

    public function testRecordsAgainAfterRedisForgotTheScript(): void
    {
        $caller = ApiCaller::oauth2User('some-app', Uuid::uuid7()->toString());
        $at = new DateTimeImmutable('2026-10-07 10:00:00 UTC');

        $this->counter->record(new ApiRequestRecord($caller, 'GET /api/v1/me', ApiStatusClass::Success, 1, $at));
        // What a Redis restart does to the script cache
        $this->redis->script('flush');
        $this->counter->record(new ApiRequestRecord($caller, 'GET /api/v1/me', ApiStatusClass::Success, 1, $at));

        $snapshot = $this->counter->snapshot($at);

        self::assertCount(1, $snapshot->counts);
        self::assertSame(2, $snapshot->counts[0]->requests);
    }

    public function testEveryKeyExpires(): void
    {
        $caller = ApiCaller::oauth2Client('some-app');
        $this->counter->record(new ApiRequestRecord($caller, 'GET /api/v1/puzzles', ApiStatusClass::Success, 1, new DateTimeImmutable()));

        $iterator = null;
        $keys = [];

        do {
            /** @var false|list<string> $found */
            $found = $this->redis->scan($iterator, $this->prefix . '*', 1000);
            $keys = array_merge($keys, is_array($found) ? $found : []);
        } while ($iterator > 0);

        self::assertCount(5, $keys);

        foreach ($keys as $key) {
            $ttl = $this->redis->ttl($key);
            self::assertIsInt($ttl);
            self::assertGreaterThan(0, $ttl, $key);
            self::assertLessThanOrEqual(RedisApiUsageCounter::DAY_TTL_SECONDS, $ttl, $key);
        }
    }

    public function testAnEmptyDayIsAnEmptySnapshot(): void
    {
        self::assertTrue($this->counter->snapshot(new DateTimeImmutable('2020-01-01'))->isEmpty());
    }

    private function assertUsage(ApiUsageCount $count, int $requests, int $durationMsTotal): void
    {
        self::assertSame($requests, $count->requests);
        self::assertSame($durationMsTotal, $count->durationMsTotal);
    }
}
