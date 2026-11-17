<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\CompetitionToolkit;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Redis;
use SpeedPuzzling\Web\Services\CompetitionToolkit\RedisCompetitorHeartbeats;
use SpeedPuzzling\Web\Value\CompetitorHeartbeat;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * The competitor heartbeat's Lua script against a real Redis (`redis-state` in compose and in CI), like
 * RedisOnlinePresenceTest. Every test writes under its own key prefix.
 */
final class RedisCompetitorHeartbeatsTest extends TestCase
{
    private const string ROUND = '018D0070-0000-7000-8000-000000000101';
    private const string ENTRY = 'participant_round:018D0070-0000-7000-8000-000000000201';
    private const string PLAYER = '018D0070-0000-7000-8000-000000000301';

    private Redis $redis;
    private string $prefix;
    private RedisCompetitorHeartbeats $heartbeats;

    protected function setUp(): void
    {
        $dsn = $_SERVER['REDIS_STATE_DSN'] ?? $_ENV['REDIS_STATE_DSN'] ?? 'redis://redis-state:6379';
        self::assertIsString($dsn);

        $redis = RedisAdapter::createConnection($dsn, ['timeout' => 2]);
        self::assertInstanceOf(Redis::class, $redis);

        $this->redis = $redis;
        $this->prefix = 'test_competitor_heartbeat:' . Uuid::uuid7()->toString() . ':';
        $this->heartbeats = new RedisCompetitorHeartbeats($this->redis, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->redis->del($this->prefix . strtolower(self::ROUND));
    }

    public function testTheLastBeatAndTheLastCleanOneWithAnExpiry(): void
    {
        self::assertNull($this->heartbeats->of(self::ROUND, self::ENTRY, self::PLAYER));

        $this->heartbeats->beat(self::ROUND, self::ENTRY, self::PLAYER, new CompetitorHeartbeat(unsent: 0, atMs: 990, offsetMs: -12.0, rttMs: 40.0), 1_000);
        // An unsent tap: the last beat moves on, the clean one stays
        $this->heartbeats->beat(strtolower(self::ROUND), strtolower(self::ENTRY), strtolower(self::PLAYER), new CompetitorHeartbeat(unsent: 1, oldestUnsentTapMs: 5_000, atMs: 16_000, offsetMs: -14.0), 16_000);

        $record = $this->heartbeats->of(self::ROUND, self::ENTRY, self::PLAYER);
        self::assertNotNull($record);
        self::assertSame(16_000, $record->lastHeardAtMs);
        // That beat had no measured clock sample (no rtt): its offset is the page's hint, never compared
        self::assertNull($record->lastOffsetMs);
        self::assertSame(990, $record->cleanAtMs);
        self::assertSame(1_000, $record->cleanHeardAtMs);

        // Another player of the same entry is kept apart
        self::assertNull($this->heartbeats->of(self::ROUND, self::ENTRY, '018d0070-0000-7000-8000-000000000302'));

        $ttl = $this->redis->ttl($this->prefix . strtolower(self::ROUND));
        self::assertIsInt($ttl);
        self::assertGreaterThan(RedisCompetitorHeartbeats::TTL_SECONDS - 60, $ttl);
        self::assertLessThanOrEqual(RedisCompetitorHeartbeats::TTL_SECONDS, $ttl);
    }

    public function testTheEvidenceMomentIsNeverLaterThanTheReceiptNorLongBeforeIt(): void
    {
        $this->heartbeats->beat(self::ROUND, self::ENTRY, self::PLAYER, new CompetitorHeartbeat(unsent: 0, atMs: 99_000), 50_000);
        self::assertSame(50_000, $this->heartbeats->of(self::ROUND, self::ENTRY, self::PLAYER)?->cleanAtMs);

        $this->heartbeats->beat(self::ROUND, self::ENTRY, self::PLAYER, new CompetitorHeartbeat(unsent: 0, atMs: 1_000), 100_000);
        self::assertSame(70_000, $this->heartbeats->of(self::ROUND, self::ENTRY, self::PLAYER)?->cleanAtMs);
    }
}
