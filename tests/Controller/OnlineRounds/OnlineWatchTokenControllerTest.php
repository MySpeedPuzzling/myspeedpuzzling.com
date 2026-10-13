<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Controller\OnlineRounds\OnlineWatchTokenController;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\TestDouble\LiveKitHttpMock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * `POST /en/online-rounds/{roundId}/watch/token` (contract phase-1b.md §3, §5 `online_watch_token`): a spectator's
 * watch-only token to the round's studio - for anybody, as long as the event is public (its editors excepted), the
 * round's video is on, the client IP is under its limit and the studio is not full (LiveKit's own count, cached a few
 * seconds - LiveKitHttpMock answers ListParticipants; the count's window and its lock: StudioSpectatorsTest).
 */
final class OnlineWatchTokenControllerTest extends WebTestCase
{
    use LiveVideoTesting;

    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string URL = '/en/online-rounds/' . self::ROUND . '/watch/token';
    private const string STUDIO = 'studio-' . self::ROUND;

    private null|false|string $originalMaxSpectators = null;

    protected function setUp(): void
    {
        self::resetLiveVideoDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreLiveVideo();

        if ($this->originalMaxSpectators !== null) {
            if ($this->originalMaxSpectators === false) {
                unset($_ENV['ONLINE_WATCH_MAX_SPECTATORS'], $_SERVER['ONLINE_WATCH_MAX_SPECTATORS']);
            } else {
                $_ENV['ONLINE_WATCH_MAX_SPECTATORS'] = $this->originalMaxSpectators;
                $_SERVER['ONLINE_WATCH_MAX_SPECTATORS'] = $this->originalMaxSpectators;
            }
        }

        parent::tearDown();
    }

    public function testASpectatorGetsAWatchOnlyTokenToTheStudio(): void
    {
        $browser = $this->watchable();

        $browser->request('POST', self::URL, server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $response = $browser->getResponse();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // No session, no cookie - the page's token carries nothing of the viewer
        self::assertSame([], $response->headers->getCookies());

        $answer = $this->json($browser);
        self::assertSame(['url', 'token', 'identity', 'room'], array_keys($answer));
        self::assertSame(self::LIVEKIT_TEST_PUBLIC_URL, $answer['url']);
        self::assertSame(self::STUDIO, $answer['room']);
        self::assertIsString($answer['identity']);
        self::assertMatchesRegularExpression('/^s:[0-9a-f]{16}$/', $answer['identity']);
        self::assertIsString($answer['token']);

        $claims = self::liveKitClaims($answer['token']);
        self::assertSame($answer['identity'], $claims['sub']);
        self::assertArrayNotHasKey('name', $claims);
        self::assertSame([
            'room' => self::STUDIO,
            'roomJoin' => true,
            'canPublish' => false,
            'canSubscribe' => true,
            'canPublishData' => false,
            'hidden' => true,
        ], $claims['video']);

        // The studio's spectators were counted once, with a server token for that room only
        $requests = LiveKitHttpMock::requests();
        self::assertCount(1, $requests);
        self::assertStringEndsWith('/twirp/livekit.RoomService/ListParticipants', $requests[0]['url']);
        self::assertSame(['room' => self::STUDIO], json_decode($requests[0]['body'], true));

        // Every spectator is somebody else
        $browser->request('POST', self::URL);
        self::assertResponseIsSuccessful();
        self::assertNotSame($answer['identity'], $this->json($browser)['identity']);
    }

    public function testRefusals(): void
    {
        $browser = self::createClient();
        $connection = $this->connection($browser);
        self::publish($connection);

        // The round's video is off
        $browser->request('POST', self::URL);
        self::assertRefused($browser, 409, 'video_unavailable');

        // A round without online timing, a round that does not exist
        $browser->request('POST', '/en/online-rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/watch/token');
        self::assertRefused($browser, 404, 'round_not_online');
        $browser->request('POST', '/en/online-rounds/018d0050-0000-0000-0000-00000000dead/watch/token');
        self::assertRefused($browser, 404, 'not_found');

        // Only POST
        $browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(405);

        // No media server
        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $browser = $this->watchable();
        $browser->request('POST', self::URL);
        self::assertRefused($browser, 409, 'video_unavailable');
        self::assertSame([], LiveKitHttpMock::requests(), 'nothing asked of a server that is not there');
    }

    public function testAnEventThatIsNotPublicIsWatchedOnlyByItsEditors(): void
    {
        $browser = self::createClient();
        // The Online Test Cup is a draft
        self::turnRoundVideoOn($this->connection($browser));

        $browser->request('POST', self::URL);
        self::assertRefused($browser, 404, 'not_found');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('POST', self::URL);
        self::assertRefused($browser, 404, 'not_found');

        // Waiting for approval - the same
        $this->connection($browser)->executeStatement(
            'UPDATE competition SET is_draft = false, approved_at = NULL, approved_by_player_id = NULL WHERE id = :id',
            ['id' => OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP],
        );
        $browser->request('POST', self::URL);
        self::assertRefused($browser, 404, 'not_found');
        self::assertSame([], LiveKitHttpMock::requests());

        // Its organiser previews the studio
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('POST', self::URL);
        self::assertResponseIsSuccessful();
        self::assertSame(self::STUDIO, $this->json($browser)['room']);
    }

    public function testAFullStudioRefusesTheNextSpectator(): void
    {
        $browser = $this->watchable();
        $limit = OnlineWatchTokenController::DEFAULT_MAX_SPECTATORS;

        // The host and a guest on air do not count - the spectators do
        LiveKitHttpMock::queue(LiveKitHttpMock::answer(['participants' => self::studio($limit)]));
        $browser->request('POST', self::URL);
        self::assertRefused($browser, 409, 'studio_full');

        LiveKitHttpMock::queue(LiveKitHttpMock::answer(['participants' => self::studio($limit - 1)]));
        $browser->request('POST', self::URL);
        self::assertResponseIsSuccessful();
    }

    public function testTheLimitIsConfigured(): void
    {
        $this->limitSpectatorsTo('2');
        $browser = $this->watchable();

        LiveKitHttpMock::queue(LiveKitHttpMock::answer(['participants' => self::studio(2)]));
        $browser->request('POST', self::URL);
        self::assertRefused($browser, 409, 'studio_full');

        LiveKitHttpMock::queue(LiveKitHttpMock::answer(['participants' => self::studio(1)]));
        $browser->request('POST', self::URL);
        self::assertResponseIsSuccessful();
    }

    public function testOneAddressAskingTooOftenIsToldWhenToComeBack(): void
    {
        $browser = $this->watchable();
        $ip = '198.51.100.' . random_int(1, 254);
        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = $browser->getContainer()->get('online_rate_limiter.online_watch_ip');

        try {
            $limiter->create($ip)->consume($limiter->create($ip)->consume(0)->getLimit());

            $browser->request('POST', self::URL, server: ['REMOTE_ADDR' => $ip]);
            self::assertRefused($browser, 429, 'rate_limited');
            $retryAfter = $browser->getResponse()->headers->get('Retry-After');
            self::assertIsString($retryAfter);
            self::assertGreaterThanOrEqual(1, (int) $retryAfter);
            self::assertLessThanOrEqual(60, (int) $retryAfter);
            // Refused before anything was read - LiveKit included
            self::assertSame([], LiveKitHttpMock::requests());

            // Another address is not held up
            $browser->request('POST', self::URL, server: ['REMOTE_ADDR' => '203.0.113.' . random_int(1, 254)]);
            self::assertResponseIsSuccessful();
        } finally {
            $limiter->create($ip)->reset();
        }
    }

    private function watchable(): KernelBrowser
    {
        $browser = self::createClient();
        self::publish($this->connection($browser));
        self::turnRoundVideoOn($this->connection($browser));

        return $browser;
    }

    private function limitSpectatorsTo(string $limit): void
    {
        if ($this->originalMaxSpectators === null) {
            $original = $_ENV['ONLINE_WATCH_MAX_SPECTATORS'] ?? false;
            $this->originalMaxSpectators = is_string($original) ? $original : false;
        }

        $_ENV['ONLINE_WATCH_MAX_SPECTATORS'] = $limit;
        $_SERVER['ONLINE_WATCH_MAX_SPECTATORS'] = $limit;
    }

    /**
     * The studio as LiveKit lists it: a host, a guest on air and $spectators spectators
     *
     * @return list<array{identity: string}>
     */
    private static function studio(int $spectators): array
    {
        $participants = [
            ['identity' => 'm:' . PlayerFixture::PLAYER_ADMIN],
            ['identity' => 'p:' . OnlineRoundsFixture::PARTICIPANT_CONNECTED],
        ];

        for ($i = 0; $i < $spectators; $i++) {
            $participants[] = ['identity' => 's:' . str_pad(dechex($i), 16, '0', STR_PAD_LEFT)];
        }

        return $participants;
    }

    private static function publish(Connection $connection): void
    {
        $connection->executeStatement('UPDATE competition SET is_draft = false WHERE id = :id', ['id' => OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP]);
    }

    private static function assertRefused(KernelBrowser $browser, int $status, string $error): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertSame(['error' => $error], json_decode((string) $browser->getResponse()->getContent(), true));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(KernelBrowser $browser): array
    {
        $decoded = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function connection(KernelBrowser $browser): Connection
    {
        return $browser->getContainer()->get(Connection::class);
    }
}
