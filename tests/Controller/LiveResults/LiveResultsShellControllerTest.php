<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\LiveResults;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The live entry page's offline copy (LiveResultsShellController, competition toolkit C3 - C §1.3, R §2.7): the same
 * markup as the live page, for everybody alike - the service worker keeps it, so it must hold nothing of the person who
 * fetched it, never set a cookie, carry no `msp-rendered-at` timestamp and never the native apps' Turbo.
 */
final class LiveResultsShellControllerTest extends WebTestCase
{
    private const string SHELL = '/en/live-results-shell';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function localizedShells(): iterable
    {
        yield 'cs' => ['/zadavani-vysledku-shell', 'cs'];
        yield 'en' => ['/en/live-results-shell', 'en'];
        yield 'de' => ['/de/live-results-shell', 'de'];
        yield 'es' => ['/es/live-results-shell', 'es'];
        yield 'fr' => ['/fr/live-results-shell', 'fr'];
        yield 'ja' => ['/ja/live-results-shell', 'ja'];
    }

    #[DataProvider('localizedShells')]
    public function testAnybodyGetsTheShellOfEachLocaleWithoutACookie(string $path, string $locale): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        $response = $browser->getResponse();
        self::assertSame([], $response->headers->getCookies());
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        self::assertSame($locale, $crawler->filter('html')->attr('lang'));

        $root = $crawler->filter('[data-controller="live-results"]');
        self::assertCount(1, $root, 'the live page\'s markup');
        self::assertSame('true', $root->attr('data-live-results-shell-value'));
        self::assertSame('', $root->attr('data-live-results-round-id-value'));
        self::assertSame($locale, $root->attr('data-live-results-locale-value'));
    }

    public function testASignedInOrganiserGetsTheVeryShellAnybodyGets(): void
    {
        $browser = self::createClient();
        $anonymous = $this->shellBody($browser);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        // The organiser's live page first: the device cookie, the session and the remember-me cookie are all there
        $browser->request('GET', '/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertResponseIsSuccessful();

        $signedIn = $this->shellBody($browser);

        self::assertSame([], $browser->getResponse()->headers->getCookies(), 'no Set-Cookie - no session, no remember-me renewal, no device cookie');
        self::assertSame($anonymous, $signedIn, 'byte for byte what a signed-out visitor gets');

        foreach (
            [
            PlayerFixture::PLAYER_WITH_STRIPE,
            OfficialResultsFixture::ROUND_GROUP_A,
            OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'mercure',
            'logout',
            ] as $personal
        ) {
            self::assertStringNotContainsStringIgnoringCase($personal, $signedIn);
        }
    }

    public function testTheShellCarriesNoRenderedAtTimestamp(): void
    {
        $browser = self::createClient();
        $body = $this->shellBody($browser);

        self::assertStringNotContainsString('msp-rendered-at" content', $body);
        self::assertStringNotContainsString('<meta name="msp-rendered-at"', $body);
        // The stale-document script itself stays - without the meta it does nothing
        self::assertStringContainsString('/-/stale-document', $body);
    }

    /**
     * The service worker stores every file of this list before the copy itself (toolkit C6) - the very files the copy
     * loads, never one more page's.
     */
    public function testTheShellListsTheFilesItNeeds(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', self::SHELL);
        self::assertResponseIsSuccessful();

        $list = $crawler->filter('script#msp-live-shell-assets');
        self::assertCount(1, $list);
        self::assertSame('application/json', $list->attr('type'));

        /** @var list<string> $assets */
        $assets = json_decode($list->text(), true, flags: JSON_THROW_ON_ERROR);
        $body = (string) $browser->getResponse()->getContent();

        $scripts = $crawler->filter('head script[src]')->each(static fn ($node): string => (string) $node->attr('src'));
        $styles = $crawler->filter('head link[rel="stylesheet"]')->each(static fn ($node): string => (string) $node->attr('href'));
        self::assertNotSame([], $scripts);
        self::assertNotSame([], $styles);

        foreach ([...$scripts, ...$styles] as $file) {
            self::assertContains($file, $assets, 'every script and stylesheet the copy loads is listed');
        }

        self::assertContains('/fonts/rubik/rubik-latin.woff2', $assets);

        foreach ($assets as $asset) {
            self::assertMatchesRegularExpression('#^/(build|fonts)/#', $asset);
            self::assertStringContainsString($asset, str_replace('\\/', '/', $body));
        }
    }

    public function testTheLivePageItselfListsNoFiles(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#msp-live-shell-assets'));
    }

    public function testTheShellNeverLoadsTheNativeAppsTurbo(): void
    {
        $browser = self::createClient();
        $browser->request('GET', self::SHELL, server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) MySpeedPuzzling iOS; Turbo Native iOS']);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/build/live_entry_native', (string) $browser->getResponse()->getContent());
        self::assertStringContainsString('/build/live_entry', (string) $browser->getResponse()->getContent());
    }

    private function shellBody(KernelBrowser $browser): string
    {
        $browser->request('GET', self::SHELL);
        self::assertResponseIsSuccessful();

        return (string) $browser->getResponse()->getContent();
    }
}
