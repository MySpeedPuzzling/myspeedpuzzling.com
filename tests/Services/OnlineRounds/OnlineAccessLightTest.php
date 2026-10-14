<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordOnlineEntryEvents;
use SpeedPuzzling\Web\Results\RecordedOnlineEntryEvents;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccessLight;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Value\OnlineAccessLightContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The heartbeat's cached view of a personal link (OnlineAccessLight) after another device claims the entry
 * (`device.ready`, README §5.4 "Several devices on one link"): once the claim is committed and the link forgotten
 * (ApiEventsController after the dispatch), the next lookup sees the new device - also when a lookup that read the link
 * before the claim committed is written to the cache only after the forget (docs/TODO.md: the newly claiming device
 * wrongly gave way for up to a minute).
 */
final class OnlineAccessLightTest extends KernelTestCase
{
    private const string TOKEN = OnlineRoundsFixture::TOKEN_CONNECTED;

    private OnlineAccessLight $light;
    private CacheInterface $cache;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->light = $container->get(OnlineAccessLight::class);
        $this->cache = $container->get(CacheInterface::class);

        // The shared cache lives across tests (on disk) - every test starts from what the database says
        $this->cache->delete(OnlineAccessLight::cacheKey(self::TOKEN));
        $this->cache->delete(OnlineAccessLight::GENERATION_KEY_PREFIX . self::TOKEN);
        $this->cache->delete(OnlineAccessLight::UNKNOWN_KEY_PREFIX . self::TOKEN);
    }

    protected function tearDown(): void
    {
        $this->cache->delete(OnlineAccessLight::cacheKey(self::TOKEN));
        $this->cache->delete(OnlineAccessLight::GENERATION_KEY_PREFIX . self::TOKEN);

        parent::tearDown();
    }

    public function testAClaimIsSeenByTheNextLookup(): void
    {
        self::assertSame(OnlineRoundsFixture::DEVICE_CONNECTED, $this->light->forToken(self::TOKEN)->activeDeviceId);

        $this->claim('phone-b');
        $this->light->forget(self::TOKEN);

        self::assertSame('phone-b', $this->light->forToken(self::TOKEN)->activeDeviceId);
    }

    /**
     * The race: a heartbeat read the link (the old device) before the claim committed, and its answer reaches the cache
     * only after the forget - it is read again, never used
     */
    public function testALookupReadBeforeTheClaimIsNeverUsedAfterIt(): void
    {
        $stale = $this->light->forToken(self::TOKEN);
        self::assertSame(OnlineRoundsFixture::DEVICE_CONNECTED, $stale->activeDeviceId);

        $this->claim('phone-b');
        $this->light->forget(self::TOKEN);

        // The late write of the heartbeat that read the link before the claim
        $this->cache->get(OnlineAccessLight::cacheKey(self::TOKEN), static function (ItemInterface $item) use ($stale): array {
            $item->expiresAfter(OnlineAccessLight::TTL_SECONDS);

            return $stale->toArray();
        });

        $fresh = $this->light->forToken(self::TOKEN);
        self::assertSame('phone-b', $fresh->activeDeviceId);
        self::assertNotNull($fresh->generation);

        // Kept from now on - with the generation it was read under
        $cached = OnlineAccessLightContext::fromArray($this->cache->get(OnlineAccessLight::cacheKey(self::TOKEN), static fn (): array => []));
        self::assertNotNull($cached);
        self::assertSame('phone-b', $cached->activeDeviceId);
        self::assertSame($fresh->generation, $cached->generation);
    }

    /**
     * Every forget starts a generation of its own - a lookup of an earlier one is read again
     */
    public function testEveryForgetStartsANewGeneration(): void
    {
        $this->light->forget(self::TOKEN);
        $first = $this->light->forToken(self::TOKEN);

        $this->light->forget(self::TOKEN);
        $second = $this->light->forToken(self::TOKEN);

        self::assertNotNull($first->generation);
        self::assertNotNull($second->generation);
        self::assertNotSame($first->generation, $second->generation);
    }

    /**
     * A link never forgotten keeps its lookup as before - one read a minute
     */
    public function testWithoutAForgetTheLookupIsKept(): void
    {
        $first = $this->light->forToken(self::TOKEN);

        // The database changes behind the cache's back: the cached lookup stays
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE online_entry SET active_device_id = 'phone-z' WHERE id = :id",
            ['id' => OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED],
        );

        $second = $this->light->forToken(self::TOKEN);

        self::assertNull($first->generation);
        self::assertSame(OnlineRoundsFixture::DEVICE_CONNECTED, $second->activeDeviceId);
    }

    private function claim(string $deviceId): void
    {
        $envelope = self::getContainer()->get(MessageBusInterface::class)->dispatch(new RecordOnlineEntryEvents(
            participantId: OnlineRoundsFixture::PARTICIPANT_CONNECTED,
            roundId: OnlineRoundsFixture::ROUND_ONLINE_SOLO,
            deviceId: $deviceId,
            events: [[
                'id' => Uuid::uuid7()->toString(),
                'type' => 'device.ready',
                'roundId' => OnlineRoundsFixture::ROUND_ONLINE_SOLO,
                'clientMs' => (int) floor(microtime(true) * 1000),
                'offsetMs' => -3.25,
                'rttMs' => 22,
                'data' => [],
            ]],
        ));

        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(RecordedOnlineEntryEvents::class, $recorded);
        self::assertTrue($recorded->activeDeviceChanged);
    }
}
