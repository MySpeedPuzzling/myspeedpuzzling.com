<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Navigation\FormerNavigation;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
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
        // Its status line is a live region that is always there and empty until something is typed (LAUNCH review F5):
        // never hidden, so a screen reader hears what the script writes into it
        $status = $crawler->filter('main [data-where-did-it-go-target="bar"] [data-where-did-it-go-target="status"][role="status"]');
        self::assertCount(1, $status);
        self::assertNull($status->attr('hidden'));
        self::assertSame('', $status->text());
        self::assertSame('Nothing matches. Try another word, or look in ☰ Menu.', $crawler->filter('main [data-controller="where-did-it-go"]')->attr('data-where-did-it-go-none-value'));
        // The page's own stylesheet (LAUNCH review F7)
        self::assertCount(1, $crawler->filter('head link[rel="stylesheet"][href*="/build/where_did_it_go"]'));
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
     * FormerNavigationTest), the admin key's rows included - all but the guests' Sign in and Register (LAUNCH review F4);
     * a guest and a member never see the role rows.
     */
    public function testAnAdminSeesEveryItemOthersNoRoleRows(): void
    {
        $crawler = $this->page(NavigationViewer::ADMIN);
        self::assertCount(count(FormerNavigation::items()) - 2, $crawler->filter(self::ROWS));
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

        // 768-1023 px: no left menu yet - the compact top bar carries Home and Puzzles, the avatar ▾ the profile
        // (LAUNCH review F1)
        self::assertSame('Top bar › Home', $row->filter('.wdig-now--tablet')->text());
        self::assertSame('Top bar › Puzzles', $this->row($crawler, 'hamburger', 'Puzzle')->filter('.wdig-now--tablet')->text());
        $row = $this->row($crawler, 'account-dropdown', 'My profile');
        self::assertSame('Bottom bar › Profile', $row->filter('.wdig-now--phone')->text());
        self::assertSame('Account menu (your picture) › View profile', $row->filter('.wdig-now--tablet')->text());
        self::assertSame('Left menu › Profile', $row->filter('.wdig-now--desktop')->text());

        // The bell and the envelope: the top bar's Inbox, next to ☰ on a phone and on a computer (LAUNCH review F1) - one
        // text, no Menu link; its Menu row stays in what the filter searches
        foreach (['Notifications', 'Messages'] as $oldName) {
            $row = $this->row($crawler, 'toolbar', $oldName);
            self::assertSame(['Top bar › Inbox'], $row->filter('.wdig-now')->each(static fn (Crawler $now): string => $now->text()), $oldName);
            self::assertCount(0, $row->filter('.wdig-new a'), $oldName);
            self::assertStringContainsString('☰ › inbox', (string) $row->attr('data-search'), $oldName);
        }

        // Send feedback (the top strip's and the footer's): the top bar's ? "Help & feedback" on a phone and at
        // 768-1023 px, its Feedback on a computer
        foreach (['topbar', 'footer'] as $surface) {
            $row = $this->row($crawler, $surface, 'Send feedback');
            self::assertSame('Top bar › Help & feedback', $row->filter('.wdig-now--phone')->text(), $surface);
            self::assertSame('Top bar › Help & feedback', $row->filter('.wdig-now--tablet')->text(), $surface);
            self::assertSame('Top bar › Feedback', $row->filter('.wdig-now--desktop')->text(), $surface);
            self::assertStringContainsString('☰ › help & learn › send feedback', (string) $row->attr('data-search'), $surface);
        }

        // Search is the top bar's field
        self::assertSame('Top bar › Search', $this->row($crawler, 'toolbar', 'Search')->filter('.wdig-now')->text());

        // What the filter searches: the old name and the new place, folded
        self::assertSame('export data ☰ › account › your data', $this->row($crawler, 'account-dropdown', 'Export data')->attr('data-search'));
    }

    /**
     * Log out is an action: its old name links its place in the Menu, never /logout - a player scanning the table is not
     * signed out by a tap on it (LAUNCH review F3). No row of any viewer links an action.
     */
    public function testAnActionsOldNameLinksItsPlaceNeverTheAction(): void
    {
        $row = $this->row($this->page(NavigationViewer::MEMBER), 'account-dropdown', 'Log out');
        self::assertSame('/en/menu#menu-row-logout', $row->filter('.wdig-old a')->attr('href'));
        self::assertSame('/en/menu#menu-row-logout', $row->filter('.wdig-new a')->attr('href'));

        foreach ([NavigationViewer::GUEST, NavigationViewer::MEMBER, NavigationViewer::ADMIN] as $role) {
            self::assertCount(0, $this->page($role)->filter('main a[href^="/logout"]'), $role);
        }
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

        // The Help sheet's page opens for guests and its new place - the top bar's ? - is theirs too (LAUNCH review F4;
        // SRCH+FOOT review F1/F2): no "after signing in"
        foreach ([['topbar', 'Send feedback'], ['footer', 'Send feedback']] as [$surface, $oldName]) {
            self::assertCount(0, $this->row($crawler, $surface, $oldName)->filter('.wdig-after, .wdig-members'), $surface . ': ' . $oldName);
        }

        // What next?'s landing opens for guests - the old name links it - but its new place is a Menu row only players
        // have (QA-04 R13): the new place says "after signing in" and links no row a guest's Menu does not have; so do
        // Log out (its old name signs in) and the old footer's Achievements (the public overview)
        foreach ([['account-dropdown', 'What next?', '/en/what-to-solve-next'], ['footer', 'What should I solve next?', '/en/what-to-solve-next'], ['footer', 'Achievements', '/en/achievements'], ['account-dropdown', 'Log out', null]] as [$surface, $oldName, $oldHref]) {
            $row = $this->row($crawler, $surface, $oldName);
            self::assertSame('After signing in:', $row->filter('.wdig-after')->text(), $surface . ': ' . $oldName);
            self::assertCount(0, $row->filter('.wdig-new a'), $surface . ': ' . $oldName);

            if ($oldHref !== null) {
                self::assertSame($oldHref, $row->filter('.wdig-old a')->attr('href'), $surface . ': ' . $oldName);
            } else {
                self::assertStringStartsWith('/login', (string) $row->filter('.wdig-old a')->attr('href'));
            }
        }

        // The membership page asks a guest to sign in (#[IsGranted] on MembershipController): the same rule as the page
        // search's sign-in mark, so its rows keep "after signing in"
        foreach (['topbar', 'footer'] as $surface) {
            self::assertCount(1, $this->row($crawler, $surface, 'Membership')->filter('.wdig-after'), $surface);
        }

        // The guest card's Sign in and Join free: a guest's rows
        self::assertSame('☰ › Sign in', $this->row($crawler, 'toolbar', 'Sign in')->filter('.wdig-now')->text());
        self::assertSame('☰ › Join free', $this->row($crawler, 'toolbar', 'Register')->filter('.wdig-now')->text());
    }

    /**
     * QA-04 R13: a "Now" links a row of the `menu` page only where the viewer's own Menu has that row - a guest's Menu has
     * no signed-in rows, a non-member's no members' rows. Every row of every viewer is checked against their Menu page.
     */
    #[DataProvider('provideRoles')]
    public function testNowLinksOnlyRowsOfTheViewersOwnMenu(string $role): void
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);

        $menu = $browser->request('GET', '/en/menu');
        self::assertResponseIsSuccessful();
        $anchors = $menu->filter('main [id^="menu-row-"]')->each(static fn (Crawler $row): string => (string) $row->attr('id'));
        self::assertNotSame([], $anchors);

        $crawler = $this->request($browser);
        $linked = 0;

        foreach ($crawler->filter(self::ROWS)->filter('a[href*="#menu-row-"]') as $link) {
            assert($link instanceof DOMElement);
            $anchor = (string) parse_url($link->getAttribute('href'), PHP_URL_FRAGMENT);
            self::assertContains($anchor, $anchors, sprintf('%s: %s links a row their Menu does not have', $role, $link->getAttribute('href')));
            $linked++;
        }

        self::assertGreaterThan(0, $linked, $role);
    }

    /**
     * A signed-in player's Menu has the You card where a guest's has Sign in · Join free: those two old toolbar items were
     * for guests only, and so are their rows (LAUNCH review F4).
     */
    #[DataProvider('provideRoles')]
    public function testSignInAndRegisterAreGuestRows(string $role): void
    {
        $oldNames = $this->page($role)->filter('#wdig-h-toolbar + table .wdig-old a')->each(static fn (Crawler $link): string => trim($link->text()));

        foreach (['Sign in', 'Register'] as $oldName) {
            self::assertSame($role === NavigationViewer::GUEST, in_array($oldName, $oldNames, true), $role . ': ' . $oldName);
        }
    }

    /**
     * Every old name links where its item went (LAUNCH review F8): its own page (one of the item's old routes), the page
     * of the entry it names (its route or the guests' route), sign-in where the row says "after signing in", and the
     * Menu page only for an item without a page of its own (the language menu), one whose new place is ☰ itself (the
     * guest card) or an action (Log out). Rows are matched to FormerNavigation's items by their ids (wdig-<surface>-<n>),
     * so a builder that sent old names anywhere else - the Menu page for all - fails here. Every link carries its data-nav
     * (LAUNCH review Q3): page/none/wdig_<the linked entry>, page/none/wdig_now_<the new home>.
     */
    #[DataProvider('provideRoles')]
    public function testEveryOldNameLinksWhereItsItemWent(string $role): void
    {
        $crawler = $this->page($role);
        $router = self::getContainer()->get(RouterInterface::class);
        $registry = self::getContainer()->get(NavigationRegistry::class);
        $positions = [];
        $checked = 0;

        foreach (FormerNavigation::items() as $item) {
            $surface = $item->surface->value;
            $positions[$surface] = ($positions[$surface] ?? 0) + 1;
            $id = 'wdig-' . str_replace('_', '-', $surface) . '-' . $positions[$surface];
            $row = $crawler->filter('#' . $id);

            if ($row->count() === 0) {
                // Not for this viewer (roles, audiences they are not in); an admin misses only the guests' two rows
                if ($role === NavigationViewer::ADMIN) {
                    self::assertContains($item->label, ['menu.sign_in', 'menu.register'], $id . ' is missing for an admin');
                }

                continue;
            }

            $entry = $registry->byId($item->entry);
            $link = $registry->byId($item->linkEntry());
            self::assertNotNull($entry);
            self::assertNotNull($link);

            // An action (Log out) only ever links its place (F3)
            $allowed = $link->kind->isAction() ? ['menu'] : [...$item->routes, $link->target->route, $link->target->guestRoute];

            if ($row->filter('.wdig-after')->count() > 0) {
                $allowed[] = 'login';
            }

            if ($item->routes === [] || ($item->path[0] ?? null) === '☰') {
                $allowed[] = 'menu';
            }

            $old = $row->filter('.wdig-old a');
            $route = $this->routeOf($router, (string) $old->attr('href'));
            self::assertContains($route, array_values(array_filter($allowed)), sprintf('%s %s (%s): %s', $role, $id, $item->label ?? $item->entry, $old->attr('href')));

            self::assertSame('page/none/wdig_' . $link->id, $old->attr('data-nav'), $id);
            self::assertSame(1, preg_match(NavClickController::NAV_PATTERN, (string) $old->attr('data-nav')), $id);

            foreach ($row->filter('.wdig-new a') as $now) {
                assert($now instanceof DOMElement);
                self::assertSame('page/none/wdig_now_' . $entry->id, $now->getAttribute('data-nav'), $id);
                self::assertSame(1, preg_match(NavClickController::NAV_PATTERN, $now->getAttribute('data-nav')), $id);
                self::assertSame('menu', $this->routeOf($router, $now->getAttribute('href')), $id);
            }

            $checked++;
        }

        self::assertCount($checked, $crawler->filter(self::ROWS), 'every row is an item of FormerNavigation, in its order');

        if ($role === NavigationViewer::ADMIN) {
            // Everything but the guests' two rows
            self::assertSame(count(FormerNavigation::items()) - 2, $checked);
        }
    }

    /**
     * The native app has no ☰ and none of the web's bars (implementation plan §3.5 item 9; LAUNCH review F6): the old
     * names keep their links, the "Now" column and every word about ☰ and the bars stay out, and one line points at the
     * app's own tab bar and Menu.
     */
    public function testTheAppGetsTheOldNamesWithoutTheWebsBars(): void
    {
        foreach ([NavigationViewer::GUEST, NavigationViewer::MEMBER] as $role) {
            self::ensureKernelShutdown();
            $browser = self::createClient();
            NavigationViewer::signIn($browser, $role);
            $crawler = $browser->request('GET', self::PAGE, server: ['HTTP_USER_AGENT' => NativeUserAgents::IOS]);
            self::assertResponseIsSuccessful();

            $main = $crawler->filter('main');
            self::assertSame('In the app, use its tab bar and Menu.', trim($main->filter('.wdig-app')->text()), $role);
            self::assertGreaterThan(50, $crawler->filter(self::ROWS)->count(), $role);
            self::assertCount($crawler->filter(self::ROWS)->count(), $crawler->filter(self::ROWS . ' .wdig-old a[href]'), $role);
            self::assertCount(0, $main->filter('.wdig-new, .wdig-now'), $role);
            self::assertCount($main->filter('.wdig-table')->count(), $main->filter('.wdig-table thead th'), $role . ': one column');

            foreach (['☰', 'Bottom bar', 'Top bar', 'Left menu', 'Account menu'] as $word) {
                self::assertStringNotContainsString($word, $main->text(), $role . ': ' . $word);
                self::assertStringNotContainsString($word, $main->html(), $role . ': ' . $word);
            }

            // The filter searches the old names alone; its "Nothing matches" names no ☰ either
            self::assertSame('puzzle database', $this->row($crawler, 'footer', 'Puzzle database')->attr('data-search'), $role);
            self::assertSame('Nothing matches. Try another word.', $main->filter('[data-controller="where-did-it-go"]')->attr('data-where-did-it-go-none-value'), $role);
        }
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
     * The route a link of the page leads to (a localized route by its canonical name).
     */
    private function routeOf(RouterInterface $router, string $href): string
    {
        $parameters = $router->match(rawurldecode((string) parse_url($href, PHP_URL_PATH)));
        $route = $parameters['_canonical_route'] ?? $parameters['_route'] ?? null;
        self::assertIsString($route, $href);

        return $route;
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
