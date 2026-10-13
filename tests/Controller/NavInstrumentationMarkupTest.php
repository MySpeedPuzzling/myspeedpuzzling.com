<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\InboxBadgeParts;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

/**
 * Navigation instrumentation (docs/features/navigation P0-03): every link and button of the site header, the Menu's
 * docked-column slot and the footer carries a well-formed data-nav="<surface>/<place>/<item>" -
 * assets/nav_instrumentation.js counts nothing else, so a link without one is a link nobody can measure. The page's own
 * <header>/<footer> elements are not chrome.
 */
final class NavInstrumentationMarkupTest extends WebTestCase
{
    // The top bar (NAV-03), the Menu in its docked-column slot (MENU-01: the old header's rows and its admin key went
    // there with NAV-06), the footer
    private const string CHROME = 'body > header a, body > header button, #menu-slot a, #menu-slot button, body > footer a, body > footer button';

    /**
     * @return iterable<string, array{null|string, bool, list<string>}>
     */
    public static function viewers(): iterable
    {
        // Guests: sign in (the top bar) and join (the Menu's guest card), the newsletter form, popular searches; the top
        // bar's tools (NAV-03)
        yield 'guest' => [null, false, ['topbar/home/logo', 'topbar/none/search', 'topbar/none/help', 'topbar/none/menu', 'topbar/places/home', 'topbar/add/add', 'topbar/profile/sign_in', 'menu/you/sign_in', 'menu/you/join', 'footer/newsletter/subscribe', 'footer/newsletter/privacy', 'footer/popular_brands/cobble_hill', 'footer/popular_world_championship/wjpc_2026', 'footer/credits/jan']];
        // NAV-05: the account menu (avatar ▾) - its trigger is the top bar's, its items account/links/*; the Inbox (one
        // link for both parts, INB-01/02) and Home (the logo, the places) in the top bar
        yield 'member' => [PlayerFixture::PLAYER_WITH_STRIPE, false, ['topbar/none/inbox', 'topbar/none/scan', 'topbar/profile/account', 'account/links/view_profile', 'account/links/settings', 'account/links/membership', 'account/language/de', 'account/links/logout', 'topbar/home/logo', 'topbar/places/home', 'menu/home/home', 'footer/language/de']];
        // Every queue of the old admin key: Your roles in the Menu (MENU-01)
        yield 'admin' => [PlayerFixture::PLAYER_ADMIN, false, ['menu/roles/mod_change_requests', 'menu/roles/mod_merge_requests', 'menu/roles/mod_puzzle_approvals', 'menu/roles/mod_time_verification', 'menu/roles/admin_duplicate_results', 'menu/roles/admin_api_usage', 'account/language/de']];
        // A player who switched the newsletter off gets the "Turn it on" button
        yield 'player with the newsletter off' => [PlayerFixture::PLAYER_REGULAR, true, ['footer/newsletter/turn_on']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('viewers')]
    public function testEveryChromeLinkAndButtonCarriesAWellFormedNavId(null|string $playerId, bool $newsletterOff, array $expected): void
    {
        $browser = self::createClient();

        if ($newsletterOff && $playerId !== null) {
            $browser->getContainer()->get(Connection::class)->executeStatement(
                'UPDATE player SET newsletter_enabled = false WHERE id = :id',
                ['id' => $playerId],
            );
        }

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $elements = $crawler->filter(self::CHROME);
        self::assertGreaterThan(40, $elements->count());

        $missing = [];
        $navIds = [];

        foreach ($elements as $element) {
            assert($element instanceof DOMElement);
            $value = $element->getAttribute('data-nav');

            if (strlen($value) > 100 || preg_match(NavClickController::NAV_PATTERN, $value) !== 1) {
                $missing[] = sprintf('<%s> "%s" data-nav="%s"', $element->tagName, trim((string) preg_replace('/\s+/', ' ', $element->textContent)), $value);
            }

            $navIds[] = $value;
        }

        self::assertSame([], $missing, 'Chrome links and buttons without a well-formed data-nav');

        foreach ($expected as $navId) {
            self::assertContains($navId, $navIds);
        }

        // The listener only matches links and buttons; nothing else - <html> least of all - carries data-nav
        self::assertCount(0, $crawler->filter('[data-nav]:not(a):not(button)'));
        self::assertNull($crawler->filter('html')->attr('data-nav'));
    }

    /**
     * Navigation containers outside the header and footer (implementation plan §3.3): the Community landing (COM-01a,
     * its previews COM-01), the section chips (COM-02, PUZ-01)
     * and the Help sheet (HELP-01a; its feedback box HELP-01b, its order HELP-02) - its page and its frame - the end-of-page
     * line (HELP-04), the Inbox header's tabs on both
     * Inbox pages (INB-01, signed in), the Menu (MENU-01), its phone panel's shell (MENU-02b), the phone bottom bar
     * (NAV-01), the top bar (NAV-03, NAV-04, NAV-05) and the auth pages' header (NAV-08). Every link and button there is
     * counted too.
     *
     * @return iterable<string, array{0: string, 1: array<string, string>, 2: string, 3: list<string>, 4?: string}>
     */
    public static function navigationContainers(): iterable
    {
        // The Community landing (COM-01a) with its previews (COM-01): the solo 500-piece leaders, the most active puzzlers
        // (none in the fixtures: the community stats are not computed), the next events - each card's call to action last,
        // Compare beside Find puzzlers
        yield 'community landing' => ['/en/community', [], '.community-landing', [
            'community/community/leader', 'community/community/leader', 'community/community/leader', 'community/community/leaderboards',
            'community/community/puzzlers', 'community/community/compare',
            'community/community/event', 'community/community/event', 'community/community/event', 'community/community/events',
        ]];
        // The section chips (COM-02, PUZ-01): Community's three on every page of a section, Puzzles' four (My library for
        // players) on its start pages
        yield 'section chips (community)' => ['/en/ladder/solo/500-pieces', [], 'main > nav.section-chips', ['chips/community/leaderboards', 'chips/community/puzzlers', 'chips/community/events']];
        yield 'section chips (puzzles, guest)' => ['/en/marketplace', [], 'main > nav.section-chips', ['chips/puzzles/all', 'chips/puzzles/marketplace', 'chips/puzzles/next']];
        yield 'section chips (puzzles, player)' => ['/en/puzzle', [], 'main > nav.section-chips', ['chips/puzzles/library', 'chips/puzzles/all', 'chips/puzzles/marketplace', 'chips/puzzles/next'], PlayerFixture::PLAYER_REGULAR];
        // The sheet in its order (HELP-02): guests' "Sign in to send feedback" (HELP-03), the registry links, the language
        // row (the help page itself on the page, the homepages without a page attached) and Reload (installed app only)
        $helpLinks = ['help/help/feature_requests', 'help/help/faq', 'help/help/getting_started', 'help/help/progression'];
        $languages = ['help/help/language_de', 'help/help/language_cs', 'help/help/language_fr', 'help/help/language_es', 'help/help/language_ja'];
        yield 'help page' => ['/en/help', [], 'main [data-help-sheet]', ['help/help/sign_in', ...$helpLinks, ...$languages, 'help/help/reload']];
        yield 'help sheet' => ['/en/help', ['HTTP_TURBO_FRAME' => 'modal-frame'], '[data-help-sheet]', ['help/help/close', 'help/help/sign_in', ...$helpLinks, ...$languages, 'help/help/reload']];
        // The feedback box of a signed-in player (HELP-01b): its Send button is counted too
        yield 'help page (player)' => ['/en/help', [], 'main [data-help-sheet]', ['help/help/send_feedback', ...$helpLinks, 'help/help/where_did_it_go', ...$languages, 'help/help/reload'], PlayerFixture::PLAYER_REGULAR];
        yield 'help sheet (player)' => ['/en/help', ['HTTP_TURBO_FRAME' => 'modal-frame'], '[data-help-sheet]', ['help/help/close', 'help/help/send_feedback', ...$helpLinks, 'help/help/where_did_it_go', ...$languages, 'help/help/reload'], PlayerFixture::PLAYER_REGULAR];
        // On a puzzle page the correction comes first (HELP-02)
        yield 'help sheet on a puzzle page (player)' => ['/en/help?url=' . rawurlencode('/en/puzzle/' . PuzzleFixture::PUZZLE_500_01), ['HTTP_TURBO_FRAME' => 'modal-frame'], '[data-help-sheet]', ['help/help/close', 'help/help/send_feedback', 'help/help/suggest_correction', ...$helpLinks, 'help/help/where_did_it_go', ...$languages, 'help/help/reload'], PlayerFixture::PLAYER_REGULAR];
        // The end-of-page line (HELP-04, surface page): its place is the page's
        yield 'end-of-page line (guest)' => ['/en/faq', [], 'main > .page-help-line', ['page/none/help_line']];
        yield 'end-of-page line (player, a puzzle page)' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, [], 'main > .page-help-line', ['page/puzzles/help_line'], PlayerFixture::PLAYER_REGULAR];
        yield 'inbox for you' => ['/en/notifications', [], 'main nav.inbox-tabs', ['inbox/none/for_you', 'inbox/none/messages'], PlayerFixture::PLAYER_REGULAR];
        yield 'inbox messages' => ['/en/messages', [], 'main nav.inbox-tabs', ['inbox/none/for_you', 'inbox/none/messages'], PlayerFixture::PLAYER_REGULAR];
        // The Menu (MENU-01): the page's one #site-menu in the docked-column slot (a guest) and the `menu` page (a player)
        yield 'menu slot (guest)' => ['/en/faq', [], '#site-menu', [
            'menu/home/home', 'menu/puzzles/puzzles_place', 'menu/puzzles/all_puzzles', 'menu/puzzles/brands', 'menu/puzzles/marketplace',
            'menu/community/community_place', 'menu/community/leaderboards', 'menu/community/msp_rating', 'menu/community/level_boards',
            'menu/community/puzzlers', 'menu/community/all_puzzlers', 'menu/community/events', 'menu/community/organizations', 'menu/community/latest_times',
            'menu/you/sign_in', 'menu/you/join',
            'menu/add/disclosure', 'menu/add/add',
            'menu/help/disclosure', 'menu/help/methodology', 'menu/help/feature_requests', 'menu/help/getting_started', 'menu/help/faq', 'menu/help/guides', 'menu/help/progression',
            'menu/help/language_de', 'menu/help/language_cs', 'menu/help/language_fr', 'menu/help/language_es', 'menu/help/language_ja',
            'menu/about/disclosure', 'menu/about/tracker_app', 'menu/about/wjpc', 'menu/about/developers', 'menu/about/organizers',
            'menu/about/legal_terms', 'menu/about/legal_privacy', 'menu/about/legal_data_deletion',
        ]];
        yield 'menu page (player)' => ['/en/menu', [], 'main #site-menu', [
            'menu/you/profile', 'menu/you/settings', 'menu/you/membership',
            'menu/inbox/inbox',
            'menu/puzzling/results', 'menu/puzzling/favorites', 'menu/puzzling/statistics', 'menu/puzzling/compare', 'menu/puzzling/review_results',
            'menu/puzzling/pairs_and_teams', 'menu/puzzling/calendar', 'menu/puzzling/achievements', 'menu/puzzling/level_xp', 'menu/puzzling/your_week',
            'menu/library/library', 'menu/library/collection', 'menu/library/what_next', 'menu/library/unsolved', 'menu/library/sell_swap',
            'menu/library/solved', 'menu/library/lent_borrowed', 'menu/library/wishlist',
            'menu/community/leaderboards', 'menu/community/msp_rating', 'menu/community/level_boards', 'menu/community/puzzlers',
            'menu/community/all_puzzlers', 'menu/community/events', 'menu/community/organizations', 'menu/community/latest_times',
            'menu/puzzles/all_puzzles', 'menu/puzzles/brands', 'menu/puzzles/marketplace',
            'menu/add/add', 'menu/add/stopwatch', 'menu/add/multiscan', 'menu/add/add_event',
            'menu/account/membership', 'menu/account/settings', 'menu/account/your_data', 'menu/account/blocked_players',
            'menu/help/send_feedback', 'menu/help/methodology', 'menu/help/feature_requests', 'menu/help/getting_started', 'menu/help/faq',
            'menu/help/guides', 'menu/help/progression', 'menu/help/where_did_it_go',
            'menu/help/language_de', 'menu/help/language_cs', 'menu/help/language_fr', 'menu/help/language_es', 'menu/help/language_ja',
            'menu/about/tracker_app', 'menu/about/wjpc', 'menu/about/developers', 'menu/about/organizers',
            'menu/about/legal_terms', 'menu/about/legal_privacy', 'menu/about/legal_data_deletion',
            'menu/logout/logout',
        ], PlayerFixture::PLAYER_PRIVATE];
        // The Menu shell of the phone panel (MENU-02b): its header - the Menu itself is moved in when it opens
        yield 'menu shell (guest)' => ['/en/faq', [], '#menu-layer', ['menu/none/close', 'menu/none/logo']];
        yield 'menu shell (player)' => ['/en/hub', [], '#menu-layer', ['menu/none/close', 'menu/none/logo'], PlayerFixture::PLAYER_REGULAR];
        // The phone bottom bar (NAV-01): the five places, for guests and players alike
        $places = ['tabbar/places/home', 'tabbar/places/puzzles', 'tabbar/places/add', 'tabbar/places/community', 'tabbar/places/profile'];
        yield 'bottom bar (guest)' => ['/en/faq', [], '#bottom-nav', $places];
        yield 'bottom bar (player)' => ['/en/hub', [], '#bottom-nav', $places, PlayerFixture::PLAYER_REGULAR];
        // The top bar (NAV-03, NAV-04, NAV-05): ☰ (desktop) · back (installed app, not on a root page) · logo · the
        // 768-1023 px places · the search field's scan and × · 🔍 · + Add · ? / Feedback · Inbox (signed in) · the account
        // menu (signed in) / Sign in (guests) · ☰ (phones); a guest gets the locked scan inside the opened search only
        $compactPlaces = ['topbar/places/home', 'topbar/places/puzzles', 'topbar/places/community'];
        $account = ['topbar/profile/account', 'account/links/view_profile', 'account/links/settings', 'account/links/membership', 'account/language/de', 'account/language/cs', 'account/language/fr', 'account/language/es', 'account/language/ja', 'account/links/logout'];
        yield 'top bar (guest)' => ['/en/faq', [], 'body > header.site-header', ['topbar/none/menu', 'topbar/none/back', 'topbar/home/logo', ...$compactPlaces, 'topbar/none/scan_locked', 'topbar/none/close_search', 'topbar/none/search', 'topbar/add/add', 'topbar/none/help', 'topbar/profile/sign_in', 'topbar/none/menu']];
        yield 'top bar (player, a root page)' => ['/en/hub', [], 'body > header.site-header', ['topbar/none/menu', 'topbar/home/logo', ...$compactPlaces, 'topbar/none/scan_locked', 'topbar/none/close_search', 'topbar/none/search', 'topbar/add/add', 'topbar/none/help', 'topbar/none/inbox', ...$account, 'topbar/none/menu'], PlayerFixture::PLAYER_PRIVATE];
        // The sign-in / sign-up pages' header (NAV-08, decision 25): the logo and the language switch, nothing else
        yield 'auth header (guest)' => ['/login', [], 'body > header.site-header--auth', ['topbar/home/logo', 'topbar/language/de', 'topbar/language/cs', 'topbar/language/fr', 'topbar/language/es', 'topbar/language/ja']];
    }

    /**
     * @param array<string, string> $server
     * @param list<string> $expected
     */
    #[DataProvider('navigationContainers')]
    public function testNavigationContainersCarryWellFormedNavIds(string $url, array $server, string $container, array $expected, null|string $playerId = null): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $browser->request('GET', $url, server: $server);
        self::assertResponseIsSuccessful();

        $navIds = [];

        foreach ($crawler->filter($container)->filter('a, button') as $element) {
            assert($element instanceof DOMElement);
            $value = $element->getAttribute('data-nav');
            self::assertMatchesRegularExpression(NavClickController::NAV_PATTERN, $value, sprintf('<%s> "%s" in %s', $element->tagName, trim($element->textContent), $container));
            $navIds[] = $value;
        }

        self::assertSame($expected, $navIds);
    }

    /**
     * One id per destination: two elements share an id only when they are the same destination (the phone and the
     * desktop ☰) - the beacon's layout field tells the tiers apart.
     */
    public function testNavIdsNameOneDestinationEach(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/faq');

        $hrefsById = [];

        foreach ($crawler->filter(self::CHROME) as $element) {
            assert($element instanceof DOMElement);
            $hrefsById[$element->getAttribute('data-nav')][] = $element->tagName . ' ' . $element->getAttribute('href');
        }

        foreach ($hrefsById as $navId => $targets) {
            self::assertCount(1, array_unique($targets), $navId . ' names different destinations: ' . implode(', ', $targets));
        }

        // The old header's two logos went with NAV-06: the top bar has one; its phone and desktop ☰ share an id
        self::assertSame(['a /en/hub'], $hrefsById['topbar/home/logo'] ?? [], 'The logo');
        self::assertSame(['a /en/menu', 'a /en/menu'], $hrefsById['topbar/none/menu'] ?? [], 'The phone and the desktop ☰');
    }

    /**
     * The Inbox links keep their ids through a realtime push: the Mercure stream (MercureNotifier, INB-02) carries
     * numbers only and never re-renders a link, and the top bar's Inbox link (the old header's bell and Messages tool
     * went with NAV-06) renders its id with the badge inside.
     */
    public function testTheStreamedBadgeLeavesTheNavIdsInPlace(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $stream = $twig->render('inbox/_badge_stream.html.twig', ['parts' => new InboxBadgeParts(1, 2, 3), 'at' => 1760000000]);
        self::assertStringNotContainsString('<a', $stream);
        self::assertStringNotContainsString('data-nav', $stream);

        $crawler = new Crawler($twig->render('inbox/_link.html.twig', ['parts' => new InboxBadgeParts(1, 2, 3), 'nav' => 'topbar/none/inbox', 'surface' => 'topbar']));

        self::assertSame('topbar/none/inbox', $crawler->filter('a')->attr('data-nav'));
        self::assertSame('6', $crawler->filter('a .inbox-badge[data-inbox-part="total"]')->text());
    }
}
