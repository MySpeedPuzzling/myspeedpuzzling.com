# Navigation registry

<!-- Generated from src/Navigation/NavigationRegistry.php by `bin/console myspeedpuzzling:navigation:dump-doc`. Do not edit by hand: NavigationRegistryDocTest fails on any difference. -->

Every destination linked by a navigation surface (bars, rail, the Menu, the account menu, the Help sheet, the footer, the community landing, the chips) is a Menu row, a search-only entry or "not in Menu" with a reason - the placement rulebook of the navigation README (§7). Labels are the English ones; moderator and admin rows stay English in every locale.

126 entries: 73 phone-panel rows, 73 docked-column rows, 32 search-only, 18 not in the Menu.

## Phone panel (☰ Menu)

### You

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `you_card` | My profile | `player_profile` {playerId: viewer} | signed_in | you_card | membership_status | profile | Q8 | 2026-10-12 | Avatar, name, View your profile, Settings and the membership status at the top of the panel |
| 20 | `guest_card` | Sign in | `login` | guest | guest_card |  | none | Q5 | 2026-10-12 | Guests' Sign in · Join free card (README §4.12); also in the column, which hides the rail's Sign in (decision 31) |

### Inbox

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `inbox` | Notifications | `notifications`; also active on conversations_list, conversation_detail | signed_in | link | inbox_badge | none | Q5 | 2026-10-12 | Inbox = notifications and messages with one badge (D3). Also: top bar Inbox tool |

### Your roles

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `organized_events` | You organize | `organized_events` | organiser | link | lazy_organised | community/events | Q3 | 2026-10-12 | Events you organize, for organisers only; also the "You organize" button on the events page. Also: events page "You organize" button |
| 20 | `mod_change_requests` | Puzzle Change Requests (English) | `admin_puzzle_change_requests`; also active on admin_puzzle_change_request_detail | moderator | link | queue_change_requests | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 30 | `mod_merge_requests` | Puzzle Merge Requests (English) | `admin_puzzle_merge_requests`; also active on admin_puzzle_merge_request_detail | moderator | link | queue_merge_requests | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 40 | `mod_puzzle_approvals` | Puzzle Approvals (English) | `admin_puzzle_approvals`; also active on admin_puzzle_approval_detail, admin_edit_puzzle, admin_puzzle_history | moderator | link | queue_puzzle_approvals | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 50 | `mod_time_verification` | Time verification (English) | `admin_time_verification` | time_verifier | link | queue_time_verification | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 60 | `admin_competition_approvals` | Competition Approvals (English) | `admin_competition_approvals` | admin | link | queue_competition_approvals | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 70 | `admin_duplicate_results` | Duplicate results (English) | `admin_duplicate_results` | admin | link | queue_duplicate_signals | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 80 | `admin_oauth2_requests` | OAuth2 Requests (English) | `admin_oauth2_client_requests`; also active on admin_oauth2_client_request_detail | admin | link | queue_oauth2_requests | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 90 | `admin_moderation` | Moderation (English) | `admin_moderation_dashboard`; also active on admin_moderation_history, admin_report_detail, admin_conversation_log | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 100 | `admin_vouchers` | Vouchers (English) | `admin_vouchers` | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 110 | `admin_referrals` | Referrals (English) | `admin_referrals`; also active on admin_referral_detail | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 120 | `admin_email_audit` | Email Audit (English) | `admin_email_audit_list`; also active on admin_email_audit_detail | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 130 | `admin_moderators` | Community Moderators (English) | `admin_moderators` | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 140 | `admin_free_trial` | Free trial & modals (English) | `admin_free_trial` | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 150 | `admin_api_usage` | API usage (English) | `admin_api_usage` | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |

### Your puzzling

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 20 | `favorites` | Favorites | `player_favorite_puzzlers` {playerId: viewer} | signed_in | link |  | profile | Q8 | 2026-10-12 | Owner-only view of your own data: player tab and Menu row. Also: player header tab Favorites |
| 30 | `statistics` | Statistics | `player_statistics` {playerId: viewer} | signed_in | link |  | profile | Q8 | 2026-10-12 | About one player, others may see it: player tab; your own also a Menu row. Also: player header tab Statistics |
| 40 | `compare` | Compare | `comparison`; also active on compare_players | signed_in | link | line_up | community/puzzlers | Q8 | 2026-10-12 | Your line-ups (Community › Puzzlers is its home, README §7). Also: player header compare button; Players page |
| 50 | `review_results` | Review your results | `review_results`; also active on first_try_conflicts | signed_in | link | lazy_review | profile | Q8 | 2026-10-12 | Owner manages own results (duplicates, first tries, verification). Also: Hub banner; own profile banner |
| 60 | `pairs_and_teams` | Pairs & teams | `pairs_and_teams`; also active on pairs_and_teams_guest_link | signed_in | link |  | profile | Q8 | 2026-10-12 | Owner manages own pairs, teams and guests |
| 70 | `calendar` | Calendar | `activity_calendar` {playerId: viewer} | signed_in | link |  | profile | Q8 | 2026-10-12 | About one player: player tab; your own also a Menu row. Also: player header tab Calendar |
| 80 | `achievements` | Achievements | `player_achievements` {playerId: viewer}; also active on badge_reveals | xp_visible | link |  | profile | Q8 | 2026-10-12 | About one player: player tab; your own also a Menu row (XP gate). Also: player header tab Achievements |
| 90 | `level_xp` | Level & XP | `xp_history`; also active on xp_launch_reveal | xp_participant | link | level | profile | Q8 | 2026-10-12 | Owner-only view of your own XP (XP gate, not opted out) |
| 100 | `karma` | Karma | `karma_history` | karma_holder | link | karma | profile | Q8 | 2026-10-12 | Owner-only view of your own karma (holders who did not hide it) |
| 110 | `your_week` | Your week | `my_activity` | xp_visible | link |  | profile | Q8 | 2026-10-12 | Owner-only view of your own week (XP gate) |

### Your puzzles

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `library` | Library | `puzzle_library` {playerId: viewer}; also active on legacy_player_collections | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles; also Puzzles › My library and the player tab Library. Also: Puzzles › My library chip; player header tab Library |
| 20 | `collection` | Puzzle Collection | `system_collection_detail` {playerId: viewer}; also active on collection_detail, edit_system_collection | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles: a list of the library |
| 30 | `what_next` | What next? | `puzzle_picker` | signed_in | link |  | puzzles/next | Q8 | 2026-10-12 | Picks from your own library; also Puzzles › What next?. Also: Puzzles › What next? chip |
| 40 | `unsolved` | Unsolved | `unsolved_puzzles_detail` {playerId: viewer}; also active on edit_unsolved_puzzles | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles: a list of the library |
| 50 | `sell_swap` | Sell / swap | `sell_swap_list_detail` {playerId: viewer}; also active on edit_sell_swap_list_settings | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles: a list of the library |
| 60 | `solved` | Solved | `solved_puzzles_detail` {playerId: viewer}; also active on edit_solved_puzzles | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles: a list of the library |
| 70 | `lent_borrowed` | Lend & Borrow | `lend_borrow_list_detail` {playerId: viewer}; also active on edit_lend_borrow_list_settings | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles: a list of the library |
| 80 | `wishlist` | Wishlist | `wish_list_detail` {playerId: viewer}; also active on edit_wish_list | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles: a list of the library |

### Community

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `leaderboards` | Leaderboards | `ladder`; also active on ladder_country, ladder_solo_500_pieces, ladder_solo_500_pieces_country, ladder_solo_1000_pieces, ladder_solo_1000_pieces_country, ladder_pairs_500_pieces, ladder_pairs_500_pieces_country, ladder_pairs_1000_pieces, ladder_pairs_1000_pieces_country, ladder_groups_500_pieces, ladder_groups_500_pieces_country, ladder_groups_1000_pieces, ladder_groups_1000_pieces_country | everyone | link |  | community/leaderboards | Q9 | 2026-10-12 | Rankings are community content (Community › Leaderboards). Also: Community landing; section chips |
| 20 | `msp_rating` | MSP Rating | `msp_rating_ladder`; also active on legacy_msp_elo_ladder | everyone | link |  | community/leaderboards | Q9 | 2026-10-12 | A ranking: Community › Leaderboards |
| 30 | `level_boards` | Level leaderboard | `level_leaderboard`; also active on achievement_points_leaderboard, karma_leaderboard, legacy_xp_leaderboard | xp_visible | link |  | community/leaderboards | Q9 | 2026-10-12 | Level, achievement and karma boards: Community › Leaderboards (XP gate) |
| 40 | `puzzlers` | Players | `players`; also active on players_per_country | everyone | link |  | community/puzzlers | Q9 | 2026-10-12 | People: Community › Puzzlers. Also: Community landing; section chips |
| 50 | `all_puzzlers` | All puzzlers | `players_directory` | everyone | link |  | community/puzzlers | Q9 | 2026-10-12 | People: Community › Puzzlers |
| 60 | `events` | Events | `events`; also active on events_archive, event_detail, event_round_results, competition_series_detail, edition_detail, edition_detail_legacy, edition_round_results | everyone | link |  | community/events | Q9 | 2026-10-12 | Events: Community › Events. Also: Community landing; section chips |
| 70 | `organizations` | Organizations | `organizations`; also active on organization_detail | everyone | link |  | community/events | Q9 | 2026-10-12 | Events: Community › Events |
| 80 | `latest_times` | Latest Puzzle Times | `recent_activity` | everyone | link |  | home | Q9 | 2026-10-12 | Everyone's latest times (its page belongs to Home, README §3). Also: Hub feed |

### Puzzles

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `all_puzzles` | Puzzle database | `puzzles`; also active on puzzles_plural_redirect | everyone | link |  | puzzles/all | Q9 | 2026-10-12 | The catalogue: Puzzles › All puzzles. Also: Puzzles › All puzzles chip |
| 20 | `brands` | Brands | `puzzle_brands`; also active on brand_puzzles, brand_puzzles_page, brand_pieces_puzzles, brand_pieces_puzzles_page, brand_easiest_puzzles, brand_hardest_puzzles, pieces_puzzles, pieces_puzzles_page, pieces_easiest_puzzles, pieces_hardest_puzzles | everyone | link |  | puzzles | Q9 | 2026-10-12 | The catalogue by brand and piece count |
| 30 | `marketplace` | Marketplace | `marketplace`; also active on marketplace_puzzle | everyone | link |  | puzzles/marketplace | Q9 | 2026-10-12 | Offers of puzzles: Puzzles › Marketplace. Also: Puzzles › Marketplace chip |

### Add & track

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `add` | Add | `puzzle_add`; also active on legacy_add_time, edit_time, added_time_recap, added_tracking_recap | everyone | link |  | add | Q4 | 2026-10-12 | Records a time or a puzzle: the Add page (D7); also the Add tab and the + Add button. Also: Add tab; desktop + Add button |
| 20 | `stopwatch` | Stopwatch | `stopwatch`; also active on stopwatch_puzzle, finish_stopwatch | signed_in | link | stopwatch | add | Q4 | 2026-10-12 | Records a time: Add › Speed puzzling › Use the stopwatch. Also: Add page; running stopwatch pill |
| 30 | `multiscan` | Scanning | `multiscan` | signed_in | link |  | add | Q4 | 2026-10-12 | Records puzzles: Add › Collection › Scan several boxes. Also: Add page |
| 40 | `add_event` | Add Event | `add_competition` | signed_in | link |  | community/events | Q4 | 2026-10-12 | Creates an event; also the button on the events page. Also: events page button |

### Account

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `membership` | Membership | `membership`; also active on buy_membership, free_trial_started, stripe_checkout_success | signed_in | link | membership_status | profile | Q6 | 2026-10-12 | Your membership and its status; guests reach it from the footer. Also: You card status line; avatar ▾ menu |
| 20 | `settings` | Edit profile | `edit_profile`; also active on edit_player_code | signed_in | link |  | profile | Q6 | 2026-10-12 | Configures your account (name, photo, country, sign-in, privacy, e-mails). Also: You card ⚙; profile header Edit profile |
| 30 | `referral` | Referral Program | `affiliate_dashboard` | member_or_referral | link |  | profile | Q6 | 2026-10-12 | Members or players in the programme only |
| 40 | `your_data` | Export data | `export_puzzler_data` {playerId: viewer} | signed_in | link |  | profile | Q6 | 2026-10-12 | Export results and library, delete the account |
| 50 | `blocked_players` | Blocked players | `blocked_users` | signed_in | link |  | profile | Q6 | 2026-10-12 | Configures who you see |
| 60 | `profile_viewers` | Who can see my profile | `private_profile_viewers` | private_member | link |  | profile | Q6 | 2026-10-12 | Configures who sees your private profile (members with a private profile) |

### Help & learn

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `send_feedback` | Send feedback | `feedback` | signed_in | help_sheet |  | none | Q7 | 2026-10-12 | Collects feedback: opens the Help sheet (D17); guests get "Sign in to send feedback" there. Also: top bar ? / Feedback; end-of-page line |
| 20 | `methodology` | Difficulty & Rating Methodology | `methodology` | everyone | link |  | none | Q7 | 2026-10-12 | Explains or helps: Help & learn (and the Help sheet) |
| 30 | `feature_requests` | Feature Requests | `feature_requests`; also active on feature_request_detail, feature_request_edit | everyone | link |  | none | Q7 | 2026-10-12 | Explains or helps: Help & learn (and the Help sheet) |
| 40 | `getting_started` | Getting started | `getting_started` | everyone | link |  | none | Q7 | 2026-10-12 | Explains or helps: Help & learn (and the Help sheet) |
| 50 | `faq` | FAQ | `faq` | everyone | link |  | none | Q7 | 2026-10-12 | Explains or helps: Help & learn (and the Help sheet) |
| 60 | `guides` | Speed Puzzling Guides | `guides`; also active on guide_average_puzzle_time, guide_puzzle_time_by_pieces, guide_puzzle_time_for_pieces, guide_puzzle_time_pairs_and_teams, guide_speed_puzzling_tips, guide_what_is_speed_puzzling | everyone | link |  | none | Q7 | 2026-10-12 | Explains or helps: Help & learn (and the Help sheet) |
| 70 | `progression` | How progress works | `progression_hub` | xp_visible | link |  | none | Q7 | 2026-10-12 | Explains or helps: Help & learn (and the Help sheet) |
| 90 | `language` | Language | the current page in another language | everyone | language |  | none | Q7 | 2026-10-12 | Inline language switch: the current page in the other locales. Also: footer language menu; Help sheet |

### About

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `tracker_app` | Puzzle Tracker App | `puzzle_tracker_app` | everyone | link |  | none | Q7 | 2026-10-12 | About MySpeedPuzzling: footer and Menu › About |
| 20 | `wjpc` | World Jigsaw Puzzle Championship | `wjpc_hub` | everyone | link |  | community/events | Q7 | 2026-10-12 | About MySpeedPuzzling: footer and Menu › About |
| 30 | `developers` | For Developers | `for_developers` | everyone | link |  | none | Q7 | 2026-10-12 | About MySpeedPuzzling: footer and Menu › About |
| 40 | `organizers` | For Organizers | `for_organizers` | everyone | link |  | none | Q7 | 2026-10-12 | About MySpeedPuzzling: footer and Menu › About |
| 50 | `legal_terms` | Terms of service | `terms_of_service` | everyone | legal |  | none | Q7 | 2026-10-12 | Legal: footer and Menu › About (one row of three links) |
| 50 | `legal_privacy` | Privacy policy | `privacy_policy` | everyone | legal |  | none | Q7 | 2026-10-12 | Legal: footer and Menu › About (one row of three links) |
| 50 | `legal_data_deletion` | Data deletion | `data_deletion` | everyone | legal |  | none | Q7 | 2026-10-12 | Legal: footer and Menu › About (one row of three links) |

### Log out

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `logout` | Log out | `logout` | signed_in | logout |  | none | Q6 | 2026-10-12 | Last row of the phone panel, visually separated |

## Docked column (desktop)

### Home

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `home` | Home | `hub` (guests: `homepage`); also active on homepage_legacy, locale_root | everyone | place |  | home | Q10 | 2026-10-12 | Place Home = the Hub for players, the homepage for guests (D1, D5) |

### Puzzles

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 0 | `puzzles_place` | Puzzle | `puzzles` | everyone | place |  | puzzles | Q10 | 2026-10-12 | Place Puzzles; the tab opens the last used section (D1, D6) |
| 10 | `all_puzzles` | Puzzle database | `puzzles`; also active on puzzles_plural_redirect | everyone | link |  | puzzles/all | Q9 | 2026-10-12 | The catalogue: Puzzles › All puzzles. Also: Puzzles › All puzzles chip |
| 20 | `brands` | Brands | `puzzle_brands`; also active on brand_puzzles, brand_puzzles_page, brand_pieces_puzzles, brand_pieces_puzzles_page, brand_easiest_puzzles, brand_hardest_puzzles, pieces_puzzles, pieces_puzzles_page, pieces_easiest_puzzles, pieces_hardest_puzzles | everyone | link |  | puzzles | Q9 | 2026-10-12 | The catalogue by brand and piece count |
| 30 | `marketplace` | Marketplace | `marketplace`; also active on marketplace_puzzle | everyone | link |  | puzzles/marketplace | Q9 | 2026-10-12 | Offers of puzzles: Puzzles › Marketplace. Also: Puzzles › Marketplace chip |

### Community

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `leaderboards` | Leaderboards | `ladder`; also active on ladder_country, ladder_solo_500_pieces, ladder_solo_500_pieces_country, ladder_solo_1000_pieces, ladder_solo_1000_pieces_country, ladder_pairs_500_pieces, ladder_pairs_500_pieces_country, ladder_pairs_1000_pieces, ladder_pairs_1000_pieces_country, ladder_groups_500_pieces, ladder_groups_500_pieces_country, ladder_groups_1000_pieces, ladder_groups_1000_pieces_country | everyone | link |  | community/leaderboards | Q9 | 2026-10-12 | Rankings are community content (Community › Leaderboards). Also: Community landing; section chips |
| 20 | `msp_rating` | MSP Rating | `msp_rating_ladder`; also active on legacy_msp_elo_ladder | everyone | link |  | community/leaderboards | Q9 | 2026-10-12 | A ranking: Community › Leaderboards |
| 30 | `level_boards` | Level leaderboard | `level_leaderboard`; also active on achievement_points_leaderboard, karma_leaderboard, legacy_xp_leaderboard | xp_visible | link |  | community/leaderboards | Q9 | 2026-10-12 | Level, achievement and karma boards: Community › Leaderboards (XP gate) |
| 40 | `puzzlers` | Players | `players`; also active on players_per_country | everyone | link |  | community/puzzlers | Q9 | 2026-10-12 | People: Community › Puzzlers. Also: Community landing; section chips |
| 50 | `all_puzzlers` | All puzzlers | `players_directory` | everyone | link |  | community/puzzlers | Q9 | 2026-10-12 | People: Community › Puzzlers |
| 60 | `events` | Events | `events`; also active on events_archive, event_detail, event_round_results, competition_series_detail, edition_detail, edition_detail_legacy, edition_round_results | everyone | link |  | community/events | Q9 | 2026-10-12 | Events: Community › Events. Also: Community landing; section chips |
| 70 | `organizations` | Organizations | `organizations`; also active on organization_detail | everyone | link |  | community/events | Q9 | 2026-10-12 | Events: Community › Events |
| 80 | `latest_times` | Latest Puzzle Times | `recent_activity` | everyone | link |  | home | Q9 | 2026-10-12 | Everyone's latest times (its page belongs to Home, README §3). Also: Hub feed |

### You

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 20 | `guest_card` | Sign in | `login` | guest | guest_card |  | none | Q5 | 2026-10-12 | Guests' Sign in · Join free card (README §4.12); also in the column, which hides the rail's Sign in (decision 31) |

### Your roles

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `organized_events` | You organize | `organized_events` | organiser | link | lazy_organised | community/events | Q3 | 2026-10-12 | Events you organize, for organisers only; also the "You organize" button on the events page. Also: events page "You organize" button |
| 20 | `mod_change_requests` | Puzzle Change Requests (English) | `admin_puzzle_change_requests`; also active on admin_puzzle_change_request_detail | moderator | link | queue_change_requests | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 30 | `mod_merge_requests` | Puzzle Merge Requests (English) | `admin_puzzle_merge_requests`; also active on admin_puzzle_merge_request_detail | moderator | link | queue_merge_requests | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 40 | `mod_puzzle_approvals` | Puzzle Approvals (English) | `admin_puzzle_approvals`; also active on admin_puzzle_approval_detail, admin_edit_puzzle, admin_puzzle_history | moderator | link | queue_puzzle_approvals | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 50 | `mod_time_verification` | Time verification (English) | `admin_time_verification` | time_verifier | link | queue_time_verification | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 60 | `admin_competition_approvals` | Competition Approvals (English) | `admin_competition_approvals` | admin | link | queue_competition_approvals | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 70 | `admin_duplicate_results` | Duplicate results (English) | `admin_duplicate_results` | admin | link | queue_duplicate_signals | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 80 | `admin_oauth2_requests` | OAuth2 Requests (English) | `admin_oauth2_client_requests`; also active on admin_oauth2_client_request_detail | admin | link | queue_oauth2_requests | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 90 | `admin_moderation` | Moderation (English) | `admin_moderation_dashboard`; also active on admin_moderation_history, admin_report_detail, admin_conversation_log | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 100 | `admin_vouchers` | Vouchers (English) | `admin_vouchers` | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 110 | `admin_referrals` | Referrals (English) | `admin_referrals`; also active on admin_referral_detail | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 120 | `admin_email_audit` | Email Audit (English) | `admin_email_audit_list`; also active on admin_email_audit_detail | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 130 | `admin_moderators` | Community Moderators (English) | `admin_moderators` | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 140 | `admin_free_trial` | Free trial & modals (English) | `admin_free_trial` | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |
| 150 | `admin_api_usage` | API usage (English) | `admin_api_usage` | admin | link |  | none | Q3 | 2026-10-12 | Review queue or admin page: holders only, moved from the admin key menu |

### Your puzzling

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 0 | `profile` | Profile | `player_profile` {playerId: viewer}; also active on my_profile | signed_in | place |  | profile | Q10 | 2026-10-12 | Place Profile = your own profile page, content first (D1, D9); heads Your puzzling in the column |
| 20 | `favorites` | Favorites | `player_favorite_puzzlers` {playerId: viewer} | signed_in | link |  | profile | Q8 | 2026-10-12 | Owner-only view of your own data: player tab and Menu row. Also: player header tab Favorites |
| 30 | `statistics` | Statistics | `player_statistics` {playerId: viewer} | signed_in | link |  | profile | Q8 | 2026-10-12 | About one player, others may see it: player tab; your own also a Menu row. Also: player header tab Statistics |
| 40 | `compare` | Compare | `comparison`; also active on compare_players | signed_in | link | line_up | community/puzzlers | Q8 | 2026-10-12 | Your line-ups (Community › Puzzlers is its home, README §7). Also: player header compare button; Players page |
| 50 | `review_results` | Review your results | `review_results`; also active on first_try_conflicts | signed_in | link | lazy_review | profile | Q8 | 2026-10-12 | Owner manages own results (duplicates, first tries, verification). Also: Hub banner; own profile banner |
| 60 | `pairs_and_teams` | Pairs & teams | `pairs_and_teams`; also active on pairs_and_teams_guest_link | signed_in | link |  | profile | Q8 | 2026-10-12 | Owner manages own pairs, teams and guests |
| 70 | `calendar` | Calendar | `activity_calendar` {playerId: viewer} | signed_in | link |  | profile | Q8 | 2026-10-12 | About one player: player tab; your own also a Menu row. Also: player header tab Calendar |
| 80 | `achievements` | Achievements | `player_achievements` {playerId: viewer}; also active on badge_reveals | xp_visible | link |  | profile | Q8 | 2026-10-12 | About one player: player tab; your own also a Menu row (XP gate). Also: player header tab Achievements |
| 90 | `level_xp` | Level & XP | `xp_history`; also active on xp_launch_reveal | xp_participant | link | level | profile | Q8 | 2026-10-12 | Owner-only view of your own XP (XP gate, not opted out) |
| 100 | `karma` | Karma | `karma_history` | karma_holder | link | karma | profile | Q8 | 2026-10-12 | Owner-only view of your own karma (holders who did not hide it) |
| 110 | `your_week` | Your week | `my_activity` | xp_visible | link |  | profile | Q8 | 2026-10-12 | Owner-only view of your own week (XP gate) |

### Your puzzles

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `library` | Library | `puzzle_library` {playerId: viewer}; also active on legacy_player_collections | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles; also Puzzles › My library and the player tab Library. Also: Puzzles › My library chip; player header tab Library |
| 20 | `collection` | Puzzle Collection | `system_collection_detail` {playerId: viewer}; also active on collection_detail, edit_system_collection | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles: a list of the library |
| 30 | `what_next` | What next? | `puzzle_picker` | signed_in | link |  | puzzles/next | Q8 | 2026-10-12 | Picks from your own library; also Puzzles › What next?. Also: Puzzles › What next? chip |
| 40 | `unsolved` | Unsolved | `unsolved_puzzles_detail` {playerId: viewer}; also active on edit_unsolved_puzzles | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles: a list of the library |
| 50 | `sell_swap` | Sell / swap | `sell_swap_list_detail` {playerId: viewer}; also active on edit_sell_swap_list_settings | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles: a list of the library |
| 60 | `solved` | Solved | `solved_puzzles_detail` {playerId: viewer}; also active on edit_solved_puzzles | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles: a list of the library |
| 70 | `lent_borrowed` | Lend & Borrow | `lend_borrow_list_detail` {playerId: viewer}; also active on edit_lend_borrow_list_settings | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles: a list of the library |
| 80 | `wishlist` | Wishlist | `wish_list_detail` {playerId: viewer}; also active on edit_wish_list | signed_in | link |  | puzzles/library | Q8 | 2026-10-12 | Owner manages own puzzles: a list of the library |

### Add & track

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `add` | Add | `puzzle_add`; also active on legacy_add_time, edit_time, added_time_recap, added_tracking_recap | everyone | link |  | add | Q4 | 2026-10-12 | Records a time or a puzzle: the Add page (D7); also the Add tab and the + Add button. Also: Add tab; desktop + Add button |
| 20 | `stopwatch` | Stopwatch | `stopwatch`; also active on stopwatch_puzzle, finish_stopwatch | signed_in | link | stopwatch | add | Q4 | 2026-10-12 | Records a time: Add › Speed puzzling › Use the stopwatch. Also: Add page; running stopwatch pill |
| 30 | `multiscan` | Scanning | `multiscan` | signed_in | link |  | add | Q4 | 2026-10-12 | Records puzzles: Add › Collection › Scan several boxes. Also: Add page |
| 40 | `add_event` | Add Event | `add_competition` | signed_in | link |  | community/events | Q4 | 2026-10-12 | Creates an event; also the button on the events page. Also: events page button |

### Account

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `membership` | Membership | `membership`; also active on buy_membership, free_trial_started, stripe_checkout_success | signed_in | link | membership_status | profile | Q6 | 2026-10-12 | Your membership and its status; guests reach it from the footer. Also: You card status line; avatar ▾ menu |
| 20 | `settings` | Edit profile | `edit_profile`; also active on edit_player_code | signed_in | link |  | profile | Q6 | 2026-10-12 | Configures your account (name, photo, country, sign-in, privacy, e-mails). Also: You card ⚙; profile header Edit profile |
| 30 | `referral` | Referral Program | `affiliate_dashboard` | member_or_referral | link |  | profile | Q6 | 2026-10-12 | Members or players in the programme only |
| 40 | `your_data` | Export data | `export_puzzler_data` {playerId: viewer} | signed_in | link |  | profile | Q6 | 2026-10-12 | Export results and library, delete the account |
| 50 | `blocked_players` | Blocked players | `blocked_users` | signed_in | link |  | profile | Q6 | 2026-10-12 | Configures who you see |
| 60 | `profile_viewers` | Who can see my profile | `private_profile_viewers` | private_member | link |  | profile | Q6 | 2026-10-12 | Configures who sees your private profile (members with a private profile) |

### Help & learn

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `send_feedback` | Send feedback | `feedback` | signed_in | help_sheet |  | none | Q7 | 2026-10-12 | Collects feedback: opens the Help sheet (D17); guests get "Sign in to send feedback" there. Also: top bar ? / Feedback; end-of-page line |
| 20 | `methodology` | Difficulty & Rating Methodology | `methodology` | everyone | link |  | none | Q7 | 2026-10-12 | Explains or helps: Help & learn (and the Help sheet) |
| 30 | `feature_requests` | Feature Requests | `feature_requests`; also active on feature_request_detail, feature_request_edit | everyone | link |  | none | Q7 | 2026-10-12 | Explains or helps: Help & learn (and the Help sheet) |
| 40 | `getting_started` | Getting started | `getting_started` | everyone | link |  | none | Q7 | 2026-10-12 | Explains or helps: Help & learn (and the Help sheet) |
| 50 | `faq` | FAQ | `faq` | everyone | link |  | none | Q7 | 2026-10-12 | Explains or helps: Help & learn (and the Help sheet) |
| 60 | `guides` | Speed Puzzling Guides | `guides`; also active on guide_average_puzzle_time, guide_puzzle_time_by_pieces, guide_puzzle_time_for_pieces, guide_puzzle_time_pairs_and_teams, guide_speed_puzzling_tips, guide_what_is_speed_puzzling | everyone | link |  | none | Q7 | 2026-10-12 | Explains or helps: Help & learn (and the Help sheet) |
| 70 | `progression` | How progress works | `progression_hub` | xp_visible | link |  | none | Q7 | 2026-10-12 | Explains or helps: Help & learn (and the Help sheet) |
| 90 | `language` | Language | the current page in another language | everyone | language |  | none | Q7 | 2026-10-12 | Inline language switch: the current page in the other locales. Also: footer language menu; Help sheet |

### About

| Pos | Entry | Label | Target | Audience | Kind | Count | Place | Rule | Since | Reason |
|---|---|---|---|---|---|---|---|---|---|---|
| 10 | `tracker_app` | Puzzle Tracker App | `puzzle_tracker_app` | everyone | link |  | none | Q7 | 2026-10-12 | About MySpeedPuzzling: footer and Menu › About |
| 20 | `wjpc` | World Jigsaw Puzzle Championship | `wjpc_hub` | everyone | link |  | community/events | Q7 | 2026-10-12 | About MySpeedPuzzling: footer and Menu › About |
| 30 | `developers` | For Developers | `for_developers` | everyone | link |  | none | Q7 | 2026-10-12 | About MySpeedPuzzling: footer and Menu › About |
| 40 | `organizers` | For Organizers | `for_organizers` | everyone | link |  | none | Q7 | 2026-10-12 | About MySpeedPuzzling: footer and Menu › About |
| 50 | `legal_terms` | Terms of service | `terms_of_service` | everyone | legal |  | none | Q7 | 2026-10-12 | Legal: footer and Menu › About (one row of three links) |
| 50 | `legal_privacy` | Privacy policy | `privacy_policy` | everyone | legal |  | none | Q7 | 2026-10-12 | Legal: footer and Menu › About (one row of three links) |
| 50 | `legal_data_deletion` | Data deletion | `data_deletion` | everyone | legal |  | none | Q7 | 2026-10-12 | Legal: footer and Menu › About (one row of three links) |

## Search-only entries

Found by the Menu search ("Pages & settings"), opened under their parent row.

| Entry | Label | Target | Parent | Audience | Rule | Reason |
|---|---|---|---|---|---|---|
| `change_password` | Password | `change_account_password` | `settings` | signed_in | Q6 | A setting on Settings: found by search (README §7 Q6) |
| `change_email` | Email address | `change_account_email` | `settings` | signed_in | Q6 | A setting on Settings: found by search (README §7 Q6) |
| `set_password` | Add a password | `account_set_password` | `settings` | signed_in | Q6 | A setting on Settings: found by search (README §7 Q6) |
| `sign_in_methods` | Connected sign-in methods | `edit_profile#sign-in-methods` | `settings` | signed_in | Q6 | A setting on Settings: found by search (README §7 Q6) |
| `recent_sign_ins` | Recent account activity | `account_recent_activity` | `settings` | signed_in | Q6 | A setting on Settings: found by search (README §7 Q6) |
| `email_settings` | Messaging & Notifications | `edit_profile#emails` | `settings` | signed_in | Q6 | A setting on Settings: found by search (README §7 Q6) |
| `delete_account` | Delete your account | `edit_profile#danger-zone` | `settings` | signed_in | Q6 | A setting on Settings: found by search (README §7 Q6) |
| `player_code` | Change player code | `edit_profile#player-code` | `settings` | member | Q6 | A member setting on Settings: found by search |
| `api_tokens` | API usage | `api_usage` | `settings` | signed_in | Q6 | API tokens and their usage: found by search |
| `request_api_access` | Request API Access | `request_api_access` | `developers` | signed_in | Q6 | Developers only: For developers and search |
| `fair_use` | API Fair Use Policy | `fair_use_policy` | `developers` | everyone | Q7 | API terms: For developers and search |
| `redeem_voucher` | Redeem a voucher | `claim_voucher` | `membership` | signed_in | Q6 | A step of Membership: found by search |
| `new_collection` | Create Collection | `create_collection` | `library` | member | Q1 | Action on your library (members): a button there and search |
| `sold_swapped_history` | Sold/Swapped History | `sold_swapped_history` {playerId: viewer} | `sell_swap` | signed_in | Q8 | History of your sell/swap list: a button there and search |
| `lending_history` | Lend & Borrow History | `lend_borrow_history` {playerId: viewer} | `lent_borrowed` | signed_in | Q8 | History of your lending: a button there and search |
| `marketplace_how` | How the Marketplace Works | `marketplace_how_it_works` | `marketplace` | everyone | Q7 | Explains the Marketplace: linked there and found by search |
| `past_events` | Archive | `events_archive` {year: current year} | `events` | everyone | Q9 | The events archive: linked from the events page and found by search |
| `add_organization` | Add organization | `add_organization` | `organizations` | signed_in | Q4 | Creates an organization: a button on Organizations and search |
| `suggest_feature` | Suggest a Feature | `feature_request_create` | `feature_requests` | member | Q1 | Action on Ideas & feature requests (members): a button there and search |
| `ladder_solo_500` | Solo | `ladder_solo_500_pieces` | `leaderboards` | everyone | Q9 | A ranking per category and piece count: Leaderboards |
| `ladder_solo_1000` | Solo | `ladder_solo_1000_pieces` | `leaderboards` | everyone | Q9 | A ranking per category and piece count: Leaderboards |
| `ladder_pairs_500` | Pair | `ladder_pairs_500_pieces` | `leaderboards` | everyone | Q9 | A ranking per category and piece count: Leaderboards |
| `ladder_pairs_1000` | Pair | `ladder_pairs_1000_pieces` | `leaderboards` | everyone | Q9 | A ranking per category and piece count: Leaderboards |
| `ladder_groups_500` | Team | `ladder_groups_500_pieces` | `leaderboards` | everyone | Q9 | A ranking per category and piece count: Leaderboards |
| `ladder_groups_1000` | Team | `ladder_groups_1000_pieces` | `leaderboards` | everyone | Q9 | A ranking per category and piece count: Leaderboards |
| `country_ranking` | Country | `ladder_country` {countryCode: viewer's country} | `leaderboards` | member | Q9 | Your country's ranking (members): a filter of Leaderboards and search |
| `achievements_list` | Achievements | `badges_overview` | `achievements` | xp_visible | Q9 | Every achievement: footer, achievements pages and search (XP gate) |
| `xp_explainer` | How XP & Levels work | `xp_explainer` | `progression` | xp_visible | Q7 | Explains XP: How progress works and search (XP gate) |
| `fair_play_xp` | Fair play & trust | `xp_fair_play` | `progression` | xp_visible | Q7 | Explains fair play: How progress works and search (XP gate) |
| `karma_explainer` | How karma works | `karma_explainer` | `progression` | xp_visible | Q7 | Explains karma: How progress works and search (XP gate) |
| `first_try_conflicts` | First-try conflicts | `review_results` | `review_results` | signed_in | Q8 | Old name of Review your results (the old URL redirects there) |
| `hub_guest` | Hub | `hub` | `home` | guest | Q10 | Guests' Home is the homepage (D5); the old guest hamburger link to the Hub stays findable (R11) |

## Not in the Menu

Linked by a navigation surface, but not a Menu destination - each with why.

| Entry | Label | Target | Audience | Rule | Reason |
|---|---|---|---|---|---|
| `notifications` | Notifications | `notifications` | everyone | Q5 | Inbox tab For you, behind the Inbox row and the top-bar Inbox tool; guests have no Inbox (today's guest bell leads to sign in) |
| `messages` | Messages | `conversations_list` | signed_in | Q5 | Inbox tab Messages (D3) |
| `register` | Register | `register` | guest | Q5 | The guest card's Join free link |
| `my_profile` | My profile | `my_profile` | everyone | Q8 | Legacy redirect (your profile, or sign in for guests); the Profile tab and the You card link player_profile directly (D9) |
| `player_page` | Profile | `player_profile` | everyone | Q2 | Object page: others' profiles are reached from lists, search and the player card |
| `player_card` | Profile | `player_card` | everyone | Q2 | Layer of a player name (D12); its page is the no-JS fallback |
| `puzzle_page` | Puzzle | `puzzle_detail` | everyone | Q2 | Object page: reached from lists, search and scans |
| `event_page` | Events | `event_detail` | everyone | Q2 | Object page: reached from Events (the footer lists WJPC editions) |
| `team_page` | Pairs & teams | `puzzling_team_detail` | everyone | Q2 | Object page: reached from results and Pairs & teams |
| `start_free_trial` | Membership | `start_free_trial` | signed_in | Q1 | Form action of the membership page and the members modal |
| `newsletter_subscribe` | What's new on MySpeedPuzzling | `newsletter_subscribe` | guest | Q1 | Form action of the footer's newsletter band |
| `newsletter_turn_on` | Turn it on | `newsletter_turn_on` | signed_in | Q1 | Form action of the footer's "Turn it on" line |
| `brand_hub` | Brands | `brand_puzzles` | everyone | Q2 | Footer popular searches (SEO hub); reached from Brands and the catalogue filters |
| `brand_pieces_hub` | Brand & size | `brand_pieces_puzzles` | everyone | Q2 | Footer popular searches (SEO hub); reached from Brands and the catalogue filters |
| `pieces_hub` | Piece counts & times | `pieces_puzzles` | everyone | Q2 | Footer popular searches (SEO hub); reached from Brands and the catalogue filters |
| `pieces_hardest_hub` | Piece counts & times | `pieces_hardest_puzzles` | everyone | Q2 | Footer popular searches (SEO hub); reached from Brands and the catalogue filters |
| `guide_time_by_pieces` | Speed Puzzling Guides | `guide_puzzle_time_by_pieces` | everyone | Q7 | Footer popular searches (SEO hub); reached from Brands and the catalogue filters |
| `guide_average_time` | Puzzle solve times by piece count | `guide_average_puzzle_time` | everyone | Q7 | Footer popular searches (SEO hub); reached from Brands and the catalogue filters |

## Rows not on both surfaces

| Entry | Phone panel | Docked column |
|---|---|---|
| `home` | not in Menu: Phones: a tab of the bottom bar (D1) | Home / 10 |
| `puzzles_place` | not in Menu: Phones: a tab of the bottom bar (D1) | Puzzles / 0 |
| `profile` | not in Menu: Phones: a tab of the bottom bar (D1) | Your puzzling / 0 |
| `you_card` | You / 10 | not in Menu: Desktop: the avatar ▾ account menu (README §4.3) |
| `inbox` | Inbox / 10 | not in Menu: Desktop: the Inbox tool of the top bar (D3, D16) |
| `logout` | Log out / 10 | not in Menu: Desktop: Log out in the avatar ▾ account menu (README §4.3) |

## Rulebook

- **Q1** Action on a thing that has a page: a button on that page
- **Q2** Page of one object: no nav entry, reached from lists and search
- **Q3** Only for a role: Menu › Your roles
- **Q4** Creates or records something: the Add page
- **Q5** Needed on every page: the top bar
- **Q6** Configures your account: Settings and search
- **Q7** Explains, helps, collects feedback, about MSP: Help & feedback
- **Q8** About one player: player tab or Menu › Your puzzling / Your puzzles / Account
- **Q9** Shared catalogue or community content: Puzzles or Community
- **Q10** Claimed top task: a place
