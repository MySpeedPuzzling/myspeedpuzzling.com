<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
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
}
