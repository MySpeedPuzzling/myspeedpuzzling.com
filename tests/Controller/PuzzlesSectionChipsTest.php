<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Puzzles' sub-navigation (docs/features/navigation README D6, PUZ-01, decision 75): My library (signed-in, first) ·
 * All puzzles · Marketplace · What next? on the four start pages only; every page of a Puzzles section records it on
 * <main data-puzzles-section> (your own library's lists too, object pages never) and every Puzzles place link carries
 * the sections' links (data-puzzles-sections) for assets/puzzles_section.js - for guests without My library, the same
 * for every guest (PuzzlesSectionScriptTest runs the module).
 */
final class PuzzlesSectionChipsTest extends WebTestCase
{
    private const string MEMBER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string MY_LIBRARY = '/en/puzzle-library/' . self::MEMBER;

    private const array GUEST_SECTIONS = [
        'all' => '/en/puzzle',
        'marketplace' => '/en/marketplace',
        'next' => '/en/what-to-solve-next',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideStartPages(): iterable
    {
        yield 'my library' => [self::MY_LIBRARY, 'library'];
        yield 'all puzzles' => ['/en/puzzle', 'all'];
        yield 'marketplace' => ['/en/marketplace', 'marketplace'];
        yield 'what next' => ['/en/what-to-solve-next', 'next'];
    }

    #[DataProvider('provideStartPages')]
    public function testAPlayerGetsTheFourChipsWithMyLibraryFirst(string $url, string $section): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MEMBER);

        $crawler = $this->get($browser, $url);

        self::assertSame(['library' => self::MY_LIBRARY, ...self::GUEST_SECTIONS], $this->chipHrefs($crawler));
        self::assertSame(['My library', 'All puzzles', 'Marketplace', 'What next?'], $crawler->filter('main > nav.section-chips a.section-chip')->each(
            static fn (Crawler $chip): string => trim($chip->text()),
        ));
        self::assertSame(['chips/puzzles/' . $section => 'page'], $this->currentChips($crawler));
        self::assertSame('Puzzles sections', $crawler->filter('main > nav.section-chips')->attr('aria-label'));
        self::assertSame($section, $crawler->filter('main')->attr('data-puzzles-section'));
        $this->assertPlaceLinksCarry($crawler, ['library' => self::MY_LIBRARY, ...self::GUEST_SECTIONS]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideGuestStartPages(): iterable
    {
        yield 'all puzzles' => ['/en/puzzle', 'all'];
        yield 'marketplace' => ['/en/marketplace', 'marketplace'];
        yield 'what next (the guests\' landing)' => ['/en/what-to-solve-next', 'next'];
    }

    #[DataProvider('provideGuestStartPages')]
    public function testAGuestGetsThreeChipsOnASharedCacheablePage(string $url, string $section): void
    {
        $browser = self::createClient();

        $crawler = $this->get($browser, $url);
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame([], $browser->getResponse()->headers->getCookies());
        $first = $this->navigationMarkup($crawler);

        self::assertSame(self::GUEST_SECTIONS, $this->chipHrefs($crawler));
        self::assertSame(['chips/puzzles/' . $section => 'page'], $this->currentChips($crawler));
        self::assertSame($section, $crawler->filter('main')->attr('data-puzzles-section'));
        $this->assertPlaceLinksCarry($crawler, self::GUEST_SECTIONS);

        // Another guest gets the same chips and place links: the browser alone remembers the section
        self::ensureKernelShutdown();
        $other = self::createClient();
        self::assertSame($first, $this->navigationMarkup($this->get($other, $url)));
    }

    /**
     * Your own library's lists record "My library" but show no chips (the player header's tabs are theirs); object
     * pages and somebody else's library record nothing
     *
     * @return iterable<string, array{string, null|string}>
     */
    public static function provideOtherPages(): iterable
    {
        yield 'own wishlist' => ['/en/wish-list/' . self::MEMBER, 'library'];
        yield 'own unsolved puzzles' => ['/en/unsolved-puzzles/' . self::MEMBER, 'library'];
        yield 'own collection' => ['/en/puzzle-collection/' . self::MEMBER, 'library'];
        yield 'puzzle page' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, null];
        yield "somebody else's library" => ['/en/puzzle-library/' . PlayerFixture::PLAYER_REGULAR, null];
        yield "somebody else's wishlist" => ['/en/wish-list/' . PlayerFixture::PLAYER_REGULAR, null];
        yield 'brands' => ['/en/puzzle/brands', null];
        yield 'hub' => ['/en/hub', null];
    }

    #[DataProvider('provideOtherPages')]
    public function testOtherPagesShowNoChipsAndRecordOnlyTheirOwnLibrary(string $url, null|string $recorded): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MEMBER);

        $crawler = $this->get($browser, $url);

        self::assertCount(0, $crawler->filter('.section-chips'), $url);
        self::assertSame($recorded, $crawler->filter('main')->attr('data-puzzles-section'), $url);
        // The place links carry the sections everywhere
        $this->assertPlaceLinksCarry($crawler, ['library' => self::MY_LIBRARY, ...self::GUEST_SECTIONS]);
    }

    private function get(KernelBrowser $browser, string $url): Crawler
    {
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return $crawler;
    }

    /**
     * @return array<string, string>
     */
    private function chipHrefs(Crawler $crawler): array
    {
        $hrefs = [];

        foreach ($crawler->filter('main > nav.section-chips a.section-chip') as $chip) {
            assert($chip instanceof \DOMElement);
            self::assertMatchesRegularExpression('~^chips/puzzles/[a-z]+$~', $chip->getAttribute('data-nav'));
            $hrefs[substr($chip->getAttribute('data-nav'), strlen('chips/puzzles/'))] = $chip->getAttribute('href');
        }

        return $hrefs;
    }

    /**
     * @return array<string, string>
     */
    private function currentChips(Crawler $crawler): array
    {
        $current = [];

        foreach ($crawler->filter('main > nav.section-chips a.section-chip[aria-current]') as $chip) {
            assert($chip instanceof \DOMElement);
            $current[$chip->getAttribute('data-nav')] = $chip->getAttribute('aria-current');
        }

        return $current;
    }

    /**
     * The tab bar (= the rail), the 768-1023 px top bar's places and the docked Menu's Puzzles row
     *
     * @param array<string, string> $sections
     */
    private function assertPlaceLinksCarry(Crawler $crawler, array $sections): void
    {
        $links = $crawler->filter('#bottom-nav a[data-nav-place="puzzles"], header .site-header-places a[data-nav-place="puzzles"], #site-menu a[data-nav-place="puzzles"]');
        self::assertCount(3, $links);

        foreach ($links as $link) {
            assert($link instanceof \DOMElement);
            self::assertSame($sections, json_decode($link->getAttribute('data-puzzles-sections'), true, flags: JSON_THROW_ON_ERROR), $link->getAttribute('data-nav'));
        }
    }

    private function navigationMarkup(Crawler $crawler): string
    {
        return $crawler->filter('main > nav.section-chips')->outerHtml() . $crawler->filter('#bottom-nav')->outerHtml() . $crawler->filter('header .site-header-places')->outerHtml();
    }
}
