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

    public function testAnalyticsOffResponseIsNotSharedCacheableAndHasNoAnalytics(): void
    {
        $this->withGaTracking(function (): void {
            $browser = self::createClient();

            $browser->request('GET', '/');
            $this->assertResponseIsSuccessful();
            self::assertStringContainsString(
                'data-analytics-tracking-id-value="G-TEST123"',
                (string) $browser->getResponse()->getContent(),
                'Control: a normal visitor gets the analytics controller',
            );

            $browser->request('GET', '/', server: ['HTTP_X_BB_ANALYTICS' => 'off']);
            $this->assertResponseIsSuccessful();

            $response = $browser->getResponse();
            $cacheControl = (string) $response->headers->get('Cache-Control');

            self::assertStringNotContainsString('s-maxage', $cacheControl);
            self::assertStringNotContainsString('public', $cacheControl);
            self::assertStringNotContainsString('data-analytics-tracking-id-value', (string) $response->getContent());
            self::assertStringNotContainsString('toast analytics', (string) $response->getContent());
        });
    }

    /**
     * GA_TRACKING is empty in the test env; the env var is resolved when the
     * (per-client) container builds the Twig globals, so set it around the run.
     */
    private function withGaTracking(callable $test): void
    {
        $previousEnv = $_ENV['GA_TRACKING'] ?? null;
        $previousServer = $_SERVER['GA_TRACKING'] ?? null;
        $_ENV['GA_TRACKING'] = 'G-TEST123';
        $_SERVER['GA_TRACKING'] = 'G-TEST123';

        try {
            $test();
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['GA_TRACKING']);
            } else {
                $_ENV['GA_TRACKING'] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER['GA_TRACKING']);
            } else {
                $_SERVER['GA_TRACKING'] = $previousServer;
            }
        }
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
