<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Redis;
use SpeedPuzzling\Web\Services\OnlineRounds\RedisOnlinePresence;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * The heartbeat's Lua script against a real Redis (`redis-state` in compose and in CI), like RedisApiUsageCounterTest.
 * Every test writes under its own key prefix, so parallel ParaTest workers never meet.
 */
final class RedisOnlinePresenceTest extends TestCase
{
    private const string ROUND = '018D0050-0000-0000-0000-000000000101';
    private const string PARTICIPANT = '018D0050-0000-0000-0000-000000000201';
    private const string OTHER_PARTICIPANT = '018d0050-0000-0000-0000-000000000202';

    private Redis $redis;
    private string $prefix;
    private RedisOnlinePresence $presence;

    protected function setUp(): void
    {
        $dsn = $_SERVER['REDIS_STATE_DSN'] ?? $_ENV['REDIS_STATE_DSN'] ?? 'redis://redis-state:6379';
        self::assertIsString($dsn);

        $redis = RedisAdapter::createConnection($dsn, ['timeout' => 2]);
        self::assertInstanceOf(Redis::class, $redis);

        $this->redis = $redis;
        $this->prefix = 'test_online_presence:' . Uuid::uuid7()->toString() . ':';
        $this->presence = new RedisOnlinePresence($this->redis, $this->prefix);
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

    public function testTheLastBeatOfEveryParticipantPerRound(): void
    {
        $this->presence->beat(self::ROUND, self::PARTICIPANT, ['deviceId' => 'a', 'visible' => true, 'clock' => ['offsetMs' => -12.0, 'rttMs' => 38.0]], 1_000);
        $this->presence->beat(strtolower(self::ROUND), strtolower(self::PARTICIPANT), ['deviceId' => 'b', 'visible' => false], 16_000);
        $this->presence->beat(self::ROUND, self::OTHER_PARTICIPANT, ['deviceId' => 'c', 'camera' => null], 5_000);
        $this->presence->beat('018d0050-0000-0000-0000-000000000199', self::PARTICIPANT, ['deviceId' => 'd'], 7_000);

        $presence = $this->presence->forRound(self::ROUND);
        // A hash has no order
        ksort($presence);

        self::assertSame([
            strtolower(self::PARTICIPANT) => ['deviceId' => 'b', 'visible' => false, 'lastSeenMs' => 16_000],
            self::OTHER_PARTICIPANT => ['deviceId' => 'c', 'camera' => null, 'lastSeenMs' => 5_000],
        ], $presence);
    }

    public function testFloatsComeBackAsFloats(): void
    {
        $this->presence->beat(self::ROUND, self::PARTICIPANT, ['clock' => ['offsetMs' => -12.0, 'rttMs' => 38.5]], 1_000);

        self::assertSame(
            [strtolower(self::PARTICIPANT) => ['clock' => ['offsetMs' => -12.0, 'rttMs' => 38.5], 'lastSeenMs' => 1_000]],
            $this->presence->forRound(self::ROUND),
        );
    }

    /**
     * Another device on the same link beats into a field of its own (`{participantId}#other`) - never over the holder's
     */
    public function testAnotherDevicesBeatIsKeptApart(): void
    {
        $this->presence->beat(self::ROUND, self::PARTICIPANT, ['deviceId' => 'phone', 'visible' => true], 1_000);
        $this->presence->beat(self::ROUND, self::PARTICIPANT, ['deviceId' => 'laptop', 'visible' => false], 2_000, holdsEntry: false);
        $this->presence->beat(self::ROUND, self::OTHER_PARTICIPANT, ['deviceId' => 'tablet'], 3_000, holdsEntry: false);

        $presence = $this->presence->forRound(self::ROUND);
        ksort($presence);

        self::assertSame([
            strtolower(self::PARTICIPANT) => ['deviceId' => 'phone', 'visible' => true, 'lastSeenMs' => 1_000, 'otherDevice' => ['deviceId' => 'laptop', 'lastSeenMs' => 2_000]],
            self::OTHER_PARTICIPANT => ['otherDevice' => ['deviceId' => 'tablet', 'lastSeenMs' => 3_000]],
        ], $presence);
        self::assertSame(1, $this->redis->hExists($this->prefix . strtolower(self::ROUND), strtolower(self::PARTICIPANT) . RedisOnlinePresence::OTHER_DEVICE_SUFFIX) ? 1 : 0);
    }

    public function testARoundNobodyBeatInIsEmpty(): void
    {
        self::assertSame([], $this->presence->forRound(self::ROUND));
    }

    /**
     * redis-state never evicts (64 MB, noeviction): every beat renews the round's expiry
     */
    public function testEveryBeatRenewsTheRoundsExpiry(): void
    {
        $this->presence->beat(self::ROUND, self::PARTICIPANT, ['deviceId' => 'a'], 1_000);
        $this->redis->expire($this->prefix . strtolower(self::ROUND), 10);

        $this->presence->beat(self::ROUND, self::OTHER_PARTICIPANT, ['deviceId' => 'b'], 2_000);

        $ttl = $this->redis->ttl($this->prefix . strtolower(self::ROUND));
        self::assertIsInt($ttl);
        self::assertGreaterThan(RedisOnlinePresence::TTL_SECONDS - 60, $ttl);
        self::assertLessThanOrEqual(RedisOnlinePresence::TTL_SECONDS, $ttl);
    }

    public function testBeatsAgainAfterRedisForgotTheScript(): void
    {
        $this->presence->beat(self::ROUND, self::PARTICIPANT, ['deviceId' => 'a'], 1_000);
        // What a Redis restart does to the script cache
        $this->redis->script('flush');
        $this->presence->beat(self::ROUND, self::PARTICIPANT, ['deviceId' => 'b'], 2_000);

        self::assertSame([strtolower(self::PARTICIPANT) => ['deviceId' => 'b', 'lastSeenMs' => 2_000]], $this->presence->forRound(self::ROUND));
    }

    public function testAValueThatIsNoBeatIsLeftOut(): void
    {
        $this->presence->beat(self::ROUND, self::PARTICIPANT, ['deviceId' => 'a'], 1_000);
        $this->redis->hSet($this->prefix . strtolower(self::ROUND), self::OTHER_PARTICIPANT, 'not json');

        self::assertSame([strtolower(self::PARTICIPANT) => ['deviceId' => 'a', 'lastSeenMs' => 1_000]], $this->presence->forRound(self::ROUND));
    }
}
