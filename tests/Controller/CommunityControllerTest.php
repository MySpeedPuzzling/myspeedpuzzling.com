<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Community landing (docs/features/navigation README §4.9, COM-01a): the place's start page with Leaderboards,
 * Puzzlers and Events, in every locale, indexable, and - for guests - shared-cacheable.
 */
final class CommunityControllerTest extends WebTestCase
{
    private const array SECTIONS = [
        'leaderboards' => '/en/ladder',
        'puzzlers' => '/en/puzzlers',
        'events' => '/en/events',
    ];

    public function testGuestsGetTheThreeSectionsOnASharedCacheablePage(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/community');

        self::assertResponseIsSuccessful();
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        // Shared-cacheable guest HTML: no cookie, no CSRF token in the page (the footer's newsletter form keeps its stateless one)
        self::assertSame([], $browser->getResponse()->headers->getCookies());
        self::assertCount(0, $crawler->filter('main [name="_token"], main [name$="[_token]"]'));
        $this->assertTheSections($crawler);
        self::assertSame('Community', trim($crawler->filter('main h1')->text()));
    }

    public function testMembersGetTheSamePage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/community');

        self::assertResponseIsSuccessful();
        $this->assertTheSections($crawler);
    }

    public function testItIsIndexableWithCanonicalAndHreflangInEveryLocale(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/community');

        self::assertResponseIsSuccessful();
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('http://localhost/en/community', $crawler->filter('link[rel="canonical"]')->attr('href'));

        $alternates = [];

        foreach ($crawler->filter('link[rel="alternate"][hreflang]') as $link) {
            assert($link instanceof \DOMElement);
            $alternates[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }

        self::assertSame([
            'cs' => 'http://localhost/komunita',
            'en' => 'http://localhost/en/community',
            'es' => 'http://localhost/es/comunidad',
            'ja' => 'http://localhost/ja/' . rawurlencode('コミュニティ'),
            'fr' => 'http://localhost/fr/communaute',
            'de' => 'http://localhost/de/community',
            'x-default' => 'http://localhost/en/community',
        ], $alternates);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLocalePaths(): iterable
    {
        yield 'cs' => ['/komunita', 'Komunita'];
        yield 'es' => ['/es/comunidad', 'Comunidad'];
        yield 'ja' => ['/ja/コミュニティ', 'コミュニティ'];
        yield 'fr' => ['/fr/communaute', 'Communauté'];
        yield 'de' => ['/de/community', 'Community'];
    }

    #[DataProvider('provideLocalePaths')]
    public function testEveryLocaleHasItsPage(string $path, string $title): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame($title, trim($crawler->filter('main h1')->text()));
        self::assertCount(3, $crawler->filter('.community-landing [data-nav^="community/community/"]'));
    }

    private function assertTheSections(Crawler $crawler): void
    {
        $links = $crawler->filter('.community-landing a');
        self::assertCount(3, $links);

        $found = [];

        foreach ($links as $link) {
            assert($link instanceof \DOMElement);
            $found[$link->getAttribute('data-nav')] = $link->getAttribute('href');
        }

        $expected = [];

        foreach (self::SECTIONS as $item => $href) {
            $expected['community/community/' . $item] = $href;
        }

        self::assertSame($expected, $found);

        // The titles are the registry's labels (one name per destination)
        self::assertSame(['Leaderboards', 'Players', 'Events'], $crawler->filter('.community-entry-title')->each(
            static fn (Crawler $title): string => trim($title->text()),
        ));
    }
}
