<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther;

use Facebook\WebDriver\WebDriverDimension;

/**
 * The site header is `position: sticky` and every viewport-fixed overlay follows
 * the measured `--header-height` (header_height_controller.js). It used to be
 * `position: fixed` with `main` reserving a hardcoded 97/101px for it, so any
 * header taller than the guess - the Spanish navbar wraps, the sign-in migration
 * strip once sat above the topbar - simply covered the page heading.
 *
 * None of that can be reasoned about from the stylesheet, so this measures the
 * real boxes in a real browser.
 */
final class StickyHeaderTest extends AbstractPantherTestCase
{
    public function testPageContentAlwaysClearsTheHeader(): void
    {
        $client = self::createBrowserClient();

        // German is the longest copy, Spanish has the widest navbar - both used to
        // be the cases that broke
        foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
            $client->manage()->window()->setSize(new WebDriverDimension($width, $height));

            foreach (['/en/puzzle', '/de/puzzle', '/es/puzzles'] as $path) {
                $client->request('GET', $path);

                $geometry = $client->executeScript(<<<'JS'
                    var header = document.querySelector('header');
                    var heading = document.querySelector('main h1');
                    var headerRect = header.getBoundingClientRect();
                    return {
                        headerPosition: window.getComputedStyle(header).position,
                        headerHeight: Math.round(headerRect.height),
                        headerTop: Math.round(headerRect.top),
                        headingClear: Math.round(heading.getBoundingClientRect().top - headerRect.bottom),
                        cssVariable: parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--header-height')),
                        searchTop: parseFloat(getComputedStyle(document.querySelector('.global-search')).top)
                    };
                JS);

                self::assertIsArray($geometry);
                $message = sprintf('%s at %dx%d: %s', $path, $width, $height, json_encode($geometry));

                // Sticky, not fixed: the header takes its own space, so no page needs
                // to know how tall it happens to be
                self::assertSame('sticky', $geometry['headerPosition'], $message);
                self::assertSame(0, $geometry['headerTop'], $message);

                // Nothing of the page hides underneath the header
                self::assertGreaterThanOrEqual(0, $geometry['headingClear'], $message);

                // Overlays that cannot be in the flow track the measured height
                self::assertEqualsWithDelta($geometry['headerHeight'], $geometry['cssVariable'], 1.0, $message);
                self::assertGreaterThanOrEqual($geometry['headerHeight'], $geometry['searchTop'], $message);
            }
        }
    }

    public function testHeaderStaysStuckAllTheWayDownAndTheFooterStaysAtTheBottom(): void
    {
        $client = self::createBrowserClient();
        $client->manage()->window()->setSize(new WebDriverDimension(1440, 900));

        // A sticky element only sticks inside its containing block, and `html, body
        // { height: 100% }` made that exactly one viewport - the header came unstuck
        // after roughly one screen of scrolling. body must be free to grow.
        $client->request('GET', '/en/sign-in-is-moving');

        $longPage = $client->executeScript(<<<'JS'
            var header = document.querySelector('header');
            var maxScroll = document.documentElement.scrollHeight - window.innerHeight;
            var tops = [];
            [0.25, 0.5, 0.75, 1].forEach(function (fraction) {
                window.scrollTo(0, Math.round(maxScroll * fraction));
                tops.push(Math.round(header.getBoundingClientRect().top));
            });
            window.scrollTo(0, 0);
            return { scrollable: maxScroll, headerTops: tops };
        JS);

        self::assertIsArray($longPage);
        self::assertGreaterThan(1000, $longPage['scrollable'], 'the page must be long enough to prove the point');

        foreach ($longPage['headerTops'] as $top) {
            self::assertSame(0, $top, json_encode($longPage));
        }

        // The `height: 100%` being replaced was there to hold the footer down on
        // short pages - the flex column does that, but prove it
        $client->request('GET', '/en/contact');

        $shortPage = $client->executeScript(<<<'JS'
            var footer = document.querySelector('footer');
            return {
                footerBottom: Math.round(footer.getBoundingClientRect().bottom),
                viewportHeight: window.innerHeight
            };
        JS);

        self::assertIsArray($shortPage);
        self::assertGreaterThanOrEqual($shortPage['viewportHeight'], $shortPage['footerBottom'], json_encode($shortPage));
    }
}
