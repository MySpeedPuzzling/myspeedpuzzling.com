<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SitemapControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessSitemapIndex(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap.xml');

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('<sitemapindex', $content);
        self::assertStringContainsString('/sitemap-static.xml', $content);
        self::assertStringContainsString('/sitemap-puzzles-1.xml', $content);
        self::assertStringContainsString('/sitemap-marketplace.xml', $content);
        self::assertStringContainsString('/sitemap-events.xml', $content);
        self::assertStringContainsString('/sitemap-players.xml', $content);
        self::assertStringContainsString('/sitemap-feature-requests.xml', $content);
        self::assertStringContainsString('/sitemap-countries.xml', $content);
    }

    public function testLoggedInUserCanAccessSitemapIndex(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/sitemap.xml');

        $this->assertResponseIsSuccessful();
    }

    public function testSitemapIsCacheableBySharedCaches(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/sitemap.xml');

        $this->assertResponseIsSuccessful();

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');

        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('s-maxage=21600', $cacheControl);
        self::assertStringNotContainsString('private', $cacheControl);
    }

    public function testChildSitemapsAreAccessible(): void
    {
        $browser = self::createClient();

        $paths = [
            '/sitemap-static.xml',
            '/sitemap-puzzles-1.xml',
            '/sitemap-marketplace.xml',
            '/sitemap-events.xml',
            '/sitemap-players.xml',
            '/sitemap-feature-requests.xml',
            '/sitemap-countries.xml',
        ];

        foreach ($paths as $path) {
            $browser->request('GET', $path);

            $this->assertResponseIsSuccessful(sprintf('Sitemap "%s" should be accessible', $path));

            $content = (string) $browser->getResponse()->getContent();

            self::assertStringContainsString('<urlset', $content, sprintf('Sitemap "%s" should contain a urlset', $path));
            self::assertStringNotContainsString('xhtml:link', $content, sprintf('Sitemap "%s" must not contain hreflang alternates', $path));
        }
    }

    public function testStaticSitemapContainsEveryLocale(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-static.xml');

        $this->assertResponseIsSuccessful();

        $content = (string) $browser->getResponse()->getContent();

        foreach (['/en/', '/es/', '/ja/', '/fr/', '/de/'] as $localePrefix) {
            self::assertStringContainsString($localePrefix, $content);
        }
    }

    public function testOutOfRangePuzzlesPageReturnsNotFound(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-puzzles-999.xml');

        $this->assertResponseStatusCodeSame(404);
    }
}
