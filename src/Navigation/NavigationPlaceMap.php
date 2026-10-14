<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\Controller\CollectionDetailController;

/**
 * Which place every route belongs to (navigation README §3 "Place mapping"), in PlaceRule notation:
 * "place[/section][*]", own|other split on "|" for routes whose playerId may be the viewer's, "none" for no place.
 *
 * Every route except admin_*, api_*, internal_api_*, _* and sitemap* is matched by exactly one entry here or one
 * prefix (NavigationPlaceResolverTest fails on an unclassified new route): a new route gets its line in the commit
 * that adds it.
 */
final class NavigationPlaceMap
{
    /**
     * @var array<string, string>
     */
    public const array ROUTES = [
        // Home (README §3: hub, recent activity)
        'hub' => 'home*',
        'homepage' => 'home*',
        'homepage_legacy' => 'home',
        'locale_root' => 'home',
        'recent_activity' => 'home',
        // Puzzles: the catalogue, puzzle pages, brands, pieces, marketplace, the picker
        'puzzles' => 'puzzles/all*',
        'puzzles_plural_redirect' => 'puzzles/all',
        'puzzle_detail' => 'puzzles',
        'puzzle_detail_qr' => 'puzzles',
        'puzzle_qr_code_modal' => 'puzzles',
        'puzzle_qr_redirect' => 'puzzles',
        'puzzle_result_detail' => 'puzzles',
        'puzzle_suggest_change' => 'puzzles',
        'puzzle_report_duplicate' => 'puzzles',
        'puzzle_pending_proposals' => 'puzzles',
        'puzzle_brands' => 'puzzles',
        'ravensburger_puzzle_month' => 'puzzles',
        'marketplace' => 'puzzles/marketplace*',
        'marketplace_puzzle' => 'puzzles',
        'marketplace_how_it_works' => 'puzzles',
        'rate_transaction' => 'puzzles',
        'puzzle_picker' => 'puzzles/next*',
        // Puzzles: your own library and its lists (somebody else's lists are their player pages: Community)
        'puzzle_library' => 'puzzles/library*|community',
        'system_collection_detail' => 'puzzles/library|community',
        'unsolved_puzzles_detail' => 'puzzles/library|community',
        'solved_puzzles_detail' => 'puzzles/library|community',
        'sell_swap_list_detail' => 'puzzles/library|community',
        'lend_borrow_list_detail' => 'puzzles/library|community',
        'wish_list_detail' => 'puzzles/library|community',
        'sold_swapped_history' => 'puzzles/library|community',
        'lend_borrow_history' => 'puzzles/library|community',
        'legacy_player_collections' => 'puzzles/library|community',
        'edit_system_collection' => 'puzzles/library|community',
        'edit_unsolved_puzzles' => 'puzzles/library|community',
        'edit_solved_puzzles' => 'puzzles/library|community',
        'edit_wish_list' => 'puzzles/library|community',
        'edit_lend_borrow_list_settings' => 'puzzles/library|community',
        'edit_sell_swap_list_settings' => 'puzzles/library|community',
        // A custom collection: its URL has no playerId - CollectionDetailController names the owner
        // (NavigationPlaceResolver::PAGE_OWNER_ATTRIBUTE, OWNER_FROM_CONTROLLER)
        'collection_detail' => 'puzzles/library|community',
        // Puzzles: library modals and actions (the URL carries no owner; they act on your own library)
        'create_collection' => 'puzzles/library',
        'edit_collection' => 'puzzles/library',
        'delete_collection' => 'puzzles/library',
        'edit_collection_item_comment' => 'puzzles/library',
        'collection_add' => 'puzzles/library',
        'collection_move' => 'puzzles/library',
        'collection_remove' => 'puzzles/library',
        'collection_selected_lend' => 'puzzles/library',
        'collection_selected_move' => 'puzzles/library',
        'collection_selected_remove' => 'puzzles/library',
        'collection_display_mode' => 'puzzles/library',
        'puzzle_list_selected_action' => 'puzzles/library',
        'puzzle_list_selected_add_to_collection' => 'puzzles/library',
        'puzzle_list_selected_lend' => 'puzzles/library',
        'borrow_puzzle' => 'puzzles/library',
        'lend_puzzle' => 'puzzles/library',
        'pass_puzzle' => 'puzzles/library',
        'return_puzzle' => 'puzzles/library',
        'wishlist_add' => 'puzzles/library',
        'remove_puzzle_from_collection' => 'puzzles/library',
        'remove_puzzle_from_wish_list' => 'puzzles/library',
        'sellswap_add' => 'puzzles/library',
        'sellswap_mark_sold' => 'puzzles/library',
        'sellswap_mark_sold_to_player' => 'puzzles/library',
        'sellswap_remove' => 'puzzles/library',
        'sell_swap_mark_reserved' => 'puzzles/library',
        'sell_swap_remove_reservation' => 'puzzles/library',
        'sell_swap_bring_to_event' => 'puzzles/library',
        'edit_sell_swap_list_item' => 'puzzles/library',
        // Add: the add form and its recap, the stopwatch, multiscan (decision 40: edit_time too)
        'puzzle_add' => 'add*',
        // The guests' Add tab (ADD-05): their start page of Add
        'add_intro' => 'add*',
        'legacy_add_time' => 'add',
        'edit_time' => 'add',
        'delete_time' => 'add',
        'added_time_recap' => 'add',
        'added_tracking_recap' => 'add',
        'stopwatch' => 'add',
        'stopwatch_puzzle' => 'add',
        'start_stopwatch' => 'add',
        'finish_stopwatch' => 'add',
        'reset_stopwatch' => 'add',
        'resume_stopwatch' => 'add',
        'stop_stopwatch' => 'add',
        'multiscan' => 'add',
        // Community: the landing is the place's start page, without a section (decision 75)
        'community' => 'community*',
        // Community › Leaderboards
        'ladder' => 'community/leaderboards*',
        'msp_rating_ladder' => 'community/leaderboards',
        'legacy_msp_elo_ladder' => 'community/leaderboards',
        'level_leaderboard' => 'community/leaderboards',
        'achievement_points_leaderboard' => 'community/leaderboards',
        'karma_leaderboard' => 'community/leaderboards',
        'legacy_xp_leaderboard' => 'community/leaderboards',
        'badges_overview' => 'community/leaderboards',
        'badges_overview_legacy' => 'community/leaderboards',
        'achievement_detail' => 'community/leaderboards',
        'achievement_info' => 'community/leaderboards',
        // Community › Puzzlers (Compare lives under Puzzlers, D8)
        'players' => 'community/puzzlers*',
        'players_directory' => 'community/puzzlers',
        'players_per_country' => 'community/puzzlers',
        'set_my_country' => 'community/puzzlers',
        'comparison' => 'community/puzzlers',
        'compare_players' => 'community/puzzlers',
        // Community › Events: agenda, archive, organizations, series/editions/events, round results, organiser tools
        'events' => 'community/events*',
        'events_archive' => 'community/events',
        'competition_series_detail' => 'community/events',
        'organizations' => 'community/events',
        'organization_detail' => 'community/events',
        'organized_events' => 'community/events',
        'wjpc_hub' => 'community/events',
        'wjpc_2024' => 'community/events',
        'add_competition' => 'community/events',
        'add_competition_round' => 'community/events',
        'add_edition' => 'community/events',
        'add_editions' => 'community/events',
        'add_organization' => 'community/events',
        'add_puzzle_to_round' => 'community/events',
        'remove_puzzle_from_round' => 'community/events',
        'add_page_section' => 'community/events',
        'edit_page_section' => 'community/events',
        'delete_page_section' => 'community/events',
        'reorder_page_sections' => 'community/events',
        'upload_page_section_image' => 'community/events',
        'change_page_section_visibility' => 'community/events',
        'edit_competition' => 'community/events',
        'edit_competition_round' => 'community/events',
        'edit_competition_series' => 'community/events',
        'edit_organization' => 'community/events',
        'delete_competition' => 'community/events',
        'delete_competition_edition' => 'community/events',
        'delete_competition_round' => 'community/events',
        'delete_competition_series' => 'community/events',
        'delete_organization' => 'community/events',
        'publish_competition' => 'community/events',
        'publish_competition_series' => 'community/events',
        'publish_organization' => 'community/events',
        'unpublish_competition' => 'community/events',
        'unpublish_competition_series' => 'community/events',
        'unpublish_organization' => 'community/events',
        'convert_competition_to_series' => 'community/events',
        'create_organization_from_series' => 'community/events',
        'move_competition_round' => 'community/events',
        'move_edition' => 'community/events',
        'competition_check_in' => 'community/events',
        'competition_name_tags' => 'community/events',
        'competition_referees' => 'community/events',
        'competition_referee_remove' => 'community/events',
        'competition_referees_qr_code' => 'community/events',
        'competition_results_overview' => 'community/events',
        'export_competition_participants' => 'community/events',
        'import_competition_participants' => 'community/events',
        'download_competition_participant_template' => 'community/events',
        'participant_import_preview' => 'community/events',
        'participant_import_confirm' => 'community/events',
        'participant_import_discard' => 'community/events',
        'participants_sheet' => 'community/events',
        'results_desk' => 'community/events',
        'results_desk_export' => 'community/events',
        'print_round_tables' => 'community/events',
        'generate_table_layout' => 'community/events',
        'change_round_puzzle_reveal' => 'community/events',
        'keep_round_puzzle_hidden_everywhere' => 'community/events',
        'reveal_round_puzzle_now' => 'community/events',
        'start_round_stopwatch' => 'community/events',
        'stop_round_stopwatch' => 'community/events',
        'reset_round_stopwatch' => 'community/events',
        'schedule_round_start' => 'community/events',
        'cancel_round_start' => 'community/events',
        // Online rounds (docs/features/online-competitions): the organiser's pages and actions - links, QR sheet, settings,
        // control room, review, accuracy, the host's studio - a participant's page (bar-free, README §4.10: one document for
        // the whole round, like the round stopwatch) and the public watch page of the round's studio (bar-free too: the
        // video and the round's clock on one dark document, ?obs=1 the video alone for a stream); their JSON endpoints
        // have no place (below)
        'online_links' => 'community/events',
        'online_links_csv' => 'community/events',
        'online_links_qr_sheet' => 'community/events',
        'online_link_regenerate' => 'community/events',
        'online_round_settings' => 'community/events',
        'online_control_room' => 'community/events',
        'online_review' => 'community/events',
        'online_accuracy' => 'community/events',
        'online_studio' => 'community/events',
        'compete_page' => 'community/events',
        'online_watch' => 'community/events',
        'join_competition' => 'community/events',
        'leave_competition' => 'community/events',
        // Community without a section: team pages; Profile/Community by owner: player pages (decision 75)
        'puzzling_team_detail' => 'community',
        'player_profile' => 'profile*|community',
        'player_statistics' => 'profile|community',
        'player_results' => 'profile|community',
        'activity_calendar' => 'profile|community',
        'player_achievements' => 'profile|community',
        'player_favorite_puzzlers' => 'profile|community',
        'player_ratings' => 'profile|community',
        'export_puzzler_data' => 'profile|community',
        'player_card' => 'profile|community',
        // Profile: your own pages without an owner in the URL - XP/karma, pairs & teams, review, settings, membership, account
        'my_profile' => 'profile',
        'xp_history' => 'profile',
        'karma_history' => 'profile',
        'my_activity' => 'profile',
        'badge_reveals' => 'profile',
        'xp_launch_reveal' => 'profile',
        'pairs_and_teams' => 'profile',
        'review_results' => 'profile',
        'first_try_conflicts' => 'profile',
        'edit_profile' => 'profile',
        'edit_player_code' => 'profile',
        'membership' => 'profile',
        'buy_membership' => 'profile',
        'billing_portal' => 'profile',
        'claim_voucher' => 'profile',
        'free_trial_started' => 'profile',
        'stripe_checkout_success' => 'profile',
        'affiliate_dashboard' => 'profile',
        'blocked_users' => 'profile',
        'private_profile_viewers' => 'profile',
        'private_profile_viewer_allow' => 'profile',
        'private_profile_viewer_revoke' => 'profile',
        'account_recent_activity' => 'profile',
        'account_set_password' => 'profile',
        'change_account_email' => 'profile',
        'change_account_password' => 'profile',
        'api_usage' => 'profile',
        'request_api_access' => 'profile',
        'claim_oauth2_credentials' => 'profile',
        'create_personal_access_token' => 'profile',
        'revoke_personal_access_token' => 'profile',
        'revoke_oauth2_consent' => 'profile',
        // None: the Inbox (notifications, messages)
        'notifications' => 'none',
        'conversations_list' => 'none',
        'conversations_list_tab' => 'none',
        'conversation_detail' => 'none',
        'conversation_older_messages' => 'none',
        'accept_conversation' => 'none',
        'ignore_conversation' => 'none',
        'report_conversation' => 'none',
        'send_message' => 'none',
        'typing_indicator' => 'none',
        'start_conversation' => 'none',
        'start_marketplace_conversation' => 'none',
        // None: help, learn and about pages; the Menu page; "Where did it go?" (README §3)
        'help' => 'none',
        'menu' => 'none',
        'where_did_it_go' => 'none',
        'feedback' => 'none',
        'faq' => 'none',
        'getting_started' => 'none',
        'methodology' => 'none',
        'guides' => 'none',
        'feature_requests' => 'none',
        'progression_hub' => 'none',
        'xp_explainer' => 'none',
        'xp_fair_play' => 'none',
        'karma_explainer' => 'none',
        'for_developers' => 'none',
        'for_organizers' => 'none',
        'puzzle_tracker_app' => 'none',
        'terms_of_service' => 'none',
        'privacy_policy' => 'none',
        'data_deletion' => 'none',
        'fair_use_policy' => 'none',
        'contact' => 'none',
        'blog' => 'none',
        'puzzle_service' => 'none',
        // None: sign-in, registration, account deletion, e-mail links
        'login' => 'none',
        'logout' => 'none',
        'register' => 'none',
        'registration_welcome' => 'none',
        'finish_profile' => 'none',
        'request_password_reset' => 'none',
        'password_reset' => 'none',
        'password_reset_sent' => 'none',
        'verify_email' => 'none',
        'resend_email_verification' => 'none',
        'set_password_after_sign_in_link' => 'none',
        'oauth2_authorize' => 'none',
        'oauth2_device_code' => 'none',
        'oauth2_token' => 'none',
        'connect_wjpf' => 'none',
        'connect_wjpf_confirm' => 'none',
        'request_account_deletion' => 'none',
        'confirm_account_deletion' => 'none',
        'account_deleted' => 'none',
        'email_preferences' => 'none',
        'email_preferences_save' => 'none',
        'content_digest_unsubscribe' => 'none',
        'digest_emails_unsubscribe' => 'none',
        'result_emails_unsubscribe' => 'none',
        // None: JSON, POST actions, images, telemetry, webhooks, test routes
        'add_player_to_favorite' => 'none',
        'remove_player_from_favorite' => 'none',
        'block_user' => 'none',
        'unblock_user' => 'none',
        'start_free_trial' => 'none',
        'accept_fair_use_policy' => 'none',
        'dismiss_hint' => 'none',
        'reveal_badge' => 'none',
        'first_try_check' => 'none',
        'first_try_conflict_resolve' => 'none',
        'first_try_late_keep' => 'none',
        'first_try_late_remove' => 'none',
        'my_co_puzzlers' => 'none',
        'menu_counts' => 'none',
        'my_co_puzzlers_archive' => 'none',
        'photo_stash_preview' => 'none',
        'competition_picker_editions' => 'none',
        'competition_picker_series_preview' => 'none',
        // JSON of online rounds: the control room's live feed, the review's entries and confirmations, a participant's
        // "This is me", the clock the participant page syncs to, the live video's tokens (moderator, spectator) and the
        // studio's invitations
        'online_live' => 'none',
        'online_review_entry' => 'none',
        'online_review_confirm' => 'none',
        'compete_connect' => 'none',
        'online_time' => 'none',
        'online_video_token' => 'none',
        'online_watch_token' => 'none',
        'online_studio_invite' => 'none',
        'online_studio_end' => 'none',
        'player_search_autocomplete' => 'none',
        'puzzle_by_brand_autocomplete' => 'none',
        'puzzle_by_ean_search' => 'none',
        'puzzle_search_filter_options' => 'none',
        'puzzle_search_items' => 'none',
        'puzzle_qr_code_image' => 'none',
        'ravensburger_puzzle_month_qr_code_image' => 'none',
        'result_image' => 'none',
        'voucher_image' => 'none',
        'xp_share_card' => 'none',
        'xp_launch_level_revealed' => 'none',
        'export_puzzler_data_download' => 'none',
        'export_puzzle_library_download' => 'none',
        'ux_live_component' => 'none',
        'announcement_modal_seen' => 'none',
        'asset_load_failure' => 'none',
        'asset_recovery' => 'none',
        'stale_document' => 'none',
        'navigation_fetch_failure' => 'none',
        'nav_click' => 'none',
        'nav_search_index' => 'none',
        'nav_go' => 'none',
        'homepage_stats' => 'none',
        'microsoft_identity_association' => 'none',
        'apple_sign_in_notification' => 'none',
        'stripe_webhook' => 'none',
        // Server to server: the live video server's webhooks, Amazon SES events through SNS
        'livekit_webhook' => 'none',
        'ses_events' => 'none',
        'sentry_test' => 'none',
        'test_login' => 'none',
        'test_whoami' => 'none',
        // None: API v1 routes named without the api_ prefix (API Platform operations)
        'my_favorites' => 'none',
        'my_followers' => 'none',
        'my_lend_borrow' => 'none',
        'my_library' => 'none',
        'my_sell_swap' => 'none',
        'my_unsolved_puzzles' => 'none',
        'my_wishlist' => 'none',
        'player_lend_borrow' => 'none',
        'player_library' => 'none',
        'player_sell_swap' => 'none',
        'player_unsolved_puzzles' => 'none',
        'player_wishlist' => 'none',
    ];

    /**
     * Own|other routes whose URL carries no playerId: their controller sets NavigationPlaceResolver::PAGE_OWNER_ATTRIBUTE
     * to the page's player (route => controller). Every other own|other route has {playerId} in its path
     * (NavigationPlaceResolverTest).
     *
     * @var array<string, class-string>
     */
    public const array OWNER_FROM_CONTROLLER = [
        'collection_detail' => CollectionDetailController::class,
    ];

    /**
     * Route-name prefixes of homogeneous families, each with why. A route matches exactly one prefix or one name.
     *
     * @var array<string, string>
     */
    public const array PREFIXES = [
        // Brand pages of the catalogue (SEO hubs, rankings)
        'brand_' => 'puzzles',
        // Piece-count pages of the catalogue (SEO hubs, rankings)
        'pieces_' => 'puzzles',
        // Ladder variants per category, piece count and country
        'ladder_' => 'community/leaderboards',
        // Event pages and round results
        'event_' => 'community/events',
        // Series edition pages and round results
        'edition_' => 'community/events',
        // Compare actions and JSON
        'comparison_' => 'community/puzzlers',
        // Organiser tools of events and series
        'manage_' => 'community/events',
        // Seating and the round stopwatch of events
        'round_' => 'community/events',
        // Public live results of events
        'live_results' => 'community/events',
        // JSON/POST endpoints of the results desk
        'official_results_' => 'none',
        // JSON/POST endpoints of the participants spreadsheet
        'participants_sheet_' => 'none',
        // The participant page's API of online rounds (state, heartbeat, events, media)
        'compete_api_' => 'none',
        // Actions and pages of your pairs, teams and guests
        'pairs_and_teams_' => 'profile',
        // POST actions of Review your results
        'review_results_' => 'none',
        // Feature request pages and actions
        'feature_request_' => 'none',
        // English guides
        'guide_' => 'none',
        // Newsletter confirmation and unsubscribe landings
        'newsletter_' => 'none',
        // Sign-in links and codes
        'sign_in_' => 'none',
        // Social sign-in and linking flows
        'social_' => 'none',
        // Auto-named routes: health checks, internal API, API v0 and app receipts
        'speedpuzzling_web_' => 'none',
    ];

    /**
     * The rule of a route name (canonical, without the locale suffix), or null when nothing classifies it.
     */
    public static function ruleFor(string $route): null|PlaceRule
    {
        $notations = self::notationsFor($route);

        return count($notations) === 1 ? PlaceRule::parse($notations[0]) : null;
    }

    /**
     * Every rule that classifies the route - exactly one for a well-kept map.
     *
     * @return list<string>
     */
    public static function notationsFor(string $route): array
    {
        $notations = [];

        if (isset(self::ROUTES[$route])) {
            $notations[] = self::ROUTES[$route];
        }

        foreach (self::PREFIXES as $prefix => $notation) {
            if (str_starts_with($route, $prefix)) {
                $notations[] = $notation;
            }
        }

        return $notations;
    }
}
