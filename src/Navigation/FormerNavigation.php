<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * The navigation before the redesign, item by item, and where each item lives now - the data of "Where did it go?"
 * (docs/features/navigation README §8, LAUNCH-02, decision 78). Constant: the old chrome is gone (NAV-06), so this
 * describes the header, its account menu and admin key and the footer of the P0-02 baseline (a76cd36b,
 * docs/features/navigation/delivery/baseline-chrome-routes.json): every route the old chrome linked for any role is
 * an item's route (FormerNavigationTest), the old label keys stay in the catalogues as the "old name" (and as search
 * synonyms through the registry's legacyLabels).
 *
 * The new home is a registry entry: its Menu path is what the page shows (NavigationRegistry::menuPathOn()), its
 * audience decides who sees the row and how it is marked (WhereDidItGoBuilder).
 */
final class FormerNavigation
{
    /**
     * @return list<FormerNavigationItem>
     */
    public static function items(): array
    {
        return [...self::topbar(), ...self::adminKey(), ...self::toolbar(), ...self::accountMenu(), ...self::hamburger(), ...self::footer()];
    }

    /**
     * @return list<FormerNavigationItem>
     */
    private static function topbar(): array
    {
        $surface = FormerSurface::Topbar;

        return [
            new FormerNavigationItem($surface, 'menu.feedback', 'send_feedback', ['feedback']),
            new FormerNavigationItem($surface, 'menu.membership', 'membership', ['membership']),
            new FormerNavigationItem($surface, 'menu.feature_requests', 'feature_requests', ['feature_requests']),
            // The flag + language code menu
            new FormerNavigationItem($surface, 'footer.language', 'language', []),
        ];
    }

    /**
     * The key menu: its rows are the Menu's Your roles now, under the same English names.
     *
     * @return list<FormerNavigationItem>
     */
    private static function adminKey(): array
    {
        $surface = FormerSurface::AdminKey;

        return [
            new FormerNavigationItem($surface, null, 'mod_change_requests', ['admin_puzzle_change_requests']),
            new FormerNavigationItem($surface, null, 'mod_merge_requests', ['admin_puzzle_merge_requests']),
            new FormerNavigationItem($surface, null, 'mod_puzzle_approvals', ['admin_puzzle_approvals']),
            new FormerNavigationItem($surface, null, 'mod_time_verification', ['admin_time_verification']),
            new FormerNavigationItem($surface, null, 'admin_vouchers', ['admin_vouchers']),
            new FormerNavigationItem($surface, null, 'admin_referrals', ['admin_referrals']),
            new FormerNavigationItem($surface, null, 'admin_competition_approvals', ['admin_competition_approvals']),
            new FormerNavigationItem($surface, null, 'admin_email_audit', ['admin_email_audit_list']),
            new FormerNavigationItem($surface, null, 'admin_moderation', ['admin_moderation_dashboard']),
            new FormerNavigationItem($surface, null, 'admin_oauth2_requests', ['admin_oauth2_client_requests']),
            new FormerNavigationItem($surface, null, 'admin_moderators', ['admin_moderators']),
            new FormerNavigationItem($surface, null, 'admin_free_trial', ['admin_free_trial']),
            new FormerNavigationItem($surface, null, 'admin_api_usage', ['admin_api_usage']),
            new FormerNavigationItem($surface, null, 'admin_duplicate_results', ['admin_duplicate_results']),
        ];
    }

    /**
     * @return list<FormerNavigationItem>
     */
    private static function toolbar(): array
    {
        $surface = FormerSurface::Toolbar;

        return [
            // The logo stayed at the top
            new FormerNavigationItem($surface, 'where_did_it_go.logo', 'home', ['homepage'], path: ['where_did_it_go.top_bar', 'where_did_it_go.logo']),
            // The search icon became the top bar's field; without JavaScript it leads to the puzzle search (decision 79)
            new FormerNavigationItem($surface, 'menu.search', 'all_puzzles', [], path: ['where_did_it_go.top_bar', 'nav.bar.search_placeholder']),
            // Guests: the guest card at the top of the Menu - for guests only (its audience), as in the old toolbar; a
            // signed-in player's Menu has the You card there (LAUNCH review F4)
            new FormerNavigationItem($surface, 'menu.sign_in', 'guest_card', ['my_profile'], path: ['☰', 'nav.menu.sign_in']),
            new FormerNavigationItem($surface, 'menu.register', 'guest_card', ['register'], link: 'register', path: ['☰', 'nav.menu.join_free']),
            new FormerNavigationItem($surface, 'menu.puzzle_add', 'add', ['puzzle_add']),
            // The bell and the envelope are one Inbox
            new FormerNavigationItem($surface, 'notifications.title', 'inbox', ['notifications']),
            new FormerNavigationItem($surface, 'messaging.title', 'inbox', ['conversations_list'], link: 'messages'),
        ];
    }

    /**
     * "My profile ▾"
     *
     * @return list<FormerNavigationItem>
     */
    private static function accountMenu(): array
    {
        $surface = FormerSurface::AccountMenu;

        return [
            new FormerNavigationItem($surface, 'menu.my_profile', 'profile', ['my_profile']),
            new FormerNavigationItem($surface, 'menu.statistics', 'statistics', ['player_statistics']),
            new FormerNavigationItem($surface, 'menu.activity_calendar', 'calendar', ['activity_calendar']),
            new FormerNavigationItem($surface, 'menu.favorite_puzzlers', 'favorites', ['player_favorite_puzzlers']),
            new FormerNavigationItem($surface, 'badges.my_title', 'achievements', ['player_achievements']),
            new FormerNavigationItem($surface, 'xp.history.title', 'level_xp', ['xp_history']),
            new FormerNavigationItem($surface, 'progression_hub.menu_label', 'progression', ['progression_hub']),
            new FormerNavigationItem($surface, 'menu.pairs_and_teams', 'pairs_and_teams', ['pairs_and_teams']),
            new FormerNavigationItem($surface, 'menu.puzzle_library', 'library', ['puzzle_library']),
            new FormerNavigationItem($surface, 'puzzle_picker.menu_label', 'what_next', ['puzzle_picker']),
            new FormerNavigationItem($surface, 'menu.export_data', 'your_data', ['export_puzzler_data']),
            new FormerNavigationItem($surface, 'menu.referral_program', 'referral', ['affiliate_dashboard']),
            new FormerNavigationItem($surface, 'menu.edit_profile', 'settings', ['edit_profile']),
            new FormerNavigationItem($surface, 'onboarding.guide.link', 'getting_started', ['getting_started']),
            new FormerNavigationItem($surface, 'my_profile.logout', 'logout', ['logout']),
        ];
    }

    /**
     * @return list<FormerNavigationItem>
     */
    private static function hamburger(): array
    {
        $surface = FormerSurface::Hamburger;

        return [
            // The Hub is Home once signed in; a guest's Home is the homepage, the Hub stays at its address
            new FormerNavigationItem($surface, 'menu.hub', 'home', ['hub'], link: 'hub_guest', audience: NavVisibility::SignedIn),
            new FormerNavigationItem($surface, 'menu.puzzles_overview', 'puzzles_place', ['puzzles']),
            new FormerNavigationItem($surface, 'marketplace.title', 'marketplace', ['marketplace']),
            new FormerNavigationItem($surface, 'menu.ladder', 'leaderboards', ['ladder']),
            new FormerNavigationItem($surface, 'menu.players', 'puzzlers', ['players']),
            new FormerNavigationItem($surface, 'menu.events', 'events', ['events']),
            new FormerNavigationItem($surface, 'menu.stopwatch', 'stopwatch', ['stopwatch']),
            new FormerNavigationItem($surface, 'menu.faq', 'faq', ['faq']),
            new FormerNavigationItem($surface, 'menu.feature_requests', 'feature_requests', ['feature_requests']),
        ];
    }

    /**
     * The footer's columns (Discover, Track, Learn, MySpeedPuzzling), the guests' popular searches by group, the legal
     * links and the language menu. The footer logo's homepage is the toolbar logo's.
     *
     * @return list<FormerNavigationItem>
     */
    private static function footer(): array
    {
        $surface = FormerSurface::Footer;

        return [
            new FormerNavigationItem($surface, 'footer.puzzle_database', 'all_puzzles', ['puzzles']),
            new FormerNavigationItem($surface, 'menu.ladder', 'leaderboards', ['ladder']),
            new FormerNavigationItem($surface, 'badges.overview_title', 'achievements_list', ['badges_overview']),
            new FormerNavigationItem($surface, 'menu.players', 'puzzlers', ['players']),
            new FormerNavigationItem($surface, 'footer.latest_puzzle_times', 'latest_times', ['recent_activity']),
            new FormerNavigationItem($surface, 'menu.events', 'events', ['events']),
            new FormerNavigationItem($surface, 'marketplace.title', 'marketplace', ['marketplace']),
            new FormerNavigationItem($surface, 'footer.puzzle_add', 'add', ['puzzle_add']),
            new FormerNavigationItem($surface, 'menu.stopwatch', 'stopwatch', ['stopwatch']),
            new FormerNavigationItem($surface, 'multiscan.title', 'multiscan', ['multiscan']),
            new FormerNavigationItem($surface, 'puzzle_picker.title', 'what_next', ['puzzle_picker']),
            new FormerNavigationItem($surface, 'menu.pairs_and_teams', 'pairs_and_teams', ['pairs_and_teams']),
            new FormerNavigationItem($surface, 'comparison.title', 'compare', ['comparison']),
            new FormerNavigationItem($surface, 'progression_hub.menu_label', 'progression', ['progression_hub']),
            new FormerNavigationItem($surface, 'onboarding.guide.link', 'getting_started', ['getting_started']),
            new FormerNavigationItem($surface, 'footer.guides', 'guides', ['guides']),
            new FormerNavigationItem($surface, 'menu.faq', 'faq', ['faq']),
            new FormerNavigationItem($surface, 'footer.methodology', 'methodology', ['methodology']),
            new FormerNavigationItem($surface, 'footer.wjpc', 'wjpc', ['wjpc_hub']),
            new FormerNavigationItem($surface, 'footer.puzzle_tracker_app', 'tracker_app', ['puzzle_tracker_app']),
            new FormerNavigationItem($surface, 'menu.membership', 'membership', ['membership']),
            new FormerNavigationItem($surface, 'menu.feature_requests', 'feature_requests', ['feature_requests']),
            new FormerNavigationItem($surface, 'menu.feedback', 'send_feedback', ['feedback']),
            new FormerNavigationItem($surface, 'menu.for_organizers', 'organizers', ['for_organizers']),
            new FormerNavigationItem($surface, 'menu.for_developers', 'developers', ['for_developers']),
            // Popular searches (guests): still in the guests' footer, and reached from Brands and the guides
            new FormerNavigationItem($surface, 'footer.group.brand_size', 'brands', ['brand_pieces_puzzles']),
            new FormerNavigationItem($surface, 'footer.group.brands', 'brands', ['brand_puzzles', 'puzzle_brands']),
            new FormerNavigationItem($surface, 'footer.group.pieces_times', 'brands', ['pieces_puzzles', 'pieces_hardest_puzzles', 'guide_puzzle_time_by_pieces', 'guide_average_puzzle_time']),
            new FormerNavigationItem($surface, 'footer.group.world_championship', 'wjpc', ['event_detail']),
            new FormerNavigationItem($surface, 'menu.terms_of_service', 'legal_terms', ['terms_of_service']),
            new FormerNavigationItem($surface, 'menu.privacy_policy', 'legal_privacy', ['privacy_policy']),
            new FormerNavigationItem($surface, 'menu.data_deletion', 'legal_data_deletion', ['data_deletion']),
            new FormerNavigationItem($surface, 'footer.language', 'language', []),
        ];
    }
}
