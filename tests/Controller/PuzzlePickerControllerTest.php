<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PuzzlePickerControllerTest extends WebTestCase
{
    public function testGuestGetsTheIndexableLandingPageWithADemoCard(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/what-to-solve-next');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('<meta name="robots" content="index, follow">', $content);
        self::assertStringContainsString('<link rel="canonical" href="http://localhost/en/what-to-solve-next">', $content);
        self::assertStringContainsString('hreflang="cs"', $content);
        self::assertCount(1, $crawler->filter('h1:contains("What should I solve next?")'));

        // Live demo: one visible card + hidden ones, spin + show more, "1 of N"
        self::assertGreaterThan(1, $crawler->filter('.puzzle-picker-card')->count());
        self::assertCount(1, $crawler->filter('[data-puzzle-picker-reveal-target="card"]:not(.d-none)'));
        self::assertCount(1, $crawler->filter('[data-action="puzzle-picker-reveal#reveal"]'));
        self::assertStringContainsString('matching puzzles', $content);
        self::assertStringContainsString('Pick another', $content);
        self::assertStringContainsString('Show 5 more', $content);

        // Marketing sections + conversion path
        self::assertStringContainsString('How it works', $content);
        self::assertStringContainsString('What you can filter', $content);
        self::assertStringContainsString('For members', $content);
        self::assertStringContainsString('Sign in to pick from your own shelf', $content);
        self::assertGreaterThan(0, $crawler->filter('a[href="/login?return=/en/what-to-solve-next"]')->count());

        // Guests never see personal CTAs or personal data
        self::assertStringNotContainsString('puzzle-stopwatch/', $content);
        self::assertStringNotContainsString('/en/puzzle-add/', $content);
        self::assertGreaterThan(0, $crawler->filter('.puzzle-picker-card a[href="/login?return=/en/what-to-solve-next"]')->count(), 'Guest cards lead to sign-in');
        self::assertCount(2, $crawler->filter('#puzzlePickerFilters fieldset[disabled]'), 'Shelf and history groups are disabled for guests');
    }

    public function testEveryLocalePathIsServed(): void
    {
        $browser = self::createClient();

        foreach (['/co-skladat-dal', '/en/what-to-solve-next', '/es/que-puzzle-armar', '/fr/quel-puzzle-faire', '/de/welches-puzzle-als-naechstes', '/ja/' . rawurlencode('次のパズル')] as $path) {
            $browser->request('GET', $path);

            self::assertResponseIsSuccessful("{$path} should render");
        }
    }

    #[DataProvider('provideNoindexQueries')]
    public function testAnyQueryParameterMakesThePageNoindexButKeepsTheCanonical(string $query): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/what-to-solve-next?' . $query);

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $content);
        self::assertStringNotContainsString('<meta name="robots" content="index, follow">', $content);
        self::assertStringContainsString('<link rel="canonical" href="http://localhost/en/what-to-solve-next">', $content);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNoindexQueries(): iterable
    {
        yield 'pieces filter' => ['pieces[]=500'];
        yield 'seed only' => ['seed=abcd1234'];
        yield 'brand filter' => ['brand[]=' . ManufacturerFixture::MANUFACTURER_TREFL];
        yield 'garbage' => ['foo=bar'];
    }

    public function testFilterAndSpinLinksAreNofollowAndTheSeedNeverLeaksIntoTheBareUrl(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/what-to-solve-next?pieces[]=500&brand[]=' . ManufacturerFixture::MANUFACTURER_TREFL);

        $this->assertResponseIsSuccessful();

        // Chips: pieces + brand, each removing itself, brand chip labelled by name
        $chips = $crawler->filter('.puzzle-picker-filter-bar a[title="Remove this filter"]');
        self::assertCount(2, $chips);
        self::assertStringContainsString('500 pieces', $chips->eq(0)->text());
        self::assertStringContainsString('Trefl', $chips->eq(1)->text());
        self::assertStringNotContainsString('pieces', (string) $chips->eq(0)->attr('href'));
        self::assertStringContainsString('brand', (string) $chips->eq(0)->attr('href'));

        $crawler->filter('.puzzle-picker-filter-bar a, a:contains("Pick another")')->each(static function (Crawler $link): void {
            self::assertSame('nofollow', $link->attr('rel'), 'Every filter/spin link is nofollow');
        });

        $spin = $crawler->filter('a:contains("Pick another")');
        self::assertCount(1, $spin);
        self::assertMatchesRegularExpression('/seed=[a-z0-9]{4,16}/', (string) $spin->attr('href'));
        self::assertStringContainsString('pieces', (string) $spin->attr('href'), 'Spinning keeps the filters');

        // Reset goes to the bare canonical URL
        self::assertCount(1, $crawler->filter('.puzzle-picker-filter-bar a[href="/en/what-to-solve-next"]'));
    }

    public function testSignedInPlayerGetsTheToolWithTheirOwnData(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/what-to-solve-next?seed=abcd1234');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $content);
        self::assertCount(1, $crawler->filter('h1:contains("What should I solve next?")'));
        self::assertStringNotContainsString('How it works', $content, 'No marketing sections for signed-in players');

        // PLAYER_REGULAR's shelf has 7 pickable puzzles - 6 cards, "1 of 7"
        self::assertCount(6, $crawler->filter('.puzzle-picker-card'));
        self::assertStringContainsString('of 7 matching puzzles', $content);

        // Personal CTAs + personal history on every card of a fully solved shelf
        self::assertCount(6, $crawler->filter('.puzzle-picker-card a[href*="/en/puzzle-stopwatch/"]'));
        self::assertCount(6, $crawler->filter('.puzzle-picker-card a[href*="/en/puzzle-add/"]'));
        self::assertStringContainsString('solved', $content);
        self::assertStringContainsString('In Collection', $content);

        // Default shelf chip with a widening ×
        $chips = $crawler->filter('.puzzle-picker-filter-bar a[title="Remove this filter"]');
        self::assertCount(1, $chips);
        self::assertStringContainsString('My collection', $chips->text());
        self::assertStringContainsString('source=any', (string) $chips->attr('href'));

        // Personal filter groups are enabled
        self::assertCount(0, $crawler->filter('#puzzlePickerFilters fieldset[disabled]'));
        self::assertCount(1, $crawler->filter('#puzzlePickerFilters input[name="source"][value="mine"][checked]'));
    }

    public function testEmptyStateWhenNothingMatches(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Everything on PLAYER_REGULAR's shelf is solved
        $crawler = $browser->request('GET', '/en/what-to-solve-next?solved=never');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertCount(0, $crawler->filter('.puzzle-picker-card'));
        self::assertStringContainsString('Nothing matches these filters', $content);
        self::assertCount(1, $crawler->filter('.card a[href="/en/what-to-solve-next"]:contains("Reset filters")'));
        self::assertCount(1, $crawler->filter('.card a[href="/en/what-to-solve-next?source=any"]:contains("Pick from all puzzles")'));
    }

    public function testBorrowedPuzzlesAreTheShelfOfAPlayerWithoutCollectionItems(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/what-to-solve-next');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        // Two borrowed puzzles are the whole shelf: 2 cards, "1 of 2", "Show 1 more"
        self::assertCount(2, $crawler->filter('.puzzle-picker-card'));
        self::assertStringContainsString('of 2 matching puzzles', $content);
        self::assertStringContainsString('Borrowed', $content);
        self::assertCount(1, $crawler->filter('button:contains("Show 1 more")'));
    }

    public function testGuestPersonalFiltersAreIgnored(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/what-to-solve-next?source=mine&solved=never&lent=1');

        $this->assertResponseIsSuccessful();
        self::assertGreaterThan(1, $crawler->filter('.puzzle-picker-card')->count(), 'A guest still gets the demo from all puzzles');
        self::assertCount(0, $crawler->filter('.puzzle-picker-filter-bar a[title="Remove this filter"]'), 'No personal chips for guests');
    }

    public function testEntryPointsLinkToThePicker(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle');
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('h1 + a[href="/en/what-to-solve-next"], .d-flex a[href="/en/what-to-solve-next"]'), 'Small button next to the H1 of the puzzle overview');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-library/' . PlayerFixture::PLAYER_REGULAR);
        $this->assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('a[href="/en/what-to-solve-next"]'), 'Profile dropdown item + library header button on the own library');
        self::assertCount(1, $crawler->filter('.dropdown-menu a[href="/en/what-to-solve-next"]:contains("What next?")'));

        $crawler = $browser->request('GET', '/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE);
        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/en/what-to-solve-next"]'), 'Only the dropdown item on somebody else\'s library');
    }

    public function testStaticSitemapContainsThePickerInEveryLocale(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-static.xml');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        self::assertStringContainsString('/en/what-to-solve-next</loc>', $content);
        self::assertStringContainsString('/co-skladat-dal</loc>', $content);
        self::assertStringContainsString('/de/welches-puzzle-als-naechstes</loc>', $content);
    }
}
