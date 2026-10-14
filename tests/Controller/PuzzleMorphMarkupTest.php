<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use SplFileInfo;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The thumbnail → puzzle page morph's markup (docs/features/navigation README §4.13 G15, requirement GEST-17): the
 * catalogue, library and search cards carry their puzzle page's path (`data-morph-source`, assets/view_transition_gate.js
 * compares it with the tapped link), and the puzzle page's box image is the one `img.puzzle-hero` - never the compact
 * bar's thumbnail. The morph itself is checks/GEST-17.py.
 */
final class PuzzleMorphMarkupTest extends WebTestCase
{
    public function testThePuzzlePageHasOneHeroImage(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);
        self::assertResponseIsSuccessful();

        $hero = $crawler->filter('img.puzzle-hero');
        self::assertCount(1, $hero);
        self::assertCount(1, $crawler->filter('.puzzle-head-image img.puzzle-hero'));
        self::assertCount(0, $crawler->filter('.compact-bar-thumb.puzzle-hero, .compact-bar img.puzzle-hero'));
    }

    public function testLibraryCardsCarryTheirPuzzlePage(): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, NavigationViewer::MEMBER);
        $crawler = $browser->request('GET', '/en/wish-list/' . NavigationViewer::playerId(NavigationViewer::MEMBER));
        self::assertResponseIsSuccessful();

        $cards = $crawler->filter('[id^="library-wishlist-"][data-morph-source]');
        self::assertGreaterThan(0, $cards->count());

        $cards->each(static function (Crawler $card): void {
            $source = (string) $card->attr('data-morph-source');
            self::assertMatchesRegularExpression('~^/en/puzzle/[0-9a-f-]{36}$~', $source);
            // The thumbnail's link and the name's link lead there: the tap's path is compared with it
            self::assertGreaterThanOrEqual(1, $card->filter('a[href="' . $source . '"] img')->count());
        });
    }

    /** Rendered only with results (the catalogue's list, the search): the partials themselves */
    public function testCatalogueAndSearchCardsCarryTheirPuzzlePage(): void
    {
        $item = (string) file_get_contents(__DIR__ . '/../../templates/_puzzle_item.html.twig');
        self::assertMatchesRegularExpression('~<div\s+id="puzzle-list-item-\{\{ puzzle\.puzzleId \}\}"\s+class="[^"]*"\s+data-morph-source="\{\{ path\(\'puzzle_detail\', \{puzzleId: puzzle\.puzzleId\}\) \}\}"~', $item);

        $search = (string) file_get_contents(__DIR__ . '/../../templates/components/GlobalSearch.html.twig');
        self::assertStringContainsString('<div class="d-flex py-2" data-morph-source="{{ path(\'puzzle_detail\', {puzzleId: puzzle.puzzleId}) }}">', $search);

        // Never on table rows (README §4.13 G15): the three card partials only
        $found = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../templates', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), 'data-morph-source=')) {
                $found[] = substr($file->getPathname(), strlen(__DIR__ . '/../../templates/'));
            }
        }

        sort($found);
        self::assertSame(['_puzzle_item.html.twig', '_puzzle_library_item.html.twig', 'components/GlobalSearch.html.twig'], $found);
    }
}
