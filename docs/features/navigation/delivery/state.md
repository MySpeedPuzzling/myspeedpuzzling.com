# Delivery state — navigation redesign

**Single source of truth for progress.** Only the orchestrator edits this file. Every session starts by reading it and continues at the first item that is not `verified`. Status flow: `todo → in-progress → implemented → verified` (or `implemented (verify-after: <IDs>)`, `blocked: <reason>` / `blocked: owner`). `implemented` needs evidence; `verified` needs the item's **Verify** (requirements.md) re-run by the orchestrator in the delivery environment with the output summarised here. An item whose Verify needs a later item stays `implemented (verify-after: <IDs>)` with the evidence of what already ran, and becomes `verified` in the phase of the last named ID (requirements.md, Conventions "Verify-after"). A subagent's claim alone never makes an item `verified`.

Evidence = commit hash(es) · test names · command output summary · screenshot paths under `var/navigation-delivery/` (in the worktree) · notes.

## Environment (filled on first start)

| Key | Value |
|---|---|
| Base | `origin/main` at start: `9347ccdabb0f3d19fd9fc155c0ad12715ff95964` · 2026-10-12T01:07:33+02:00 (synced at every phase gate, see run log) |
| Last sync with `origin/main` | e6068ac7 (merge 56ef583e, 2026-10-12) |
| Delivery branch / worktree | `feature/navigation-redesign` / `.claude/worktrees/navigation` |
| Delivery DB clone | `speedpuzzling_nav_test` |
| Isolated web URL | `http://127.0.0.1:18080` (container `nav-web`, php -S; restart: `var/navigation-delivery/tools/restart-web.sh`) |
| Mailer | `nav-mailer` (axllent/mailpit, `--rm`, UI `127.0.0.1:18025`); `restart-web.sh` points nav-web's `MAILER_*_DSN` at it (without it every feedback POST 500s) |
| Selenium | `nav-delivery-chrome` on `127.0.0.1:14444` (selenium/standalone-chromium:latest); helper `var/navigation-delivery/tools/wd.py` |
| Gate scripts | `var/navigation-delivery/tools/{phpunit,paratest,phpstan,cs,console,encore,restart-web}.sh` (one-off containers; test DB `speedpuzzling_navsuite_test`) |
| Deps | composer.lock identical to main → main `vendor` mounted read-only. `node_modules`: own copy in the worktree from the Layers phase on (GEST-01 pins Turbo exactly → lock differs); tools no longer mount main's |
| P0-03 commit to deploy separately | `62c99662` (cherry-picks cleanly onto origin/main e6068ac7: every touched file equals origin/main at its parent). Not `b49d3c8d` (superseded, logged secret token paths) |

## Status

| ID | Item | Status | Evidence | Notes |
|---|---|---|---|---|
| P0-01 | Implementation plan | verified | f46505d7 · 12 area drafts (git-ignored `var/navigation-delivery/plan-drafts/`, they hold production figures) merged into `implementation-plan.md` (1031 lines: phases, cross-cutting rules, per-ID sections, ownership table, tests-to-change table, Help sheet transport §7, decisions 1–104, risk register) · fresh adversarial review `delivery/plan-review.md`: 28 findings (1 blocker, 9 major, 18 minor) → all resolved, 0 won't-fix · coverage script: `comm -23 <(requirement IDs) <(IDs in plan)` → 0 of 107 missing | |
| P0-02 | Baseline and budget pins | verified | 524a3305 + f6dfd77c (review F5: role-pure `MEMBER_ONLY` rows, chrome 5 / Hub 9; no pin moved) · orchestrator re-ran `phpunit.sh -- tests/Navigation/` → OK (20 tests, 172 assertions); agent's full ParaTest 9685 tests exit 0; phpstan OK, phpcs clean · `baseline.md` + `baseline-chrome-routes.json` exist; JSON surface×role: guest topbar 3/toolbar 5/hamburger 9/footer 37; player·member·organiser 3/15 dropdown/4/9/29; moderator +admin_key 4; admin +admin_key 14; absent pairs with reasons (admin_key non-mods, dropdown guests, live strip expired) · screenshots `var/navigation-delivery/baseline/screens/` (27) · no ids/names in committed files (grep) | pins: chrome guest 1 / signed-in 5 / mod+admin 6; Hub guest 1, player 8, member 9, admin 10; profile 11–24. Note: profile results already page 50 + Show more on main (biggest guest profile 366.6 KB). Lighthouse via frankenphp php-server prod build (QA-03 must reuse `scripts/lighthouse-web.sh`) |
| P0-03 | Navigation click instrumentation (current navigation first) | verified | 62c99662 (amended from b49d3c8d after P0 review F1–F4: secret path segments masked as {token} in browser + server, strict data-nav/page validation, Sentry sampler skips /-/ beacons, signed-in session test) · re-run after the fix: NavClickControllerTest OK (60/220), NavInstrumentationScriptTest OK (31/188), markup OK (6/134), SentryTracesSamplerTest OK (12/21), SentryMonologHandlersTest OK (8/157), tests/Navigation OK (22/190); `navlib.py check P0-03` 9/9 PASS; live beacon with a 64-hex reset token logged as `/password-reset/{token}` · original evidence: · orchestrator re-ran: NavClickControllerTest OK (25/94), NavInstrumentationMarkupTest OK (6/134; guest, member, admin, newsletter-off player: every header/footer a/button has a valid data-nav), NavInstrumentationScriptTest (node) OK (27/138), SentryMonologHandlersTest OK (8/157), tests/Navigation OK (20/172, budgets unchanged, guest public) · `navlib.py check P0-03` 9/9 PASS (viewport once/session 1280→1280_1439; non-nav click → nothing; button + link → msp:nav + beacon; Turbo visit + later full load → no 2nd viewport; history.back() → exactly one turbo_restore/back line; no console errors; server log 1 viewport + 2 click + 1 history on `navigation`) · agent's full ParaTest 9744 OK, phpstan OK, phpcs clean | **Deploy separately**: parent = origin/main e6068ac7 for every touched file → `git cherry-pick b49d3c8d` onto origin/main applies cleanly |
| F-01 | Navigation registry + guard test | verified | a31db772 · rendered re-run in the delivery worktree after all of Stream A (d4d406a7): tests/Navigation OK (202/10902) (cherry-pick of 90fefefd from `nav/stream-b-f`) · in navigation-b: tests/Navigation OK (158/6335) incl. NavigationRegistryTest (structure + rendered chrome 6 roles × /en/faq, /en/hub + baseline JSON), NavigationRegistryDocTest, NavigationServicesResetTest · orchestrator recorded: re-pointing the `wjpc` entry away from `wjpc_hub` → FAIL "Today's chrome links \"wjpc_hub\" (guest, footer) but no registry entry targets it"; hand edit of docs/navigation.md → FAIL "docs/navigation.md is out of date or was edited by hand"; both restored → green · `grep -rn "docs/" src/Navigation` → 0 | 126 entries: 73 phone rows, 73 dock rows (70 shared), 32 search-only, 18 not in Menu. Admin/mod rows keep today's English labels until MENU-01 (`nav_admin.*`); `send_feedback` → `feedback` until HELP-01a |
| F-02 | Place resolver | verified | a31db772 · NavigationPlaceResolverTest (in tests/Navigation OK 158): every route except admin_/api_/internal_api_/_/sitemap matched by exactly one rule (place or none), own/other table (player_profile, puzzle_library + list routes, player_statistics, team page, puzzle_detail, puzzle_add, stopwatch, hub, notifications, ladder variants, players, comparison, events, organization/series/edition/event detail) — spot-checked | consumers (aria-current) come with the bars/Menu |
| F-03 | Layout variables, fixed elements, overlays | implemented (verify-after: NAV-01, NAV-13) | 6c50dca4 · NavLayoutCssTest OK (40/155: closed states display:none, no JS sets --nav-left/--topbar-h, listed selectors use --bottom-nav-height/--nav-left, nothing at var(--header-height) animates top; mutation-checked) · tests/Navigation OK (198/6490, budgets unchanged; F-01 rendered check green after F-03) · `checks/F-03.py` skips cleanly (no .bottom-nav / column yet); simulated bar (F03_SIMULATE=1): 0 overlaps on 6 phone pages, compact bar at 256/88 in first frame · before/after screenshots identical at 360 (`var/navigation-delivery/F-03/`) | **NAV-03 must decide**: translating a sticky sub-bar also moves it while not stuck (needs a stuck-only condition); `.ev-month-header` already had `transition: top` (allow-listed, NAV-03/NAV-07 decide); `--bottom-nav-height` without `:has()` → 0 (NAV-01 decides fallback); `--topbar-h` = `var(--header-height)` until NAV-03 sets 52/64 px |
| F-04 | Meta fixes | implemented (verify-after: PROF-05a, MENU-03) | d4d406a7 · HeadMetaTest OK (3/30: no maximum-scale, theme-color #ffffff, manifest JSON) · manifest grep `theme_color: #ffffff` · `navlib.py check F-04` PASS: head; catalogue filters (6), marketplace filters (11), add form (7), edit profile (10) all ≥ 16 px at 360 touch; no console errors; Results + Menu search rows skip until built · screenshots `var/navigation-delivery/screens/F-04/` (looked: filter row grows, placeholders fit) | selector specificity (0,2,1) skips controls already > 16 px; desktop unchanged |
| F-05 | Translation hygiene | verified | d4d406a7 · NavigationLabelHygieneTest OK (4/4412) · grep: no literal Marketplace/Messages in `_footer`, base header, `_unread_badge` → none; cs `menu.events` = "Události" (parsed); `menu.new_badge` referenced 0× and removed from 6 catalogues; only `new_badge` used · parse-compare: only those keys differ per locale | Mercure badge translates in the triggering request's locale until INB-02 (numbers-only stream) |
| COM-01a | Community route skeleton | verified | 885ab657 · CommunityControllerTest OK (8/31): guest + member 200, three entries, guest `Cache-Control: public`, index/follow, canonical + 6 hreflang · curl /en/community, /de/community, /komunita 200 public · screenshots `var/navigation-delivery/skeletons/community*.png` (looked) | sitemap-static + crawlable robots row come with COM-01 |
| LAUNCH-02a | "Where did it go?" skeleton | verified | 885ab657 · WhereDidItGoControllerTest OK (7/21): 200 guest + member, noindex · curl /en/where-did-it-go, /es/donde-esta-ahora 200 public noindex | content in LAUNCH-02 |
| NAV-01 | Phone bottom bar | todo | | |
| NAV-02 | Label fallback | todo | | |
| NAV-03 | Phone top bar | todo | | |
| NAV-04 | Desktop top bar | todo | | |
| NAV-05 | Desktop account menu | todo | | |
| NAV-06 | Remove old chrome | todo | | |
| NAV-07 | Turbo behaviour without layout shift | todo | | |
| NAV-08 | Bar-free contexts and keyboard | todo | | verify-after: ADD-04 (plan decision 101) |
| NAV-09 | Guests | todo | | |
| NAV-10 | Home = Hub for signed-in players | todo | | |
| NAV-11 | Accessibility | todo | | verify-after: NAV-06, PROF-05a, CARD-01a (plan decision 101) |
| NAV-12 | No orphaned pages | todo | | |
| NAV-13 | Desktop docked menu, rail and overlay | todo | | |
| NAV-14 | Native-ready navigation | implemented (verify-after: NAV-01, NAV-03, NAV-04, NAV-13, MENU-02b, HELP-01b, CARD-01a, GEST-01, GEST-02, GEST-06, GEST-10, GEST-12, GEST-15) | 89de282a foundation · PlatformDetectorTest OK (21/123, old + new markers), NativeAppNavigationTest OK (12/187: /en/faq, puzzle page, member Hub × iOS/Android native UA → data-native-app, no web chrome, guest not public; web UA → no attribute, header, guest public; notifications, conversations_list, player_card, achievement_detail plain routes 200 no chrome), AnonymousCacheHeadersSubscriberTest OK (13/79), PlatformModuleTest OK (12/66), PlatformFlagReadersTest OK, NativeAppStepAsideTest OK · waits: data-bar-action (Stream B BarAction), menu/help rows, haptics grep (5 navigator.vibrate today → GEST-10), layer/gesture node rows | verify-after: NAV-01, NAV-03, NAV-04, NAV-13, MENU-02b, HELP-01b, CARD-01a, GEST-01, GEST-02, GEST-06, GEST-10, GEST-12, GEST-15 |
| MENU-01 | Menu partial and rows | todo | | from F review F3: `nav_viewer_audience()` must emit `xp_player` (signed in + XP visible) for such viewers, never for guests; admin/mod rows move to `nav_admin.*` keys; registry audiences table in docs/navigation.md |
| MENU-02a | Menu fallback route | implemented (verify-after: MENU-01) | 885ab657 · MenuPageControllerTest OK (7/19): guest/member 200, noindex/follow, guest public · NativeAppNavigationTest menu row · curl /en/menu, /ja/メニュー 200 | panel-row parity per role after MENU-01 |
| MENU-02b | Phone panel | todo | | |
| MENU-03 | One search for pages and settings | todo | | |
| MENU-04 | Menu completeness guard | todo | | |
| INB-01 | Inbox page | todo | | |
| INB-02 | Badge | todo | | verify-after: NAV-03, NAV-04, MENU-01, NAV-13 (plan decision 101) |
| INB-03 | Favorites' times out of the bell | todo | | |
| INB-04a | "New since your last visit" storage | todo | | |
| INB-04b | Favorites default and highlight | todo | | |
| INB-05 | Inbox pointer | todo | | |
| HELP-01a | Help route | verified | 885ab657 · HelpControllerTest OK (12/1165): guest/member full page 200, noindex/follow, guest public, Vary Turbo-Frame; frame → only frame (no <header>, no #site-menu), `private, no-store`, Vary; hostile ?url= ignored; native UA → full page; member frame = exactly 2 statements (empty signed-in endpoint) · RobotsTxtTest OK (help?url= disallowed ×6, bare allowed) · NativeAppNavigationTest help row · curl /en/help, /napoveda 200 public; frame header private no-store | `send_feedback` now targets `help` |
| HELP-01b | Help sheet | todo | | |
| HELP-02 | Order and context | todo | | |
| HELP-03 | Guests | todo | | |
| HELP-04 | End-of-page line | todo | | |
| HELP-05 | Phone grey-screen bug | todo | step (a) done: `var/navigation-delivery/HELP-05/repro.md` — normal send/close paths clean on Pixel + iOS; grey screen only via a race (send on slow connection, × while pending, reopen; late success stream lands within the 150 ms backdrop fade) because `dynamic_modal_controller.js:482-484` `isOpen()` ignores Bootstrap's showing state; (d) expired session reproduces as silent loss (login page's empty frame closes the modal) | fix in the general close path after GEST-01 (6 steps in repro.md) |
| ADD-01 | Modes and last mode | todo | | |
| ADD-02 | Tools under their intent | todo | | |
| ADD-03 | Running stopwatch pill | todo | | |
| ADD-04 | Stopwatch page | todo | | |
| ADD-05 | Guest Add explainer | verified | 885ab657 · AddIntroControllerTest OK (8/78): 6 locale paths 200, noindex/follow, public, Join → register, Sign in → login?return=/<locale>/puzzle-add, tracker app link, member → 302 puzzle_add, guest statements ≤ /en/faq pin · curl /en/add, /fr/ajouter 200 public noindex | |
| PUZ-01 | Puzzles sub-navigation | todo | | |
| COM-01 | Community landing previews | todo | | |
| COM-02 | Section chips | todo | | |
| PROF-00 | Results route skeleton | verified | 885ab657 · PlayerResultsControllerTest OK (12/48): public player 200 guest + member, 404 blocked + unknown, 302 private hidden · PlayerProfileQueryBudgetTest unchanged (tests/Navigation OK 262/11349) · Blocklist/PrivateProfile canaries + rows OK | |
| PROF-01 | Own profile, content first | todo | | |
| PROF-02 | Player header tabs | todo | | |
| PROF-03 | Overview content | todo | | |
| PROF-04 | Statistics receives charts/cards | todo | | |
| PROF-05a | Results route and parity | todo | | |
| PROF-05b | Paging | todo | | |
| PROF-05c | History and Back restore | todo | | |
| PROF-05d | Search | todo | | |
| PROF-05e | Old links | todo | | |
| PROF-06 | Rank on rows | todo | | |
| PROF-07 | SEO | todo | | |
| PROF-08 | Canaries and coverage | todo | | |
| CARD-01a | Trigger and behaviour | todo | | |
| CARD-01b | Surfaces | todo | | |
| CARD-01c | Shared actions service | todo | | |
| CARD-02 | Context blocks | todo | | |
| CARD-03 | Content and actions | todo | | |
| CARD-04 | Own card | todo | | |
| CARD-05 | Privacy | todo | | |
| CARD-06 | Budget | todo | | |
| CARD-07 | Measurement | todo | | |
| TEASE-01 | Teaser scope for this release | todo | | |
| GEST-01 | Back closes the top layer | verified | 34a1361d · LayerHistoryScriptTest (node) OK (25/211: owner set — first resume doesn't restart Turbo, swap = replaceState no back(), popstate closes, replace marking incl. forms, data-turbo=false → location.replace, native → no push, identity restore) · TurboHistoryOwnershipTest OK (no direct pushState / Turbo history callers left) · TurboContractScriptTest OK (Turbo 8.0.23 internals) · AchievementInfoSheetTest OK, LiveResultsScriptsTest OK, NativeAppStepAsideTest OK, NavInstrumentationScriptTest OK (Back line unchanged, layer closes not counted) · grep CloseWatcher → none · `navlib.py check GEST-01` 52/52 PASS (Pixel + iOS UA, test page + achievement sheet: back()/×/Esc/scrim close with probe holding, one more back leaves, link inside = replace → opener page at pre-open index, Forward doesn't reopen, no console errors) + GEST-01-live-results 5/5 | layer re-runs: MENU-02b, HELP-01b, CARD-01a, NAV-03 search view, NAV-13 overlay, PROF-05c. PROF-05c must call replacePageState on its first entry. `data-turbo=false` *forms* in a layer still add an entry (plan covers links). Docs to fix in DOC-01: xp-levels design-spec.md:390, hotwire guide ~260/~1072 |
| GEST-02 | Swipe down closes bottom sheets | implemented (verify-after: HELP-01b, CARD-01a) | b01dbdc3 (cherry-pick of 98bc802b from `nav/gest-02`) + f836d43c (duplicate import removed) · re-run on the combined delivery branch: GEST-02.py 46/46 PASS, GEST-01.py 52/52 PASS · orchestrator re-ran in worktree C: SheetDragGestureTest (node) OK (35/104), AchievementInfoSheetTest OK, PuzzleResultDetailControllerTest OK, NavLayoutCssTest OK (42/160), LayerHistoryScriptTest OK, StimulusControllerLoadingTest OK · `SITE=http://nav-web-c:8000 navlib.py check GEST-02` 46/46 PASS (Pixel + iOS UA: achievement + result-detail sheets — 120 px closes with probe, 60 px slow springs back, 40 px flick closes, scrolled body scrolls, textarea scroll/selection no drag, draft survives close/reopen, reduced motion transition none, × visible) | Help sheet (HELP-01b) and card (CARD-01a) adopt it and re-run GEST-02.py |
| GEST-03 | Tap the active tab | todo | | |
| GEST-04 | Pressed states within 100 ms | todo | | |
| GEST-05 | No accidental zoom | todo | | |
| GEST-06 | Installed-app pull-to-refresh | todo | | |
| GEST-07 | Document scroller and motion rules | todo | | |
| GEST-08 | Back measurement | todo | | |
| GEST-09 | Platform attribute | verified | 89de282a · orchestrator re-ran: PlatformOsScriptTest OK (33/114: 27 device cases incl. the 14 of the Verify, PHP↔JS marker parity, identical inline text on /en/faq, a cs page, member Hub, head order), GuestHtmlDeviceIndependenceTest OK (3/48), HeadFlagsCoverageTest OK, StaleDocumentScriptTest + AssetFailureScriptTest untouched OK · `navlib.py check GEST-09` 23/23 PASS (Pixel→android, iOS UA-CH→ios, desktop→other, set at DOMContentLoaded, kept after Turbo visit; guest HTML for iPhone/Pixel/desktop differs only where two desktop fetches differ; guest public) · WebKit n/a (no Playwright run) | head script 1.2 KB (test caps 1300 B) |
| GEST-10 | Haptics + real iOS switches | todo | | |
| GEST-11 | Pressed feedback per platform | todo | | |
| GEST-12 | Place switches per platform | todo | | |
| GEST-13 | In-page confirmations | verified | 2f8235c0 · NativeConfirmAllowlistTest OK (4/43: grep over templates/ + assets/, allow-list = 4 admin confirms + 3 method definitions), NativeDialogAllowlistTest OK, ConfirmDialogScriptTest (node) OK (14/148), NavClickControllerTest OK (62/230, `dialog_suppressed` kind), NavInstrumentationScriptTest OK, LayerHistoryScriptTest OK, tests/Navigation OK (budgets unchanged), agent regression set 2850 tests OK · `navlib.py check GEST-13` 49/49 PASS (Pixel 360, iOS UA 390, desktop 1280: dialog before any request, Cancel/Esc/scrim send nothing + focus returns, action sends one request, inside Bootstrap modal, over the dynamic-modal layer Esc closes only the dialog + Back still closes the layer, real pages stopwatch delete / remove referee / Live table delete; iOS watchdog one beacon, none on Pixel/desktop; no native dialog; no console errors) · screenshots `var/navigation-delivery/GEST-13/` (looked) · orchestrator made `confirm-action` eager (lazy chunk let a first-visit Live delete run unasked) | `beforeunload` prompts stay (plan); `afterRestore` from loadedByTraverse() until GEST-15 |
| GEST-14 | Instant Back step 1: keep bfcache restores | todo | | |
| GEST-15 | Instant Back step 2: Turbo restores browse pages | todo | | iOS only if GEST-13 is verified (`INSTANT_BACK_IOS`) |
| GEST-16 | Swipe between Home's feeds | todo | | |
| GEST-17 | Thumbnail → puzzle page morph | todo | | |
| GEST-18 | Long-press a truncated tab shows its label | todo | | |
| GEST-19 | Installed-app pull indicator per platform | todo | | |
| SRCH-01 | Pages & settings in the global search | todo | | |
| FOOT-01 | Footer | todo | | |
| LAUNCH-00 | Launch constant | verified | a31db772 · NavigationLaunchTest (null, before/at/after, +14 d, +30 d, MockClock) OK in tests/Navigation · grep `const null\|string DATE` → one hit `NavigationLaunch.php:26 … = null;` · `docs/features/feature_flags.md` row added | consumers get their null/set-date tests in their own items |
| LAUNCH-01 | What moved | todo | | |
| LAUNCH-02 | "Where did it go?" content | todo | | |
| LAUNCH-03 | Pointers | todo | | |
| LAUNCH-04 | Announcement drafts | todo | | |
| LAUNCH-05 | Watch list | todo | | |
| DOC-01 | Project docs | todo | | |
| DOC-02 | Translations complete | todo | | |
| QA-01 | Screenshot matrix | todo | | |
| QA-02 | Behaviour checks | todo | | |
| QA-03 | Quality gates | todo | | |
| QA-04 | Independent post-implementation review | todo | | |
| QA-05 | Full re-verification | todo | | |
| QA-06 | Device check (owner) | todo | | |

## Phase gates

Order per requirements.md "Phase order" (the plan may refine it). A gate passes with `implemented (verify-after: …)` rows listed in its evidence. Gate = phpstan · cs-fix (no diff) · paratest (Project Test Suite) · schema validation on a scratch DB from committed migrations · cache:warmup · phase review file `delivery/reviews/<phase>.md` with all findings resolved.

| Phase | Items | Status | Evidence |
|---|---|---|---|
| P0 | P0-01…03 | verified | HEAD f46505d7 (+ state): phpstan [OK] No errors · cs-fix no diff · schema in sync (scratch `speedpuzzling_navschema_test` from committed migrations) · cache:warmup OK · ParaTest Project Test Suite 9798 tests OK (27 notices, 5 skipped — pre-existing) · review `delivery/reviews/P0.md` F1 blocker + F2–F5 minor all resolved (62c99662, f6dfd77c) · plan review F1–F28 resolved |
| F | F-01…05 (F-03 verify-after NAV-01, NAV-13; F-04 verify-after PROF-05a, MENU-03), LAUNCH-00, NAV-14 foundation (verify-after, see its row), GEST-09 | verified | HEAD 40f208d4: phpstan [OK] · cs-fix no diff · schema in sync · cache:warmup OK · ParaTest 10097 OK (27 notices, 5 skipped pre-existing; first run 1 failure = RoundRevealMomentComputedOnlyHereTest false positive on NavigationLaunch → allow-listed fc0f8960) · review `delivery/reviews/F.md` F1–F3 major + F4–F6 minor resolved (40f208d4) · origin/main unchanged since e6068ac7 · open verify-after rows: F-03 (NAV-01, NAV-13), F-04 (PROF-05a, MENU-03), NAV-14 (list) |
| Skeletons | COM-01a, ADD-05, HELP-01a, LAUNCH-02a, MENU-02a, PROF-00 | verified | gate at 885ab657: phpstan [OK] · cs no diff · schema in sync · ParaTest 10203 OK · review `delivery/reviews/Skeletons.md` F1–F5 minor resolved in cc3dfe6a (targeted re-runs: HelpPageContextFactoryTest 26/126, AddIntro + Community controller tests, tests/Navigation 263/11354; phpstan on the factory OK). Full-suite re-run of cc3dfe6a folded into the Layers gate (GEST-01 was mid-edit in the worktree). Open verify-after: MENU-02a (MENU-01) |
| Layers | GEST-01, GEST-02 (verified on the achievement sheet and a test page), GEST-13 | todo | |
| ADD-03 + INB | ADD-03 (verify-after NAV-03, NAV-04), INB-02, INB-01, INB-03, INB-04a, INB-04b, INB-05 | todo | |
| MENU + Help sheet | MENU-01, MENU-02b, MENU-03, HELP-01b | todo | |
| NAV (alongside old chrome) | NAV-01…05, 07…11, 13 (NAV-11 verify-after NAV-06) + F-03 overlap checks | todo | |
| Removal | NAV-06, NAV-12, MENU-04 | todo | |
| HELP | HELP-02…05 | todo | |
| ADD | ADD-01, ADD-02, ADD-04 | todo | |
| PUZ + COM | PUZ-01, COM-01, COM-02 | todo | |
| PROF | PROF-03, PROF-01, PROF-02, PROF-04, PROF-05a…e, PROF-06…08 | todo | |
| CARD + TEASE | CARD-*, TEASE-01 | todo | |
| GEST | GEST-03…07 | todo | |
| Platform feel | GEST-10, GEST-11, GEST-12, GEST-16, GEST-17, GEST-18, GEST-19 | todo | |
| Instant Back | GEST-14, GEST-15, GEST-08 (iOS included only when GEST-13 is verified; decision logged) | todo | |
| SRCH + FOOT | SRCH-01, FOOT-01 | todo | |
| LAUNCH + DOC | LAUNCH-01…05, DOC-01, DOC-02 | todo | |
| QA | QA-01…06 | todo | |

## Decisions log
Decisions taken during delivery where the spec was silent (date · ID · decision · reason). Never reverse a README decision here; if one is impossible, mark the item `blocked` with options.

| Date | ID | Decision | Reason |
|---|---|---|---|
| 2026-10-12 | env | Inside the delivery worktree every phpunit/paratest run uses DB `speedpuzzling_navsuite_test`; parallel agents in their own worktrees use their own `speedpuzzling_<name>_test` | `tests/.database.cache` is per worktree, not per DB: a second DB name in the same worktree is skipped as "current" and never created |
| 2026-10-12 | P0-01 | R1 footer/Menu "New" badges expire 14 days after `NavigationLaunch::DATE` (shown while null) | README §7 rulebook "gone after first visit or 14 days"; drafts disagreed (14 vs 30) |
| 2026-10-12 | P0-01 | R2 "Where did it go?" link follows its registry audience (signed-in) on every surface; the page stays public | README §4.12 hides it for guests; consistency between Menu, Help sheet and footer |
| 2026-10-12 | P0-01 | R3 top bar and Menu panel each hold their own `<twig:GlobalSearch>`; `global_search_controller.js` scoped to its element | README §4.12 allows a second instance (empty query = no statement); avoids moving one Live component between DOM slots |
| 2026-10-12 | P0-01 | R5 `NavigationLaunch` is a service: `DATE` constant (null) + overridable date for tests; consumers never read the constant | a null constant cannot be tested "with a set date" |
| 2026-10-12 | F review | Audience model: every `NavVisibility` case names all its conditions; new `XpPlayer` (signed in + XP visible) for own XP pages; `includesGuests()`; pages without `playerId` in the URL name their owner via `NavigationPlaceResolver::PAGE_OWNER_ATTRIBUTE` (listed in `NavigationPlaceMap::OWNER_FROM_CONTROLLER`) | personal rows must never reach guests; others' collection pages belong to Community (README §3) |
| 2026-10-12 | P0-01 | Decisions 1–104 of `implementation-plan.md` §8 adopted as delivery decisions (incl. R1–R14 orchestrator resolutions, the plan-review fixes 92–104: Turbo pinned 8.0.23 + contract test, Help-only own scrim, GEST-15 guard scope + `post_action`, test-DB rule) | recorded once in the committed plan instead of duplicated here; later deviations are logged below |

## Run log
One line per session and per phase: date · what was done · next step.

| Date | Note |
|---|---|
| 2026-10-12 | Session 1 start: worktree created from origin/main 9347ccda; spec committed (a76cd36b); clone `speedpuzzling_nav_test` restored + migrated (at Version20261011130000); assets built; nav-web + Selenium up; next P0-01 (12 exploration drafts in parallel) |
| 2026-10-12 | P0-02 verified (524a3305). origin/main moved 9347ccda→e6068ac7 (7 commits, XP/karma/achievements; no migrations, no lock changes): merged as 56ef583e before committing P0-03 so P0-03 cherry-picks cleanly; one conflict (account toggle class vs data-nav) resolved keeping both. P0-03 verified (b49d3c8d). Plan drafts moved to git-ignored `var/navigation-delivery/plan-drafts/` (they hold production figures). Next: plan review findings → commit P0-01 → P0 gate |
| 2026-10-12 | **P0 gate verified.** P0 summary: plan (f46505d7) from 12 area drafts + adversarial review (28 findings resolved); baseline pins + measurements (524a3305, f6dfd77c); click instrumentation (62c99662, deploy separately; the P0 review caught secret reset/claim tokens in logged paths → masked as {token} before commit was final). Next: phase F — Stream A (delivery worktree: GEST-09 + NAV-14 foundation → F-03 → F-04 + F-05) ∥ Stream B (worktree `navigation-b`, branch `nav/stream-b-f`: F-01 → F-02 → LAUNCH-00) |
| 2026-10-12 | **Phase F gate verified.** Two parallel streams: A in the delivery worktree (GEST-09 data-os head script + NAV-14 native foundation 89de282a, F-03 layout variables/base split 6c50dca4 with no visual change, F-04/F-05 meta + translation hygiene d4d406a7), B in worktree `navigation-b` (registry 126 entries + place resolver + launch service, cherry-picked a31db772; worktree + DBs removed). Review fixes 40f208d4 (touch rule never shrinks, others' collections → Community, XpPlayer audience, modals above the bar, form targets guarded). Next: skeletons (COM-01a, ADD-05, HELP-01a, LAUNCH-02a, MENU-02a, PROF-00) in one sequential agent (all touch translations, registry, place map) |
| 2026-10-12 | **Skeletons gate verified** (see phase row). HELP-05 (a) repro done (normal paths clean; race in the dynamic modal's isOpen(); expired session = silent loss). Delivery mailer `nav-mailer` added (feedback POSTs need it). Worktree got its own node_modules (Turbo pin). Next: Layers — GEST-01 (in progress) → GEST-02 ∥ GEST-13 |
| 2026-10-12 07:50 | Layers gate: first ParaTest run had one worker die (`PuzzleDetailControllerTest::testRelatedPuzzlesShareTheBrandAndPieceCount`: a request error whose debug error page ran out of 512 MB rendering a cache-file excerpt); the test passes alone (76/76) and the full re-run at the same HEAD 2fba145a is green (10305 OK) → recorded as a flake (likely the test-env container cache rebuilt by parallel workers right after a commit); watch at later gates |
| 2026-10-12 06:59 | Owner going offline ~09:15 CEST: planned pause. No new agents after ~08:40; everything committed before the stop; resume point written here |
| 2026-10-12 08:07 | Provisional resume point (pause ~09:15): the delivery branch is committed and clean at this line. Layers review (`reviews/Layers.md`: F1 major, F2–F9 minor) is being fixed in worktree `.claude/worktrees/navigation-c` (branch `nav/layers-fix`, web `nav-web-c`, test DB `speedpuzzling_navc_test`). **On resume:** read the fix agent's result (or inspect `git -C .claude/worktrees/navigation-c status/log`), verify (LayerHistory/SheetDrag/ConfirmDialog node tests + `navlib.py check GEST-01/02/13` with `SITE=http://nav-web-c:8000`), commit there, cherry-pick into `feature/navigation-redesign`, remove worktree C + its DBs, re-run the Layers gate, mark the Layers phase verified; then sync `origin/main` (main moved: a4f3b387 et al.) and continue with phase ADD-03 + INB (ADD-03 → INB-02 → INB-01 ∥ INB-03 → INB-04a → INB-04b → INB-05) |

## Final report
(written at the end: what shipped, evidence summary, blocked items with options, owner steps — README §11)
