<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use AsyncAws\Core\Configuration;
use AsyncAws\S3\S3Client;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Redis;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineStoreBreaker;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The circuit breaker against a real Redis (`redis-state` in compose and in CI) - which store a new upload goes to
 * (docs/features/online-competitions/README.md §5.11). Every test writes under its own key prefix.
 */
final class OnlineStoreBreakerTest extends TestCase
{
    private Redis $redis;
    private string $prefix;
    private MockClock $clock;
    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $dsn = $_SERVER['REDIS_STATE_DSN'] ?? $_ENV['REDIS_STATE_DSN'] ?? 'redis://redis-state:6379';
        self::assertIsString($dsn);

        $redis = RedisAdapter::createConnection($dsn, ['timeout' => 2]);
        self::assertInstanceOf(Redis::class, $redis);

        $this->redis = $redis;
        $this->prefix = 'test_online_store_failures:' . Uuid::uuid7()->toString() . ':';
        $this->clock = new MockClock(new DateTimeImmutable('2026-10-13 12:00:00', new DateTimeZone('UTC')));
        $this->logger = new InMemoryLogger();
    }

    protected function tearDown(): void
    {
        $keys = $this->keys();

        if ($keys !== []) {
            $this->redis->del($keys);
        }
    }

    public function testWithoutASecondStoreEverythingGoesToThePrimaryAndRedisIsNeverAsked(): void
    {
        $breaker = $this->breaker(secondStore: false);

        foreach (['p1', 'p2', 'p3', 'p4'] as $source) {
            $breaker->recordFailure(OnlineMediaStore::Primary, $source);
        }

        self::assertSame(OnlineMediaStore::Primary, $breaker->storeForNewUpload());
        self::assertFalse($breaker->isOpen(OnlineMediaStore::Primary));
        self::assertSame([], $this->keys());
    }

    public function testThreeSourcesFailingSendNewUploadsToTheSecondStore(): void
    {
        $breaker = $this->breaker();

        $breaker->recordFailure(OnlineMediaStore::Primary, 'participant-1');
        $breaker->recordFailure(OnlineMediaStore::Primary, OnlineStoreBreaker::SERVER);
        self::assertSame(OnlineMediaStore::Primary, $breaker->storeForNewUpload());

        $breaker->recordFailure(OnlineMediaStore::Primary, 'participant-2');
        self::assertTrue($breaker->isOpen(OnlineMediaStore::Primary));
        self::assertSame(OnlineMediaStore::Secondary, $breaker->storeForNewUpload());
        self::assertTrue($this->logger->hasRecord('warning', 'new uploads go to the other store'));

        // Told once per opening
        $breaker->recordFailure(OnlineMediaStore::Primary, 'participant-3');
        self::assertCount(1, array_filter($this->logger->records, static fn (array $record): bool => $record['level'] === 'warning'));
    }

    /**
     * Sources are counted, not failures: one participant's phone never moves everybody's uploads
     */
    public function testOneSourceFailingAgainAndAgainIsOneSource(): void
    {
        $breaker = $this->breaker();

        foreach (range(1, 10) as $attempt) {
            $breaker->recordFailure(OnlineMediaStore::Primary, 'participant-1');
            $this->clock->sleep(1);
        }

        self::assertFalse($breaker->isOpen(OnlineMediaStore::Primary));
        self::assertSame(OnlineMediaStore::Primary, $breaker->storeForNewUpload());
    }

    public function testFailuresAgeOutAndThePrimaryIsTriedAgain(): void
    {
        $breaker = $this->breaker();

        foreach (['participant-1', 'participant-2', 'participant-3'] as $source) {
            $breaker->recordFailure(OnlineMediaStore::Primary, $source);
        }

        self::assertSame(OnlineMediaStore::Secondary, $breaker->storeForNewUpload());

        $this->clock->sleep(OnlineStoreBreaker::WINDOW_SECONDS + 1);

        self::assertSame(OnlineMediaStore::Primary, $breaker->storeForNewUpload());
    }

    public function testBothStoresFailingIsThePrimary(): void
    {
        $breaker = $this->breaker();

        foreach (['participant-1', 'participant-2', 'participant-3'] as $source) {
            $breaker->recordFailure(OnlineMediaStore::Primary, $source);
            $breaker->recordFailure(OnlineMediaStore::Secondary, $source);
        }

        self::assertSame(OnlineMediaStore::Primary, $breaker->storeForNewUpload());
    }

    public function testTheCountersExpire(): void
    {
        $this->breaker()->recordFailure(OnlineMediaStore::Primary, 'participant-1');

        $keys = $this->keys();
        self::assertCount(1, $keys);

        $ttl = $this->redis->ttl($keys[0]);
        self::assertIsInt($ttl);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(2 * OnlineStoreBreaker::WINDOW_SECONDS, $ttl);
    }

    /**
     * A Redis that does not answer never stops an upload
     */
    public function testWithoutRedisUploadsGoToThePrimary(): void
    {
        $deadRedis = RedisAdapter::createConnection('redis://127.0.0.1:1', ['lazy' => true, 'timeout' => 0.2]);
        self::assertInstanceOf(Redis::class, $deadRedis);

        $breaker = new OnlineStoreBreaker($deadRedis, self::stores(secondStore: true), $this->clock, $this->logger, $this->prefix);
        $breaker->recordFailure(OnlineMediaStore::Primary, 'participant-1');

        self::assertSame(OnlineMediaStore::Primary, $breaker->storeForNewUpload());
        self::assertTrue($this->logger->hasRecord('warning', 'failed'));
    }

    private function breaker(bool $secondStore = true): OnlineStoreBreaker
    {
        return new OnlineStoreBreaker($this->redis, self::stores($secondStore), $this->clock, $this->logger, $this->prefix);
    }

    public static function stores(bool $secondStore): OnlineMediaStores
    {
        return new OnlineMediaStores(
            primaryClient: new S3Client([
                Configuration::OPTION_REGION => 'eu',
                Configuration::OPTION_ENDPOINT => 'http://primary.invalid',
                Configuration::OPTION_ACCESS_KEY_ID => 'key',
                Configuration::OPTION_SECRET_ACCESS_KEY => 'secret',
                Configuration::OPTION_PATH_STYLE_ENDPOINT => 'true',
            ], null, new MockHttpClient()),
            primaryRegion: 'eu',
            primaryBucket: 'puzzle',
            primaryAccessKey: 'key',
            primarySecretKey: 'secret',
            primaryPublicEndpoint: 'https://primary.example.org',
            secondaryEndpoint: $secondStore ? 'https://secondary.example.org' : '',
            secondaryRegion: 'garage',
            secondaryBucket: 'evidence',
            secondaryAccessKey: 'second-key',
            secondarySecretKey: 'second-secret',
            secondaryPathStyle: true,
            httpClient: new MockHttpClient(),
            logger: new NullLogger(),
        );
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        $iterator = null;
        $keys = [];

        do {
            /** @var false|list<string> $found */
            $found = $this->redis->scan($iterator, $this->prefix . '*', 1000);
            $keys = array_merge($keys, is_array($found) ? $found : []);
        } while ($iterator > 0);

        return $keys;
    }
}
