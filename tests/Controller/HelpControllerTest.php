<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\Navigation\NavChromeAssertions;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Help & feedback (docs/features/navigation README §4.5, implementation plan §7, HELP-01a): one public URL, two bodies -
 * the full page (no JS, a new tab, the native app; guests shared-cacheable) and, for a `Turbo-Frame: modal-frame`
 * request, only the sheet's frame (never shared-cached). Both say so in `Vary: Turbo-Frame`.
 */
final class HelpControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string FRAME_HEADERS = 'modal-frame';

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => [null];
        yield 'member' => [PlayerFixture::PLAYER_WITH_STRIPE];
    }

    #[DataProvider('provideViewers')]
    public function testTheFullPage(null|string $playerId): void
    {
        $browser = $this->browser($playerId);

        $crawler = $browser->request('GET', '/en/help');

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertVaryTurboFrame($browser);
        NavChromeAssertions::assertWebHeaderAndFooter($crawler);
        self::assertSame('Help & feedback', trim($crawler->filter('main h1')->text()));
        $this->assertTheRows($crawler->filter('main [data-help-sheet]'));

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');

        if ($playerId === null) {
            // Guest HTML stays shared-cacheable: no CSRF token, no session, no per-visitor content
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), $cacheControl);
            // Shared-cacheable guest HTML: no cookie, no CSRF token in the page (the footer's newsletter form keeps its stateless one)
            self::assertSame([], $browser->getResponse()->headers->getCookies());
            self::assertCount(0, $crawler->filter('main [name="_token"], main [name$="[_token]"]'));
        } else {
            self::assertStringNotContainsString('public', $cacheControl);
        }
    }

    #[DataProvider('provideViewers')]
    public function testTheFrameIsOnlyTheSheet(null|string $playerId): void
    {
        $browser = $this->browser($playerId);

        $crawler = $browser->request('GET', '/en/help?url=' . rawurlencode('/en/faq'), server: ['HTTP_TURBO_FRAME' => self::FRAME_HEADERS]);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringStartsWith('<turbo-frame id="modal-frame">', trim($content));
        self::assertStringNotContainsString('<html', $content);
        self::assertCount(0, $crawler->filter('header'));
        self::assertCount(0, $crawler->filter('#site-menu'));
        self::assertCount(0, $crawler->filter('footer'));

        $sheet = $crawler->filter('turbo-frame#modal-frame > [data-help-sheet]');
        self::assertCount(1, $sheet);
        // A sheet on phones whose Back closes it (dynamic_modal_controller.js)
        self::assertSame('', $sheet->attr('data-modal-size'));
        self::assertNotNull($sheet->attr('data-modal-sheet'));
        self::assertNotNull($sheet->attr('data-modal-history'));
        self::assertSame('Help & feedback', trim($sheet->filter('.modal-title')->text()));
        self::assertSame('Close', $sheet->filter('.btn-close')->attr('aria-label'));
        $this->assertTheRows($sheet);

        // Never shared-cached: the frame shares its URL with the page
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertVaryTurboFrame($browser);
    }

    /**
     * The frame is the "empty signed-in endpoint" of the endpoint budgets (implementation plan §3.7): the session's user
     * refresh and the viewer's profile row, nothing of its own.
     */
    public function testTheFrameCostsOnlyTheViewersOwnRows(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_STRIPE);
        $url = '/en/help?url=' . rawurlencode('/en/faq');

        $browser->request('GET', $url, server: ['HTTP_TURBO_FRAME' => self::FRAME_HEADERS]);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url, server: ['HTTP_TURBO_FRAME' => self::FRAME_HEADERS]);
        self::assertResponseIsSuccessful();

        self::assertSame(2, $this->queryCount($browser), "The help frame ran:\n" . implode("\n", $this->executedSql($browser)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLocalePaths(): iterable
    {
        yield 'cs' => ['/napoveda', 'Nápověda a zpětná vazba'];
        yield 'de' => ['/de/hilfe', 'Hilfe & Feedback'];
        yield 'es' => ['/es/ayuda', 'Ayuda y comentarios'];
        yield 'fr' => ['/fr/aide', 'Aide et commentaires'];
        yield 'ja' => ['/ja/ヘルプ', 'ヘルプとフィードバック'];
    }

    #[DataProvider('provideLocalePaths')]
    public function testEveryLocaleHasItsPage(string $path, string $title): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame($title, trim($crawler->filter('main h1')->text()));
        self::assertCount(2, $crawler->filter('main [data-help-sheet] a[data-nav^="help/help/"]'));
    }

    /**
     * ?url= is untrusted: whatever it says never reaches a link, the page or the frame (implementation plan §7)
     */
    public function testTheUrlParameterIsNeverEchoed(): void
    {
        $browser = self::createClient();
        $hostile = [
            'https://evil.example/phish',
            '//evil.example/phish',
            '/\\evil.example/phish',
            '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '"><script>alert(1)</script>',
            'javascript:alert(1)',
        ];

        foreach ($hostile as $url) {
            foreach ([[], ['HTTP_TURBO_FRAME' => self::FRAME_HEADERS]] as $server) {
                $crawler = $browser->request('GET', '/en/help?url=' . rawurlencode($url), server: $server);

                self::assertResponseIsSuccessful($url);
                self::assertStringNotContainsString('<script>alert(1)', (string) $browser->getResponse()->getContent(), $url);

                // The layout around the full page keeps the current URL in its own same-site links (language switch,
                // today's feedback link) - the sheet itself knows nothing of the parameter
                $sheet = (string) $crawler->filter('[data-help-sheet]')->outerHtml();
                self::assertStringNotContainsString('evil.example', $sheet, $url);
                self::assertStringNotContainsString('alert(1)', $sheet, $url);

                foreach ($crawler->filter('[href]') as $element) {
                    assert($element instanceof \DOMElement);
                    $href = $element->getAttribute('href');
                    self::assertDoesNotMatchRegularExpression('~^(https?:)?[/\\\\]{2}evil\.example~i', $href, $url);
                    self::assertStringStartsNotWith('javascript:', $href, $url);
                }
            }
        }
    }

    /**
     * Inside the app every layer is a screen of its own (README §4.14 (a)): the full page, without web chrome, even if
     * the frame header comes along
     */
    public function testTheNativeAppAlwaysGetsTheFullPage(): void
    {
        $browser = self::createClient();

        foreach (NativeUserAgents::native() as [$userAgent, $platform]) {
            $crawler = $browser->request('GET', '/en/help', server: ['HTTP_USER_AGENT' => $userAgent, 'HTTP_TURBO_FRAME' => self::FRAME_HEADERS]);

            self::assertResponseIsSuccessful();
            self::assertSame($platform, $crawler->filter('html')->attr('data-native-app'));
            NavChromeAssertions::assertNoWebChrome($crawler);
            self::assertCount(1, $crawler->filter('main [data-help-sheet]'));
            self::assertStringNotContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }

    private function browser(null|string $playerId): KernelBrowser
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        return $browser;
    }

    /**
     * The skeleton's rows: the registry's Help sheet links, leaving the sheet for the whole page
     */
    private function assertTheRows(Crawler $sheet): void
    {
        $rows = $sheet->filter('a[data-nav^="help/help/"]');

        self::assertSame(['help/help/feature_requests', 'help/help/faq'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-nav')));
        self::assertSame(['/en/feature-requests', '/en/faq'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('href')));
        self::assertSame(['_top', '_top'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('data-turbo-frame')));
    }

    private static function assertVaryTurboFrame(KernelBrowser $browser): void
    {
        self::assertContains('Turbo-Frame', $browser->getResponse()->getVary(), (string) $browser->getResponse()->headers->get('Vary'));
    }
}
