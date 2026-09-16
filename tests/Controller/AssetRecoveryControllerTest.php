<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AssetRecoveryControllerTest extends WebTestCase
{
    public function testPageIsPubliclyReachable(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/reset');

        $this->assertResponseIsSuccessful();
    }

    public function testPageIsNotIndexable(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/reset');

        self::assertStringContainsString(
            '<meta name="robots" content="noindex, nofollow">',
            (string) $browser->getResponse()->getContent(),
        );
    }

    /**
     * The whole point of this page is to work on a browser whose /build assets
     * are broken, so it must never depend on one. entrypoints.json and
     * manifest.json are fetched by its script to know what to refresh — they are
     * not page assets, and a failure to read them leaves the page intact.
     */
    public function testPageLoadsNoBuildAssets(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/-/reset');

        self::assertCount(
            0,
            $crawler->filter('link[href*="/build/"], script[src*="/build/"]'),
            'The recovery page must not depend on the assets it exists to repair.',
        );

        // Guard against the assertion above passing because we fetched the wrong
        // page, or an empty one: the repair script itself has to be present.
        self::assertStringContainsString(
            'msp-asset-heal',
            (string) $browser->getResponse()->getContent(),
        );
    }
}
