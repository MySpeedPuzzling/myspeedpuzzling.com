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
 * Navigation instrumentation (docs/features/navigation P0-03): every link and button of the site header and footer
 * carries a well-formed data-nav="<surface>/<place>/<item>" - assets/nav_instrumentation.js counts nothing else, so a
 * link without one is a link nobody can measure. The page's own <header>/<footer> elements are not chrome.
 */
final class NavInstrumentationMarkupTest extends WebTestCase
{
    // The top bar (NAV-03) and the old header's rows under it until NAV-06 (.legacy-chrome), the footer
    private const string CHROME = 'body > header a, body > header button, body > .legacy-chrome a, body > .legacy-chrome button, body > footer a, body > footer button';

    /**
     * @return iterable<string, array{null|string, bool, list<string>}>
     */
    public static function viewers(): iterable
    {
        // Guests: register / sign in, the newsletter form, popular searches; the top bar's tools (NAV-03)
        yield 'guest' => [null, false, ['topbar/home/logo', 'topbar/none/search', 'topbar/none/help', 'topbar/none/menu', 'toolbar/tools/sign_in', 'toolbar/tools/register', 'footer/newsletter/subscribe', 'footer/newsletter/privacy', 'footer/popular_brands/cobble_hill', 'footer/popular_world_championship/wjpc_2026', 'footer/credits/jan']];
        yield 'member' => [PlayerFixture::PLAYER_WITH_STRIPE, false, ['topbar/none/inbox', 'topbar/none/scan', 'account/toggle/open', 'account/links/logout', 'toolbar/tools/messages', 'toolbar/tools/notifications', 'hamburger/places/hub', 'footer/language/de']];
        // The admin key with every queue
        yield 'admin' => [PlayerFixture::PLAYER_ADMIN, false, ['admin_key/toggle/open', 'admin_key/queues/change_requests', 'admin_key/queues/duplicate_results', 'topbar/language/de']];
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
     * Navigation containers outside the header and footer (implementation plan §3.3): the Community landing (COM-01a)
     * and the Help sheet (HELP-01a; its feedback box HELP-01b, its order HELP-02) - its page and its frame - the end-of-page
     * line (HELP-04), the Inbox header's tabs on both
     * Inbox pages (INB-01, signed in), the Menu (MENU-01), its phone panel's shell (MENU-02b) and the phone bottom bar
     * (NAV-01). Every link and button there is counted too.
     *
     * @return iterable<string, array{0: string, 1: array<string, string>, 2: string, 3: list<string>, 4?: string}>
     */
    public static function navigationContainers(): iterable
    {
        yield 'community landing' => ['/en/community', [], '.community-landing', ['community/community/leaderboards', 'community/community/puzzlers', 'community/community/events']];
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
        // The top bar (NAV-03): back (installed app, not on a root page) · logo · the search field's scan and × · 🔍 · ? ·
        // Inbox (signed in) · ☰; a guest gets the locked scan inside the opened search only
        yield 'top bar (guest)' => ['/en/faq', [], 'body > header.site-header', ['topbar/none/back', 'topbar/home/logo', 'topbar/none/scan_locked', 'topbar/none/close_search', 'topbar/none/search', 'topbar/none/help', 'topbar/none/menu']];
        yield 'top bar (player, a root page)' => ['/en/hub', [], 'body > header.site-header', ['topbar/home/logo', 'topbar/none/scan_locked', 'topbar/none/close_search', 'topbar/none/search', 'topbar/none/help', 'topbar/none/inbox', 'topbar/none/menu'], PlayerFixture::PLAYER_PRIVATE];
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
     * desktop logo) - the beacon's layout field tells the tiers apart.
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

        self::assertSame(['/', '/'], array_map(
            static fn (string $target): string => substr($target, 2),
            $hrefsById['navbar/logo/home'] ?? [],
        ), 'The phone and the desktop logo');
    }

    /**
     * The toolbar's Inbox links keep their ids through a realtime push: the Mercure stream (MercureNotifier, INB-02)
     * carries numbers only and never re-renders a link, and the Messages tool it used to replace renders its id.
     */
    public function testTheStreamedBadgeLeavesTheNavIdsInPlace(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $stream = $twig->render('inbox/_badge_stream.html.twig', ['parts' => new InboxBadgeParts(1, 2, 3), 'at' => 1760000000]);
        self::assertStringNotContainsString('<a', $stream);
        self::assertStringNotContainsString('data-nav', $stream);

        $crawler = new Crawler($twig->render('messaging/_unread_badge.html.twig', ['parts' => new InboxBadgeParts(1, 2, 3)]));

        self::assertSame('toolbar/tools/messages', $crawler->filter('a')->attr('data-nav'));
        self::assertSame('5', $crawler->filter('a .inbox-badge[data-inbox-part="messages"]')->text());
    }
}
