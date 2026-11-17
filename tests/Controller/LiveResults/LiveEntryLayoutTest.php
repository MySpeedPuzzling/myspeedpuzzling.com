<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\LiveResults;

use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The live entry page's own lean layout (templates/live_results/_layout.html.twig, competition toolkit C2): none of
 * base.html.twig's chrome - no flashes or e-mail banners above the page, no modals, global search, analytics - and no
 * Turbo or site bundle, only its own Encore entry `live_entry` with the site's stylesheet. The two inline self-heal
 * scripts are the very ones every other page carries (shared partials `_asset_self_heal.html.twig` /
 * `_stale_document_check.html.twig`, which base.html.twig renders exactly as before).
 */
final class LiveEntryLayoutTest extends WebTestCase
{
    private const string PAGE = '/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A;

    public function testThePageHasNoneOfTheSiteChrome(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('Live result entry – MySpeedPuzzling', $crawler->filter('title')->text());
        self::assertCount(1, $crawler->filter('main#main-content > [data-controller="live-results"]'), 'the page right inside <main>, nothing above it');
        self::assertCount(0, $crawler->filter('header, footer, .alert-dismissible, #dynamic-modal-container, turbo-frame, .global-search, [data-controller~="analytics"], [data-controller="mercure-hub"]'));
        self::assertNull($crawler->filter('body')->attr('data-controller'), 'none of the site\'s body controllers (pwa-lifecycle, global-search, toast …)');
        self::assertCount(0, $crawler->filter('meta[name="turbo-cache-control"]'));

        $scripts = $crawler->filter('head script[src]')->each(static fn ($script): string => (string) $script->attr('src'));
        self::assertNotEmpty($scripts);
        self::assertCount(1, array_filter($scripts, static fn (string $src): bool => str_contains($src, '/build/live_entry')), 'the live entry bundle');
        self::assertCount(0, array_filter($scripts, static fn (string $src): bool => str_contains($src, '/build/app')), 'never the site bundle');
        self::assertCount(1, $crawler->filter('head link[rel="stylesheet"][href*="/build/app"]'), 'the site\'s stylesheet');
    }

    public function testTheSelfHealScriptsAreTheOnesOfEveryOtherPage(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/hub');
        $sitePage = (string) $browser->getResponse()->getContent();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', self::PAGE);
        $livePage = (string) $browser->getResponse()->getContent();

        $assetScript = '~<script>((?:(?!</script>).)*?/-/asset-load-failure(?:(?!</script>).)*)</script>~s';
        $staleScript = '~<meta name="msp-rendered-at"[^>]*>\s*<script>(.*?)</script>~s';

        foreach (['asset-failure' => $assetScript, 'stale-document' => $staleScript] as $name => $pattern) {
            self::assertSame(1, preg_match($pattern, $sitePage, $site), $name . ' script on a site page');
            self::assertSame(1, preg_match($pattern, $livePage, $live), $name . ' script on the live entry page');
            self::assertSame($site[1], $live[1], 'The ' . $name . ' script differs between the live entry page and the site');
        }
    }
}
