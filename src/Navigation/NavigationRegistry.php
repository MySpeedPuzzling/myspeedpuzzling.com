<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The navigation registry (D19, D21): one entry per destination linked by a navigation surface - the bars, the
 * desktop top bar and rail, the Menu (phone panel, docked column, overlay, fallback page), the account menu, the Help
 * sheet, the footer, the community landing and the chips. Every destination is a Menu row, a search-only entry or
 * "not in Menu" with a reason (placement rulebook, README §7).
 *
 * Plain data read at runtime by the Menu, the rail, the page search list and the guards; its human-readable mirror
 * navigation.md is generated from it (myspeedpuzzling:navigation:dump-doc) and never read back.
 *
 * Only routes that exist are registered: each later item adds the entries of its own routes in its own commit
 * (community_place, results, help, where_did_it_go, menu, add_intro, …) - NavigationRegistryTest refuses unknown routes.
 */
final readonly class NavigationRegistry
{
    private const string BAR_TAB = 'Phones: a tab of the bottom bar (D1)';
    private const string SINCE = '2026-10-12';

    /**
     * Built once per service instance: plain request-independent data (no translator, no viewer), so the readonly
     * service keeps no per-request state (FrankenPHP worker mode) and lookups never rebuild the entries.
     *
     * @var list<NavEntry>
     */
    private array $entries;

    /**
     * @var array<string, NavEntry>
     */
    private array $entriesById;

    /**
     * Route => the entries whose row is the current one on it (target, guests' target or activeOn), in registry order.
     *
     * @var array<string, list<NavEntry>>
     */
    private array $entriesByRoute;

    /**
     * Route => the entries that link it (target or guests' target), in registry order.
     *
     * @var array<string, list<NavEntry>>
     */
    private array $entriesByTargetRoute;

    public function __construct(
        private TranslatorInterface $translator,
    ) {
        $entries = $this->build();
        $byId = [];
        $byRoute = [];
        $byTargetRoute = [];

        foreach ($entries as $entry) {
            // The first entry of an id wins; duplicates are refused by NavigationRegistryTest
            $byId[$entry->id] ??= $entry;

            foreach ($entry->target->routes() as $route) {
                $byTargetRoute[$route][] = $entry;
            }

            foreach (array_unique([...$entry->target->routes(), ...$entry->activeOn]) as $route) {
                $byRoute[$route][] = $entry;
            }
        }

        $this->entries = $entries;
        $this->entriesById = $byId;
        $this->entriesByRoute = $byRoute;
        $this->entriesByTargetRoute = $byTargetRoute;
    }

    /**
     * @return list<NavEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * @return list<NavEntry>
     */
    private function build(): array
    {
        return [
            ...$this->places(),
            ...$this->you(),
            ...$this->inbox(),
            ...$this->roles(),
            ...$this->puzzling(),
            ...$this->library(),
            ...$this->community(),
            ...$this->puzzles(),
            ...$this->add(),
            ...$this->account(),
            ...$this->help(),
            ...$this->about(),
            ...$this->searchOnlyList(),
            ...$this->notInMenuList(),
        ];
    }

    public function byId(string $id): null|NavEntry
    {
        return $this->entriesById[$id] ?? null;
    }

    /**
     * Entries whose row is the current one on the route: the route is their target or one of their activeOn routes.
     *
     * @return list<NavEntry>
     */
    public function byRoute(string $route): array
    {
        return $this->entriesByRoute[$route] ?? [];
    }

    /**
     * Entries that link the route (their target or guests' target) - what the chrome guard looks for.
     *
     * @return list<NavEntry>
     */
    public function byTargetRoute(string $route): array
    {
        return $this->entriesByTargetRoute[$route] ?? [];
    }

    /**
     * The rows of one Menu surface in display order (group order, then position).
     *
     * @return list<NavEntry>
     */
    public function rows(MenuSurface $surface): array
    {
        $rows = array_values(array_filter(
            $this->all(),
            static fn (NavEntry $entry): bool => $entry->placement($surface)->isRow(),
        ));

        usort($rows, static function (NavEntry $a, NavEntry $b) use ($surface): int {
            $placementA = $a->placement($surface);
            $placementB = $b->placement($surface);

            return [$placementA->group?->order($surface), $placementA->position]
                <=> [$placementB->group?->order($surface), $placementB->position];
        });

        return $rows;
    }

    /**
     * @return list<NavEntry>
     */
    public function searchOnly(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (NavEntry $entry): bool => $entry->isSearchOnly(),
        ));
    }

    /**
     * @return list<NavEntry>
     */
    public function notInMenu(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (NavEntry $entry): bool => $entry->isNotInMenu(),
        ));
    }

    /**
     * The footer's links in column order (filled by FOOT-01).
     *
     * @return list<NavEntry>
     */
    public function footer(): array
    {
        $entries = array_values(array_filter(
            $this->all(),
            static fn (NavEntry $entry): bool => $entry->footer !== null,
        ));

        usort($entries, static fn (NavEntry $a, NavEntry $b): int => [$a->footer?->column, $a->footer?->position] <=> [$b->footer?->column, $b->footer?->position]);

        return $entries;
    }

    /**
     * The Help sheet's links in order (filled by HELP-02).
     *
     * @return list<NavEntry>
     */
    public function helpSheet(): array
    {
        $entries = array_values(array_filter(
            $this->all(),
            static fn (NavEntry $entry): bool => $entry->helpSheet !== null,
        ));

        usort($entries, static fn (NavEntry $a, NavEntry $b): int => $a->helpSheet <=> $b->helpSheet);

        return $entries;
    }

    /**
     * Where an entry lives in the phone Menu, e.g. "☰ › Account › Your data" (page search, "Where did it go?").
     * A dock-only row is described in the docked column, a search-only entry under its parent; null = not in the Menu.
     */
    public function menuPath(string $id, string $locale): null|string
    {
        $entry = $this->byId($id);

        if ($entry === null) {
            return null;
        }

        $label = $this->label($entry, $locale);

        if ($entry->isSearchOnly()) {
            $parent = $entry->phone->parent;
            $parentPath = $parent !== null && $parent !== $id ? $this->menuPath($parent, $locale) : null;

            return $parentPath === null ? null : $parentPath . ' › ' . $label;
        }

        $placement = $entry->phone->isRow() ? $entry->phone : $entry->dock;
        $group = $placement->group;

        if ($group === null) {
            return null;
        }

        if (!$group->hasHeading() || $entry->kind === RowKind::Place) {
            return '☰ › ' . $label;
        }

        return '☰ › ' . $this->groupTitle($group, $locale) . ' › ' . $label;
    }

    /**
     * The entry's label in the locale (englishOnly role rows: their English label).
     */
    public function label(NavEntry $entry, string $locale): string
    {
        if ($entry->englishOnly && !$this->isTranslationKey($entry->label)) {
            return $entry->label;
        }

        return $this->translator->trans($entry->label, [], null, $locale);
    }

    public function groupTitle(MenuGroup $group, string $locale): string
    {
        if (!$this->isTranslationKey($group->titleKey())) {
            return $group->docTitle();
        }

        return $this->translator->trans($group->titleKey(), [], null, $locale);
    }

    private function isTranslationKey(string $id): bool
    {
        return $this->translator instanceof TranslatorBagInterface
            && $this->translator->getCatalogue('en')->has($id);
    }

    /**
     * The places as rows of the docked column (on phones they are the tabs).
     *
     * @return list<NavEntry>
     */
    private function places(): array
    {
        return [
            new NavEntry(
                id: 'home',
                target: RouteTarget::route('hub', guestRoute: 'homepage'),
                label: 'navigation.home',
                icon: 'bi-house-door',
                place: NavPlace::Home,
                phone: MenuPlacement::notInMenu(self::BAR_TAB),
                dock: MenuPlacement::row(MenuGroup::Home, 10),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q10,
                reason: 'Place Home = the Hub for players, the homepage for guests (D1, D5)',
                since: self::SINCE,
                kind: RowKind::Place,
                legacyLabels: ['menu.hub'],
                activeOn: ['homepage_legacy', 'locale_root'],
            ),
            new NavEntry(
                id: 'puzzles_place',
                target: RouteTarget::route('puzzles'),
                label: 'menu.puzzles_overview',
                icon: 'bi-puzzle',
                place: NavPlace::Puzzles,
                phone: MenuPlacement::notInMenu(self::BAR_TAB),
                dock: MenuPlacement::row(MenuGroup::Puzzles, 0),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q10,
                reason: 'Place Puzzles; the tab opens the last used section (D1, D6)',
                since: self::SINCE,
                kind: RowKind::Place,
                legacyLabels: ['menu.puzzles_overview'],
            ),
            new NavEntry(
                id: 'profile',
                target: RouteTarget::viewer('player_profile'),
                label: 'menu.profile',
                icon: 'bi-person',
                place: NavPlace::Profile,
                phone: MenuPlacement::notInMenu(self::BAR_TAB),
                dock: MenuPlacement::row(MenuGroup::Puzzling, 0),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q10,
                reason: 'Place Profile = your own profile page, content first (D1, D9); heads Your puzzling in the column',
                since: self::SINCE,
                kind: RowKind::Place,
                legacyLabels: ['menu.profile', 'menu.my_profile'],
                activeOn: ['my_profile'],
            ),
        ];
    }

    /**
     * @return list<NavEntry>
     */
    private function you(): array
    {
        return [
            new NavEntry(
                id: 'you_card',
                target: RouteTarget::viewer('player_profile'),
                label: 'menu.my_profile',
                icon: 'bi-person-circle',
                place: NavPlace::Profile,
                phone: MenuPlacement::row(MenuGroup::You, 10),
                dock: MenuPlacement::notInMenu('Desktop: the avatar ▾ account menu (README §4.3)'),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q8,
                reason: 'Avatar, name, View your profile, Settings and the membership status at the top of the panel',
                since: self::SINCE,
                kind: RowKind::YouCard,
                legacyLabels: ['menu.my_profile'],
                count: CountSource::MembershipStatus,
            ),
            new NavEntry(
                id: 'guest_card',
                target: RouteTarget::route('login'),
                label: 'menu.sign_in',
                icon: 'bi-box-arrow-in-right',
                place: NavPlace::None,
                // Guests only, where players get the You card (10)
                phone: MenuPlacement::row(MenuGroup::You, 20),
                dock: MenuPlacement::row(MenuGroup::You, 20),
                visibility: NavVisibility::GuestOnly,
                rule: RulebookQuestion::Q5,
                reason: "Guests' Sign in · Join free card (README §4.12); also in the column, which hides the rail's Sign in (decision 31)",
                since: self::SINCE,
                kind: RowKind::GuestCard,
                legacyLabels: ['menu.sign_in', 'menu.register'],
            ),
        ];
    }

    /**
     * @return list<NavEntry>
     */
    private function inbox(): array
    {
        return [
            new NavEntry(
                id: 'inbox',
                target: RouteTarget::route('notifications'),
                label: 'notifications.title',
                icon: 'bi-inbox',
                place: NavPlace::None,
                phone: MenuPlacement::row(MenuGroup::Inbox, 10),
                dock: MenuPlacement::notInMenu('Desktop: the Inbox tool of the top bar (D3, D16)'),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q5,
                reason: 'Inbox = notifications and messages with one badge (D3)',
                since: self::SINCE,
                legacyLabels: ['notifications.title', 'messaging.title'],
                count: CountSource::InboxBadge,
                activeOn: ['conversations_list', 'conversation_detail'],
                secondary: ['top bar Inbox tool'],
            ),
        ];
    }

    /**
     * Your roles (holders only). Moderator and admin rows keep today's English labels (README §4.12).
     *
     * @return list<NavEntry>
     */
    private function roles(): array
    {
        $row = static fn (int $position): MenuPlacement => MenuPlacement::row(MenuGroup::Roles, $position);

        return [
            new NavEntry(
                id: 'organized_events',
                target: RouteTarget::route('organized_events'),
                label: 'events_organizer.organize.title',
                icon: 'bi-clipboard-check',
                place: NavPlace::Community,
                phone: $row(10),
                dock: $row(10),
                visibility: NavVisibility::Organiser,
                rule: RulebookQuestion::Q3,
                reason: 'Events you organize, for organisers only; also the "You organize" button on the events page',
                since: self::SINCE,
                section: 'events',
                count: CountSource::LazyOrganised,
                secondary: ['events page "You organize" button'],
            ),
            $this->roleRow('mod_change_requests', 'admin_puzzle_change_requests', 'Puzzle Change Requests', 'bi-pencil-square', 20, NavVisibility::Moderator, CountSource::QueueChangeRequests, ['admin_puzzle_change_request_detail']),
            $this->roleRow('mod_merge_requests', 'admin_puzzle_merge_requests', 'Puzzle Merge Requests', 'bi-intersect', 30, NavVisibility::Moderator, CountSource::QueueMergeRequests, ['admin_puzzle_merge_request_detail']),
            $this->roleRow('mod_puzzle_approvals', 'admin_puzzle_approvals', 'Puzzle Approvals', 'bi-patch-check', 40, NavVisibility::Moderator, CountSource::QueuePuzzleApprovals, ['admin_puzzle_approval_detail', 'admin_edit_puzzle', 'admin_puzzle_history']),
            $this->roleRow('mod_time_verification', 'admin_time_verification', 'Time verification', 'bi-stopwatch-fill', 50, NavVisibility::TimeVerifier, CountSource::QueueTimeVerification),
            $this->roleRow('admin_competition_approvals', 'admin_competition_approvals', 'Competition Approvals', 'bi-calendar-check', 60, NavVisibility::Admin, CountSource::QueueCompetitionApprovals),
            $this->roleRow('admin_duplicate_results', 'admin_duplicate_results', 'Duplicate results', 'bi-files', 70, NavVisibility::Admin, CountSource::QueueDuplicateSignals),
            $this->roleRow('admin_oauth2_requests', 'admin_oauth2_client_requests', 'OAuth2 Requests', 'bi-braces', 80, NavVisibility::Admin, CountSource::QueueOauth2Requests, ['admin_oauth2_client_request_detail']),
            $this->roleRow('admin_moderation', 'admin_moderation_dashboard', 'Moderation', 'bi-shield-exclamation', 90, NavVisibility::Admin, null, ['admin_moderation_history', 'admin_report_detail', 'admin_conversation_log']),
            $this->roleRow('admin_vouchers', 'admin_vouchers', 'Vouchers', 'bi-ticket-perforated', 100, NavVisibility::Admin),
            $this->roleRow('admin_referrals', 'admin_referrals', 'Referrals', 'bi-people', 110, NavVisibility::Admin, null, ['admin_referral_detail']),
            $this->roleRow('admin_email_audit', 'admin_email_audit_list', 'Email Audit', 'bi-envelope-check', 120, NavVisibility::Admin, null, ['admin_email_audit_detail']),
            $this->roleRow('admin_moderators', 'admin_moderators', 'Community Moderators', 'bi-person-badge', 130, NavVisibility::Admin),
            $this->roleRow('admin_free_trial', 'admin_free_trial', 'Free trial & modals', 'bi-gift', 140, NavVisibility::Admin),
            $this->roleRow('admin_api_usage', 'admin_api_usage', 'API usage', 'bi-graph-up', 150, NavVisibility::Admin),
        ];
    }

    /**
     * @param list<string> $activeOn
     */
    private function roleRow(
        string $id,
        string $route,
        string $englishLabel,
        string $icon,
        int $position,
        NavVisibility $visibility,
        null|CountSource $count = null,
        array $activeOn = [],
    ): NavEntry {
        return new NavEntry(
            id: $id,
            target: RouteTarget::route($route),
            label: $englishLabel,
            icon: $icon,
            place: NavPlace::None,
            phone: MenuPlacement::row(MenuGroup::Roles, $position),
            dock: MenuPlacement::row(MenuGroup::Roles, $position),
            visibility: $visibility,
            rule: RulebookQuestion::Q3,
            reason: 'Review queue or admin page: holders only, moved from the admin key menu',
            since: self::SINCE,
            count: $count,
            activeOn: $activeOn,
            englishOnly: true,
        );
    }

    /**
     * Your puzzling (the "You" place row heads it in the column).
     *
     * @return list<NavEntry>
     */
    private function puzzling(): array
    {
        $row = static fn (int $position): MenuPlacement => MenuPlacement::row(MenuGroup::Puzzling, $position);

        return [
            new NavEntry(
                id: 'favorites',
                target: RouteTarget::viewer('player_favorite_puzzlers'),
                label: 'player_header.tabs.favorites',
                icon: 'bi-star',
                place: NavPlace::Profile,
                phone: $row(20),
                dock: $row(20),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q8,
                reason: 'Owner-only view of your own data: player tab and Menu row',
                since: self::SINCE,
                legacyLabels: ['menu.favorite_puzzlers'],
                secondary: ['player header tab Favorites'],
            ),
            new NavEntry(
                id: 'statistics',
                target: RouteTarget::viewer('player_statistics'),
                label: 'player_header.tabs.statistics',
                icon: 'bi-bar-chart-line',
                place: NavPlace::Profile,
                phone: $row(30),
                dock: $row(30),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q8,
                reason: 'About one player, others may see it: player tab; your own also a Menu row',
                since: self::SINCE,
                legacyLabels: ['menu.statistics'],
                secondary: ['player header tab Statistics'],
            ),
            new NavEntry(
                id: 'compare',
                target: RouteTarget::route('comparison'),
                label: 'comparison.title',
                icon: 'bi-layout-split',
                place: NavPlace::Community,
                phone: $row(40),
                dock: $row(40),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q8,
                reason: 'Your line-ups (Community › Puzzlers is its home, README §7)',
                since: self::SINCE,
                section: 'puzzlers',
                count: CountSource::LineUp,
                activeOn: ['compare_players'],
                secondary: ['player header compare button', 'Players page'],
            ),
            new NavEntry(
                id: 'review_results',
                target: RouteTarget::route('review_results'),
                label: 'review_results.page.title',
                icon: 'bi-check2-square',
                place: NavPlace::Profile,
                phone: $row(50),
                dock: $row(50),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q8,
                reason: 'Owner manages own results (duplicates, first tries, verification)',
                since: self::SINCE,
                count: CountSource::LazyReview,
                activeOn: ['first_try_conflicts'],
                secondary: ['Hub banner', 'own profile banner'],
            ),
            new NavEntry(
                id: 'pairs_and_teams',
                target: RouteTarget::route('pairs_and_teams'),
                label: 'menu.pairs_and_teams',
                icon: 'bi-people-fill',
                place: NavPlace::Profile,
                phone: $row(60),
                dock: $row(60),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q8,
                reason: 'Owner manages own pairs, teams and guests',
                since: self::SINCE,
                legacyLabels: ['menu.pairs_and_teams'],
                activeOn: ['pairs_and_teams_guest_link'],
            ),
            new NavEntry(
                id: 'calendar',
                target: RouteTarget::viewer('activity_calendar'),
                label: 'player_header.tabs.calendar',
                icon: 'bi-calendar3',
                place: NavPlace::Profile,
                phone: $row(70),
                dock: $row(70),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q8,
                reason: 'About one player: player tab; your own also a Menu row',
                since: self::SINCE,
                legacyLabels: ['menu.activity_calendar'],
                secondary: ['player header tab Calendar'],
            ),
            new NavEntry(
                id: 'achievements',
                target: RouteTarget::viewer('player_achievements'),
                label: 'player_header.tabs.achievements',
                icon: 'bi-award',
                place: NavPlace::Profile,
                phone: $row(80),
                dock: $row(80),
                visibility: NavVisibility::XpPlayer,
                rule: RulebookQuestion::Q8,
                reason: 'About one player: player tab; your own also a Menu row (XP gate)',
                since: self::SINCE,
                legacyLabels: ['badges.my_title'],
                activeOn: ['badge_reveals'],
                secondary: ['player header tab Achievements'],
            ),
            new NavEntry(
                id: 'level_xp',
                target: RouteTarget::route('xp_history'),
                label: 'progression_hub.level.title',
                icon: 'bi-receipt',
                place: NavPlace::Profile,
                phone: $row(90),
                dock: $row(90),
                visibility: NavVisibility::XpParticipant,
                rule: RulebookQuestion::Q8,
                reason: 'Owner-only view of your own XP (XP gate, not opted out)',
                since: self::SINCE,
                legacyLabels: ['xp.history.title'],
                count: CountSource::Level,
                activeOn: ['xp_launch_reveal'],
            ),
            new NavEntry(
                id: 'karma',
                target: RouteTarget::route('karma_history'),
                label: 'xp.leaderboard.board.karma.tab',
                icon: 'bi-heart',
                place: NavPlace::Profile,
                phone: $row(100),
                dock: $row(100),
                visibility: NavVisibility::KarmaHolder,
                rule: RulebookQuestion::Q8,
                reason: 'Owner-only view of your own karma (holders who did not hide it)',
                since: self::SINCE,
                legacyLabels: ['karma.history.title'],
                count: CountSource::Karma,
            ),
            new NavEntry(
                id: 'your_week',
                target: RouteTarget::route('my_activity'),
                label: 'my_activity.title.week',
                icon: 'bi-calendar-week',
                place: NavPlace::Profile,
                phone: $row(110),
                dock: $row(110),
                visibility: NavVisibility::XpPlayer,
                rule: RulebookQuestion::Q8,
                reason: 'Owner-only view of your own week (XP gate)',
                since: self::SINCE,
            ),
        ];
    }

    /**
     * Your puzzles: the library and its lists.
     *
     * @return list<NavEntry>
     */
    private function library(): array
    {
        $row = static fn (int $position): MenuPlacement => MenuPlacement::row(MenuGroup::Library, $position);

        return [
            new NavEntry(
                id: 'library',
                target: RouteTarget::viewer('puzzle_library'),
                label: 'player_header.tabs.library',
                icon: 'bi-collection',
                place: NavPlace::Puzzles,
                phone: $row(10),
                dock: $row(10),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q8,
                reason: 'Owner manages own puzzles; also Puzzles › My library and the player tab Library',
                since: self::SINCE,
                section: 'library',
                legacyLabels: ['menu.puzzle_library'],
                activeOn: ['legacy_player_collections'],
                secondary: ['Puzzles › My library chip', 'player header tab Library'],
            ),
            $this->listRow('collection', 'system_collection_detail', 'collections.system_name', 'bi-box-seam', 20, null, ['collection_detail', 'edit_system_collection']),
            new NavEntry(
                id: 'what_next',
                target: RouteTarget::route('puzzle_picker'),
                label: 'puzzle_picker.menu_label',
                icon: 'bi-shuffle',
                place: NavPlace::Puzzles,
                phone: $row(30),
                dock: $row(30),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q8,
                reason: 'Picks from your own library; also Puzzles › What next?',
                since: self::SINCE,
                section: 'next',
                legacyLabels: ['puzzle_picker.menu_label'],
                secondary: ['Puzzles › What next? chip'],
            ),
            $this->listRow('unsolved', 'unsolved_puzzles_detail', 'puzzle_search.list.unsolved', 'bi-hourglass-split', 40, 'unsolved_puzzles.title', ['edit_unsolved_puzzles']),
            $this->listRow('sell_swap', 'sell_swap_list_detail', 'puzzle_search.list.sell_swap', 'bi-arrow-left-right', 50, 'sell_swap_list.title', ['edit_sell_swap_list_settings']),
            $this->listRow('solved', 'solved_puzzles_detail', 'status.solved', 'bi-check2-circle', 60, 'solved_puzzles.title', ['edit_solved_puzzles']),
            $this->listRow('lent_borrowed', 'lend_borrow_list_detail', 'lend_borrow.title', 'bi-arrow-repeat', 70, null, ['edit_lend_borrow_list_settings']),
            $this->listRow('wishlist', 'wish_list_detail', 'puzzle_search.list.wishlist', 'bi-gift', 80, 'wish_list.title', ['edit_wish_list']),
        ];
    }

    /**
     * Community and its sections (Leaderboards · Puzzlers · Events). The Community place row arrives with its landing
     * page (COM-01a).
     *
     * @return list<NavEntry>
     */
    private function community(): array
    {
        $row = static fn (int $position): MenuPlacement => MenuPlacement::row(MenuGroup::Community, $position);
        $ladders = [];

        foreach (['solo', 'pairs', 'groups'] as $category) {
            foreach (['500', '1000'] as $pieces) {
                $ladders[] = 'ladder_' . $category . '_' . $pieces . '_pieces';
                $ladders[] = 'ladder_' . $category . '_' . $pieces . '_pieces_country';
            }
        }

        return [
            new NavEntry(
                id: 'leaderboards',
                target: RouteTarget::route('ladder'),
                label: 'menu.ladder',
                icon: 'bi-trophy',
                place: NavPlace::Community,
                phone: $row(10),
                dock: $row(10),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q9,
                reason: 'Rankings are community content (Community › Leaderboards)',
                since: self::SINCE,
                section: 'leaderboards',
                legacyLabels: ['menu.ladder'],
                activeOn: ['ladder_country', ...$ladders],
                secondary: ['Community landing', 'section chips'],
            ),
            new NavEntry(
                id: 'msp_rating',
                target: RouteTarget::route('msp_rating_ladder'),
                label: 'ladder.board.msp_rating',
                icon: 'bi-graph-up-arrow',
                place: NavPlace::Community,
                phone: $row(20),
                dock: $row(20),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q9,
                reason: 'A ranking: Community › Leaderboards',
                since: self::SINCE,
                section: 'leaderboards',
                activeOn: ['legacy_msp_elo_ladder'],
            ),
            new NavEntry(
                id: 'level_boards',
                target: RouteTarget::route('level_leaderboard'),
                label: 'xp.leaderboard.board.xp.title',
                icon: 'bi-bar-chart-steps',
                place: NavPlace::Community,
                phone: $row(30),
                dock: $row(30),
                visibility: NavVisibility::XpVisible,
                rule: RulebookQuestion::Q9,
                reason: 'Level, achievement and karma boards: Community › Leaderboards (XP gate)',
                since: self::SINCE,
                section: 'leaderboards',
                activeOn: ['achievement_points_leaderboard', 'karma_leaderboard', 'legacy_xp_leaderboard'],
            ),
            new NavEntry(
                id: 'puzzlers',
                target: RouteTarget::route('players'),
                label: 'menu.players',
                icon: 'bi-people',
                place: NavPlace::Community,
                phone: $row(40),
                dock: $row(40),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q9,
                reason: 'People: Community › Puzzlers',
                since: self::SINCE,
                section: 'puzzlers',
                legacyLabels: ['menu.players'],
                activeOn: ['players_per_country'],
                secondary: ['Community landing', 'section chips'],
            ),
            new NavEntry(
                id: 'all_puzzlers',
                target: RouteTarget::route('players_directory'),
                label: 'players.directory.title',
                icon: 'bi-person-lines-fill',
                place: NavPlace::Community,
                phone: $row(50),
                dock: $row(50),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q9,
                reason: 'People: Community › Puzzlers',
                since: self::SINCE,
                section: 'puzzlers',
            ),
            new NavEntry(
                id: 'events',
                target: RouteTarget::route('events'),
                label: 'menu.events',
                icon: 'bi-calendar-event',
                place: NavPlace::Community,
                phone: $row(60),
                dock: $row(60),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q9,
                reason: 'Events: Community › Events',
                since: self::SINCE,
                section: 'events',
                legacyLabels: ['menu.events'],
                activeOn: ['events_archive', 'event_detail', 'event_round_results', 'competition_series_detail', 'edition_detail', 'edition_detail_legacy', 'edition_round_results'],
                secondary: ['Community landing', 'section chips'],
            ),
            new NavEntry(
                id: 'organizations',
                target: RouteTarget::route('organizations'),
                label: 'organization.directory_title',
                icon: 'bi-building',
                place: NavPlace::Community,
                phone: $row(70),
                dock: $row(70),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q9,
                reason: 'Events: Community › Events',
                since: self::SINCE,
                section: 'events',
                activeOn: ['organization_detail'],
            ),
            new NavEntry(
                id: 'latest_times',
                target: RouteTarget::route('recent_activity'),
                label: 'footer.latest_puzzle_times',
                icon: 'bi-clock-history',
                place: NavPlace::Home,
                phone: $row(80),
                dock: $row(80),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q9,
                reason: "Everyone's latest times (its page belongs to Home, README §3)",
                since: self::SINCE,
                legacyLabels: ['footer.latest_puzzle_times'],
                secondary: ['Hub feed'],
            ),
        ];
    }

    /**
     * Puzzles and its sections; My library and What next? are rows of Your puzzles.
     *
     * @return list<NavEntry>
     */
    private function puzzles(): array
    {
        $row = static fn (int $position): MenuPlacement => MenuPlacement::row(MenuGroup::Puzzles, $position);

        return [
            new NavEntry(
                id: 'all_puzzles',
                target: RouteTarget::route('puzzles'),
                label: 'footer.puzzle_database',
                icon: 'bi-grid-3x3-gap',
                place: NavPlace::Puzzles,
                phone: $row(10),
                dock: $row(10),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q9,
                reason: 'The catalogue: Puzzles › All puzzles',
                since: self::SINCE,
                section: 'all',
                legacyLabels: ['menu.puzzles_overview', 'footer.puzzle_database'],
                activeOn: ['puzzles_plural_redirect'],
                secondary: ['Puzzles › All puzzles chip'],
            ),
            new NavEntry(
                id: 'brands',
                target: RouteTarget::route('puzzle_brands'),
                label: 'footer.group.brands',
                icon: 'bi-tags',
                place: NavPlace::Puzzles,
                phone: $row(20),
                dock: $row(20),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q9,
                reason: 'The catalogue by brand and piece count',
                since: self::SINCE,
                activeOn: [
                    'brand_puzzles',
                    'brand_puzzles_page',
                    'brand_pieces_puzzles',
                    'brand_pieces_puzzles_page',
                    'brand_easiest_puzzles',
                    'brand_hardest_puzzles',
                    'pieces_puzzles',
                    'pieces_puzzles_page',
                    'pieces_easiest_puzzles',
                    'pieces_hardest_puzzles',
                ],
            ),
            new NavEntry(
                id: 'marketplace',
                target: RouteTarget::route('marketplace'),
                label: 'marketplace.title',
                icon: 'bi-shop',
                place: NavPlace::Puzzles,
                phone: $row(30),
                dock: $row(30),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q9,
                reason: 'Offers of puzzles: Puzzles › Marketplace',
                since: self::SINCE,
                section: 'marketplace',
                activeOn: ['marketplace_puzzle'],
                secondary: ['Puzzles › Marketplace chip'],
            ),
        ];
    }

    /**
     * Add & track.
     *
     * @return list<NavEntry>
     */
    private function add(): array
    {
        $row = static fn (int $position): MenuPlacement => MenuPlacement::row(MenuGroup::Add, $position);

        return [
            new NavEntry(
                id: 'add',
                target: RouteTarget::route('puzzle_add'),
                label: 'menu.puzzle_add',
                icon: 'bi-plus-lg',
                place: NavPlace::Add,
                phone: $row(10),
                dock: $row(10),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q4,
                reason: 'Records a time or a puzzle: the Add page (D7); also the Add tab and the + Add button',
                since: self::SINCE,
                legacyLabels: ['menu.puzzle_add', 'footer.puzzle_add'],
                activeOn: ['legacy_add_time', 'edit_time', 'added_time_recap', 'added_tracking_recap'],
                secondary: ['Add tab', 'desktop + Add button'],
            ),
            new NavEntry(
                id: 'stopwatch',
                target: RouteTarget::route('stopwatch'),
                label: 'menu.stopwatch',
                icon: 'bi-stopwatch',
                place: NavPlace::Add,
                phone: $row(20),
                dock: $row(20),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q4,
                reason: 'Records a time: Add › Speed puzzling › Use the stopwatch',
                since: self::SINCE,
                legacyLabels: ['menu.stopwatch'],
                count: CountSource::Stopwatch,
                activeOn: ['stopwatch_puzzle', 'finish_stopwatch'],
                secondary: ['Add page', 'running stopwatch pill'],
            ),
            new NavEntry(
                id: 'multiscan',
                target: RouteTarget::route('multiscan'),
                label: 'multiscan.title',
                icon: 'bi-upc-scan',
                place: NavPlace::Add,
                phone: $row(30),
                dock: $row(30),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q4,
                reason: 'Records puzzles: Add › Collection › Scan several boxes',
                since: self::SINCE,
                legacyLabels: ['multiscan.title'],
                secondary: ['Add page'],
            ),
            new NavEntry(
                id: 'add_event',
                target: RouteTarget::route('add_competition'),
                label: 'competition.add_event',
                icon: 'bi-calendar-plus',
                place: NavPlace::Community,
                phone: $row(40),
                dock: $row(40),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q4,
                reason: 'Creates an event; also the button on the events page',
                since: self::SINCE,
                section: 'events',
                secondary: ['events page button'],
            ),
        ];
    }

    /**
     * Account.
     *
     * @return list<NavEntry>
     */
    private function account(): array
    {
        $row = static fn (int $position): MenuPlacement => MenuPlacement::row(MenuGroup::Account, $position);

        return [
            new NavEntry(
                id: 'membership',
                target: RouteTarget::route('membership'),
                label: 'menu.membership',
                icon: 'bi-gem',
                place: NavPlace::Profile,
                phone: $row(10),
                dock: $row(10),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q6,
                reason: 'Your membership and its status; guests reach it from the footer',
                since: self::SINCE,
                legacyLabels: ['menu.membership'],
                count: CountSource::MembershipStatus,
                activeOn: ['buy_membership', 'free_trial_started', 'stripe_checkout_success'],
                secondary: ['You card status line', 'avatar ▾ menu'],
            ),
            new NavEntry(
                id: 'settings',
                target: RouteTarget::route('edit_profile'),
                label: 'menu.edit_profile',
                icon: 'bi-gear',
                place: NavPlace::Profile,
                phone: $row(20),
                dock: $row(20),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q6,
                reason: 'Configures your account (name, photo, country, sign-in, privacy, e-mails)',
                since: self::SINCE,
                legacyLabels: ['menu.edit_profile'],
                activeOn: ['edit_player_code'],
                secondary: ['You card ⚙', 'profile header Edit profile'],
            ),
            new NavEntry(
                id: 'referral',
                target: RouteTarget::route('affiliate_dashboard'),
                label: 'menu.referral_program',
                icon: 'bi-megaphone',
                place: NavPlace::Profile,
                phone: $row(30),
                dock: $row(30),
                visibility: NavVisibility::MemberOrReferral,
                rule: RulebookQuestion::Q6,
                reason: 'Members or players in the programme only',
                since: self::SINCE,
                legacyLabels: ['menu.referral_program'],
            ),
            new NavEntry(
                id: 'your_data',
                target: RouteTarget::viewer('export_puzzler_data'),
                label: 'menu.export_data',
                icon: 'bi-download',
                place: NavPlace::Profile,
                phone: $row(40),
                dock: $row(40),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q6,
                reason: 'Export results and library, delete the account',
                since: self::SINCE,
                legacyLabels: ['menu.export_data'],
            ),
            new NavEntry(
                id: 'blocked_players',
                target: RouteTarget::route('blocked_users'),
                label: 'blocklist.title',
                icon: 'bi-slash-circle',
                place: NavPlace::Profile,
                phone: $row(50),
                dock: $row(50),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q6,
                reason: 'Configures who you see',
                since: self::SINCE,
            ),
            new NavEntry(
                id: 'profile_viewers',
                target: RouteTarget::route('private_profile_viewers'),
                label: 'private_profile_viewers.title',
                icon: 'bi-eye-slash',
                place: NavPlace::Profile,
                phone: $row(60),
                dock: $row(60),
                visibility: NavVisibility::PrivateMember,
                rule: RulebookQuestion::Q6,
                reason: 'Configures who sees your private profile (members with a private profile)',
                since: self::SINCE,
            ),
        ];
    }

    /**
     * Help & learn.
     *
     * @return list<NavEntry>
     */
    private function help(): array
    {
        $row = static fn (int $position): MenuPlacement => MenuPlacement::row(MenuGroup::Help, $position);
        $guides = ['guide_average_puzzle_time', 'guide_puzzle_time_by_pieces', 'guide_puzzle_time_for_pieces', 'guide_puzzle_time_pairs_and_teams', 'guide_speed_puzzling_tips', 'guide_what_is_speed_puzzling'];

        return [
            // Opens the feedback sheet (today the feedback page in modal-frame); its target becomes the Help sheet
            // route with HELP-01a, and feedback stays the sheet's form endpoint (decision 76)
            new NavEntry(
                id: 'send_feedback',
                target: RouteTarget::route('feedback'),
                label: 'menu.feedback',
                icon: 'bi-chat-heart',
                place: NavPlace::None,
                phone: $row(10),
                dock: $row(10),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q7,
                reason: 'Collects feedback: opens the Help sheet (D17); guests get "Sign in to send feedback" there',
                since: self::SINCE,
                kind: RowKind::HelpSheet,
                legacyLabels: ['menu.feedback'],
                secondary: ['top bar ? / Feedback', 'end-of-page line'],
            ),
            $this->helpRow('methodology', 'methodology', 'footer.methodology', 'bi-rulers', 20),
            $this->helpRow('feature_requests', 'feature_requests', 'menu.feature_requests', 'bi-lightbulb', 30, ['feature_request_detail', 'feature_request_edit']),
            $this->helpRow('getting_started', 'getting_started', 'onboarding.guide.link', 'bi-signpost-2', 40),
            $this->helpRow('faq', 'faq', 'menu.faq', 'bi-patch-question', 50),
            $this->helpRow('guides', 'guides', 'footer.guides', 'bi-book', 60, $guides),
            $this->helpRow('progression', 'progression_hub', 'progression_hub.menu_label', 'bi-question-circle', 70, [], NavVisibility::XpVisible),
            new NavEntry(
                id: 'language',
                target: RouteTarget::currentPage(),
                label: 'footer.language',
                icon: 'bi-translate',
                place: NavPlace::None,
                phone: $row(90),
                dock: $row(90),
                visibility: NavVisibility::Everyone,
                rule: RulebookQuestion::Q7,
                reason: 'Inline language switch: the current page in the other locales',
                since: self::SINCE,
                kind: RowKind::Language,
                secondary: ['footer language menu', 'Help sheet'],
            ),
        ];
    }

    /**
     * About (legal = one row of three links: the Legal entries share a position).
     *
     * @return list<NavEntry>
     */
    private function about(): array
    {
        return [
            $this->aboutRow('tracker_app', 'puzzle_tracker_app', 'footer.puzzle_tracker_app', 'bi-phone', 10),
            $this->aboutRow('wjpc', 'wjpc_hub', 'footer.wjpc', 'bi-globe2', 20, RowKind::Link, NavPlace::Community, 'events'),
            $this->aboutRow('developers', 'for_developers', 'menu.for_developers', 'bi-code-slash', 30),
            $this->aboutRow('organizers', 'for_organizers', 'menu.for_organizers', 'bi-easel', 40),
            $this->aboutRow('legal_terms', 'terms_of_service', 'menu.terms_of_service', 'bi-file-text', 50, RowKind::Legal),
            $this->aboutRow('legal_privacy', 'privacy_policy', 'menu.privacy_policy', 'bi-shield-lock', 50, RowKind::Legal),
            $this->aboutRow('legal_data_deletion', 'data_deletion', 'menu.data_deletion', 'bi-trash', 50, RowKind::Legal),
            new NavEntry(
                id: 'logout',
                target: RouteTarget::route('logout'),
                label: 'my_profile.logout',
                icon: 'bi-box-arrow-right',
                place: NavPlace::None,
                phone: MenuPlacement::row(MenuGroup::LogOut, 10),
                dock: MenuPlacement::notInMenu('Desktop: Log out in the avatar ▾ account menu (README §4.3)'),
                visibility: NavVisibility::SignedIn,
                rule: RulebookQuestion::Q6,
                reason: 'Last row of the phone panel, visually separated',
                since: self::SINCE,
                kind: RowKind::LogOut,
                legacyLabels: ['my_profile.logout'],
            ),
        ];
    }

    /**
     * Found by the Menu search only (README §4.12 "Search-only entries"); each points to its parent page or anchor.
     *
     * @return list<NavEntry>
     */
    private function searchOnlyList(): array
    {
        $settings = 'A setting on Settings: found by search (README §7 Q6)';
        $ladders = [];

        foreach (['solo' => 'ladder.solo', 'pairs' => 'ladder.duo', 'groups' => 'ladder.team'] as $category => $label) {
            foreach (['500', '1000'] as $pieces) {
                $ladders[] = $this->searchEntry('ladder_' . $category . '_' . $pieces, RouteTarget::route('ladder_' . $category . '_' . $pieces . '_pieces'), $label, 'bi-trophy', 'leaderboards', NavPlace::Community, RulebookQuestion::Q9, 'A ranking per category and piece count: Leaderboards', NavVisibility::Everyone, 'leaderboards');
            }
        }

        return [
            $this->searchEntry('change_password', RouteTarget::route('change_account_password'), 'edit_profile.change_password', 'bi-key', 'settings', NavPlace::Profile, RulebookQuestion::Q6, $settings),
            $this->searchEntry('change_email', RouteTarget::route('change_account_email'), 'edit_profile.change_email', 'bi-envelope-at', 'settings', NavPlace::Profile, RulebookQuestion::Q6, $settings),
            $this->searchEntry('set_password', RouteTarget::route('account_set_password'), 'edit_profile.social.set_password_button', 'bi-key', 'settings', NavPlace::Profile, RulebookQuestion::Q6, $settings),
            $this->searchEntry('sign_in_methods', RouteTarget::anchor('edit_profile', 'sign-in-methods'), 'edit_profile.social.title', 'bi-shield-check', 'settings', NavPlace::Profile, RulebookQuestion::Q6, $settings),
            $this->searchEntry('recent_sign_ins', RouteTarget::route('account_recent_activity'), 'account_activity.title', 'bi-clock-history', 'settings', NavPlace::Profile, RulebookQuestion::Q6, $settings),
            $this->searchEntry('email_settings', RouteTarget::anchor('edit_profile', 'emails'), 'edit_profile.messaging_notifications', 'bi-envelope', 'settings', NavPlace::Profile, RulebookQuestion::Q6, $settings),
            $this->searchEntry('delete_account', RouteTarget::anchor('edit_profile', 'danger-zone'), 'account_deletion.confirm.title', 'bi-person-x', 'settings', NavPlace::Profile, RulebookQuestion::Q6, $settings),
            $this->searchEntry('player_code', RouteTarget::anchor('edit_profile', 'player-code'), 'edit_profile.player_code_change', 'bi-hash', 'settings', NavPlace::Profile, RulebookQuestion::Q6, 'A member setting on Settings: found by search', NavVisibility::Member),
            $this->searchEntry('api_tokens', RouteTarget::route('api_usage'), 'api_usage.title', 'bi-braces', 'settings', NavPlace::Profile, RulebookQuestion::Q6, 'API tokens and their usage: found by search'),
            $this->searchEntry('request_api_access', RouteTarget::route('request_api_access'), 'request_api_access.meta.title', 'bi-braces', 'developers', NavPlace::Profile, RulebookQuestion::Q6, 'Developers only: For developers and search'),
            $this->searchEntry('fair_use', RouteTarget::route('fair_use_policy'), 'fair_use_policy.meta.title', 'bi-file-earmark-check', 'developers', NavPlace::None, RulebookQuestion::Q7, 'API terms: For developers and search', NavVisibility::Everyone),
            $this->searchEntry('redeem_voucher', RouteTarget::route('claim_voucher'), 'membership.redeem_voucher', 'bi-ticket-perforated', 'membership', NavPlace::Profile, RulebookQuestion::Q6, 'A step of Membership: found by search'),
            $this->searchEntry('new_collection', RouteTarget::route('create_collection'), 'collections.create.button', 'bi-folder-plus', 'library', NavPlace::Puzzles, RulebookQuestion::Q1, 'Action on your library (members): a button there and search', NavVisibility::Member, 'library'),
            $this->searchEntry('sold_swapped_history', RouteTarget::viewer('sold_swapped_history'), 'sell_swap_list.history.title', 'bi-archive', 'sell_swap', NavPlace::Puzzles, RulebookQuestion::Q8, 'History of your sell/swap list: a button there and search', NavVisibility::SignedIn, 'library'),
            $this->searchEntry('lending_history', RouteTarget::viewer('lend_borrow_history'), 'lend_borrow.history.title', 'bi-archive', 'lent_borrowed', NavPlace::Puzzles, RulebookQuestion::Q8, 'History of your lending: a button there and search', NavVisibility::SignedIn, 'library'),
            $this->searchEntry('marketplace_how', RouteTarget::route('marketplace_how_it_works'), 'marketplace.how_it_works.title', 'bi-question-circle', 'marketplace', NavPlace::Puzzles, RulebookQuestion::Q7, 'Explains the Marketplace: linked there and found by search', NavVisibility::Everyone),
            $this->searchEntry('past_events', RouteTarget::route('events_archive', dynamic: ['year' => RouteParam::CurrentYear]), 'events_page.archive.title', 'bi-archive', 'events', NavPlace::Community, RulebookQuestion::Q9, 'The events archive: linked from the events page and found by search', NavVisibility::Everyone, 'events'),
            $this->searchEntry('add_organization', RouteTarget::route('add_organization'), 'organization_page.directory.add', 'bi-building-add', 'organizations', NavPlace::Community, RulebookQuestion::Q4, 'Creates an organization: a button on Organizations and search', NavVisibility::SignedIn, 'events'),
            $this->searchEntry('suggest_feature', RouteTarget::route('feature_request_create'), 'feature_requests.create_title', 'bi-lightbulb', 'feature_requests', NavPlace::None, RulebookQuestion::Q1, 'Action on Ideas & feature requests (members): a button there and search', NavVisibility::Member),
            ...$ladders,
            $this->searchEntry('country_ranking', RouteTarget::route('ladder_country', dynamic: ['countryCode' => RouteParam::ViewerCountryCode]), 'ladder.country', 'bi-flag', 'leaderboards', NavPlace::Community, RulebookQuestion::Q9, "Your country's ranking (members): a filter of Leaderboards and search", NavVisibility::Member, 'leaderboards'),
            $this->searchEntry('achievements_list', RouteTarget::route('badges_overview'), 'badges.overview_title', 'bi-award', 'achievements', NavPlace::Community, RulebookQuestion::Q9, 'Every achievement: footer, achievements pages and search (XP gate)', NavVisibility::XpVisible, 'leaderboards'),
            $this->searchEntry('xp_explainer', RouteTarget::route('xp_explainer'), 'xp.explainer.title', 'bi-question-circle', 'progression', NavPlace::None, RulebookQuestion::Q7, 'Explains XP: How progress works and search (XP gate)', NavVisibility::XpVisible),
            $this->searchEntry('fair_play_xp', RouteTarget::route('xp_fair_play'), 'xp.fair_play.title', 'bi-shield-check', 'progression', NavPlace::None, RulebookQuestion::Q7, 'Explains fair play: How progress works and search (XP gate)', NavVisibility::XpVisible),
            $this->searchEntry('karma_explainer', RouteTarget::route('karma_explainer'), 'karma.explainer.title', 'bi-question-circle', 'progression', NavPlace::None, RulebookQuestion::Q7, 'Explains karma: How progress works and search (XP gate)', NavVisibility::XpVisible),
            $this->searchEntry('first_try_conflicts', RouteTarget::route('review_results'), 'first_try.page.title', 'bi-check2-square', 'review_results', NavPlace::Profile, RulebookQuestion::Q8, 'Old name of Review your results (the old URL redirects there)'),
            $this->searchEntry('hub_guest', RouteTarget::route('hub'), 'menu.hub', 'bi-house-door', 'home', NavPlace::Home, RulebookQuestion::Q10, "Guests' Home is the homepage (D5); the old guest hamburger link to the Hub stays findable (R11)", NavVisibility::GuestOnly),
        ];
    }

    /**
     * Linked by a navigation surface but not a Menu destination, with the reason (README §7).
     *
     * @return list<NavEntry>
     */
    private function notInMenuList(): array
    {
        $seoHub = 'Footer popular searches (SEO hub); reached from Brands and the catalogue filters';

        return [
            $this->notInMenuEntry('notifications', RouteTarget::route('notifications'), 'notifications.title', 'bi-bell', NavPlace::None, RulebookQuestion::Q5, "Inbox tab For you, behind the Inbox row and the top-bar Inbox tool; guests have no Inbox (today's guest bell leads to sign in)"),
            $this->notInMenuEntry('messages', RouteTarget::route('conversations_list'), 'messaging.title', 'bi-chat-dots', NavPlace::None, RulebookQuestion::Q5, 'Inbox tab Messages (D3)', NavVisibility::SignedIn),
            $this->notInMenuEntry('register', RouteTarget::route('register'), 'menu.register', 'bi-person-plus', NavPlace::None, RulebookQuestion::Q5, "The guest card's Join free link", NavVisibility::GuestOnly),
            $this->notInMenuEntry('my_profile', RouteTarget::route('my_profile'), 'menu.my_profile', 'bi-person', NavPlace::Profile, RulebookQuestion::Q8, 'Legacy redirect (your profile, or sign in for guests); the Profile tab and the You card link player_profile directly (D9)'),
            $this->notInMenuEntry('player_page', RouteTarget::route('player_profile'), 'menu.profile', 'bi-person', NavPlace::Community, RulebookQuestion::Q2, "Object page: others' profiles are reached from lists, search and the player card"),
            $this->notInMenuEntry('player_card', RouteTarget::route('player_card'), 'menu.profile', 'bi-person-vcard', NavPlace::Community, RulebookQuestion::Q2, 'Layer of a player name (D12); its page is the no-JS fallback'),
            $this->notInMenuEntry('puzzle_page', RouteTarget::route('puzzle_detail'), 'menu.puzzles_overview', 'bi-puzzle', NavPlace::Puzzles, RulebookQuestion::Q2, 'Object page: reached from lists, search and scans'),
            $this->notInMenuEntry('event_page', RouteTarget::route('event_detail'), 'menu.events', 'bi-calendar-event', NavPlace::Community, RulebookQuestion::Q2, 'Object page: reached from Events (the footer lists WJPC editions)'),
            $this->notInMenuEntry('team_page', RouteTarget::route('puzzling_team_detail'), 'menu.pairs_and_teams', 'bi-people-fill', NavPlace::Community, RulebookQuestion::Q2, 'Object page: reached from results and Pairs & teams'),
            $this->notInMenuEntry('start_free_trial', RouteTarget::route('start_free_trial'), 'menu.membership', 'bi-gem', NavPlace::Profile, RulebookQuestion::Q1, 'Form action of the membership page and the members modal', NavVisibility::SignedIn),
            $this->notInMenuEntry('newsletter_subscribe', RouteTarget::route('newsletter_subscribe'), 'newsletter.footer.title', 'bi-envelope', NavPlace::None, RulebookQuestion::Q1, "Form action of the footer's newsletter band", NavVisibility::GuestOnly),
            $this->notInMenuEntry('newsletter_turn_on', RouteTarget::route('newsletter_turn_on'), 'newsletter.footer.turn_on', 'bi-envelope', NavPlace::None, RulebookQuestion::Q1, "Form action of the footer's \"Turn it on\" line", NavVisibility::SignedIn),
            $this->notInMenuEntry('brand_hub', RouteTarget::route('brand_puzzles'), 'footer.group.brands', 'bi-tags', NavPlace::Puzzles, RulebookQuestion::Q2, $seoHub),
            $this->notInMenuEntry('brand_pieces_hub', RouteTarget::route('brand_pieces_puzzles'), 'footer.group.brand_size', 'bi-tags', NavPlace::Puzzles, RulebookQuestion::Q2, $seoHub),
            $this->notInMenuEntry('pieces_hub', RouteTarget::route('pieces_puzzles'), 'footer.group.pieces_times', 'bi-grid-3x3-gap', NavPlace::Puzzles, RulebookQuestion::Q2, $seoHub),
            $this->notInMenuEntry('pieces_hardest_hub', RouteTarget::route('pieces_hardest_puzzles'), 'footer.group.pieces_times', 'bi-grid-3x3-gap', NavPlace::Puzzles, RulebookQuestion::Q2, $seoHub),
            $this->notInMenuEntry('guide_time_by_pieces', RouteTarget::route('guide_puzzle_time_by_pieces'), 'footer.guides', 'bi-book', NavPlace::None, RulebookQuestion::Q7, $seoHub),
            $this->notInMenuEntry('guide_average_time', RouteTarget::route('guide_average_puzzle_time'), 'catalogue.guide.average_time', 'bi-book', NavPlace::None, RulebookQuestion::Q7, $seoHub),
        ];
    }

    /**
     * @param list<string> $activeOn
     */
    private function listRow(string $id, string $route, string $label, string $icon, int $position, null|string $legacyLabel, array $activeOn): NavEntry
    {
        return new NavEntry(
            id: $id,
            target: RouteTarget::viewer($route),
            label: $label,
            icon: $icon,
            place: NavPlace::Puzzles,
            phone: MenuPlacement::row(MenuGroup::Library, $position),
            dock: MenuPlacement::row(MenuGroup::Library, $position),
            visibility: NavVisibility::SignedIn,
            rule: RulebookQuestion::Q8,
            reason: 'Owner manages own puzzles: a list of the library',
            since: self::SINCE,
            section: 'library',
            legacyLabels: $legacyLabel !== null ? [$legacyLabel] : [],
            activeOn: $activeOn,
        );
    }

    /**
     * @param list<string> $activeOn
     */
    private function helpRow(string $id, string $route, string $label, string $icon, int $position, array $activeOn = [], NavVisibility $visibility = NavVisibility::Everyone): NavEntry
    {
        return new NavEntry(
            id: $id,
            target: RouteTarget::route($route),
            label: $label,
            icon: $icon,
            place: NavPlace::None,
            phone: MenuPlacement::row(MenuGroup::Help, $position),
            dock: MenuPlacement::row(MenuGroup::Help, $position),
            visibility: $visibility,
            rule: RulebookQuestion::Q7,
            reason: 'Explains or helps: Help & learn (and the Help sheet)',
            since: self::SINCE,
            legacyLabels: [$label],
            activeOn: $activeOn,
        );
    }

    private function aboutRow(string $id, string $route, string $label, string $icon, int $position, RowKind $kind = RowKind::Link, NavPlace $place = NavPlace::None, null|string $section = null): NavEntry
    {
        return new NavEntry(
            id: $id,
            target: RouteTarget::route($route),
            label: $label,
            icon: $icon,
            place: $place,
            phone: MenuPlacement::row(MenuGroup::About, $position),
            dock: MenuPlacement::row(MenuGroup::About, $position),
            visibility: NavVisibility::Everyone,
            rule: RulebookQuestion::Q7,
            reason: $kind === RowKind::Legal ? 'Legal: footer and Menu › About (one row of three links)' : 'About MySpeedPuzzling: footer and Menu › About',
            since: self::SINCE,
            kind: $kind,
            section: $section,
            legacyLabels: [$label],
        );
    }

    private function searchEntry(
        string $id,
        RouteTarget $target,
        string $label,
        string $icon,
        string $parent,
        NavPlace $place,
        RulebookQuestion $rule,
        string $reason,
        NavVisibility $visibility = NavVisibility::SignedIn,
        null|string $section = null,
    ): NavEntry {
        return new NavEntry(
            id: $id,
            target: $target,
            label: $label,
            icon: $icon,
            place: $place,
            phone: MenuPlacement::searchOnly($parent),
            dock: MenuPlacement::searchOnly($parent),
            visibility: $visibility,
            rule: $rule,
            reason: $reason,
            since: self::SINCE,
            section: $section,
        );
    }

    private function notInMenuEntry(
        string $id,
        RouteTarget $target,
        string $label,
        string $icon,
        NavPlace $place,
        RulebookQuestion $rule,
        string $reason,
        NavVisibility $visibility = NavVisibility::Everyone,
    ): NavEntry {
        return new NavEntry(
            id: $id,
            target: $target,
            label: $label,
            icon: $icon,
            place: $place,
            phone: MenuPlacement::notInMenu($reason),
            dock: MenuPlacement::notInMenu($reason),
            visibility: $visibility,
            rule: $rule,
            reason: $reason,
            since: self::SINCE,
        );
    }
}
