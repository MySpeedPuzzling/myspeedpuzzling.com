<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\LiveKit;

use SpeedPuzzling\Web\Services\LiveKit\LiveKitApi;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitTokens;
use SpeedPuzzling\Web\Services\LiveKit\StudioSpectators;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FlakyLockStore;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use SpeedPuzzling\Web\Tests\TestDouble\LiveKitHttpMock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The spectators of a round's studio and a place for one more (StudioSpectators, contract phase-1b.md §5
 * `online_watch_token`): LiveKit's own list of the room, asked at most once per COUNT_TTL_SECONDS, every place handed
 * out counted on top until the next listing, one admission at a time under the lock - and watching never depends on the
 * cache's lock or on LiveKit answering.
 */
final class StudioSpectatorsTest extends KernelTestCase
{
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string LIST_URL = 'http://livekit.test:7880/twirp/livekit.RoomService/ListParticipants';

    /** @var list<string> */
    private array $listed = [];

    /** @var list<MockResponse> */
    private array $answers = [];

    /**
     * The service as configured (config/packages/*), with LiveKit answering from LiveKitHttpMock
     */
    public function testTheStudioIsListedOncePerWindowAndEveryPlaceHandedOutCounts(): void
    {
        LiveKitHttpMock::reset();
        $spectators = self::getContainer()->get(StudioSpectators::class);
        LiveKitHttpMock::queue(LiveKitHttpMock::answer(['participants' => self::studio(1)]));

        self::assertTrue($spectators->admit(self::ROUND, 3));
        // LiveKit has not seen these two join yet - the places handed out hold them
        self::assertTrue($spectators->admit(self::ROUND, 3));
        self::assertFalse($spectators->admit(self::ROUND, 3));
        self::assertFalse($spectators->admit(self::ROUND, 3));

        self::assertCount(1, LiveKitHttpMock::requests(), 'one listing for the whole window');
        self::assertSame(['room' => 'studio-' . self::ROUND], json_decode(LiveKitHttpMock::requests()[0]['body'], true));
    }

    public function testOnlySpectatorsCount(): void
    {
        [$spectators] = $this->spectators([['participants' => [
            ['identity' => 'm:018d0000-0000-0000-0000-000000000003'],
            ['identity' => 'p:' . OnlineRoundsFixture::PARTICIPANT_CONNECTED],
            ['identity' => 'p:' . OnlineRoundsFixture::PARTICIPANT_CONNECTED . ':screen'],
            ['identity' => 's:00000000000000a1'],
            ['identity' => 's:00000000000000a2'],
            ['name' => 'no identity'],
        ]]]);

        self::assertTrue($spectators->admit(self::ROUND, 3));
        self::assertFalse($spectators->admit(self::ROUND, 3));
    }

    public function testAfterTheWindowLiveKitsListIsTheTruthAgain(): void
    {
        [$spectators, $clock] = $this->spectators([
            ['participants' => []],
            // Only one of the two given a place really joined
            ['participants' => self::studio(1)],
        ]);

        self::assertTrue($spectators->admit(self::ROUND, 2));
        self::assertTrue($spectators->admit(self::ROUND, 2));
        self::assertFalse($spectators->admit(self::ROUND, 2));
        self::assertSame(1, $this->listings());

        $clock->sleep(StudioSpectators::COUNT_TTL_SECONDS - 0.001);
        self::assertFalse($spectators->admit(self::ROUND, 2), 'still the same window');
        self::assertSame(1, $this->listings());

        $clock->sleep(0.001);
        self::assertTrue($spectators->admit(self::ROUND, 2), 'the place of the one who never joined is free again');
        self::assertSame(2, $this->listings());
    }

    public function testAStudioRoomNotOpenedYetHoldsNobody(): void
    {
        [$spectators, , $logger] = $this->spectators([new MockResponse('{"code":"not_found","msg":"requested room does not exist"}', ['http_code' => 404])]);

        self::assertTrue($spectators->admit(self::ROUND, 1));
        self::assertFalse($spectators->admit(self::ROUND, 1));
        self::assertSame([], $logger->records, 'nothing wrong with a studio nobody opened yet');
    }

    public function testWithoutLiveKitsAnswerSpectatorsAreLetInAndItIsAskedOncePerWindow(): void
    {
        [$spectators, $clock, $logger] = $this->spectators([
            new MockResponse('', ['http_code' => 503]),
            ['participants' => self::studio(1)],
        ]);

        self::assertTrue($spectators->admit(self::ROUND, 5));
        self::assertTrue($spectators->admit(self::ROUND, 5));
        self::assertSame(1, $this->listings(), 'a failing server is asked once per window, not once per spectator');
        self::assertSame(['warning'], array_column($logger->records, 'level'));

        $clock->sleep(StudioSpectators::COUNT_TTL_SECONDS);
        self::assertTrue($spectators->admit(self::ROUND, 2));
        self::assertFalse($spectators->admit(self::ROUND, 2));
        self::assertSame(2, $this->listings());
    }

    public function testWithoutItsLockTheCountStillHappens(): void
    {
        // The cache Redis is not there
        $store = new FlakyLockStore([new LockStorageException('Connection refused'), new LockStorageException('Connection refused')]);
        [$spectators, , $logger] = $this->spectators([['participants' => []]], new LockFactory($store));

        self::assertTrue($spectators->admit(self::ROUND, 1));
        self::assertFalse($spectators->admit(self::ROUND, 1));
        self::assertSame(['warning', 'warning'], array_column($logger->records, 'level'));
    }

    public function testATakenLockIsWaitedFor(): void
    {
        // Another admission holds the studio's lock for the first three tries
        $store = new FlakyLockStore([new LockConflictedException(), new LockConflictedException(), new LockConflictedException()]);
        [$spectators, , $logger] = $this->spectators([['participants' => []]], new LockFactory($store));
        $started = microtime(true);

        self::assertTrue($spectators->admit(self::ROUND, 1));

        self::assertSame(4, $store->saveAttempts, 'tried again until it was free');
        self::assertSame([], $logger->records);
        // A few milliseconds per try - not the lock's own 100 ms sleeps
        self::assertLessThan(0.3, microtime(true) - $started);
    }

    /**
     * @param list<array<string, mixed>|MockResponse> $answers what ListParticipants answers, in turn
     * @return array{StudioSpectators, MockClock, InMemoryLogger}
     */
    private function spectators(array $answers, null|LockFactory $lockFactory = null): array
    {
        $this->listed = [];
        $this->answers = array_map(
            static fn (array|MockResponse $answer): MockResponse => $answer instanceof MockResponse ? $answer : LiveKitHttpMock::answer($answer),
            $answers,
        );

        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame(self::LIST_URL, $url);
            $body = $options['body'] ?? '';
            $this->listed[] = is_string($body) ? $body : '';

            return array_shift($this->answers) ?? LiveKitHttpMock::answer([]);
        });

        $clock = new MockClock('2026-11-10 18:00:00');
        $settings = new LiveKitSettings('http://livekit.test:7880', 'wss://livekit.test', 'key', str_repeat('s', LiveKitSettings::MIN_SECRET_LENGTH));
        $logger = new InMemoryLogger();

        $spectators = new StudioSpectators(
            new LiveKitApi($client, $settings, new LiveKitTokens($settings, $clock)),
            new ArrayAdapter(),
            $lockFactory ?? new LockFactory(new InMemoryStore()),
            $clock,
            $logger,
        );

        return [$spectators, $clock, $logger];
    }

    /**
     * How many times LiveKit was asked for the studio's list so far
     *
     * @phpstan-impure
     */
    private function listings(): int
    {
        return count($this->listed);
    }

    /**
     * A studio as LiveKit lists it: a host, a guest on air and $spectators spectators
     *
     * @return list<array{identity: string}>
     */
    private static function studio(int $spectators): array
    {
        $participants = [
            ['identity' => 'm:018d0000-0000-0000-0000-000000000003'],
            ['identity' => 'p:' . OnlineRoundsFixture::PARTICIPANT_CONNECTED],
        ];

        for ($i = 0; $i < $spectators; $i++) {
            $participants[] = ['identity' => 's:' . str_pad(dechex($i), 16, '0', STR_PAD_LEFT)];
        }

        return $participants;
    }
}
