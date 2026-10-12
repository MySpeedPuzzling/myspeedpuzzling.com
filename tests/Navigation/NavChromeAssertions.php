<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\Assert;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The web chrome - what the Hotwire Native app never gets (docs/features/navigation README §4.14 (b), plan §3.5 item 1).
 * Base renders the chrome blocks (header, nav_chrome, footer) only under is_web(); every bar, rail, column and Menu
 * item adds its root here in its own commit.
 */
final class NavChromeAssertions
{
    /**
     * `X:not(main X)` = X outside <main>: the `menu` page renders the Menu partial as its content (also in the app) and
     * asserts `main #site-menu` itself (implementation plan decision 103, review F26)
     */
    public const array WEB_CHROME_SELECTORS = [
        // The top bar (NAV-03: header.site-header; content pages have <header> elements of their own) and the old header's
        // rows under it until NAV-06
        'body > header',
        'body > .legacy-chrome',
        'footer.site-footer',
        '#site-menu:not(main #site-menu)',
        // The Menu shell of the phone panel (MENU-02b, block nav_layers)
        '#menu-layer',
        // The phone bottom bar and the icon sprite it shares with the other bars (NAV-01, block nav_chrome)
        '#bottom-nav',
        '.nav-sprite',
    ];

    public static function assertNoWebChrome(Crawler $crawler, string $message = ''): void
    {
        foreach (self::WEB_CHROME_SELECTORS as $selector) {
            Assert::assertSame(0, self::count($crawler, $selector), trim($message . ' Web chrome rendered: ' . $selector));
        }
    }

    /**
     * The web page renders the chrome that exists today (the selectors of later items count from their own commits)
     */
    public static function assertWebHeaderAndFooter(Crawler $crawler, string $message = ''): void
    {
        Assert::assertSame(1, self::count($crawler, 'body > header'), trim($message . ' No site header'));
        Assert::assertSame(1, self::count($crawler, 'footer.site-footer'), trim($message . ' No site footer'));
        Assert::assertSame(1, self::count($crawler, 'body > #bottom-nav'), trim($message . ' No bottom bar'));
    }

    public static function count(Crawler $crawler, string $selector): int
    {
        // CssSelector only takes a simple selector inside :not() - `X:not(main X)` is counted as X minus `main X`
        if (preg_match('~^(?<x>[^:\s]+):not\(main \k<x>\)$~', $selector, $matches) === 1) {
            return $crawler->filter($matches['x'])->count() - $crawler->filter('main ' . $matches['x'])->count();
        }

        return $crawler->filter($selector)->count();
    }
}
