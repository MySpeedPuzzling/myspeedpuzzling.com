<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccessLight;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineRoundSettingsLiveUpdates;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Value\OnlineParticipantSettings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The push after a saved settings change (stream SP, README §5.3): the heartbeat's cached hashes of the round forgotten
 * first, then `{type: online.settings, roundId, settingsHash}` on the round's stopwatch topic - public, no `asOfMs`. A hub
 * that is down is logged (warning) and never thrown; the cached hashes are forgotten anyway, so the next heartbeats
 * carry the change.
 */
final class OnlineRoundSettingsLiveUpdatesTest extends KernelTestCase
{
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;

    private NullMercureHub $hub;
    private InMemoryLogger $logger;
    private OnlineRoundSettingsLiveUpdates $liveUpdates;
    private CacheInterface $cache;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->hub = new NullMercureHub();
        $this->logger = new InMemoryLogger();
        $this->cache = $container->get(CacheInterface::class);
        $this->liveUpdates = new OnlineRoundSettingsLiveUpdates(
            $this->hub,
            $container->get(OnlineRoundRepository::class),
            $container->get(OnlineAccessLight::class),
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        $this->cache->delete(OnlineAccessLight::roundHashKey(self::ROUND));

        parent::tearDown();
    }

    public function testTheOpenPagesAreToldTheNewSettingsHash(): void
    {
        $this->primeRoundHashes();

        $published = $this->liveUpdates->settingsChanged(strtoupper(self::ROUND));

        $expected = OnlineParticipantSettings::of(self::getContainer()->get(OnlineRoundRepository::class)->get(OnlineRoundsFixture::ONLINE_ROUND_SOLO))->hash();
        self::assertSame($expected, $published);

        $updates = $this->hub->getPublishedUpdates();
        self::assertCount(1, $updates);
        self::assertSame([OfficialResultsSubscription::stopwatchTopic(self::ROUND)], $updates[0]->getTopics());
        self::assertFalse($updates[0]->isPrivate());
        $data = json_decode($updates[0]->getData(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayNotHasKey('asOfMs', $data, 'never a state a page could order - a page from before this message fetches its state');
        self::assertSame(['type' => 'online.settings', 'roundId' => self::ROUND, 'settingsHash' => $expected], $data);
        self::assertFalse($this->roundHashesCached());
        self::assertSame([], $this->logger->records);
    }

    public function testAHubThatIsDownNeverFailsTheSave(): void
    {
        $this->primeRoundHashes();
        $this->hub->failWith(new \RuntimeException('Connection refused'));

        self::assertNull($this->liveUpdates->settingsChanged(self::ROUND));

        self::assertTrue($this->logger->hasRecord('warning', 'Could not publish an online round settings update'));
        self::assertInstanceOf(\RuntimeException::class, $this->logger->records[0]['context']['exception'] ?? null);
        self::assertFalse($this->roundHashesCached(), 'the next heartbeats carry the change all the same');
    }

    public function testARoundWithoutOnlineTimingTellsNobody(): void
    {
        self::assertNull($this->liveUpdates->settingsChanged(OfficialResultsFixture::ROUND_GROUP_A));
        self::assertSame([], $this->hub->getPublishedUpdates());
        self::assertSame([], $this->logger->records);
    }

    private function primeRoundHashes(): void
    {
        self::getContainer()->get(OnlineAccessLight::class)->roundHashes(self::ROUND);
        self::assertTrue($this->roundHashesCached());
    }

    private function roundHashesCached(): bool
    {
        return $this->cache->get(OnlineAccessLight::roundHashKey(self::ROUND), static function (ItemInterface $item, bool &$save): bool {
            $save = false;

            return false;
        }) !== false;
    }
}
