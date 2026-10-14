<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The chrome's weight (docs/features/navigation README §4.12 "Weight", implementation plan risk 8, decisions 34 and 82):
 * the bars, the Menu and the rail/column may grow /en/faq's HTML by at most 30 KB for a member and 20 KB for a guest
 * over the P0-02 baseline - measured as P0-02 measured it (docs/features/navigation/delivery/baseline.md §2): the fixture
 * DB, the test env, the second request, the uncompressed bytes of the whole page.
 *
 * The measure is the whole page, so a change to the FAQ itself moves it too: the failure message names the parts.
 * What keeps the chrome lean - keep it so: the chrome templates lose their indentation at compile time
 * (SpeedPuzzling\Web\Twig\ChromeWhitespace), the inline head scripts are minified builds (InlineScriptsBuildTest), the
 * sprite carries only the symbols the page uses (below), the page search's list is never inlined (MENU-03) and the
 * Inbox strings ride on the page's first Inbox link only.
 */
final class ChromeWeightBudgetTest extends WebTestCase
{
    /** /en/faq in bytes at P0-02 (524a3305, docs/features/navigation/delivery/baseline.md §2) */
    private const array BASELINE = [
        NavigationViewer::GUEST => 87_119,
        NavigationViewer::MEMBER => 87_854,
    ];

    /** README §4.12: ≤ 20 KB for a guest, ≤ 30 KB for a member */
    private const array BUDGET = [
        NavigationViewer::GUEST => 20 * 1024,
        NavigationViewer::MEMBER => 30 * 1024,
    ];

    /** viewer, page: a start page and one that is not, for guests and signed-in players */
    private const array SPRITE_PAGES = [
        [NavigationViewer::GUEST, '/en/faq'],
        [NavigationViewer::GUEST, '/'],
        [NavigationViewer::MEMBER, '/en/faq'],
        [NavigationViewer::MEMBER, '/en/hub'],
        [NavigationViewer::ADMIN, '/en/faq'],
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function viewers(): iterable
    {
        yield 'guest' => [NavigationViewer::GUEST];
        yield 'member' => [NavigationViewer::MEMBER];
    }

    #[DataProvider('viewers')]
    public function testTheChromeStaysWithinItsWeight(string $viewer): void
    {
        $html = $this->faq($viewer);
        $growth = strlen($html) - self::BASELINE[$viewer];

        $parts = [];

        foreach (
            [
            'head' => ['<head>', '</head>'],
            'header' => ['<header class="site-header', '</header>'],
            'sprite' => ['<svg class="nav-sprite"', '</svg>'],
            'tab bar' => ['<nav id="bottom-nav"', '</nav>'],
            'Menu' => ['<nav id="site-menu"', '</nav>'],
            'main' => ['<main id="main-content"', '</main>'],
            'footer' => ['<footer class="site-footer', '</footer>'],
            'Menu shell' => ['<dialog id="menu-layer"', '</dialog>'],
            ] as $part => [$start, $end]
        ) {
            $from = strpos($html, $start);
            $to = $from === false ? false : strpos($html, $end, $from);
            $parts[] = $part . ' ' . ($from === false || $to === false ? '-' : $to + strlen($end) - $from);
        }

        self::assertLessThanOrEqual(
            self::BUDGET[$viewer],
            $growth,
            sprintf(
                '/en/faq for a %s is %d B: %+d B over the P0-02 baseline, the budget is %d B (%s)',
                $viewer,
                strlen($html),
                $growth,
                self::BUDGET[$viewer],
                implode(', ', $parts),
            ),
        );
    }

    /**
     * The sprite (templates/navigation/_icons.html.twig) holds exactly the symbols the page's bars use: every
     * <use href="#nav-i-…"> finds its symbol, and no symbol is carried for nothing - a guest's person icon, a
     * signed-in player's Inbox and chevron, the back arrow on a page that is not a start page.
     */
    public function testTheSpriteCarriesTheSymbolsThePageUses(): void
    {
        foreach (self::SPRITE_PAGES as [$viewer, $url]) {
            self::ensureKernelShutdown();
            $browser = self::createClient();
            NavigationViewer::signIn($browser, $viewer);
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful($viewer . ' ' . $url);

            $symbols = $crawler->filter('svg.nav-sprite symbol')->each(static fn ($symbol): string => (string) $symbol->attr('id'));
            $used = array_unique($crawler->filter('use')->each(static fn ($use): string => ltrim((string) $use->attr('href'), '#')));
            $used = array_values(array_filter($used, static fn (string $id): bool => str_starts_with($id, 'nav-i-')));
            sort($symbols);
            sort($used);

            self::assertNotSame([], $used, $viewer . ' ' . $url);
            self::assertSame($used, $symbols, $viewer . ' ' . $url . ': the sprite holds exactly the symbols the page uses');
        }
    }

    private function faq(string $viewer): string
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        $browser->disableReboot();
        NavigationViewer::signIn($browser, $viewer);

        // The second request, as P0-02 measured: the first warms the caches and claims an announcement modal
        $browser->request('GET', '/en/faq');
        $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        return (string) $browser->getResponse()->getContent();
    }
}
