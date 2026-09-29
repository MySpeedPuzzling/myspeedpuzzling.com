<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Popular searches" in the footer (guests only) links real pages - brand × pieces pages, brand hubs, the
 * brand directory, a pieces hub, WJPC events and guides - never ?brand= / ?tag= / ?pieces= filter URLs,
 * which crawlers see as an empty shell canonicalised to /puzzle (docs/features/seo/research-2026-09.md §4.4).
 */
final class FooterPopularSearchesTest extends WebTestCase
{
    public function testPopularSearchesLinkRealPages(): void
    {
        $browser = self::createClient();
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $links = $crawler->filter('footer ul.footer-popular-searches a');

        self::assertSame([
            '/en/puzzle/brand/ravensburger/500-pieces',
            '/en/puzzle/brand/ravensburger/1000-pieces',
            '/en/puzzle/brand/trefl/500-pieces',
            '/en/puzzle/brand/clementoni/500-pieces',
            '/en/puzzle/brand/buffalo-games/500-pieces',
            '/en/puzzle/brand/cobble-hill',
            '/en/puzzle/brand/educa',
            '/en/puzzle/brand/galison',
            '/en/puzzle/brand/masterpieces',
            '/en/puzzle/brands',
            '/en/puzzle/750-pieces',
            '/en/events/world-jigsaw-puzzle-championship-2026',
            '/en/events/world-jigsaw-puzzle-championship-2025',
            '/en/events/world-jigsaw-puzzle-championship-2024',
            '/en/events/world-jigsaw-puzzle-championship-2023',
            '/en/events/world-jigsaw-puzzle-championship-2022',
            $urlGenerator->generate('ladder', ['_locale' => 'en']),
            $urlGenerator->generate('players', ['_locale' => 'en']),
            $urlGenerator->generate('recent_activity', ['_locale' => 'en']),
            '/en/guides/how-long-does-a-1000-piece-puzzle-take',
            '/en/guides/average-puzzle-time-by-piece-count',
        ], $links->extract(['href']));

        $labels = $links->each(static fn (Crawler $link): string => $link->text());
        foreach (['Ravensburger 500-Piece Puzzles', 'Cobble Hill Puzzles', 'All puzzle brands A–Z', '750 Piece Puzzles', 'WJPC 2026', 'How long does a 1000-piece puzzle take?', 'Average puzzle time by piece count'] as $label) {
            self::assertContains($label, $labels);
        }
        self::assertStringNotContainsString('BOTYP', $crawler->filter('footer')->text());

        // Nothing on the page links a filter URL any more
        foreach ($crawler->filter('a[href]')->extract(['href']) as $href) {
            self::assertDoesNotMatchRegularExpression('/[?&](brand|tag|pieces)=/', $href);
        }
    }

    public function testThePageBeingViewedIsLeftOut(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/brand/ravensburger/500-pieces');

        $this->assertResponseIsSuccessful();
        $hrefs = $crawler->filter('footer ul.footer-popular-searches a')->extract(['href']);
        self::assertNotContains('/en/puzzle/brand/ravensburger/500-pieces', $hrefs);
        self::assertContains('/en/puzzle/brand/ravensburger/1000-pieces', $hrefs);
    }

    public function testSignedInPlayersDoNotGetThem(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('ul.footer-popular-searches'));
    }

    #[DataProvider('locales')]
    public function testLabelsAreTranslated(string $locale): void
    {
        $browser = self::createClient();
        $url = self::getContainer()->get(UrlGeneratorInterface::class)->generate('puzzle_detail', [
            'puzzleId' => PuzzleFixture::PUZZLE_500_01,
            '_locale' => $locale,
        ]);

        $crawler = $browser->request('GET', $url);

        $this->assertResponseIsSuccessful();
        $links = $crawler->filter('footer ul.footer-popular-searches a');
        self::assertCount(21, $links);

        foreach ($links->each(static fn (Crawler $link): string => $link->text()) as $label) {
            self::assertStringNotContainsString('%', $label);
            self::assertDoesNotMatchRegularExpression('/^(footer|catalogue|brand_directory|brand_pieces_hub)\./', $label);
        }

        // The guides are English-only, in every language
        self::assertSame(['en', 'en'], $crawler->filter('footer ul.footer-popular-searches a[href^="/en/guides/"]')->extract(['hreflang']));
    }

    public function testCzechLabels(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $labels = $crawler->filter('footer ul.footer-popular-searches a')->each(static fn (Crawler $link): string => $link->text());
        self::assertContains('Puzzle Ravensburger 500 dílků', $labels);
        self::assertContains('Puzzle Cobble Hill', $labels);
        self::assertContains('Puzzle 750 dílků', $labels);
        self::assertContains('Jak dlouho se skládá puzzle s 1000 dílky? (v angličtině)', $labels);
        self::assertContains('/puzzle/znacka/ravensburger/500-dilku', $crawler->filter('footer ul.footer-popular-searches a')->extract(['href']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (['cs', 'en', 'es', 'ja', 'fr', 'de'] as $locale) {
            yield $locale => [$locale];
        }
    }
}
