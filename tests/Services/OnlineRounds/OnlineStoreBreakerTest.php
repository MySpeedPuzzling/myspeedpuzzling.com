<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use AsyncAws\Core\Configuration;
use AsyncAws\S3\S3Client;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Redis;
use SpeedPuzzling\Web\Services\OnlineRounds\FakeOnlineMediaStorage;
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
    private FakeOnlineMediaStorage $storage;

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
        // The store double: every store answers its probe unless taken down
        $this->storage = new FakeOnlineMediaStorage($this->clock);
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
     * What a phone reports as `previousFailure.status` - 0 is a store that gave no answer at all, which is how a store
     * that is down looks in a browser (the failover drill, spikes.md S5: a stopped primary never opened the breaker
     * while only 408 / 429 / 5xx counted)
     */
    #[DataProvider('uploadAnswers')]
    public function testWhichUploadAnswersCountAsTheStoreFailing(int $status, bool $counts): void
    {
        self::assertSame($counts, OnlineStoreBreaker::countsAsStoreFailure($status));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function uploadAnswers(): iterable
    {
        yield 'no answer at all' => [0, true];
        yield 'timeout' => [408, true];
        yield 'busy' => [429, true];
        yield 'server error' => [500, true];
        yield 'unavailable' => [503, true];
        yield 'gateway timeout' => [504, true];
        yield 'a URL the store refused' => [403, false];
        yield 'a bad request' => [400, false];
        yield 'not found' => [404, false];
        yield 'uploaded' => [200, false];
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
        self::assertSame(['primary'], $this->storage->probes(), 'asked once before closing');

        // Closed - nothing is asked again
        self::assertSame(OnlineMediaStore::Primary, $breaker->storeForNewUpload());
        self::assertSame(['primary'], $this->storage->probes());
    }

    /**
     * A store still down when its failures age out stays out of the uploads another window - one probe a minute
     * instead of a wave of failed uploads (spikes.md S5: the breaker flapped once a minute)
     */
    public function testThePrimaryIsProbedBeforeTheBreakerClosesAgain(): void
    {
        $breaker = $this->breaker();

        foreach (['participant-1', 'participant-2', 'participant-3'] as $source) {
            $breaker->recordFailure(OnlineMediaStore::Primary, $source);
        }

        $this->storage->takeDown(OnlineMediaStore::Primary);
        $this->clock->sleep(OnlineStoreBreaker::WINDOW_SECONDS + 1);

        self::assertSame(OnlineMediaStore::Secondary, $breaker->storeForNewUpload(), 'the probe failed - still open');
        self::assertSame(['primary'], $this->storage->probes());
        self::assertTrue($this->logger->hasRecord('info', 'still fails its probe'));

        // Held open for a window without asking again
        $this->clock->sleep(OnlineStoreBreaker::WINDOW_SECONDS - 1);
        self::assertSame(OnlineMediaStore::Secondary, $breaker->storeForNewUpload());
        self::assertTrue($breaker->isOpen(OnlineMediaStore::Primary));
        self::assertSame(['primary'], $this->storage->probes());

        // Then asked again - still down, held again
        $this->clock->sleep(2);
        $this->expireHeld(OnlineMediaStore::Primary);
        self::assertSame(OnlineMediaStore::Secondary, $breaker->storeForNewUpload());
        self::assertSame(['primary', 'primary'], $this->storage->probes());

        // Back: the next probe closes the breaker for good
        $this->storage->takeDown(OnlineMediaStore::Primary, false);
        $this->expireHeld(OnlineMediaStore::Primary);
        self::assertSame(OnlineMediaStore::Primary, $breaker->storeForNewUpload());
        self::assertSame(OnlineMediaStore::Primary, $breaker->storeForNewUpload());
        self::assertSame(['primary', 'primary', 'primary'], $this->storage->probes());
        self::assertTrue($this->logger->hasRecord('info', 'answers its probe again'));
        self::assertSame([], array_values(array_filter($this->keys(), static fn (string $key): bool => !str_ends_with($key, ':primary'))), 'no mark left behind');
    }

    /**
     * One request probes at a time - while it does, every other one keeps the uploads on the second store
     */
    public function testWhileAnotherRequestProbesTheBreakerStaysOpen(): void
    {
        $breaker = $this->breaker();

        foreach (['participant-1', 'participant-2', 'participant-3'] as $source) {
            $breaker->recordFailure(OnlineMediaStore::Primary, $source);
        }

        $this->clock->sleep(OnlineStoreBreaker::WINDOW_SECONDS + 1);
        $this->redis->set($this->prefix . 'primary:probing', '1', ['ex' => OnlineStoreBreaker::PROBE_LOCK_SECONDS]);

        self::assertSame(OnlineMediaStore::Secondary, $breaker->storeForNewUpload());
        self::assertSame([], $this->storage->probes());
    }

    /**
     * Failures that never opened the breaker are no reason to ask - closed is closed
     */
    public function testABreakerThatNeverOpenedProbesNothing(): void
    {
        $breaker = $this->breaker();
        $breaker->recordFailure(OnlineMediaStore::Primary, 'participant-1');
        $breaker->recordFailure(OnlineMediaStore::Primary, 'participant-2');
        $this->storage->takeDown(OnlineMediaStore::Primary);

        self::assertSame(OnlineMediaStore::Primary, $breaker->storeForNewUpload());
        $this->clock->sleep(OnlineStoreBreaker::WINDOW_SECONDS + 1);
        self::assertSame(OnlineMediaStore::Primary, $breaker->storeForNewUpload());
        self::assertSame([], $this->storage->probes());
    }

    /**
     * The mark that asks for a probe does not live forever - nor does any other key
     */
    public function testTheTrippedMarkExpires(): void
    {
        $breaker = $this->breaker();

        foreach (['participant-1', 'participant-2', 'participant-3'] as $source) {
            $breaker->recordFailure(OnlineMediaStore::Primary, $source);
        }

        $ttl = $this->redis->ttl($this->prefix . 'primary:tripped');
        self::assertIsInt($ttl);
        self::assertGreaterThan(OnlineStoreBreaker::WINDOW_SECONDS, $ttl);
        self::assertLessThanOrEqual(OnlineStoreBreaker::TRIPPED_SECONDS, $ttl);

        $this->storage->takeDown(OnlineMediaStore::Primary);
        $this->clock->sleep(OnlineStoreBreaker::WINDOW_SECONDS + 1);
        $breaker->storeForNewUpload();

        foreach ($this->keys() as $key) {
            $keyTtl = $this->redis->ttl($key);
            self::assertIsInt($keyTtl);
            self::assertGreaterThan(0, $keyTtl, $key . ' expires');
        }
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

        $breaker = new OnlineStoreBreaker($deadRedis, self::stores(secondStore: true), $this->storage, $this->clock, $this->logger, $this->prefix);
        $breaker->recordFailure(OnlineMediaStore::Primary, 'participant-1');

        self::assertSame(OnlineMediaStore::Primary, $breaker->storeForNewUpload());
        self::assertTrue($this->logger->hasRecord('warning', 'failed'));
    }

    /**
     * The real key expires on Redis' own clock - the test moves the breaker's clock only
     */
    private function expireHeld(OnlineMediaStore $store): void
    {
        $this->redis->del($this->prefix . $store->value . ':held');
    }

    private function breaker(bool $secondStore = true): OnlineStoreBreaker
    {
        return new OnlineStoreBreaker($this->redis, self::stores($secondStore), $this->storage, $this->clock, $this->logger, $this->prefix);
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
