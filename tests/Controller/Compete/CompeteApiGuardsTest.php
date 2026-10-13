<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What every request of the participant API shares (contract §6, implementation-plan.md WP 1.5): no session cookie is
 * ever set, every refusal is JSON with the clock sample, and a link is rate limited (429 + Retry-After).
 */
final class CompeteApiGuardsTest extends WebTestCase
{
    use CompeteApiRequests;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        self::forgetCachedAccess($this->browser);
    }

    public function testNoEndpointEverSetsACookieNotEvenForASignedInPlayer(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        // loginUser() hands the token to the booted kernel itself - the next requests know the player only from the
        // session cookie, as real requests do
        $this->browser->request('GET', '/-/time');

        $token = OnlineRoundsFixture::TOKEN_CONNECTED;
        $requests = [
            ['GET', self::api($token, 'state'), null],
            ['POST', self::api($token, 'heartbeat'), ['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED]],
            ['POST', self::api($token, 'events'), ['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED, 'events' => []]],
            ['POST', self::api($token, 'media'), ['deviceId' => OnlineRoundsFixture::DEVICE_CONNECTED, 'kind' => 'periodic', 'takenMs' => self::nowMs(), 'bytes' => 35_000, 'contentType' => 'image/jpeg']],
            ['GET', self::api($token, 'events'), null],
            ['GET', self::api('NoSuchLinkAnywhere2222', 'state'), null],
            ['GET', '/-/time', null],
        ];

        foreach ($requests as [$method, $url, $body]) {
            if ($body === null) {
                $this->browser->request($method, $url);
            } else {
                self::postJson($this->browser, $url, $body);
            }

            self::assertSame([], $this->browser->getResponse()->headers->getCookies(), $method . ' ' . $url);
        }
    }

    /**
     * Removed from the competition: the link opens nothing anywhere (the heartbeat knows it once its cached lookup is
     * read again - OnlineAccessLight::TTL_SECONDS at most)
     */
    public function testARemovedParticipantsLinkOpensNothingOnAnyEndpoint(): void
    {
        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $participant = $entityManager->find(CompetitionParticipant::class, OnlineRoundsFixture::PARTICIPANT_CONNECTED);
        self::assertNotNull($participant);
        $participant->softDelete(new DateTimeImmutable());
        $entityManager->flush();

        $token = OnlineRoundsFixture::TOKEN_CONNECTED;
        $device = OnlineRoundsFixture::DEVICE_CONNECTED;

        $this->browser->request('GET', self::api($token, 'state'));
        self::assertRefused($this->browser, 404, 'unknown_link');

        self::postJson($this->browser, self::api($token, 'heartbeat'), ['deviceId' => $device]);
        self::assertRefused($this->browser, 404, 'unknown_link');

        self::postJson($this->browser, self::api($token, 'events'), ['deviceId' => $device, 'events' => []]);
        self::assertRefused($this->browser, 404, 'unknown_link');

        self::postJson($this->browser, self::api($token, 'media'), ['deviceId' => $device, 'kind' => 'periodic', 'takenMs' => self::nowMs(), 'bytes' => 35_000, 'contentType' => 'image/jpeg']);
        self::assertRefused($this->browser, 404, 'unknown_link');
    }

    public function testAMethodOrPathTheApiDoesNotHaveIsAnsweredInJson(): void
    {
        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'events'));
        self::assertRefused($this->browser, 405, 'method_not_allowed');
        self::assertSame('POST', $this->browser->getResponse()->headers->get('Allow'));

        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_CONNECTED, 'whatever'));
        self::assertRefused($this->browser, 404, 'not_found');
    }

    public function testALinkAskingTooOftenIsToldWhenToComeBack(): void
    {
        $token = $this->freshLink();
        $this->browser->getContainer()->get('limiter.online_participant')->create($token)->consume(300);

        $this->browser->request('GET', self::api($token, 'state'));
        self::assertRefused($this->browser, 429, 'rate_limited');
        $retryAfter = $this->browser->getResponse()->headers->get('Retry-After');
        self::assertIsString($retryAfter);
        self::assertGreaterThanOrEqual(1, (int) $retryAfter);
        self::assertLessThanOrEqual(60, (int) $retryAfter);

        self::postJson($this->browser, self::api($token, 'heartbeat'), ['deviceId' => 'phone-a']);
        self::assertRefused($this->browser, 429, 'rate_limited');

        // Another link is not held up
        $this->browser->request('GET', self::api(OnlineRoundsFixture::TOKEN_ANONYMOUS, 'state'));
        self::assertResponseIsSuccessful();
    }

    /**
     * A new link of Nora Notoken (not entered) - its own rate limit, whatever ran before
     */
    private function freshLink(): string
    {
        $token = OnlineToken::generate();
        $entityManager = $this->browser->getContainer()->get(EntityManagerInterface::class);
        $participant = $entityManager->find(CompetitionParticipant::class, OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN);
        self::assertNotNull($participant);
        $participant->assignOnlineToken($token, new DateTimeImmutable());
        $entityManager->flush();

        return $token;
    }
}
