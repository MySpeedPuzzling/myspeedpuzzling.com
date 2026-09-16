<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the one service-worker invariant that has silently broken twice:
 * HTML must never be answered from a cache.
 *
 * public/service-worker.js used to decide "is this an image?" from the Accept
 * header. Chrome, Edge and Firefox advertise image/avif and image/webp in the
 * Accept header of every top-level navigation, so every page load in those
 * browsers matched and was served stale-while-revalidate from the shared image
 * cache - the installed PWA relaunched into whichever copy of the homepage was
 * cached first (usually the signed-out one), and personalized HTML was stored
 * under a URL-only key and replayed to the next person to open the app. Safari
 * never advertises image types on navigations, which is why only Chromium and
 * Firefox users ever saw it. Commit 98532b87 ("Service worker - disable
 * caching") believed it had already fixed this and had only edited the comment.
 *
 * There is no JS test runner in this project, so these assertions are made
 * against the source text. They are deliberately about the two design decisions
 * that matter and not about formatting.
 */
final class ServiceWorkerRoutingTest extends TestCase
{
    private const string SERVICE_WORKER = __DIR__ . '/../public/service-worker.js';

    public function testNavigationsAreRoutedBeforeImages(): void
    {
        $source = $this->serviceWorkerSource();

        $navigation = strpos($source, 'networkFirstNavigation(request)');
        $image = strpos($source, 'staleWhileRevalidate(request, IMAGES_CACHE)');

        self::assertIsInt($navigation, 'The navigation strategy must still be dispatched in the fetch router');
        self::assertIsInt($image, 'The image strategy must still be dispatched in the fetch router');
        self::assertLessThan(
            $image,
            $navigation,
            'Navigations must be routed before images, or an over-broad image test can swallow page loads',
        );
    }

    public function testImageDetectionNeverConsultsTheAcceptHeader(): void
    {
        $source = $this->serviceWorkerSource();

        $start = strpos($source, 'function isImageRequest(');
        self::assertIsInt($start, 'isImageRequest() must still exist');

        $end = strpos($source, "\n}", $start);
        self::assertIsInt($end);

        $body = substr($source, $start, $end - $start);

        self::assertStringNotContainsStringIgnoringCase(
            'accept',
            $body,
            "isImageRequest() must classify on request.destination. A navigation's Accept header "
            . 'advertises image/avif and image/webp, so an Accept-based test matches documents too.',
        );
        self::assertStringContainsString(
            "request.destination === 'image'",
            $body,
            'isImageRequest() must classify on the browser-provided request destination',
        );
    }

    /**
     * The activate handler drops every cache whose name lacks the current
     * version, so bumping the version is the only way to evict HTML that older
     * service workers already wrote to a visitor's device.
     */
    public function testCacheVersionIsPastTheReleaseThatCachedHtml(): void
    {
        $source = $this->serviceWorkerSource();

        self::assertSame(
            1,
            preg_match("/const CACHE_VERSION = 'v(\\d+)';/", $source, $matches),
            'CACHE_VERSION must stay a single quoted vN constant',
        );
        self::assertGreaterThanOrEqual(
            7,
            (int) $matches[1],
            'v6 and earlier cached HTML in the image cache - the version must stay past it so those caches are evicted',
        );
    }

    private function serviceWorkerSource(): string
    {
        $source = file_get_contents(self::SERVICE_WORKER);

        self::assertIsString($source, 'public/service-worker.js must be readable');

        return $source;
    }
}
