<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Message\RecordOnlineEntryEvents;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Results\RecordedOnlineEntryEvents;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccessLight;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Value\OnlineAccessLightContext;
use SpeedPuzzling\Web\Value\OnlineParticipantSettings;
use SpeedPuzzling\Web\Value\RoundClock;
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
 * wrongly gave way for up to a minute). And the round's hashes the heartbeat answers with (stream SP): the clock's and
 * the phone settings', shared by the round's phones, read again once a saved settings change forgot them.
 */
final class OnlineAccessLightTest extends KernelTestCase
{
    private const string TOKEN = OnlineRoundsFixture::TOKEN_CONNECTED;
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;

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
        $this->cache->delete(OnlineAccessLight::roundHashKey(self::ROUND));

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

    /**
     * The round's hashes (stream SP): the clock's and the phone settings' - kept for ROUND_HASH_TTL_SECONDS for every
     * phone of the round, read again once a saved settings change forgot them
     */
    public function testTheRoundsHashesAreSharedAndASavedChangeIsSeenAtOnce(): void
    {
        $this->cache->delete(OnlineAccessLight::roundHashKey(self::ROUND));
        $hashes = $this->light->roundHashes(self::ROUND);

        self::assertNotNull($hashes);
        self::assertSame(RoundClock::fromRound($this->onlineRound()->round, new DateTimeImmutable())->stateHash(), $hashes['stateHash']);
        self::assertSame(OnlineParticipantSettings::of($this->onlineRound())->hash(), $hashes['settingsHash']);

        // The organiser saves another detection mode: the cached hashes stay until the save forgets them
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE online_round SET detection_mode = 'shadow', video_enabled = true WHERE id = :id",
            ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO],
        );
        // The next request reads the rows anew
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame($hashes, $this->light->roundHashes(self::ROUND), 'shared for a moment by every phone');

        $this->light->forgetRound(strtoupper(self::ROUND));
        $after = $this->light->roundHashes(self::ROUND);

        self::assertNotNull($after);
        self::assertSame($hashes['stateHash'], $after['stateHash'], 'the clock did not change');
        self::assertNotSame($hashes['settingsHash'], $after['settingsHash']);
        self::assertSame(OnlineParticipantSettings::of($this->onlineRound())->hash(), $after['settingsHash']);
    }

    /**
     * A link looked up anew fills the round's hashes from the rows it read - the heartbeat asking for them next costs
     * no statement
     */
    public function testALinkLookedUpAnewFillsTheRoundsHashes(): void
    {
        $this->cache->delete(OnlineAccessLight::roundHashKey(self::ROUND));
        $this->light->forToken(self::TOKEN);

        /** @var mixed $cached a peek: nothing is stored on a miss */
        $cached = $this->cache->get(OnlineAccessLight::roundHashKey(self::ROUND), static function (ItemInterface $item, bool &$save): mixed {
            $save = false;

            return null;
        });

        self::assertSame([
            'stateHash' => RoundClock::fromRound($this->onlineRound()->round, new DateTimeImmutable())->stateHash(),
            'settingsHash' => OnlineParticipantSettings::of($this->onlineRound())->hash(),
        ], $cached);
    }

    /**
     * A round without online timing has no settings hash; a round that is gone has no hashes; what another version of
     * this code left under the key is read again
     */
    public function testRoundsWithoutOnlineTimingAndLeftovers(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM online_round WHERE id = :id', ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO]);
        $this->cache->delete(OnlineAccessLight::roundHashKey(self::ROUND));

        $hashes = $this->light->roundHashes(self::ROUND);
        self::assertNotNull($hashes);
        self::assertNull($hashes['settingsHash']);

        self::assertNull($this->light->roundHashes('018d0050-0000-0000-0000-00000000ffff'));

        $this->light->forgetRound(self::ROUND);
        $this->cache->get(OnlineAccessLight::roundHashKey(self::ROUND), static fn (): string => 'an-old-state-hash');
        self::assertSame($hashes, $this->light->roundHashes(self::ROUND));
    }

    private function onlineRound(): OnlineRound
    {
        return self::getContainer()->get(OnlineRoundRepository::class)->get(OnlineRoundsFixture::ONLINE_ROUND_SOLO);
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
