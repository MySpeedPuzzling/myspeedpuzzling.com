<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Compete;

use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccessLight;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Requests of a participant's phone to the participant API (`/api/compete/{token}/…`) in WebTestCases.
 */
trait CompeteApiRequests
{
    private static function api(string $token, string $endpoint): string
    {
        return '/api/compete/' . $token . '/' . $endpoint;
    }

    private static function postJson(KernelBrowser $browser, string $url, mixed $body): void
    {
        $browser->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(KernelBrowser $browser): array
    {
        $response = $browser->getResponse();
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));

        $decoded = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A refusal of the participant API: the code, the clock sample, never cached, never a cookie
     */
    private static function assertRefused(KernelBrowser $browser, int $status, string $error): void
    {
        self::assertResponseStatusCodeSame($status);

        $json = self::json($browser);
        self::assertSame($error, $json['error'] ?? null);
        self::assertIsInt($json['serverNowMs'] ?? null);
        self::assertPrivateNoStore($browser);
        self::assertNoCookie($browser);
    }

    private static function assertPrivateNoStore(KernelBrowser $browser): void
    {
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }

    private static function assertNoCookie(KernelBrowser $browser): void
    {
        self::assertSame([], $browser->getResponse()->headers->getCookies());
        self::assertNull($browser->getResponse()->headers->get('Set-Cookie'));
    }

    /**
     * The heartbeat's cached lookups and the links found unknown (a test removing a participant leaves its token
     * unknown for OnlineAccessLight::UNKNOWN_TTL_SECONDS) live in the shared cache, on disk in tests - across tests and
     * runs: every test starts from what the database (rolled back by DAMA) says
     */
    private static function forgetCachedAccess(KernelBrowser $browser, string ...$tokens): void
    {
        $cache = $browser->getContainer()->get(CacheInterface::class);

        foreach ([OnlineRoundsFixture::TOKEN_CONNECTED, OnlineRoundsFixture::TOKEN_ANONYMOUS, ...$tokens] as $token) {
            $cache->delete(OnlineAccessLight::cacheKey($token));
            $cache->delete(OnlineAccessLight::GENERATION_KEY_PREFIX . $token);
            $cache->delete(OnlineAccessLight::UNKNOWN_KEY_PREFIX . $token);
        }

        $cache->delete(OnlineAccessLight::ROUND_HASH_KEY_PREFIX . OnlineRoundsFixture::ROUND_ONLINE_SOLO);
    }

    private static function nowMs(): int
    {
        return TestClock::milliseconds();
    }
}
