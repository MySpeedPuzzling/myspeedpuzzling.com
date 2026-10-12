<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AnonymousCacheHeadersSubscriberTest extends WebTestCase
{
    public function testAnonymousHtmlPageIsSharedCacheableWithoutCookies(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/');

        $this->assertResponseIsSuccessful();

        $response = $browser->getResponse();
        $cacheControl = (string) $response->headers->get('Cache-Control');

        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=60', $cacheControl);
        self::assertStringContainsString('max-age=0', $cacheControl);
        self::assertStringNotContainsString('private', $cacheControl);
        self::assertSame([], $response->headers->getCookies(), 'Anonymous response must not set any cookie');
    }

    public function testAnonymousPuzzleListingIsSharedCacheable(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle');

        $this->assertResponseIsSuccessful();

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=60', $cacheControl);
    }

    public function testLoggedInResponseStaysPrivate(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle');

        $this->assertResponseIsSuccessful();

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('private', $cacheControl);
        self::assertStringNotContainsString('s-maxage', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    public function testTurboFrameResponseIsNotSharedCacheable(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        $this->assertResponseIsSuccessful();

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');

        self::assertStringNotContainsString('s-maxage', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    public function testNotFoundResponseIsNotSharedCacheable(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/this-page-definitely-does-not-exist');

        $this->assertResponseStatusCodeSame(404);

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');

        self::assertStringNotContainsString('s-maxage', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
    }

    public function testRouteWithExplicitCachingKeepsItsOwnHeaders(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/homepage-stats');

        $this->assertResponseIsSuccessful();

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('max-age=30', $cacheControl);
        self::assertStringNotContainsString('s-maxage=60', $cacheControl);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nativeAppUserAgents(): array
    {
        return array_map(static fn (array $row): array => [$row[0]], NativeUserAgents::allNative());
    }

    /**
     * The native app gets other HTML at the same URL (no web chrome, data-native-app): never shared-cacheable, with
     * the new Hotwire Native markers and the older ones alike (docs/features/navigation NAV-14). No `Vary: User-Agent`
     * either - an edge rule bypasses app requests (docs/features/seo/cloudflare.md).
     */
    #[DataProvider('nativeAppUserAgents')]
    public function testNativeAppResponseIsNotSharedCacheable(string $userAgent): void
    {
        $browser = self::createClient();

        foreach (['/', '/en/puzzle'] as $url) {
            $browser->request('GET', $url, server: ['HTTP_USER_AGENT' => $userAgent]);

            $this->assertResponseIsSuccessful();

            $response = $browser->getResponse();
            $cacheControl = (string) $response->headers->get('Cache-Control');

            self::assertStringNotContainsString('public', $cacheControl, $url);
            self::assertStringNotContainsString('s-maxage', $cacheControl, $url);
            self::assertStringNotContainsString('User-Agent', (string) $response->headers->get('Vary'), $url);
        }
    }

    public function testBrowsersOnPhonesStayCacheable(): void
    {
        $browser = self::createClient();

        foreach (NativeUserAgents::web() as $name => [$userAgent]) {
            $browser->request('GET', '/', server: ['HTTP_USER_AGENT' => $userAgent]);

            $this->assertResponseIsSuccessful();

            $response = $browser->getResponse();
            self::assertStringContainsString('s-maxage=60', (string) $response->headers->get('Cache-Control'), $name);
            self::assertStringNotContainsString('User-Agent', (string) $response->headers->get('Vary'), $name);
        }
    }
}
