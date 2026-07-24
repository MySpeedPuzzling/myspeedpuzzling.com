<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther;

use Facebook\WebDriver\WebDriverDimension;

/**
 * The sign-in migration notice (issue #147) is a strip at the top of the site
 * header, above the topbar. It changes the header's height - one line in English
 * on a desktop, two on a phone - and the header used to be `position: fixed`
 * with `main` reserving a hardcoded 97/101px for it, so a taller header simply
 * covered the page heading. The header is now `position: sticky` and every
 * viewport-fixed overlay follows `--header-height`.
 *
 * None of that can be reasoned about from the stylesheet, so this measures the
 * real boxes in a real browser.
 */
final class SignInChangesNoticeTest extends AbstractPantherTestCase
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
                    var notice = document.querySelector('header .sign-in-changes-notice');
                    var heading = document.querySelector('main h1');
                    var headerRect = header.getBoundingClientRect();
                    return {
                        headerPosition: window.getComputedStyle(header).position,
                        headerHeight: Math.round(headerRect.height),
                        noticeTop: Math.round(notice.getBoundingClientRect().top),
                        headerTop: Math.round(headerRect.top),
                        noticeHeight: Math.round(notice.getBoundingClientRect().height),
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

                // The strip is the topmost thing on the page, above the topbar
                self::assertSame($geometry['headerTop'], $geometry['noticeTop'], $message);
                self::assertGreaterThan(0, $geometry['noticeHeight'], $message);

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

    public function testDismissingReleasesTheSpaceAndSurvivesTheNextPageLoad(): void
    {
        $client = self::createBrowserClient();
        $client->manage()->window()->setSize(new WebDriverDimension(1440, 900));
        $client->request('GET', '/en/puzzle');

        $withNotice = $client->executeScript("return Math.round(document.querySelector('header').getBoundingClientRect().height);");

        $client->executeScript("document.querySelector('.sign-in-changes-notice-close').click();");
        $client->waitForInvisibility('.sign-in-changes-notice', 10);

        // A second visit must not flash the notice in and shift the page: the inline
        // script in the head hides it before the first paint
        $client->request('GET', '/en/puzzle');

        $afterDismissal = $client->executeScript(<<<'JS'
            var notice = document.querySelector('.sign-in-changes-notice');
            return {
                hidden: notice === null || window.getComputedStyle(notice).display === 'none',
                headerHeight: Math.round(document.querySelector('header').getBoundingClientRect().height)
            };
        JS);

        self::assertIsArray($afterDismissal);
        self::assertTrue($afterDismissal['hidden']);
        self::assertLessThan($withNotice, $afterDismissal['headerHeight']);

        // Leave the browser as we found it for the other test in this class
        $client->executeScript("window.localStorage.removeItem('sign-in-changes-notice-dismissed');");
    }
}
