<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\LiveVideoTesting;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `POST /en/online-rounds/{roundId}/video-token` (contract phase-1b.md §3, §5 `online_video_token`): a moderator's or
 * host's token to the hall or the studio - ONLINE_ROUNDS_MANAGE, JSON with the stateless CSRF header, `video_unavailable`
 * without a media server or with the round's video off.
 */
final class OnlineVideoTokenControllerTest extends WebTestCase
{
    use LiveVideoTesting;

    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string URL = '/en/online-rounds/' . self::ROUND . '/video-token';

    protected function setUp(): void
    {
        self::resetLiveVideoDoubles();
    }

    protected function tearDown(): void
    {
        $this->restoreLiveVideo();

        parent::tearDown();
    }

    public function testAModeratorGetsTheHallAndTheStudio(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::turnRoundVideoOn($this->connection($browser));

        $this->post($browser, self::URL, ['room' => 'hall']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        $hall = $this->json($browser);
        self::assertSame(['url', 'token', 'identity', 'room'], array_keys($hall));
        self::assertSame(self::LIVEKIT_TEST_PUBLIC_URL, $hall['url']);
        self::assertSame('m:' . PlayerFixture::PLAYER_ADMIN, $hall['identity']);
        self::assertSame('round-' . self::ROUND, $hall['room']);
        self::assertIsString($hall['token']);
        $claims = self::liveKitClaims($hall['token']);
        self::assertIsString($claims['name']);
        self::assertSame([
            'room' => 'round-' . self::ROUND,
            'roomJoin' => true,
            'canPublish' => true,
            'canPublishSources' => ['camera', 'microphone', 'screen_share'],
            'canSubscribe' => true,
            'canPublishData' => true,
        ], $claims['video']);

        $this->post($browser, '/online-kola/' . self::ROUND . '/video-token', ['room' => 'studio']);
        self::assertResponseIsSuccessful();
        $studio = $this->json($browser);
        self::assertSame('studio-' . self::ROUND, $studio['room']);
        self::assertIsString($studio['token']);
        $video = self::liveKitClaims($studio['token'])['video'];
        self::assertIsArray($video);
        self::assertTrue($video['roomAdmin']);
    }

    public function testOnlyTheRoundsOrganisers(): void
    {
        $browser = self::createClient();
        self::turnRoundVideoOn($this->connection($browser));

        $this->post($browser, self::URL, ['room' => 'hall']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'sign_in_required'], $this->json($browser));

        // Olivia's player - a participant, no organiser
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $this->post($browser, self::URL, ['room' => 'hall']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $this->json($browser));
    }

    public function testJsonAndTheCsrfHeader(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::turnRoundVideoOn($this->connection($browser));

        $browser->request('POST', self::URL, ['room' => 'hall'], server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(415);

        $this->post($browser, self::URL, ['room' => 'hall'], origin: 'https://elsewhere.example');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'invalid_csrf_token'], $this->json($browser));

        $this->post($browser, self::URL, ['room' => 'backstage']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'invalid_fields'], $this->json($browser));
    }

    public function testVideoMustBeAvailableAndOnForTheRound(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);

        $this->post($browser, self::URL, ['room' => 'hall']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'video_unavailable'], $this->json($browser));

        // A round without online timing
        $this->post($browser, '/en/online-rounds/' . OfficialResultsFixture::ROUND_GROUP_A . '/video-token', ['room' => 'hall']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'round_not_online'], $this->json($browser));

        self::ensureKernelShutdown();
        $this->turnLiveVideoOff();
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        self::turnRoundVideoOn($this->connection($browser));

        $this->post($browser, self::URL, ['room' => 'hall']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'video_unavailable'], $this->json($browser));
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(KernelBrowser $browser, string $url, array $body, string $origin = 'http://localhost'): void
    {
        $browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
            'HTTP_REFERER' => $origin . '/',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
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
