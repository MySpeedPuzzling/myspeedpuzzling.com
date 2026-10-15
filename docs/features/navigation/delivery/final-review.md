# QA-04 final review — navigation redesign

- **Commit reviewed:** `fe4a8d9306247f3c8f7b4257374a72c7c40a61aa` on `feature/navigation-redesign` (worktree `.claude/worktrees/navigation`), 2026-10-15. Start commit `9347ccdab`; the branch's own changes = `git diff $(git merge-base HEAD origin/main)..HEAD` (merge-base `7d4a0fd52`, 947 files).
- **Reviewer:** a fresh agent that wrote none of the code. Read-only; this file is the only one written.
- **Method:**
  - Read README (D1–D22, §4, §11, §12), `requirements.md`, `implementation-plan.md` §3 and §QA, `state.md` (status rows, decisions log), `baseline.md`, and the phase reviews.
  - **Done when, all 107 items:** split into five area reviews (P0/F/NAV · MENU/INB/HELP/ADD · PUZ/COM/PROF/CARD/TEASE · GEST · SRCH/FOOT/LAUNCH/DOC + translations). Each read the code against every item's Done when and checked that the tests its Verify names exist and assert the claim. Every major finding and most minor ones were re-checked by hand before landing here.
  - **PHPUnit runs** (the locked runner, by the area reviews):
    - NavigationOrphan, TopBar, PlaceResolver, ChromeQueryBudget, NativeAppNavigation, BottomNav and BarFreePages tests: OK, 208 tests.
    - MenuRows, Inbox, PageHelpLine, HelpController, HelpSheetItems, AddIntro, MenuCounts, GetInboxBadge, InboxBadgeParts, InboxPointer, MarkFavoritesSeenHandler and PuzzleAddLastMode tests: OK, 132 tests.
    - Translations, launch and footer tests: OK, 42 + 91 tests.
  - Node harnesses run directly: haptics (144-case matrix) and others.
  - `curl` against `http://127.0.0.1:18080` as a guest and with both native user agents.
  - Mechanical checks of lines 2–8 of the plan's checklist (commands below).
  - **UX:** the full QA-01 run at this HEAD (`var/navigation-delivery/qa01/summary.md`: 528 shots). `qa01/contact/` still held only two early sample sheets, so the en/de/ja member sheets at 320 and 1280 (Hub, Menu open, puzzle page, own profile, Results, Inbox) were montaged from the run's own shots. Help sheet, card sheet, fr 320 and guest Menu shots were added. The montages are in the session scratchpad, not committed (clone data).
- **Result:** **0 blocker · 4 major · 28 minor.** The status column is left for the orchestrator (`fixed <hash>` / `won't fix: <reason>`).

## Findings

| ID | Severity | Requirement | Evidence | Suggested fix | Status |
|---|---|---|---|---|---|
| R01 | major | HELP-01b, NAV-08, F-03 (UX) | **Phones: under the open Help sheet, the space the tab bar leaves is page, not scrim.** The sheet focuses its textarea on `shown.bs.modal` (`assets/controllers/help_sheet_controller.js:42,66,105`). Any focused text field hides the tab bar (`assets/styles/_nav-bars.scss:197`, CSS `:has(:focus)`). Every `.modal`, `.modal-backdrop` and the Help scrim end at `--bottom-nav-height` (`assets/styles/_dynamic-modal.scss:49-66`). So a ~56 px strip of the live page shows undimmed below the sheet and takes taps. QA-01 `en|de|ja/member/help@320-sheet.png` all show it. Where the focus raises no keyboard (iOS after the async frame load, README §4.5), nothing covers the strip. | Keep the bar while the focus is inside an open `.modal`/`dialog`, e.g. `:not(:has(.modal.show :focus))` on the hide rule. Or let backdrop and scrim reach `bottom: 0` while the bar is away. Add a QA-06 row: iPhone, open Help, look at the bottom edge. | fixed aae29aa45 |
| R02 | major | CARD-01b (+ CARD-01a "no triggers in modals") | **The card is not really wired on the comparison page.** Its only player link is "Someone at your speed" (`templates/components/ComparisonSimilarSpeed.html.twig:140`). That link carries `data-player-card aria-haspopup="dialog"` but renders inside the add sheet, a Bootstrap `.modal` (`templates/comparison/_add_sheet.html.twig:10`). `assets/player_card_rules.js:150` refuses links inside `.modal`, so the card never opens, and the link wrongly announces a dialog. No other comparison template links a player (grep). `tests/Component/ComparisonSimilarSpeedTest.php` only checks the attributes. | Either wire the card on the comparison page's subject names (the surface CARD-01b lists) and drop the trigger inside the sheet, or log that comparison has no card trigger and remove `data-player-card`/`aria-haspopup` there (as the result-detail modal does). | fixed aae29aa45 |
| R03 | major | GEST-15 (owner's Instant Back rule: never worse than before) | **The one-tap actions converted from forms to `post_action` links show no progress while their request runs.** On origin/main these were Turbo form submissions, and with `progressBarDelay = 0` (`assets/app.js:73`, same on main) Turbo shows its progress bar at once. Now `assets/controllers/post_action_controller.js:93-104` posts with `fetch` and only toggles `aria-busy`, which no stylesheet uses (`grep -rn aria-busy assets/styles` → none). The dropdown still closes on tap. On a slow phone, Remove from wishlist / collection / sell list, Return, Compare and Publish look like nothing happened until the answer arrives. | Show Turbo's progress bar around `postAction.run()` (the session adapter's form-submission hooks, or the progress bar's show/hide), or give `[data-controller~="post-action"][aria-busy]` a visible busy state. Add a node test. | fixed aae29aa45 |
| R04 | major | Conventions (tone: "Czech as surrounding keys"), DOC-02 | `translations/messages.cs.yml:1124` `edit_profile.preferences.vibration_help` says "ve **tvém** telefonu" (informal). The `edit_profile` family it sits in is formal: 23 formal forms, this is its only "ty" form (count over the family). It is visible on the Settings page (`templates/edit-profile.html.twig:62`, Android web). | "ve **vašem** telefonu". | fixed aae29aa45 (formal where the page is formal; decision 2026-10-15) |
| R05 | minor | NAV-02 / D14 ("no icon-only design") | **French at 320 px on a page without a place shows a tab bar with no labels at all.** Pages without a place include Inbox, the help page and the `menu` page. Step 3 keeps only the current tab's label, and there is no current tab. QA-01 `summary.md`: `fr/member inbox@320: labels … measured 0, visuallyHidden 5`, and `fr/member/inbox@320.png` shows icons only. | Decide and log it (long-press GEST-18 still names the tabs), or give place-less pages a step that keeps all five labels, e.g. 10 px with tighter tracking for fr. | fixed aae29aa45 |
| R06 | minor | NAV-02 | French 320 px: the current tab's "Communauté" label is 66 px in a 64 px tab (1 px over on each side). QA-01 geometry failures on `fr/member community@320`, `profile-other@320` and `card@320-sheet` (`"outside": true`). It is invisible in the shots, but a different device font could make it visible. | Re-measure fr in `_nav-label-steps.scss`, or let the current label use the tab's padding. | fixed aae29aa45 |
| R07 | minor | MENU-03 / NAV-03 (UX) | **The Menu panel's own search field has a placeholder that does not fit and does not name pages.** It reuses the long global-search placeholder: at 320 px fr "Rechercher puzzle ou joueur…" and ja "パズルまたはプレイヤーを検索" are cut (QA-01 `fr/member/menu@320-top.png`, `ja/member/menu@320-top.png`). In every locale it says puzzles/players only, although the panel lists "Pages & settings" first (README §4.12). NAV-03's placeholder-fit check covers only the top bar. | Use a placeholder that names pages and fits at 320 in all 6 locales (measure as NAV-03 does), e.g. the bar's one-word one. | fixed aae29aa45 |
| R08 | minor | Conventions (tone: de "Sie") | The Help sheet and the Menu's Help & learn row reuse main's XP key `progression_hub.help_label` / `menu_label` = "So funktioniert **dein** Fortschritt" (`messages.de.yml:8903,8912`). It sits next to the branch's formal texts in the same sheet ("Was möchten Sie uns mitteilen?", QA-01 `de/member/help@320-sheet.png`). The key predates the branch (the XP pages are informal in German), but this mix is new. | A navigation label in formal German (e.g. "So funktioniert Ihr Fortschritt" under a `nav.*` key), or log it as accepted. | fixed aae29aa45 |
| R09 | minor | GEST-15 (owner's rule) | `assets/controllers/post_action_controller.js:1` is `stimulusFetch: 'lazy'`. A tap before its chunk has loaded follows the link's `href` to the `post_action_confirm` view: one extra tap, and Space does nothing. On main the form posted in one tap without any controller. It is safe, but it is a step back in the first moments after a load. | Make the controller eager (it is small), as was done for `confirm-action`. | fixed aae29aa45 |
| R10 | minor | F-02 | `templates/navigation/_account_menu.html.twig:32` sets "View profile" `aria-current` from the raw route and a `playerId` string comparison, not from the resolver (which compares ids case-insensitively). An upper-case UUID URL marks the Profile tab current but not this row. | Take it from `nav_location()`, as the other surfaces do. | fixed aae29aa45 |
| R11 | minor | NAV-14 (d) | `templates/inbox/_link.html.twig:24-25` always renders `data-bar-action="inbox"` + `data-bar-count`, and the Menu's Inbox row embeds it (`templates/navigation/_menu_row.html.twig:28`). A signed-in page has two `[data-bar-action="inbox"]`, while the ids belong to the top-bar tools. `TopBarTest::testTheBarActionsAreTheEnumsCases` looks only inside the header. | Render both attributes only for `surface == 'topbar'`; assert page-wide uniqueness. | fixed aae29aa45 |
| R12 | minor | CARD-02 ("invalid context ignored") | `GET /en/puzzler-card/<id>?context[]=puzzle` with `Turbo-Frame: player-card` → **400**, while `?context=bogus` → 200 (curl). Cause: `src/Controller/PlayerCardController.php:86` `$request->query->get('context')` throws on an array. | `$request->query->all()['context'] ?? null` (`PlayerCardContext::tryFrom()` already rejects non-strings), plus a test row. | fixed aae29aa45 |
| R13 | minor | LAUNCH-02 | For a guest, four rows on `/en/where-did-it-go` say "Now" without "after signing in" and link Menu rows a guest's Menu does not have: Log out, footer Achievements, and What next? ×2. The links are `/en/menu#menu-row-{logout,achievements,what_next}`; the guest `/en/menu` has none of those ids (curl). | Mark them "after signing in" for guests (`NavigationSignInRule`), or point guests to where the item is for them. | fixed aae29aa45 |
| R14 | minor | TEASE-01 / README §6 rule 5 | The two members locks on the new Results page carry no `data-members-placement`: `templates/player/results/_filters.html.twig:116` (filters) and `_frame.html.twig:107` (sorts). They are counted as `none` (decision CARD-07). | Add `results.filters` / `results.sort` placements, or note in README §6 that rule 5 covers new teasers only. | fixed aae29aa45 |
| R15 | minor | TEASE-01 | `tests/Navigation/MembersTeaserScopeTest.php` `NO_TEASER_TEMPLATES` leaves out the footer partials included from base. They hold no teaser today, so nothing guards against a future one. | Add `_footer*.html.twig`. | fixed aae29aa45 |
| R16 | minor | CARD-02 (translation) | `players.card.context.round_unofficial` says "puzzler" in es (`messages.es.yml:1281`) and fr (`messages.fr.yml:1314`). The card itself and most of the catalogue say "puzzlero" / "puzzleur". | Use "puzzlero" / "puzzleur". | fixed aae29aa45 |
| R17 | minor (owner's call) | Conventions (cs tone) | The new Inbox keys are informal Czech: `inbox.tab.for_you` "Pro tebe" and the pointer "tvých… najdeš". They render on the notifications page, whose existing `notifications.*` texts are formal. The Czech catalogue is mixed: newer areas use "ty", older ones "vy". | Owner decides which tone "surrounding" means here; at least make one page consistent. | fixed aae29aa45 (decision 2026-10-15: Czech as surrounding keys - the Inbox texts sit among formal ones) |
| R18 | minor (owner sign-off pending) | GEST-16 decision 2026-10-14 (UX) | The Hub's "Most active SOLO players" shows numbered empty rows "2 –", "3 –", "4 –" on every member Hub shot at 1280 in en/de/ja (QA-01, quiet clone). To a player this reads as missing data, and it is new compared with main, where the list was just shorter. The trade for CLS is logged and listed for sign-off in state.md. | Keep it only with the owner's sign-off. Alternatively reserve the height without visible numbers (empty space, no "–"). | fixed aae29aa45 (decision 2026-10-15: height kept, nothing shown) |
| R19 | minor (owner's call) | Privacy (plan §3.12) | The committed spec carries production usage figures from the local research: `docs/features/navigation/README.md` §1 (lines 13–15: shares of page views and sessions, the bell's notification share, the biggest profile's rows and size) and D9 (line 32: daily page and link uses). They come from the owner's own spec commit `a76cd36b3`, which is already on `origin/navigation-rework`. No delivery file adds any. | Owner decides. If they must go, reword without figures before the merge to main (the integration branch's history keeps them). | fixed 94d0be1f6 + 9ffc05cf8 (decision 2026-10-15; earlier commits on navigation-rework still carry the figures - squash merge recommended in the final report) |
| R20 | minor | QA-01 | 44 of the 48 QA-01 geometry failures are run errors: every en 360 and 390 shot (member and guest) was taken at `innerWidth` 320 (`summary.md`). Those shots are invalid, so QA-01 is not complete. | Re-take the en 360/390 batches (resize per batch, assert `innerWidth` first), then regenerate `qa01/contact/`. | fixed: QA-01 run.py opens a session per phone width and asserts innerWidth per shot; all 528 shots re-taken (QA-01 re-run on the final HEAD) |
| R21 | minor | Conventions (every visible string translated) | `templates/navigation/_search_camera.html.twig:10` `<span class="visually-hidden">Loading...</span>` is hard-coded English. The text predates the branch (`GlobalSearch.html.twig` on main), but the partial now renders on every member page. | A translation key in 6 locales (or add it to `docs/TODO.md` next to the mode-switch label). | fixed aae29aa45 |
| R22 | minor (uncertain) | PROF-07 | The bare Results URL always emits 7 hreflang links, also when it is `noindex, follow` (8 or fewer results; `src/Controller/PlayerResultsController.php:124-129`, asserted by `PlayerResultsSeoTest`). README §4.7 "indexable (canonical, hreflang) only when …" can be read as "no hreflang when noindex"; plan §PROF-07 says hreflang in both cases. | Owner settles the reading; align README or code. | fixed aae29aa45 (decision 2026-10-15: hreflang only when indexable) |
| R23 | minor (uncertain) | CARD-06 | `PlayerCardQueryBudgetTest` pins the per-open cost against `EmptySignedInEndpoint` but not "0 statements on page load". That number rests on the page budget tests, whose pins only went down. | Assert one surface page's count in this test, or say in requirements.md that the page budgets carry it. | fixed aae29aa45 |
| R24 | minor | GEST-15 / NAV-14 (doc) | Decisions log 2026-10-15 Q4 says native `no-cache` "keeps today's behaviour". Inside the app `restoreDecision()` returns `leave` (`assets/instant_back.js:211-213`), so a restore is Turbo's own fetch-and-render restore visit. Main's `app.js` sent every restore to `location.href` (a full WebView load). | Reword the reason, and confirm that a Turbo restore visit without a snapshot is the intended app behaviour. | fixed 94d0be1f6 |
| R25 | minor | INB-02 (doc drift) | README §4.4 (`README.md:128`) and `requirements.md:165` still name `MercureNotifier::notifyUnreadCountChanged`; the code has `notifyInboxBadgeChanged`. The stream carries numbers only, and `assets/inbox_badge.js` sets the link and name (tested by node `tests/inbox-badge-harness.mjs`, not by a PHP test as the Verify reads). | Update README §4.4; note the numbers-only stream in §12. | fixed 94d0be1f6 |
| R26 | minor | MENU-01 (doc drift) | README §4.12 quotes the Settings and Your data descriptions differently from what ships (`messages.en.yml:9506-9507`). Neither §12 nor the decisions log records the change. | Record the shortened descriptions in §12 or update §4.12. | fixed 94d0be1f6 |
| R27 | minor | MENU-03 (doc drift) | README §4.12 / the requirement say guests carry the fixed `data-viewer-audience` value `guest`. Pages render `everyone guest xp_visible` (curl `/en/faq`); the plan (line 346) has that value. It is identical for every guest, so caching is unaffected. | Align the README and requirement with the plan. | fixed 94d0be1f6 |
| R28 | minor | PROF-06 (doc drift) | The requirement and README §4.7 say "via `GetRanking::allForPlayer()`". The rows use `GetRanking::forPuzzles()` (`src/Services/PlayerResults/PlayerResultsBuilder.php:129`); `allForPlayer()` is only used for the Best rank sort (`:177`). Both run the same ranking statement. | Reword. | fixed 94d0be1f6 |
| R29 | minor | F-01 (doc drift) | `requirements.md` names `tests/NavigationRegistryTest.php`; the file is `tests/Navigation/NavigationRegistryTest.php`. | Fix the path. | fixed 94d0be1f6 |
| R30 | minor | LAUNCH-00 | `docs/features/feature_flags.md` "Navigation launch date" names consumers by requirement ID only ("consumers added later list themselves here"). The files are not listed: `NavigationWhatMovedRule.php`, `_launch_pointer.html.twig`, `_new_badge.html.twig`, `hub.html.twig`, `navigation/_menu.html.twig`, `FooterPlacement.php`. | List the gated files (CLAUDE.md "Feature Flags"). | fixed 94d0be1f6 |
| R31 | minor | LAUNCH-00 | The Twig function `nav_launched()` (`src/Twig/NavigationTwigExtension.php:78`) is registered and documented but no template uses it; only `NavigationLaunchTest` calls it. | Drop it, or mark it as unused API. | fixed aae29aa45 |
| R32 | minor | LAUNCH-04 | `delivery/announcement.md` §5's "State then" column still shows GEST-12/14/15/16/17/19 and LAUNCH-01…03 as todo or in progress; all are verified. The §1 ship-check markers are open although `INSTANT_BACK_IOS` is decided (`true`, decision 2026-10-15 Q1). | Refresh the column and resolve the markers. | fixed 94d0be1f6 |

**Seen in QA-01 but older than the branch (not counted; candidates for `docs/TODO.md`):**
- ja 320 px own profile: the owner button "共有" (Share) wraps one character per line, and "プロフィール編集" breaks mid-word. `PlayerHeader.html.twig` and `_player-header.scss` are unchanged by the branch.
- German notifications use "du" ("Du bist jetzt Community-Moderator …"). They now sit under the branch's formal "Für Sie" tab.
- The add form's hard-coded `aria-label="Form mode selection"` is already in `docs/TODO.md:53`.

## Checked and fine

### 1. Every item: Done when holds literally
All 107 items were reviewed; only R01–R32 hold back.
- **P0/F/NAV (23 items):**
  - P0-03 checked: `/-/nav-click` single-action, `navigation` channel excluded from Sentry, stateless firewall; listener matches `a[data-nav], button[data-nav]` only; viewport once per session; Back lines; every `data-nav` on 13 curled guest pages matches the pattern.
  - F-01: the registry test renders the chrome for 6 roles and checks the baseline JSON; nothing in `src/Navigation` reads `docs/`.
  - F-02: every route maps to one rule. F-03: `--nav-left` only in critical CSS; closed overlays are `display:none`. F-04: viewport, theme-color, manifest and the 16 px coarse rule. F-05: labels.
  - NAV-01…14 all hold:
    - ☰ is last with `aria-haspopup="dialog"`; the dot comes from the queue counts; 4 px gaps; no `dropdown-toggle` for places.
    - The NAV-06 grep on `base.html.twig` finds nothing. There is no `data-turbo-permanent`. Bar-free templates empty all four blocks.
    - `NavigationOrphanTest` loads the baseline JSON. `AnonymousCacheHeadersSubscriber:64` skips app requests.
    - Native user agents get `private` and no web chrome on faq, hub, puzzle, help, menu and community (curl).
- **MENU/INB/HELP/ADD (22 items):**
  - Row sets and order per role on both surfaces (`MenuRowsTest`: guest 25/28 … admin 70/71). `menu_counts` is signed-in only, `private, no-store`, and costs ≤ 2 statements over an empty endpoint.
  - `nav-search.json` is `public, max-age=86400` in 6 locales, with no `mod_`/`admin_` entries, and is never inlined.
  - The Inbox badge is one statement excluding `SubscribedPlayerAddedTime` and `NewConversationRequest`; the custom partial index is mirrored in `tests/bootstrap.php:264` and registered in `docs/database-indexes.md:30`. "99+" and the plural-aware accessible name are in place.
  - `NotifyWhenPuzzleSolved` is deleted, and its 9-case test is inverted.
  - `favorites_seen_at` / `last_add_mode` migrations are generated, additive and nullable, and written through Messenger handlers.
  - The help frame variant is `private, no-store` with `Vary: Turbo-Frame`; Help sheet order per README §4.5; the end-of-page line has `rel="nofollow"`; `add_intro` is public and noindex.
- **PUZ/COM/PROF/CARD/TEASE (26 items):**
  - Chips and the remembered section are client-side only (guest renders identical). `CommunityPageQueryBudgetTest` pins guest 3 / signed-in 6 / admin 7; Community is in the Draft, Blocklist, PrivateProfile and PlayersPage canaries and in `sitemap-static`.
  - Tab order is pinned by `PlayerHeaderTest`. The owner header has Share + Edit and no owner panel.
  - Every sort, filter and members gate of main's `PlayerSolvedPuzzles` maps to `PlayerResultsCriteria`.
  - The Results budget is independent of result count. Search goes through `PuzzleTextSearch`. No `player_profile` link with `team:` remains.
  - The rank parity test includes a hidden co-solver and suspicious times.
  - The card's privacy 404 is byte-identical for every unavailable case. Block and Challenge are absent from the card.
- **GEST (19 items):**
  - Only `layer_history.js` touches `pushState`/`session.history`; no `CloseWatcher`; `vibrate` appears only in `haptics.js` (table 20 / [30,100,30] / 10 / 10 / none / 30); every `form-switch` has `switch`.
  - `touch-action: none` appears only on handles plus the logged exceptions. Motion stays ≤ 400 ms except the logged 450 ms ripple.
  - `NativeConfirmAllowlistTest` uses the requirement's pattern. `INSTANT_BACK` / `INSTANT_BACK_IOS` are each defined once, with `location.href` as the fallback.
  - The 19 opted-in templates exactly match README G9; the guard covers forms, `confirm(` and `data-turbo-confirm`. `data-bfcache-reload` is on the listed and the live pages.
  - Owner's rule, except R03/R09: same fields and CSRF ids as main's forms, no confirmation added or removed, no-JS confirmation page, writes and sign-out never restored stale.
- **SRCH/FOOT/LAUNCH/DOC/P0-01 (11 items):**
  - SRCH-01: 5 queries × 6 locales; a pages-only query costs what every query costs.
  - FOOT-01: four columns; every old footer route still linked; "All pages" goes to `/en/menu`; public. `FooterPopularSearchesTest` is unchanged but still green, so "updated" in the requirement is wording only.
  - `NavigationLaunch::DATE` is `null` and defined once, with null tests for every consumer.
  - What moved: modal after the XP reveal, no `<img>`. "Where did it go?" covers the 66 baseline routes.
  - Pointers never reach guests. A missing registration date gets no modal but does get pointers: a logged decision (2026-10-14 LAUNCH-01/03), not a finding.
  - DOC-01 entries are all present.
  - DOC-02: 362 + 2 new keys, 0 missing, 0 removed keys still defined, every same-as-English value allow-listed.
  - P0-01: 107/107 IDs in the plan; plan review 28/28 resolved.

### 2. No deleted tests
`git diff --diff-filter=D --name-only 9347ccdab..HEAD -- tests` and the same from the merge-base: **empty**. No renames (`-M --diff-filter=R`). Two test methods were removed: `GetConversationsTest::testCountUnreadForPlayer*`, together with the method they tested. Their cases live on in `GetInboxBadgeTest` (`testNoConversationsNoMessages` names its origin; unread messages are counted in `testAPendingRequestIsCountedOnceUnderMessages`).

### 3. No weakened tests
- 100 test files are modified. Ranked by removed vs added assertion lines, they were sampled where removals were highest: `PlayerHeaderTest`, `PlayerBlocklistTest`, `ProfileProgressionTest`, `PuzzlePickerControllerTest`, `Admin/ModeratorAccessTest`, `Admin/PuzzleApprovalControllerTest`, `Component/Players/SearchTest`, `SetMyCountryControllerTest`, `PlayersThisWeekTest`, `ApiUsageControllerTest`, the budget tests and `GetConversationsTest`.
- Every replaced assertion targets the new markup with the same or a stronger intent. Examples: compare forms become `post_action` links asserting URL, fields and token; the Block modal becomes a confirmation frame and the test posts through it; Menu queue links are asserted exactly once page-wide; tab labels are pinned with their hrefs.
- Selectors were narrowed to `main …` only where the Menu now repeats an element.

### 4. Budgets
- **Existing pins only went down, each with a comment:** DetailPages 9→8 and 19→18; OrganizationPages 10→9 and 6→5; PlayersPage 10→9; EventsPage and OrganizedEvents `SIGNED_IN_OVERHEAD` 4→3; PuzzleAddPage all −1; PuzzleDetail 19→18 and 23→22; PageSectionsOnPages −1; ProfileProgression 11→5 and 22→11. Each comment names INB-02 or PROF-03/04.
- **The branch's own pins never rose** (`git log -p` over every pin line):
  - ChromeQueryBudget and HubQueryBudget: `INBOX` 2→1.
  - PlayerProfileQueryBudget: 11/14/17/19/23/24/22 → 5/7/10/11/11/12/11, each step commented.
  - PlayerResultsQueryBudget: rows rewritten in `4dfab9f79` with the same values, tied to the Overview pins.
  - MenuCounts, PlayerCard, PlayerStatistics and CommunityPage: unchanged since creation.
- `ChromeWeightBudgetTest` pins README §4.12's ceilings (20 / 30 KB over the P0-02 bytes). The whole-page reading of that budget is still on the owner sign-off list in state.md.

### 5. Guest caching
`curl -sI` as a guest on `/en/hub`, `/en/community`, `/en/add` (`add_intro`), `/en/help`, `/en/menu`, a puzzle page, `/`, `/en/faq`, `/en/puzzle` and `/en/where-did-it-go`: **all `Cache-Control: max-age=0, public, s-maxage=60`**. `nav-search.json` is `public, max-age=86400`.
- With `Turbo-Frame: modal-frame`, `/en/help` answers `private, no-store` and `Vary: Turbo-Frame`.
- Native user agents on faq, hub, help, menu and a puzzle page answer `private`.
- Guest HTML of `/en/hub`, `/en/community` and `/en/menu` fetched with an iPhone and a desktop user agent differs only in the dev toolbar. The only token on guest pages is the fixed stateless newsletter placeholder (`value="csrf-token"`).

### 6. ResetInterface
`tests/Navigation/NavigationServicesResetTest.php` reflects over `src/Navigation/*.php`, `src/Twig/Navigation*.php` and `EXTRA_CLASSES`: Help, AddEntry, Inbox, FavoritesFeed, AdminQueueCounts, CommunityPreview, PlayerResultsBuilder and PlayerActionsResolver. It fails on any non-static, non-readonly property without `ResetInterface`. A scan of every added or changed service in the branch diff found no mutable property outside a readonly class or a resetting service.

### 7. Privacy
- **Clone accounts:** every value in `var/navigation-delivery/baseline/accounts.local.json` (account and player ids, e-mails and their local parts, codes, passwords, profile ids) was searched in the branch's added lines: **0 hits**. The only matching value is a 3-digit result count, which matches unrelated code numbers and appears in docs only as the rounded "~N-result profile".
- **Clone people and events:** 128 player names scraped from the clone's guest pages (puzzlers, directory, community, recent activity, ladders, Hub, MSP rating) and 47 event names: **0 hits**. The world championship's name appears, as on main.
- **Research files:** names, codes and e-mails from `var/navigation-research/**`: 0 hits. Figures: only README §1/D9 (R19); the other matches are breakpoints, WebKit bug numbers and byte sizes.
- This file names no player, id, e-mail or business figure.

### 8. The owner's rules
- **SEO never costs UX / Instant Back never worse:** R03, R09 and R24. Otherwise held, see GEST above.
- **Tone:** de has no du/dein/dir/dich in new keys (only the reused main key, R08). fr has no tu/ton/ta/tes/toi; es uses tú forms. cs: R04 and R17.
- **No "rival"/"suspicious" in anything a person reads:** a grep of added template, translation and asset text finds only code identifiers (`puzzle.suspicious`). `NavigationSearchIndexTest::testNoSynonymSaysRivalOrSuspicious` guards the synonyms; the translated words (verdächt, suspect, sospech, podezř, 不審, ライバル, rivale) are absent from new keys.
- **CLAUDE.md:**
  - No route removed or renamed (`comm` of route names at merge-base vs HEAD: 0 removed, 11 added). Each new route has 6 locale paths, or `/{_locale}/…` for the JSON endpoints, and `/-/nav-click`.
  - New controllers are single-action.
  - Writes go only through Messenger (`MarkFavoritesSeen`, `PushInboxBadge`, `rememberAddMode` in the save handlers); no direct `persist`/`flush`/`executeStatement` was added to controllers or components.
  - No `flush()` and no `new DateTimeImmutable()` for the current time in added code.
  - Exceptions are logged with `'exception' => $e`.
  - The three migrations are generated and additive. The custom index carries the `custom_` prefix, is mirrored and registered.

### 9. UX: done
Montages were built from the full QA-01 run at `fe4a8d930`: en/de/ja member at 320 and 1280 (Hub, Menu open, puzzle page, own profile, Results, Inbox), plus the en/de/ja Help sheet and the en card sheet at 320, fr 320 (Community, Inbox, Menu), and the guest Hub and Menu (top and end) at 320.
- **Findings from them:** R01, R05, R06, R07, R08, R18 and R20, plus the older items listed under the table.
- **Fine everywhere:**
  - Top bar at 320 with the running-stopwatch pill (logo · pill · 🔍 · ? · Inbox · ☰) and without it for guests (no Inbox).
  - The correct active tab or rail item on every page: Home on Hub and Menu, Puzzles on the puzzle page, Profile on own profile and Results, none on Inbox.
  - Bottom-bar labels fit in en/de/ja at 320.
  - Desktop 1280: one row with labelled Feedback / Inbox and the ja long placeholder fitting. The Menu overlay is opened from a `menu` link at the rail tier; its Profile group is expanded.
  - Inbox tabs and counts, with For you at 0 on the notifications page and Messages kept.
  - Results chips, tabs and sort; Overview bests above the fold; the guest Menu card (Sign in · Join free), groups and About row.
  - No broken icons and no untranslated chrome labels.
