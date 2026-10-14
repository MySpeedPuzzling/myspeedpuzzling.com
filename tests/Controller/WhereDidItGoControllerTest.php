<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\FormerNavigation;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * "Where did it go?" (docs/features/navigation README §8, LAUNCH-02a, LAUNCH-02): a public page in every locale, not for
 * the index, shared-cacheable for guests - every item of the old navigation (FormerNavigation) with its old name
 * linking its page and its new place, one table per old surface, a filter box that appears with JavaScript.
 */
final class WhereDidItGoControllerTest extends WebTestCase
{
    private const string PAGE = '/en/where-did-it-go';
    private const string ROWS = 'main .wdig-row';

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function provideViewers(): iterable
    {
        yield 'guest' => [null];
        yield 'member' => [PlayerFixture::PLAYER_WITH_STRIPE];
    }

    #[DataProvider('provideViewers')]
    public function testThePage(null|string $playerId): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame('Where did it go?', trim($crawler->filter('main h1')->text()));
        self::assertCount(1, $crawler->filter('main [data-where-did-it-go-mapping]'));

        // The filter box appears with JavaScript only; every row is there without it
        self::assertCount(1, $crawler->filter('main [data-controller="where-did-it-go"] [data-where-did-it-go-target="bar"][hidden] input[type="search"]#wdig-filter'));
        self::assertSame('Find an old item', $crawler->filter('main label[for="wdig-filter"]')->text());
        self::assertCount(0, $crawler->filter(self::ROWS . '[hidden]'));
        self::assertGreaterThan(50, $crawler->filter(self::ROWS)->count());

        if ($playerId === null) {
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
            // Shared-cacheable guest HTML: no cookie, no CSRF token in the page (the footer's newsletter form keeps its stateless one)
            self::assertSame([], $browser->getResponse()->headers->getCookies());
            self::assertCount(0, $crawler->filter('main [name="_token"], main [name$="[_token]"]'));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLocalePaths(): iterable
    {
        yield 'cs' => ['/kam-se-to-presunulo', 'Kam se to přesunulo?'];
        yield 'de' => ['/de/wo-ist-es-jetzt', 'Wo ist es jetzt?'];
        yield 'es' => ['/es/donde-esta-ahora', '¿Dónde está ahora?'];
        yield 'fr' => ['/fr/ou-est-passe', 'Où est-ce passé ?'];
        yield 'ja' => ['/ja/移動したページ', 'どこに移動しましたか？'];
    }

    #[DataProvider('provideLocalePaths')]
    public function testEveryLocaleHasItsPage(string $path, string $title): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertSame($title, trim($crawler->filter('main h1')->text()));
        self::assertGreaterThan(50, $crawler->filter(self::ROWS)->count());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRoles(): iterable
    {
        foreach ([NavigationViewer::GUEST, NavigationViewer::PLAYER, NavigationViewer::MEMBER, NavigationViewer::MODERATOR, NavigationViewer::ADMIN] as $role) {
            yield $role => [$role];
        }
    }

    /**
     * Each row's old name links a page and its "Now" the row in the Menu page - every link resolves to a route.
     */
    #[DataProvider('provideRoles')]
    public function testEveryRowLinksARoute(string $role): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);
        $crawler = $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();

        $router = self::getContainer()->get(RouterInterface::class);
        $rows = $crawler->filter(self::ROWS);

        foreach ($rows as $row) {
            assert($row instanceof DOMElement);
            $links = (new Crawler($row))->filter('a');
            self::assertGreaterThanOrEqual(1, $links->count(), (string) $row->getAttribute('id'));
            self::assertNotSame('', trim((string) $row->getAttribute('data-search')));

            foreach ($links as $link) {
                assert($link instanceof DOMElement);
                $href = $link->getAttribute('href');
                $path = (string) parse_url($href, PHP_URL_PATH);

                try {
                    $router->match(rawurldecode($path));
                } catch (ExceptionInterface) {
                    self::fail(sprintf('%s: %s (row %s) resolves to no route', $role, $href, $row->getAttribute('id')));
                }
            }
        }
    }

    /**
     * An admin sees every item of the old navigation (FormerNavigation covers every route of the P0-02 baseline -
     * FormerNavigationTest), the admin key's rows included; a guest and a member never see those.
     */
    public function testAnAdminSeesEveryItemOthersNoRoleRows(): void
    {
        $crawler = $this->page(NavigationViewer::ADMIN);
        self::assertCount(count(FormerNavigation::items()), $crawler->filter(self::ROWS));
        self::assertCount(14, $crawler->filter('#wdig-h-admin-key + table ' . '.wdig-row'));
        self::assertSame('Puzzle Change Requests', $crawler->filter('#wdig-admin-key-1 .wdig-old a')->text());
        self::assertSame('/admin/puzzle-change-requests', $crawler->filter('#wdig-admin-key-1 .wdig-old a')->attr('href'));
        self::assertSame('☰ › Your roles › Puzzle Change Requests', $crawler->filter('#wdig-admin-key-1 .wdig-now')->text());

        foreach ([NavigationViewer::GUEST, NavigationViewer::MEMBER] as $role) {
            $crawler = $this->page($role);
            self::assertCount(0, $crawler->filter('#wdig-h-admin-key'), $role);
            self::assertCount(0, $crawler->filter('main .wdig-mapping a[href^="/admin"]'), $role);
        }
    }

    /**
     * The old name and the new place: the Menu path on a phone, the computer's when it differs (the bars' places), the
     * Menu row linked on the `menu` page.
     */
    public function testWhereItIsNow(): void
    {
        $crawler = $this->page(NavigationViewer::MEMBER);

        // "Export data" in "My profile ▾" is Account › Your data in the Menu
        $row = $this->row($crawler, 'account-dropdown', 'Export data');
        self::assertSame('☰ › Account › Your data', $row->filter('.wdig-now')->text());
        self::assertSame('/en/menu#menu-row-your_data', $row->filter('.wdig-new a')->attr('href'));
        self::assertStringStartsWith('/en/export-puzzler-data/', (string) $row->filter('.wdig-old a')->attr('href'));

        // The Hub is Home: the bottom bar on a phone, the left menu on a computer
        $row = $this->row($crawler, 'hamburger', 'Hub');
        self::assertSame('/en/hub', $row->filter('.wdig-old a')->attr('href'));
        self::assertSame('Bottom bar › Home', $row->filter('.wdig-now--phone')->text());
        self::assertSame('Left menu › Home', $row->filter('.wdig-now--desktop')->text());

        // The bell: the Menu's Inbox on a phone, the top bar's Inbox on a computer
        $row = $this->row($crawler, 'toolbar', 'Notifications');
        self::assertSame('☰ › Inbox', $row->filter('.wdig-now--phone')->text());
        self::assertSame('Top bar › Inbox', $row->filter('.wdig-now--desktop')->text());

        // Search is the top bar's field
        self::assertSame('Top bar › Search', $this->row($crawler, 'toolbar', 'Search')->filter('.wdig-now')->text());

        // What the filter searches: the old name and the new place, folded
        self::assertSame('export data ☰ › account › your data', $this->row($crawler, 'account-dropdown', 'Export data')->attr('data-search'));
    }

    /**
     * Members-only rows carry a lock and "Members" for whoever is not a member (no modal); members see them plain.
     */
    public function testMembersOnlyRowsAreMarked(): void
    {
        $row = $this->row($this->page(NavigationViewer::PLAYER), 'account-dropdown', 'Referral Program');
        self::assertCount(1, $row->filter('.wdig-members .bi-lock-fill'));
        self::assertStringContainsString('Members', $row->filter('.wdig-members')->text());
        self::assertCount(0, $row->filter('[data-bs-toggle="modal"]'));

        $row = $this->row($this->page(NavigationViewer::MEMBER), 'account-dropdown', 'Referral Program');
        self::assertCount(0, $row->filter('.wdig-members'));
    }

    /**
     * A guest gets every row but the role rows; where the new place needs an account the row says so and links the
     * page (it asks to sign in) or signing in. The old hamburger Hub link still leads to the Hub (F24).
     */
    public function testAGuestFindsTheRowsThatNeedAnAccountMarked(): void
    {
        $crawler = $this->page(NavigationViewer::GUEST);

        $row = $this->row($crawler, 'account-dropdown', 'Statistics');
        self::assertSame('After signing in:', $row->filter('.wdig-after')->text());
        self::assertStringStartsWith('/login', (string) $row->filter('.wdig-old a')->attr('href'));

        $row = $this->row($crawler, 'hamburger', 'Hub');
        self::assertSame('/en/hub', $row->filter('.wdig-old a')->attr('href'));
        self::assertCount(1, $row->filter('.wdig-after'));

        // Pages for everybody: no mark
        self::assertCount(0, $this->row($crawler, 'hamburger', 'FAQ')->filter('.wdig-after'));
        self::assertCount(0, $this->row($crawler, 'footer', 'Puzzle database')->filter('.wdig-after, .wdig-members'));
    }

    private function page(string $role): Crawler
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);

        return $this->request($browser);
    }

    private function request(KernelBrowser $browser): Crawler
    {
        $crawler = $browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * The row of an old surface whose old name is $oldName.
     */
    private function row(Crawler $crawler, string $surface, string $oldName): Crawler
    {
        $rows = $crawler->filter(sprintf('#wdig-h-%s + table .wdig-row', $surface))
            ->reduce(static fn (Crawler $row): bool => trim($row->filter('.wdig-old a')->text()) === $oldName);
        self::assertCount(1, $rows, sprintf('%s: %s', $surface, $oldName));

        return $rows;
    }
}
