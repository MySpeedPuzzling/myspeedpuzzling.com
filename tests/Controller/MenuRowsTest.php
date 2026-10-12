<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Navigation\MenuSurface;
use SpeedPuzzling\Web\Navigation\NavigationRegistry;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Menu's rows per role and surface (docs/features/navigation README §4.12 / D21, requirements MENU-01): the page's
 * one #site-menu - in the docked-column slot of every page, in docked-column DOM order (the phone panel moves the group
 * nodes into data-order-phone order, decision 29) - and the `menu` page, which renders the phone rows in phone order.
 *
 * Exact row counts on the fixtures (phone panel / docked column), against README §4.12's estimates:
 * - guest 25 / 28 (README ≈ 25): the docked column adds the three place rows Home, Puzzles, Community;
 * - player 54 / 55 (README ≈ 55): PLAYER_PRIVATE has no karma and is not in the referral programme - the README's
 *   player counts the Karma row (a karma holder gets 55). The column drops the You card, Inbox and Log out (top bar /
 *   avatar menu) and adds Home, Puzzles, Community and the Profile row heading Your puzzling (+1 net);
 * - member 56 / 57 (README ≈ 56): + Referral program (members) + Who can see your profile (MEMBER_ONLY's profile is
 *   private; a public member has 55);
 * - organiser 56 / 57 (README +1): + Events you organize; PLAYER_REGULAR is also in the referral programme (+1);
 * - moderator 59 / 60 (README +4): + the four review queues; PLAYER_WITH_FAVORITES also organises (+1);
 * - admin 70 / 71 (README +14): + the four queues and ten admin rows; PLAYER_ADMIN is also a member (+ Referral
 *   program) and an organiser (+1).
 */
final class MenuRowsTest extends WebTestCase
{
    private const array GUEST_PHONE = [
        'guest_card',
        'leaderboards', 'msp_rating', 'level_boards', 'puzzlers', 'all_puzzlers', 'events', 'organizations', 'latest_times',
        'all_puzzles', 'brands', 'marketplace',
        'add',
        'methodology', 'feature_requests', 'getting_started', 'faq', 'guides', 'progression', 'language',
        'tracker_app', 'wjpc', 'developers', 'organizers', 'legal',
    ];

    private const array GUEST_DOCK = [
        'home',
        'puzzles_place', 'all_puzzles', 'brands', 'marketplace',
        'community_place', 'leaderboards', 'msp_rating', 'level_boards', 'puzzlers', 'all_puzzlers', 'events', 'organizations', 'latest_times',
        'guest_card',
        'add',
        'methodology', 'feature_requests', 'getting_started', 'faq', 'guides', 'progression', 'language',
        'tracker_app', 'wjpc', 'developers', 'organizers', 'legal',
    ];

    private const array PLAYER_PHONE = [
        'you_card',
        'inbox',
        'results', 'favorites', 'statistics', 'compare', 'review_results', 'pairs_and_teams', 'calendar', 'achievements', 'level_xp', 'your_week',
        'library', 'collection', 'what_next', 'unsolved', 'sell_swap', 'solved', 'lent_borrowed', 'wishlist',
        'leaderboards', 'msp_rating', 'level_boards', 'puzzlers', 'all_puzzlers', 'events', 'organizations', 'latest_times',
        'all_puzzles', 'brands', 'marketplace',
        'add', 'stopwatch', 'multiscan', 'add_event',
        'membership', 'settings', 'your_data', 'blocked_players',
        'send_feedback', 'methodology', 'feature_requests', 'getting_started', 'faq', 'guides', 'progression', 'where_did_it_go', 'language',
        'tracker_app', 'wjpc', 'developers', 'organizers', 'legal',
        'logout',
    ];

    private const array PLAYER_DOCK = [
        'home',
        'puzzles_place', 'all_puzzles', 'brands', 'marketplace',
        'community_place', 'leaderboards', 'msp_rating', 'level_boards', 'puzzlers', 'all_puzzlers', 'events', 'organizations', 'latest_times',
        'profile', 'results', 'favorites', 'statistics', 'compare', 'review_results', 'pairs_and_teams', 'calendar', 'achievements', 'level_xp', 'your_week',
        'library', 'collection', 'what_next', 'unsolved', 'sell_swap', 'solved', 'lent_borrowed', 'wishlist',
        'add', 'stopwatch', 'multiscan', 'add_event',
        'membership', 'settings', 'your_data', 'blocked_players',
        'send_feedback', 'methodology', 'feature_requests', 'getting_started', 'faq', 'guides', 'progression', 'where_did_it_go', 'language',
        'tracker_app', 'wjpc', 'developers', 'organizers', 'legal',
    ];

    private const array MODERATOR_ROWS = ['mod_change_requests', 'mod_merge_requests', 'mod_puzzle_approvals', 'mod_time_verification'];

    private const array ADMIN_ROWS = [
        'admin_competition_approvals', 'admin_duplicate_results', 'admin_oauth2_requests', 'admin_moderation', 'admin_vouchers',
        'admin_referrals', 'admin_email_audit', 'admin_moderators', 'admin_free_trial', 'admin_api_usage',
    ];

    // Rows behind the XP gate (XpFeatureGate): your achievements, Level & XP, your week, the level boards, How progress works
    private const array XP_ROWS = ['achievements', 'level_xp', 'your_week', 'level_boards', 'progression'];

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function provideRoles(): iterable
    {
        yield 'guest' => [NavigationViewer::GUEST, self::GUEST_PHONE, self::GUEST_DOCK];
        yield 'player' => [NavigationViewer::PLAYER, self::PLAYER_PHONE, self::PLAYER_DOCK];

        yield 'member' => [NavigationViewer::MEMBER_ONLY, self::memberRows(self::PLAYER_PHONE), self::memberRows(self::PLAYER_DOCK)];
        // PLAYER_REGULAR is also in the referral programme
        yield 'organiser' => [
            NavigationViewer::ORGANISER,
            self::insertAfter(self::withRoles(self::PLAYER_PHONE, MenuSurface::Phone, ['organized_events']), 'settings', ['referral']),
            self::insertAfter(self::withRoles(self::PLAYER_DOCK, MenuSurface::Dock, ['organized_events']), 'settings', ['referral']),
        ];
        // PLAYER_WITH_FAVORITES organises too, and is not in the referral programme
        yield 'moderator' => [
            NavigationViewer::MODERATOR,
            self::withRoles(self::PLAYER_PHONE, MenuSurface::Phone, ['organized_events', ...self::MODERATOR_ROWS]),
            self::withRoles(self::PLAYER_DOCK, MenuSurface::Dock, ['organized_events', ...self::MODERATOR_ROWS]),
        ];
        // PLAYER_ADMIN is also a member (Referral program) and an organiser
        yield 'admin' => [
            NavigationViewer::ADMIN,
            self::insertAfter(self::withRoles(self::PLAYER_PHONE, MenuSurface::Phone, ['organized_events', ...self::MODERATOR_ROWS, ...self::ADMIN_ROWS]), 'settings', ['referral']),
            self::insertAfter(self::withRoles(self::PLAYER_DOCK, MenuSurface::Dock, ['organized_events', ...self::MODERATOR_ROWS, ...self::ADMIN_ROWS]), 'settings', ['referral']),
        ];
    }

    /**
     * A member: + Referral program; MEMBER_ONLY's profile is private: + Who can see your profile
     *
     * @param list<string> $rows
     * @return list<string>
     */
    private static function memberRows(array $rows): array
    {
        return self::insertAfter(self::insertAfter($rows, 'settings', ['referral']), 'blocked_players', ['profile_viewers']);
    }

    /**
     * Your roles: after the Inbox on phones, after the places in the column
     *
     * @param list<string> $rows
     * @param list<string> $roleRows
     * @return list<string>
     */
    private static function withRoles(array $rows, MenuSurface $surface, array $roleRows): array
    {
        return self::insertAfter($rows, $surface === MenuSurface::Phone ? 'inbox' : 'latest_times', $roleRows);
    }

    /**
     * @param list<string> $phone
     * @param list<string> $dock
     */
    #[DataProvider('provideRoles')]
    public function testTheRowsOfEachRoleInPhoneAndDockedColumnOrder(string $role, array $phone, array $dock): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, $role);

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#site-menu'), 'The page holds exactly one Menu');

        $menu = $crawler->filter('#site-menu');
        self::assertSame('slot', $menu->attr('data-surface'));
        self::assertSame($dock, $this->dockOrder($menu), $role . ': the docked column (DOM order)');
        self::assertSame($phone, $this->phoneOrder($menu), $role . ': the phone panel (groups moved by data-order-phone)');

        // The `menu` page renders exactly the panel's rows in the panel's order (MENU-02a) - and is then the page's one Menu
        $page = $browser->request('GET', '/en/menu');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('#site-menu'));
        self::assertCount(1, $page->filter('main #site-menu'));
        self::assertSame('page', $page->filter('main #site-menu')->attr('data-surface'));
        self::assertSame($phone, $this->rowIds($page->filter('main #site-menu')), $role . ': the menu page');
        self::assertCount(0, $page->filter('main #site-menu .menu-only-dock'), 'The page renders phone rows only');

        // Rows the viewer cannot use are not rendered - hidden, never locked (D13): no lock icon, locked state or
        // disabled control anywhere in the Menu
        self::assertSame([], $this->lockMarkup($menu), $role . ': lock markup in the Menu');
    }

    public function testTheRowCountsAgainstTheReadmeEstimates(): void
    {
        $counts = [];

        foreach (self::provideRoles() as $name => [, $phone, $dock]) {
            $counts[$name] = [count($phone), count($dock)];
        }

        // README §4.12: guest ≈ 25, player ≈ 55, member ≈ 56, organiser +1, moderator +4, admin +14 (class docblock)
        self::assertSame([
            'guest' => [25, 28],
            'player' => [54, 55],
            'member' => [56, 57],
            'organiser' => [56, 57],
            'moderator' => [59, 60],
            'admin' => [70, 71],
        ], $counts);
    }

    public function testEveryRowOnBothSurfacesHasOnePlaceSoGroupMovesKeepTheRowOrder(): void
    {
        self::bootKernel();

        foreach (self::getContainer()->get(NavigationRegistry::class)->all() as $entry) {
            if ($entry->phone->isRow() && $entry->dock->isRow()) {
                self::assertSame($entry->phone->group, $entry->dock->group, $entry->id);
                self::assertSame($entry->phone->position, $entry->dock->position, $entry->id);
            }
        }
    }

    public function testTheXpGateHidesItsRowsUntilTheLaunchExceptForAdmins(): void
    {
        FixedXpLaunchReadiness::$ready = false;

        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/faq');
        self::assertSame(array_values(array_diff(self::GUEST_PHONE, self::XP_ROWS)), $this->phoneOrder($crawler->filter('#site-menu')), 'guest before the launch');

        NavigationViewer::signIn($browser, NavigationViewer::PLAYER);
        $crawler = $browser->request('GET', '/en/faq');
        self::assertSame(array_values(array_diff(self::PLAYER_PHONE, self::XP_ROWS)), $this->phoneOrder($crawler->filter('#site-menu')), 'player before the launch');
        self::assertCount(0, $crawler->filter('#site-menu .xp-avatar-ring'), 'No level ring before the launch');

        // Admins see the XP system before everybody else (XpFeatureGate)
        NavigationViewer::signIn($browser, NavigationViewer::ADMIN);
        $crawler = $browser->request('GET', '/en/faq');
        $rows = $this->phoneOrder($crawler->filter('#site-menu'));

        foreach (self::XP_ROWS as $row) {
            self::assertContains($row, $rows, 'admin before the launch: ' . $row);
        }

        FixedXpLaunchReadiness::$ready = true;
        NavigationViewer::signIn($browser, NavigationViewer::PLAYER);
        $crawler = $browser->request('GET', '/en/faq');
        self::assertSame(self::PLAYER_PHONE, $this->phoneOrder($crawler->filter('#site-menu')), 'player after the launch');
        self::assertCount(1, $crawler->filter('#site-menu .menu-you .xp-avatar-ring-nav'), 'The You card shows the level ring');
    }

    public function testTheGuestCard(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/faq?pieces=500');
        self::assertResponseIsSuccessful();
        // Guest HTML stays shared-cacheable with the Menu in it
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame([], $browser->getResponse()->headers->getCookies());
        self::assertSame('everyone guest xp_visible', $crawler->filter('body')->attr('data-viewer-audience'));

        $card = $crawler->filter('#menu-row-guest_card');
        self::assertCount(1, $card);
        $signIn = $card->filter('a[data-nav="menu/you/sign_in"]');
        // Back to this very page afterwards; a per-page variant crawlers do not follow
        self::assertSame(self::getContainer()->get(RouterInterface::class)->generate('login', ['return' => '/en/faq?pieces=500']), $signIn->attr('href'));
        self::assertStringStartsWith('/login?return=', (string) $signIn->attr('href'));
        self::assertSame('nofollow', $signIn->attr('rel'));
        self::assertSame('/register', $card->filter('a[data-nav="menu/you/join"]')->attr('href'));

        // Hidden for guests (README §4.12): You card, Inbox, Your roles, Your puzzling, Your puzzles, Account, Send
        // feedback, Where did it go?, Log out; Add opens the guests' explainer
        $menu = $crawler->filter('#site-menu');
        foreach (['inbox', 'roles', 'puzzling', 'library', 'account', 'logout'] as $group) {
            self::assertCount(0, $menu->filter('[data-menu-group="' . $group . '"]'), $group);
        }

        self::assertSame(['guest_card'], $this->rowIds($menu->filter('[data-menu-group="you"]')), 'The You group holds the guest card instead');
        self::assertCount(0, $menu->filter('#menu-row-you_card, #menu-row-send_feedback, #menu-row-where_did_it_go'));
        self::assertSame('/en/add', $menu->filter('#menu-row-add a')->attr('href'));
        // Decision 35: guests get the bare How progress works link (no per-page crawl variants)
        self::assertSame('/en/how-progress-works', $menu->filter('#menu-row-progression a')->attr('href'));
        self::assertNull($menu->filter('#menu-row-progression a')->attr('rel'));
        self::assertNull($menu->attr('data-controller'), 'Guests have no lazy numbers to load');
    }

    public function testTheYouCardAndThePlayersOwnLinks(): void
    {
        $browser = self::createClient();
        $playerId = NavigationViewer::signIn($browser, NavigationViewer::MEMBER_ONLY);

        $crawler = $browser->request('GET', '/en/faq');
        $menu = $crawler->filter('#site-menu');
        $card = $menu->filter('#menu-row-you_card');

        self::assertCount(1, $card);
        self::assertSame('/en/player-profile/' . $playerId, $card->filter('a[data-nav="menu/you/profile"]')->attr('href'));
        self::assertSame('/en/edit-profile', $card->filter('a[data-nav="menu/you/settings"]')->attr('href'));
        self::assertSame('/en/membership', $card->filter('a[data-nav="menu/you/membership"]')->attr('href'));
        self::assertStringStartsWith('Member until ', trim($card->filter('a[data-nav="menu/you/membership"]')->text()));

        // Decision 35: the signed-in viewer's How progress works comes back to the page it was opened on
        $progression = $menu->filter('#menu-row-progression a');
        self::assertSame('/en/how-progress-works?return=/en/faq', $progression->attr('href'));
        self::assertSame('nofollow', $progression->attr('rel'));

        // Send feedback opens the Help sheet (HELP-01b transport) with the page attached
        $feedback = $menu->filter('#menu-row-send_feedback a');
        self::assertSame('/en/help?url=/en/faq', $feedback->attr('href'));
        self::assertSame('own', $feedback->attr('data-modal-scrim'));
        self::assertSame('modal-frame', $feedback->attr('data-turbo-frame'));
        self::assertSame('false', $menu->filter('#menu-row-logout a')->attr('data-turbo'));

        // The current page's row
        self::assertSame(['menu/help/faq'], $menu->filter('[aria-current="page"]')->each(static fn (Crawler $link): string => (string) $link->attr('data-nav')));

        $crawler = $browser->request('GET', '/en/player-statistics/' . $playerId);
        self::assertSame(['menu/puzzling/statistics'], $crawler->filter('#site-menu [aria-current="page"]')->each(static fn (Crawler $link): string => (string) $link->attr('data-nav')));

        self::assertSame('everyone signed_in member member_or_referral private_member xp_visible xp_player xp_participant achievements_shown', $crawler->filter('body')->attr('data-viewer-audience'));
    }

    public function testAnOrganiserSeesEventsYouOrganizeFromTheProfileRowAlone(): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, NavigationViewer::ORGANISER);

        $crawler = $browser->request('GET', '/en/faq');
        $row = $crawler->filter('#site-menu #menu-row-organized_events');

        self::assertCount(1, $row);
        self::assertSame('/en/you-organize', $row->filter('a')->attr('href'));
        self::assertSame('Events you organize', trim($row->filter('.mr-l')->text()));
        // Its number is loaded later (menu_counts); the slot is reserved and empty in the page
        self::assertSame('', $row->filter('[data-menu-count="organised"]')->text());
        self::assertSame('/en/menu-counts.json', $crawler->filter('#site-menu')->attr('data-menu-counts-url-value'));
        self::assertStringContainsString(' organiser', (string) $crawler->filter('body')->attr('data-viewer-audience'));

        NavigationViewer::signIn($browser, NavigationViewer::PLAYER);
        $crawler = $browser->request('GET', '/en/faq');
        self::assertCount(0, $crawler->filter('#site-menu #menu-row-organized_events'));
    }

    /**
     * Rows with a number or a status from the profile row (README §4.12 "Menu data" 4): the running stopwatch ticks in its
     * row and leads back to it (ADD-03's elapsed time), the comparison line-up's size, the level
     */
    public function testRowsShowWhatTheProfileRowKnows(): void
    {
        $browser = self::createClient();
        $playerId = NavigationViewer::signIn($browser, NavigationViewer::MEMBER);
        self::assertNotNull($playerId);

        $crawler = $browser->request('GET', '/en/faq');
        self::assertCount(0, $crawler->filter('#menu-row-stopwatch .mr-running'), 'No stopwatch runs yet');
        self::assertSame('/en/stopwatch', $crawler->filter('#menu-row-stopwatch a')->attr('href'));

        $container = $browser->getContainer();
        $stopwatchId = Uuid::uuid7()->toString();
        $container->get(Connection::class)->insert('stopwatch', [
            'id' => $stopwatchId,
            'player_id' => $playerId,
            'puzzle_id' => null,
            'status' => StopwatchStatus::Running->value,
            'laps' => json_encode([['start' => $container->get(ClockInterface::class)->now()->modify('-42 minutes')->format('Y-m-d H:i:s'), 'end' => null]], JSON_THROW_ON_ERROR),
        ]);

        $crawler = $browser->request('GET', '/en/faq');
        $row = $crawler->filter('#menu-row-stopwatch');
        self::assertSame(self::getContainer()->get(RouterInterface::class)->generate('stopwatch', ['stopwatchId' => $stopwatchId]), $row->filter('a')->attr('href'));
        self::assertStringStartsWith('Running · ', trim((string) preg_replace('/\s+/', ' ', $row->filter('.mr-running')->text())));
        self::assertCount(1, $row->filter('.mr-running [data-controller="stopwatch-pill"]'), 'It ticks like the top bar pill');

        // Every other row with a number shows it only when there is one (no "0" pill)
        foreach ($crawler->filter('#site-menu .mr-n') as $number) {
            self::assertNotSame('0', trim($number->textContent));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providePages(): iterable
    {
        yield 'faq' => ['/en/faq'];
        yield 'hub' => ['/en/hub'];
        yield 'menu page' => ['/en/menu'];
        yield 'puzzle' => ['/en/puzzle/' . PuzzleFixture::PUZZLE_500_01];
        yield 'own profile' => ['/en/player-profile/' . PlayerFixture::PLAYER_ADMIN];
        yield 'inbox' => ['/en/notifications'];
        yield 'admin queue' => ['/admin/puzzle-change-requests'];
    }

    #[DataProvider('providePages')]
    public function testEveryPageHasOneMenuAndEachQueueCountOnce(string $url): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, NavigationViewer::ADMIN);

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#site-menu'), $url);

        $ids = $crawler->filter('[data-testid^="queue-count-"]')->each(static fn (Crawler $badge): string => (string) $badge->attr('data-testid'));
        self::assertCount(7, $ids, $url);
        self::assertSame($ids, array_values(array_unique($ids)), $url . ': a queue count twice');
        self::assertCount(7, $crawler->filter('#site-menu [data-testid^="queue-count-"]'), $url . ': the counts live in Your roles');
    }

    /**
     * Row ids in DOM order without the phone-only parts (the docked column)
     *
     * @return list<string>
     */
    private function dockOrder(Crawler $menu): array
    {
        $ids = [];

        foreach ($menu->filter('section.menu-group') as $section) {
            $group = new Crawler($section);

            if (str_contains(' ' . $group->attr('class') . ' ', ' menu-only-phone ')) {
                continue;
            }

            foreach ($group->filter('li[id^="menu-row-"]') as $row) {
                $li = new Crawler($row);

                if (!str_contains(' ' . $li->attr('class') . ' ', ' menu-only-phone ')) {
                    $ids[] = substr((string) $li->attr('id'), strlen('menu-row-'));
                }
            }
        }

        return $ids;
    }

    /**
     * Row ids with the groups sorted by data-order-phone (what the phone panel does, MENU-02b) and without the
     * dock-only parts
     *
     * @return list<string>
     */
    private function phoneOrder(Crawler $menu): array
    {
        $groups = [];

        foreach ($menu->filter('section.menu-group') as $index => $section) {
            $group = new Crawler($section);

            if (str_contains(' ' . $group->attr('class') . ' ', ' menu-only-dock ')) {
                continue;
            }

            $rows = [];

            foreach ($group->filter('li[id^="menu-row-"]') as $row) {
                $li = new Crawler($row);

                if (!str_contains(' ' . $li->attr('class') . ' ', ' menu-only-dock ')) {
                    $rows[] = substr((string) $li->attr('id'), strlen('menu-row-'));
                }
            }

            $groups[] = [(int) $group->attr('data-order-phone'), $index, $rows];
        }

        usort($groups, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_merge(...array_map(static fn (array $group): array => $group[2], $groups));
    }

    /**
     * Elements that look locked: a lock icon, a "locked" class, a disabled or aria-disabled control
     *
     * @return list<string>
     */
    private function lockMarkup(Crawler $menu): array
    {
        $found = [];

        foreach ($menu->filter('*') as $element) {
            assert($element instanceof \DOMElement);

            foreach (preg_split('/\s+/', $element->getAttribute('class'), flags: PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
                if (preg_match('/^(bi-lock(-fill)?|ci-locked)$|locked/', $class) === 1) {
                    $found[] = $element->tagName . '.' . $class;
                }
            }

            if ($element->hasAttribute('disabled') || $element->hasAttribute('aria-disabled')) {
                $found[] = $element->tagName . '[disabled]';
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function rowIds(Crawler $menu): array
    {
        return $menu->filter('li[id^="menu-row-"]')->each(static fn (Crawler $row): string => substr((string) $row->attr('id'), strlen('menu-row-')));
    }

    /**
     * @param list<string> $rows
     * @param list<string> $items
     * @return list<string>
     */
    private static function insertAfter(array $rows, string $after, array $items): array
    {
        $position = array_search($after, $rows, true);
        self::assertIsInt($position, $after);

        return [...array_slice($rows, 0, $position + 1), ...$items, ...array_slice($rows, $position + 1)];
    }
}
