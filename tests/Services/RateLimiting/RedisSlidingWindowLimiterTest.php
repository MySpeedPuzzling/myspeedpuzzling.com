<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\RateLimiting;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Redis;
use SpeedPuzzling\Web\Services\RateLimiting\RedisSlidingWindowLimiterFactory;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\RateLimiter\Exception\ReserveNotSupportedException;

/**
 * The participant API's rate limiter (RedisSlidingWindowLimiterFactory) against a real Redis - `redis-state` in compose
 * and in CI, like RedisOnlinePresenceTest; production counts on the cache Redis, the script is the same. Every test
 * writes under its own key prefix, so parallel ParaTest workers never meet.
 */
final class RedisSlidingWindowLimiterTest extends TestCase
{
    private Redis $redis;
    private string $prefix;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->redis = self::connect();
        $this->prefix = 'test_rate_limit:' . Uuid::uuid7()->toString() . ':';
        $this->clock = new MockClock(new DateTimeImmutable('2026-11-10 18:00:00.000', new DateTimeZone('UTC')));
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

    public function testUpToTheLimitThenRefusedWithWhenToComeBack(): void
    {
        $limiter = $this->factory(limit: 5)->create('link-a');

        foreach ([4, 3, 2, 1, 0] as $remaining) {
            $limit = $limiter->consume();
            self::assertTrue($limit->isAccepted());
            self::assertSame($remaining, $limit->getRemainingTokens());
            self::assertSame(5, $limit->getLimit());
        }

        $refused = $limiter->consume();
        self::assertFalse($refused->isAccepted());
        self::assertSame(0, $refused->getRemainingTokens());
        // The window's own hits fill it: they become the previous window's at 18:01:00 and fade - one fits at 18:01:12
        self::assertEquals(new DateTimeImmutable('2026-11-10 18:01:12', new DateTimeZone('UTC')), $refused->getRetryAfter());

        // Another key is counted apart; a refusal is not counted
        self::assertTrue($this->factory(limit: 5)->create('link-b')->consume()->isAccepted());
        self::assertFalse($limiter->consume()->isAccepted());
        self::assertSame(0, $limiter->consume(0)->getRemainingTokens());
    }

    /**
     * Symfony's sliding window: the previous window's hits count as much as it still overlaps
     */
    public function testTheWindowSlides(): void
    {
        $limiter = $this->factory(limit: 10)->create('link');

        for ($i = 0; $i < 10; $i++) {
            self::assertTrue($limiter->consume()->isAccepted());
        }

        // The next window: all ten still count at its start
        $this->clock->sleep(60);
        self::assertFalse($limiter->consume()->isAccepted());

        // Half way through it they count five: five more fit, not six
        $this->clock->sleep(30);
        self::assertSame(5, $limiter->consume(0)->getRemainingTokens());
        self::assertTrue($limiter->consume(5)->isAccepted());
        self::assertFalse($limiter->consume()->isAccepted());

        // Two windows on, nothing is left of them
        $this->clock->sleep(120);
        self::assertSame(10, $limiter->consume(0)->getRemainingTokens());
        self::assertTrue($limiter->consume(10)->isAccepted());
    }

    public function testResetAndWhatItDoesNotDo(): void
    {
        $limiter = $this->factory(limit: 2)->create('link');
        $limiter->consume(2);
        self::assertFalse($limiter->consume()->isAccepted());

        $limiter->reset();
        self::assertTrue($limiter->consume()->isAccepted());

        // Only looking never counts
        self::assertSame(1, $limiter->consume(0)->getRemainingTokens());
        self::assertSame(1, $limiter->consume(0)->getRemainingTokens());

        // A personal link's token never lands in Redis as it is
        $keys = $this->redis->keys($this->prefix . '*');
        self::assertIsArray($keys);
        self::assertSame([$this->prefix . 'bench:' . sha1('link')], $keys);
        self::assertGreaterThan(0, $this->redis->pTtl($this->prefix . 'bench:' . sha1('link')));

        $this->expectException(ReserveNotSupportedException::class);
        $limiter->reserve();
    }

    public function testMoreThanTheLimitAtOnceIsNoRequest(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->factory(limit: 3)->create('link')->consume(4);
    }

    public function testAfterRedisForgotTheScriptItCountsOn(): void
    {
        $limiter = $this->factory(limit: 3)->create('link');
        $limiter->consume();
        // What a Redis restart does to the script cache
        $this->redis->script('flush');

        self::assertSame(1, $limiter->consume()->getRemainingTokens());
    }

    /**
     * A Redis that does not answer lets the request through - the limiter must never be what takes the site down
     */
    public function testARedisThatDoesNotAnswerLetsTheRequestThrough(): void
    {
        $unreachable = RedisAdapter::createConnection('redis://127.0.0.1:1', ['lazy' => true, 'timeout' => 0.2]);
        self::assertInstanceOf(Redis::class, $unreachable);

        $limit = new RedisSlidingWindowLimiterFactory($unreachable, 'online', 3, 60, $this->clock, new NullLogger())->create('link')->consume();

        self::assertTrue($limit->isAccepted());
    }

    /**
     * The point of it (docs/features/online-competitions/README.md §6): many processes consuming one key at the same
     * moment - the count holds, exactly the limit is accepted. Symfony's limiter without a lock accepted all of them.
     */
    public function testTheCountHoldsWhenManyProcessesAskAtOnce(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are needed to ask from several processes at once');
        }

        $processes = 24;
        $consumesEach = 10;
        $limit = 100;
        $results = sys_get_temp_dir() . '/rate-limit-test-' . Uuid::uuid7()->toString();
        mkdir($results);
        // Real time on purpose: the forked processes start together at a real instant and race on a real clock
        $startAt = microtime(true) + 0.5;
        $children = [];

        for ($i = 0; $i < $processes; $i++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);

            if ($pid === 0) {
                // A connection of its own - never the parent's socket
                $limiter = new RedisSlidingWindowLimiterFactory(self::connect(), 'online', $limit, 60, new NativeClock(), new NullLogger(), $this->prefix)->create('one-link');
                time_sleep_until($startAt);
                $accepted = 0;

                for ($j = 0; $j < $consumesEach; $j++) {
                    $accepted += $limiter->consume()->isAccepted() ? 1 : 0;
                }

                file_put_contents($results . '/' . $i, (string) $accepted);
                // Gone at once: no shutdown functions, nothing of the parent's test run
                posix_kill(posix_getpid(), SIGKILL);
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $accepted = 0;
        $answered = 0;

        foreach (glob($results . '/*') ?: [] as $file) {
            $accepted += (int) file_get_contents($file);
            $answered++;
            unlink($file);
        }

        rmdir($results);

        self::assertSame($processes, $answered);
        self::assertSame($limit, $accepted, sprintf('%d processes × %d requests at once against a limit of %d', $processes, $consumesEach, $limit));
    }

    private function factory(int $limit): RedisSlidingWindowLimiterFactory
    {
        return new RedisSlidingWindowLimiterFactory($this->redis, 'bench', $limit, 60, $this->clock, new NullLogger(), $this->prefix);
    }

    private static function connect(): Redis
    {
        $dsn = $_SERVER['REDIS_STATE_DSN'] ?? $_ENV['REDIS_STATE_DSN'] ?? 'redis://redis-state:6379';
        self::assertIsString($dsn);

        $redis = RedisAdapter::createConnection($dsn, ['timeout' => 2]);
        self::assertInstanceOf(Redis::class, $redis);

        return $redis;
    }
}
