# Feature Flags

This file documents all active feature flags in the codebase — where they are, what feature they gate, and when they can be removed.

> **Operational note on flags exposed as Twig globals.** A flag registered in `config/packages/twig.php` is resolved on **nearly every page render**, not only where it is used - an unresolvable env var there breaks every page, not one. The image always carries the defaults from the committed `.env` (`.dockerignore` excludes only `.env.*`; production resolves them from `/app/.env` unless the box overrides them). Keep every such flag defined in `.env`.

## Retired: `FREE_TRIAL_ENABLED` (removed 2026-09-21)

The free trial (`docs/features/free-trial/README.md`) shipped dark behind it for a few hours and was then rolled out to everyone - Jan's call. Nothing reads it; a box `.env` that still sets it is harmless. There is no kill switch: pausing the offer means a code change (`PlayerProfile::canStartFreeTrial()` is the one door).

## Retired: Auth0 migration flags (removed 2026-09-18, Phase 6)

`NATIVE_REGISTRATION_ENABLED`, `NATIVE_LOGIN_ENABLED`, `AUTH0_TRICKLE_LOGIN_ENABLED`, `AUTH0_FALLBACK_LOGIN_ENABLED` and `SIGN_IN_CHANGES_NOTICE_ENABLED` were deleted with the Auth0 stack (`docs/features/auth-migration/implementation-plan.md` Phase 6). Native registration, login and password reset are unconditional; the trickle gateway, the `/login/auth0` fallback, the `/login` footnote and the "sign-in is moving" explainer page (its URLs now 301 to `/login`) are gone. A box `.env` that still sets any of them is harmless - nothing reads them.

## Retired: `SOCIAL_LOGIN_FACEBOOK_ENABLED` + Meta App Review preview (removed 2026-09-30)

Meta App Review approved + app published (2026-09-30), so Facebook lost its flag and follows the credentials rule like Google, Microsoft and Apple: available and shown iff `FACEBOOK_APP_ID` + `FACEBOOK_APP_SECRET` are configured (`SocialLoginSettings::isAvailable()`; templates ask `social_login.isProviderAvailable('facebook')`). `isShown()` / `isProviderShown()` / `isAnyShown()` collapsed into `isAvailable()` / `isProviderAvailable()` / `isAnyAvailable()`. The review preview (`?facebook_preview=1`, the `msp_fb_preview` cookie set by `NativeAuthPageSubscriber`, `FacebookReviewPreviewTest`) is gone too - a leftover cookie in a browser is harmless, nothing reads it; so is a box `.env` / Infisical that still sets the flag. Kill switch = empty the credentials.

## Navigation launch date (`NavigationLaunch::DATE`)

- **Feature:** the navigation redesign's launch safeguards (`docs/features/navigation/README.md` §8, requirement LAUNCH-00): the "What moved" announcement modal, the 30-day pointers (Hub banner, Inbox note, the line at the top of the Menu) and the expiry of the footer/Menu "New" badges
- **Flag:** the constant `SpeedPuzzling\Web\Navigation\NavigationLaunch::DATE` (`'Y-m-d'`, a day in Europe/Prague) → container parameter `navigation.launch_date` (`config/services.php`) → the `NavigationLaunch` service: `hasLaunched()`, `pointersActiveFor(PlayerProfile)`, `accountPredatesLaunch()`, `newBadgesVisible()`; Twig `nav_launched()`, `nav_new_badges_shown()`. Consumers never read the constant; tests replace the service with a `MockClock` and a date
- **Default:** `null` until the owner sets it at release - no "What moved" modal, no pointers, "New" badges shown. The merge may deploy the new chrome before the date is set. Setting it = one line (`public const null|string DATE = '2026-…';`), deployed like any change
- **Gated files:** `src/Navigation/NavigationLaunch.php` (the decision), `src/Twig/NavigationTwigExtension.php` (Twig functions); consumers added later list themselves here - LAUNCH-01 (What moved rule), LAUNCH-03 (pointers), INB-05 (Inbox pointer: `src/Navigation/NavigationPointers.php` - the pointers' one rule, Twig `navigation_pointer('inbox')` - and `templates/inbox/_favorites_pointer.html.twig`), FOOT-01/MENU-01 ("New" badges)
- **Remove when:** the pointers have expired (`DATE` + 30 days) - then delete the pointers, the "What moved" rule's launch check and the "New" badges of the launch, and the constant with them

## Pairs & teams picker rollout (`PAIRS_TEAMS_PICKER_PUBLIC`)

- **Feature:** the Solo / Pair / Team picker of the add/edit time form (`docs/features/pairs-and-teams/README.md`)
- **Flag:** env var `PAIRS_TEAMS_PICKER_PUBLIC` → parameter `pairsTeamsPickerPublic` (`config/services.php`), read through `CoPuzzlerPicker::isEnabled()` and exposed as Twig global `pairs_teams_picker_public`. Twig global — keep it resolvable in `.env` (see the operational note at the top).
- **Default:** **ON** since launch (2026-09-20, Jan's call: full rollout, fix forward). It is the kill switch now: `0` sends everybody but admins (`is_granted('ADMIN_ACCESS')`) back to the old co-puzzler rows. Only the *form UI* is gated: teams are resolved for every group time, the "Pairs & teams" page, team pages and filters are live for everyone.
- **Gated files:**
  - `src/Services/CoPuzzlerPicker.php` — `isEnabled()`, the single decision point
  - `src/Controller/PuzzleAddController.php`, `src/Controller/EditTimeController.php` — pass `copuzzler_picker_enabled`; the old rows' favorites query only runs while the old UI renders
  - `templates/_solving_time_form.html.twig` — picker vs. old rows (`_group_puzzler_input.html.twig` + `add_copuzzler_controller.js`)
- **Remove when:** the picker has been live without trouble for a couple of weeks — then delete `templates/_group_puzzler_input.html.twig`, `assets/controllers/add_copuzzler_controller.js`, the `favorite_players` template variable of both controllers, `forms.choose_from_favorites` / `puzzle_add.teamplayer` / `puzzle_add.add_puzzler` / `puzzle_add.group_puzzling` / `puzzle_add.player_code_info` translations, and the flag itself

## Online competitions for organisers (`ONLINE_ROUNDS_FOR_ORGANISERS`)

- **Feature:** online timing of competition rounds - personal links, the participant page, the control room and the review (`docs/features/online-competitions/`, the prototype on its way to the first public online competition in Q1 2027)
- **Flag:** env var `ONLINE_ROUNDS_FOR_ORGANISERS` → parameter `onlineRoundsForOrganisers` (`config/services.php`, bound as `$onlineRoundsForOrganisers`) → `OnlineRoundsFeature::isAvailableTo(?string $playerId)`, the single decision point → voter attribute `ONLINE_ROUNDS_MANAGE` (`OnlineRoundsVoter`, subject = competition id: `COMPETITION_EDIT` **and** the feature). Not a Twig global - templates ask `is_granted('ONLINE_ROUNDS_MANAGE', competitionId)`
- **Default:** `0` (`.env`). Admins (`is_granted('ADMIN_ACCESS')`) always have it - they set up the test cups; `1` opens it to every organiser of an event (anyone with `COMPETITION_EDIT` on it)
- **Gated files:**
  - `src/Services/OnlineRounds/OnlineRoundsFeature.php` — `isAvailableTo()`
  - `src/Security/OnlineRoundsVoter.php` — `ONLINE_ROUNDS_MANAGE`
  - the organiser pages and menu entries of online timing check the voter (added by the Phase 1 streams - list them here)
    - C1 (organiser set-up): `src/Controller/OnlineRounds/{OnlineRoundSettings,OnlineLinks,OnlineLinksCsv,OnlineLinksQrSheet,RegenerateOnlineLink}Controller.php` (`denyAccessUnlessGranted(ONLINE_ROUNDS_MANAGE)`); the event's ⋯ menu "Online timing" (`templates/events/_manage_items.html.twig`, `is_granted('ONLINE_ROUNDS_MANAGE', item.id)` for online events); the round tools "Online timing" + "Personal links" (`templates/official_results/_round_tool_links.html.twig` → Twig `online_round_tools()` → `OnlineRoundTools`: `OnlineRoundsFeature` first - no statement while it is off - the voter last)
  - the scheduled start of the round stopwatch (stream D) - `templates/manage_round_stopwatch.html.twig`: "Start in 10 s", "Start at a set time" and "Cancel the start" only with `can_schedule` (`is_granted('ONLINE_ROUNDS_MANAGE', competitionId)`); `src/Controller/ScheduleRoundStartController.php` + `src/Controller/CancelRoundStartController.php` pass `requiredAttribute: ONLINE_ROUNDS_MANAGE` to `AbstractRoundStopwatchController::act()` (403 `{"error": "scheduling_unavailable"}` for JSON, a flash otherwise, nothing changes). Start / Stop / Reset and the countdown display stay for every organiser (`COMPETITION_EDIT`)
- **Not gated:** a personal link (`/{_locale}/compete/{token}` and its API) - the token is the access right, and a link exists only after someone the voter allowed turned online timing on. Nor are the places that show existing links to whom they already belong: the event page's "Open my competition page" (the participant's own link) and the participants sheet's read-only "Personal link" column (organisers with `COMPETITION_EDIT` of an event whose round uses online timing)
- **Tests:** `OverridesFeatureFlagEnv` (`overrideFeatureFlagEnv('ONLINE_ROUNDS_FOR_ORGANISERS', true)` before the kernel boots), `tests/Security/OnlineRoundsVoterTest.php`, `tests/Controller/ScheduledStartFeatureFlagTest.php`
- **Remove when:** the public launch opens online competitions to every organiser (implementation-plan.md 4.7) - delete the flag and `OnlineRoundsFeature`; `ONLINE_ROUNDS_MANAGE` becomes `COMPETITION_EDIT` alone (or the voter goes). The scheduled start may open earlier, right after the test cups (docs/TODO.md "Online competitions"): drop `can_schedule` and the two `requiredAttribute` arguments

## Retired: Google/Apple social login flags + admin-only stage (removed 2026-09-29)

`SOCIAL_LOGIN_ADMIN_ONLY`, `SOCIAL_LOGIN_GOOGLE_ENABLED` and `SOCIAL_LOGIN_APPLE_ENABLED` were deleted at the public launch of Google + Apple sign-in (both verified end to end in production, Jan's call). There is no admin-only stage any more: the buttons render on `/login` + `/register` for everyone, rule-4 registration via the `/register/social` interstitial is on, and every signed-in player gets the "Connected sign-in methods" card. Google and Apple are now available **iff their credentials are configured** (`SocialLoginSettings::isAvailable()` — Google: `GOOGLE_CLIENT_ID` + `GOOGLE_CLIENT_SECRET`; Apple: `APPLE_CLIENT_ID` + `APPLE_TEAM_ID` + `APPLE_KEY_ID` + `APPLE_PRIVATE_KEY`), so local dev and tests without credentials show no button and 404 the provider's routes. Emptying a provider's credentials is the kill switch. A box `.env` / Infisical that still sets the old flags is harmless - nothing reads them. Microsoft (shipped 2026-09-30) follows the same rule from day one - available iff `MICROSOFT_CLIENT_ID` + `MICROSOFT_CLIENT_SECRET` are set, no flag.

## XP launch switch — `xp-launch` (replaced the `xp-system` env flag at the launch, 2026-10)

- **Feature:** XP / Levels / Achievements (`docs/features/xp-levels/`, decisions in `launch-2026-10.md`)
- **Switch:** `xp_settings.launch_ready_at` (one-row table, migration `Version20261010212501`) → `SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness` (`CachedXpLaunchReadiness`: shared cache pool `xp_launch_readiness_cache` on Redis, kept per request - no query per page view) → `XpFeatureGate::isVisibleFor(?PlayerProfile)` = **switch on OR admin**, `isEmailSendingEnabled()` = switch on. The old env var `XP_SYSTEM_ADMIN_ONLY` is gone
- **Status: ON since 2026-10-12 01:02 Prague** (`launch_ready_at` = 2026-10-11 23:02:26 UTC, set by the launch backfill - `xp-levels/launch-runbook.md` §"Launch log")
- **Who flips it:** nobody by hand. `myspeedpuzzling:xp-backfill` recalculates every player in its own process, then dispatches `MarkXpLaunchReady`, whose handler sets the marker **only when `GetXpLaunchVerification` passes** (every player with a counted solving time has ledger rows, `xp_total` = SUM(ledger), level matches XP, `achievement_points` = SUM of their achievements); the command then drops the cache entry so every worker and container sees it on its next request. `--verify-only` checks again (and flips) without recalculating; `--no-launch` recalculates and verifies without flipping
- **While off:** XP / level / achievement surfaces, `/my/xp-reveal` (redirects to the profile) and the other XP pages are for admins only; the launch announcement modal never opens and nothing is claimed; the activity digest is not sent. Admins see everything - to check production before the switch
- **Tests** run launched: the switch is `Tests\TestDouble\FixedXpLaunchReadiness` (static, `true`; `config/services_test.php`) so pages cost what production pays; a "before the launch" test sets `FixedXpLaunchReadiness::$ready = false` and `XpLaunchSwitchResetExtension` (phpunit.xml.dist) puts it back before every test; hand-built gates take `XpLaunchReadinessStub`; `XpLaunchSwitchTest` flips it off, `MarkXpLaunchReadyHandlerTest` covers the marker and `CachedXpLaunchReadiness`
- **Rollback:** `UPDATE xp_settings SET launch_ready_at = NULL;` then `bin/console cache:pool:clear xp_launch_readiness_cache` (or wait for the 5-minute TTL) - everything disappears for non-admins again, data stays
- **Gated files (pending cleanup):** components `BadgesProfileSection`, `XpRing`, `XpSolveReceipt`, `XpRecapCelebration`, `XpPuzzleEstimate`, `XpRevealInvite`; controllers `BadgesOverviewController`, `AchievementDetailController`, `AchievementInfoController`, `ProgressLeaderboardController`, `XpHistoryController`, `XpExplainerController`, `FairPlayXpController`, `XpLaunchRevealController`, `XpShareCardController`, `RevealBadgeController`, `BadgeRevealsController`, `EditTimeController`, `EditProfileController`, `MyActivityController` ("your week"), `KarmaHistoryController`, `KarmaExplainerController`; the `{% if xp_system_visible() %}` wrappers in templates (`XpTwigExtension`: profile karma chip, progression hub links, footer, navbar ring, e-mail preferences' activity frequency); digest suppression in `PlanContentDigestRunsHandler` (outcome `not_ready`) + `SendPlayerContentDigestHandler`; `XpLaunchRevealRule`. The activity digest also leaves out achievements earned before `launch_ready_at` (`XpLaunchReadiness::readyAt()` in `PlayerPeriodActivityReader`) - the backfill stamped them, they are the reveal's, not news
- **Remove when:** the launch is settled (a few weeks after the switch went on): delete `XpFeatureGate`, the `xp_system_visible()` wrappers and the readiness check in `XpLaunchRevealRule`; `XpLaunchReadiness` + `launch_ready_at` can go with them. Then add `xp_leaderboard` (both tabs) and `achievement_detail` to `BlocklistCanaryTest` + `PrivateProfileCanaryTest` if not done yet

## Competition Table Layout (admin-only)

- **Feature:** Table layout management for competition rounds
- **Flag:** `is_granted('ADMIN_ACCESS')` check in template
- **Gated files:**
  - `templates/manage_competition_rounds.html.twig` — Tables button visibility
- **Remove when:** Table layout feature is ready for all competition organizers
