# Phase review: Removal

- **Scope:** two commits on `feature/navigation-redesign`:
  - `a487b141`, covering:
    - NAV-06: the old header block leaves `base.html.twig`, along with its critical CSS, `components/_navbar.scss`, the Bootstrap navbar import, the old `app.scss` header blocks, `messaging/_unread_badge.html.twig`, `LegacyChromeSwitch` / `nav_omit_legacy_chrome` and `closeLegacyCollapse()`;
    - NAV-12: `tests/NavigationOrphanTest.php`;
    - MENU-04: `tests/NavigationMenuCompletenessTest.php`;
    - 16 old-markup tests updated.
  - `33718f65`, the weight diet:
    - `ChromeWhitespace` (a Twig node visitor);
    - inline head scripts minified by `tools/build-inline-scripts.mjs` into `templates/_inline_scripts/`, with sources moved to `assets/head/` and `assets/inline/`;
    - the sprite reduced to the symbols the page uses;
    - `inbox_link_strings()`, so only the first Inbox link carries the hrefs and labels;
    - critical CSS cleanup;
    - new tests: `ChromeWeightBudgetTest`, `ChromeWhitespaceTest`, `InlineScriptsBuildTest`.
- **Checked against:**
  - `requirements.md`: Conventions, plus the Done when / Verify of NAV-06, NAV-12 and MENU-04 (and MENU-01's weight line).
  - `implementation-plan.md`:
    - §2 and §3 (§3.2 chrome selectors, §3.5 native-ready items 1, 6 and 7, §3.7 budgets, §3.10 guest caching, §3.11 head order);
    - the Removal sections (NAV-06 delete list, NAV-12 surfaces and pages, MENU-04's four viewers);
    - the NAV-06 / MENU-01 rows of §6;
    - decisions 34, 82 and 86, R10 and R11;
    - risk 8.
  - `README.md`: §4.12 (Weight, Menu rows, Account › Referral), §4.14, D3, D5 and D13. D1–D22 were not reopened.
  - `delivery/state.md`: the NAV-06 / NAV-12 / MENU-04 rows and the decisions-log rows "NAV-12" and "NAV-06 weight" (2026-10-13).
  - `CLAUDE.md`:
    - the asset-failure and stale-document scripts (inline, dependency-free ES5, the timestamp only in the `<meta>`, Turbo re-runs a changed head script);
    - the service worker;
    - the JS build rules (`js-watch`).
- **Method:**
  - **Diffs and files.** Read both diffs in full and the resulting files in the delivery worktree:
    - `base.html.twig`, `_head_flags.html.twig` and `navigation/*`;
    - the two new tests, `NavigationRegistry` (every entry's placement and visibility) and `NavEntry`;
    - `InboxTwigExtension` and `assets/inbox_badge.js`;
    - `ChromeWhitespace` and its extension, `_icons.html.twig`, `_topbar.html.twig`, `_places.html.twig` and `PlacesBuilder`;
    - the three new tests and every changed test.
  - **What the old header carried.** Took every link, form, count and behaviour of the removed header from the `a487b141` diff and traced each to its new home with `baseline-chrome-routes.json` and the registry. Separately confirmed from `524a3305:templates/base.html.twig:483` that the old header was already `is_web()`-only at P0.
  - **Leftovers.** Grepped `templates`, `assets`, `src` and `tests` for old-header selectors, the removed classes of the deleted `_navbar.scss`, and the translation keys only the old header used.
  - **ChromeWhitespace:**
    - scanned the listed templates for multi-line attribute values (979 attributes, none), double spaces at a text node's edge next to a Twig tag (none), `<pre>` / `<textarea>` / `white-space: pre*` (one allow-listed empty `<p>`), `{% verbatim %}`, `{% trans %}` blocks and `{% set %}` captures (none), and the inline scripts left (one, single-line);
    - read Twig 3.28's `ExtensionSet::getLastModified()` and `AbstractExtension::getLastModified()` (main checkout `vendor/`, read only).
  - **Inline scripts:**
    - recomputed the sha256 of the five sources; all match `sources.json`;
    - diffed the original `<script>` bodies (`33718f65^:base.html.twig`) against `assets/inline/*`: same code, `{# #}` comments now `/* */`, `{{ path() }}` now a placeholder;
    - read every build next to its source;
    - grepped the builds for `</script`, `<!--` and Twig delimiters;
    - read `.dockerignore`, `package.json` and `package-lock.json`.
  - **Sprite:** compared the symbol paths before and after (all 18 identical) and grepped `assets/` and `templates/` for every `#nav-i-` user.
  - **Weight:** compared `ChromeWeightBudgetTest`'s constants and method with `var/navigation-delivery/baseline/chrome-weight.json` and the `removal/` and `weight-budget/` records.
  - **Evidence timing:** checked when each browser check last ran (`checks/results.jsonl` `at`, file times).
  - No tests, builds, docker or containers were run.
- **Result:** 0 blocker · 0 major · 7 minor · 4 questions.

## Findings

### F1 · minor · `tools/build-inline-scripts.mjs:49`, `tests/InlineScriptsBuildTest.php:30-52`
**Problem.** `sources.json` records only each **source's** hash, and the test checks only that hash and that the build file exists.
- A build edited by hand passes `InlineScriptsBuildTest`. Examples: a quick fix in `templates/_inline_scripts/asset_failure.js`, which is where a developer lands from `base.html.twig`'s `source()` call.
- The next `npm run inline-scripts` then silently drops that edit.
- The behaviour tests cover only part of each build:
  - `AssetFailureScriptTest` and `StaleDocumentScriptTest` run the rendered build, but only their scenarios;
  - `PlatformOsScriptTest`, `NavLayoutScriptTest` and `TopbarScrollScriptTest` compare source and build only for the inputs they list.
- The brief asked whether a hand-edited build is caught: it is not.

**Reproduction.**
1. Append `;window.x=1` to `templates/_inline_scripts/stale_document.js`.
2. `InlineScriptsBuildTest` stays green, and so do all three of its tests.

**Fix.**
- Have the tool record `build_sha256` next to `sha256` in `sources.json`.
- In `testEverySourceHasItsCurrentBuild`, assert `hash('sha256', <build>) === build_sha256`. The message should be "edit `assets/…` and run `npm run inline-scripts`; never edit `templates/_inline_scripts/`".

### F2 · minor · `src/Twig/ChromeWhitespace.php` (whole file), `src/Twig/ChromeWhitespaceTwigExtension.php:10-19`
**Problem.** Editing the visitor does not invalidate templates that are already compiled.
- Twig decides freshness with `ExtensionSet::getLastModified()`. For an `AbstractExtension` that is the mtime of the extension's own file plus a `…Runtime.php` sibling (`vendor/twig/twig/src/Extension/AbstractExtension.php`, Twig 3.28).
- The cache key covers only the extension **class names**.
- The logic (`TEMPLATES`, `collapse()`) lives in `ChromeWhitespace.php`, which neither check sees.
- So after a change to the template list or the regexes, `var/cache/dev` and `var/cache/test` keep serving the old output. `tests/bootstrap.php` never clears the Twig cache.
- Result: `ChromeWhitespaceTest::testThePagesChromeHasNoIndentation` and `ChromeWeightBudgetTest` can go green or red locally on stale output while CI (fresh cache) disagrees.
- Prod is unaffected: the image builds a fresh cache.

**Reproduction.**
1. Warm the test cache.
2. Remove `'_footer.html.twig'` from `ChromeWhitespace::TEMPLATES`.
3. Rerun `ChromeWeightBudgetTest`: it still measures the collapsed footer until `cache:clear`.

**Fix.** Either one works:
- move `TEMPLATES` and `collapse()` into `ChromeWhitespaceTwigExtension` (the visitor takes them through its constructor);
- or implement `getLastModified()` on the extension returning `max(filemtime(__FILE__), filemtime(<ChromeWhitespace.php>))`.

### F3 · minor · `tests/Twig/ChromeWhitespaceTest.php:92-115, 139-170`, `src/Twig/ChromeWhitespace.php:63-71`
**Problem.** The guard covers the constructs that exist today, but not the ones that would silently change output.
- `ChromeWhitespace` collapses **every** `TextNode` of a listed template. That includes text a future edit could make whitespace-sensitive. None of these exists today (checked), so the risk is latent, but the guard would not see any of them:
  - **A JS template literal in an inline `<script>`** (e.g. in `_menu_slot.html.twig`). ``` `a\n    b` ``` becomes ``` `a\nb` ```, which changes the string. The guard checks only for `\`-continuations.
  - **A `{% trans %}…{% endtrans %}` block body.** It is a `TextNode`, so the collapsed text becomes the **message id**: runtime looks up a key that `translation:extract` never wrote, and the lookup misses.
  - **`{% set x %}…{% endset %}` captures and `{% verbatim %}`.** Their exact text could be used as data, e.g. in a JSON attribute or a compared string.
- The stylesheet scan reads the selector from "the nearest line above ending with `{`". So a nested SCSS rule (`.a { &-b { white-space: pre } }`) yields `&-b`, and no class is found.

**Reproduction (reading).**
- `collapse("`a\n    b`")` returns ``"`a\nb`"``.
- The four patterns in `testNoListedTemplateKeepsWhitespace` match none of `` ` ``, `{% trans`, `{% set x %}` or `{% verbatim`.

**Fix.**
- Add to the guard: a backtick inside `<script>…</script>` of a listed template, `{%-? *trans *-?%}`, a capturing `{%-? *set \w+ *-?%}` and `{% verbatim`.
- Resolve `&`-selectors to their parent in the SCSS scan, or scan the compiled `public/build/app*.css` instead.
- Or have the visitor skip `TextNode`s under `TransNode` and capturing `SetNode` (track the parent in `enterNode` / `leaveNode`).

### F4 · minor · `src/Twig/InboxTwigExtension.php:42-56`, `templates/inbox/_link.html.twig:25`, `assets/inbox_badge.js:112-118`
**Problem.** The Inbox strings now ride on the page's first Inbox link only, and nothing verifies the new arrangement.
- When a realtime push arrives, the Menu row reads the hrefs and labels from the top bar's link through `linkStrings()`'s `querySelector` fallback.
- The code reads right (see Checked and fine). But nothing tests it:
  - the node harness (`tests/inbox-badge-harness.mjs`) imports only `displayCount` / `inboxBadgeState`;
  - no PHP test asserts which link carries `data-inbox-hrefs` / `data-inbox-labels` (no test mentions either attribute).
- The one check that exercises the Menu row's href and name after a push is `checks/INB-02.py` (`menu_open_rows`, `docked_rows`). It last ran at 08:14–08:15. The diet was committed at 10:10, and its sweep (`weight-budget/run-checks.sh`: F-03, GEST-01, MENU-01, MENU-02b, NAV-01…04, NAV-07, NAV-11, NAV-13) did not include it.
- A regression in which no link carries the strings would pass every automated test. An example is `inbox_link_strings()` being consumed by a link that is never output; the push would then stop updating hrefs and names.

**Reproduction.**
1. `grep -rn "inbox-hrefs\|inbox-labels\|linkStrings" tests` finds nothing.
2. `grep '"INB-02"' var/navigation-delivery/checks/results.jsonl` shows the last run at 2026-10-13T08:15.

**Fix.**
- Add a harness case for `linkStrings()`: a link with strings, a link without them on a page that has them, and a page with none.
- Add a functional assertion on a signed-in page: exactly one `a[data-inbox-link][data-inbox-labels]`, and it is `.site-header`'s. On the `menu` page with the native UA it is the Menu row's.
- Re-run `checks/INB-02.py` and record it at the gate.

### F5 · minor · `tests/NavigationMenuCompletenessTest.php:59-64, 156-207`
**Problem.** MENU-04's "every row … opens" check runs for guest, player (`PLAYER_PRIVATE`), member (`PLAYER_WITH_STRIPE`) and admin (`PLAYER_ADMIN`). None of them falls into two of the registry's audiences, so those rows are never opened, neither as rows nor as search entries:
- **`NavVisibility::PrivateMember`.** The only private fixture is `PLAYER_PRIVATE`, a non-member. So `profile_viewers` → `private_profile_viewers` (`NavigationRegistry.php:1120`) is never opened.
- **`NavVisibility::KarmaHolder`.** No fixture has `player.karma > 0`; karma is set only inside individual tests. So `karma` → `karma_history` (`NavigationRegistry.php:652`) is never opened.

The plan names the four viewers, but the requirement says "every Menu row and search-only entry resolves to a route the viewer may open". A target that 403s, 404s or sends one of these audiences to sign-in would pass.

**Reproduction (reading).**
- `NavigationViewerFactory.php:62-63` derives `privateMember` (active membership and a private profile) and `karmaHolder` (`karma > 0`) for the viewer.
- None of the four viewers has both of the first, or the second (`PlayerFixture`; no fixture sets karma).

**Fix.**
- Add `NavigationViewer::MEMBER_ONLY` (`PLAYER_PRIVATE` with a granted membership, which is a private member) to `viewers()`.
- Add a karma-holder variant: set `player.karma` in the test, as `KarmaPagesTest` does.
- Assert that the union of the viewers' audiences covers every `NavVisibility` some row uses, so a new audience cannot slip through.

### F6 · minor · `translations/messages.*.yml`, `assets/styles/_variables.scss`
**Problem.** Leftovers that only the old header used are still in the repo:
- **Four translation keys in all six locales:**
  - `accessibility.toggle_navigation` (`messages.en.yml:35`);
  - `accessibility.notifications_count` (`:37`);
  - `live_event_strip` (`:137`);
  - `free_trial.topbar_badge` (`:6938`).
  - `grep -rn` over `templates`, `src` and `assets` finds no user of any of them, including registry `legacyLabels` and `browser_translation`.
  - They are not `menu.*` / `footer.*` keys, the families the plan keeps for LAUNCH-02 old names.
- **54 `$navbar-*` / `$topbar-*` theme variables.** Nothing reads them since `_navbar.scss` and the Bootstrap navbar import went.

The keys are listed as "cleanup candidates" only in the git-ignored `var/navigation-delivery/doc-notes.md:79`, not in `docs/TODO.md` (CLAUDE.md: loose ends go there).

**Reproduction.** Run `grep -rn "toggle_navigation\|notifications_count\|live_event_strip\|topbar_badge" templates src assets`. It finds nothing; each key is still defined once in each of the six `messages.*.yml`.

**Fix.**
- Remove the four keys from the six files: text removal, no re-dump, then a parse-compare (plan §3.6).
- Delete the `$navbar-*` / `$topbar-*` block.
- Or, if they are kept for later, add both to `docs/TODO.md` under a navigation section.

### F7 · minor · `CLAUDE.md:291-292`, `var/navigation-delivery/doc-notes.md:3`
**Problem.** The documentation lags behind the diet, in three places:
- **CLAUDE.md still describes the old location.** It says the asset-failure and stale-document scripts are "an inline ES5 script in `base.html.twig`" / "a second inline ES5 script in `base.html.twig`". Their code now lives in `assets/inline/*.js` and is inlined as a minified build.
- **Nothing tells a developer to rebuild the inline scripts.**
  - CLAUDE.md also says "DO NOT manually rebuild JavaScript assets — `js-watch` rebuilds", but `js-watch` does not build `assets/head/` or `assets/inline/`.
  - `npm run inline-scripts` (in the `js-watch` container, per CLAUDE.md's rule for JS commands) is written only in the tool's header, two template comments, the test's failure message and `state.md`.
- **The DOC-01 notes are stale or missing.**
  - Line 3 still describes the removed `@head_scripts` namespace.
  - There is no note for `ChromeWhitespace` (which templates, what it may never wrap), the page-dependent sprite, `inbox_link_strings()` or `ChromeWeightBudgetTest`.

CLAUDE.md is what the next agent reads before touching those scripts. The page still works; the risk is an edit in the wrong file (F1) or a forgotten rebuild.

**Fix.**
- Update CLAUDE.md:291-292: sources in `assets/inline/`, built by `docker compose exec js-watch npm run inline-scripts`, builds committed in `templates/_inline_scripts/`, `InlineScriptsBuildTest` enforces it; still inline, dependency-free ES5.
- Fix `doc-notes.md:3`.
- Add 2–4 lines of DOC-01 notes for the four mechanisms above.

## Questions

**Q1 (the weight budget is met by the whole page, not by the chrome).** Four points for Jan:
- **The figures behind the interpretation.** README §4.12 says "the chrome (bars, Menu, rail/column) may grow `/en/faq`'s HTML by ≤ 30 KB (member) / ≤ 20 KB (guest)". The decisions log (2026-10-13, NAV-06 weight) reads this as the whole page's growth. With that reading, 9.8 KB saved in text that existed at P0-02 counts toward the budget: the minified asset-failure and stale-document scripts, plus the collapsed footer and base whitespace. The navigation alone is member +38.7 KB / guest +24.2 KB, over both budgets.
  - The log states these numbers, so the record is honest.
  - The whole-page measure is also technically right here: mostly because the old in-`<main>` `GlobalSearch` moved into the header (plus the FAQ items' `scroll-margin-top`), `main` shrank by ~2.8 KB before the diet. Measuring outside `<main>` would double-count that.
  - The interpretation is not in the README, though. Does Jan accept it?
- **Thin margins.** The margins are member 1,826 B and guest 5,875 B, and other changes land in the same measure:
  - FOOT-01, which rebuilds the footer from the registry;
  - any FAQ copy change (en) or base change from main.

  What should happen when main grows the FAQ? Re-pin `BASELINE` with a comment "moved by main `<hash>`", as plan §3.7 does for statement pins? Or treat it as an over-budget failure?
- **Stale MENU-01 evidence.** MENU-01's phase-5 weight evidence (switch on: +0.7 KB / +5.4 KB) predates the bars, which added ~32 KB in phase 6. So decision 34's premise ("the probe measures exactly what NAV-06 ships") did not hold, and the `verified` row's weight evidence is superseded. Should the MENU-01 row in `state.md` point to `ChromeWeightBudgetTest`?

**Q2 (NAV-12's "not in Menu" exemption is unconditional).** `NavigationOrphanTest.php:104, 114` passes any baseline route that has a registry entry not in the Menu, whether or not anything still links it. For guests that covers:
- `register`, `notifications`, `feedback`, `my_profile` and `event_detail`;
- the six SEO hubs: `brand_puzzles`, `brand_pieces_puzzles`, `pieces_puzzles`, `pieces_hardest_puzzles`, `guide_puzzle_time_by_pieces` and `guide_average_puzzle_time`.

For signed-in roles: `conversations_list`, `notifications`, `feedback` and `my_profile`.

This is the requirement's own wording. The reasons name the surfaces, e.g. "Footer popular searches (SEO hub); reached from Brands…" and "the footer lists WJPC editions". FOOT-01's `SiteFooterTest` is planned to pin each baseline footer route. Keep it as is? Or have NAV-12 verify, for not-in-Menu routes whose reason names the footer, that the footer still links them? The risk is greatest exactly while FOOT-01 rebuilds the footer.

**Q3 (the sprite is now page-dependent).** `_icons.html.twig` carries the following only when this page's bars use them: `person` / `person-fill` (guests), `inbox` / `chevron-down` (signed in) and `arrow-left` (non-root pages).
- Today only chrome templates and `GlobalSearch` reference `#nav-i-*`. `GlobalSearch` uses only symbols that are always carried, so Live re-renders and the Menu shell's second instance are safe.
- A future fragment that references a conditional symbol would render a blank icon on some pages: a Turbo-frame layer such as CARD-01a's card, a stream, or a Live component.
- Should a test pin that no template outside `ChromeWhitespace::TEMPLATES`'s chrome references `#nav-i-`? Or that fragments use only the always-carried symbols?

**Q4 (a guest row that ends on the sign-in page passes MENU-04).** `NavigationMenuCompletenessTest.php:202` fails "→ the sign-in page" only for signed-in viewers. For guests, a row whose target starts requiring sign-in would pass: final status 200 on the login page. That would be a "locked" row, which D13 rules out. Today only `MenuRowsTest`'s exact row list stands between them. Should guests' rows be checked too, with the guest card's Sign in / Join as the only allowed login or register targets?

## Checked and fine

**Nothing lost with the old header (`a487b141`, every role).** For each element of the old header, where it lives now:
- **Topbar band:**
  - Feedback: the top bar's ? / Feedback (help sheet) and the footer. For guests, GET `feedback` redirects to `help`.
  - Membership: Account › Membership, the You card status line and the desktop account menu. Guests get the footer only (`GUEST_FOOTER_ONLY`, README "rows hidden").
  - The free-trial badge: the trial status line in the You card / account menu (`StartFreeTrialControllerTest`, MENU-01).
  - Feature requests: Help & learn.
  - The flag + code language dropdown, for everyone: the account menu's Language (desktop, signed in), the Menu's Language row (every viewer, with `locale-preference`) and the footer. All three use the same `language_switch_urls()`.
- **Admin key:**
  - All 14 admin queues are registry role rows in Menu › Your roles, with their counts and the ☰ dot (R10).
  - The moderator's 4 queues are rows there too.
  - `ModeratorAccessTest` checks each queue linked exactly once page-wide. `NavInstrumentationMarkupTest` checks the admin ids `menu/roles/*`.
- **Toolbar:**
  - Search: the top bar's field / 🔍.
  - Register: the guest card's Join free (registry `register`).
  - Sign in: Sign in / the Profile tab, which keep `return`.
  - `puzzle_add`: + Add / the Add tab (guests go to `add_intro`).
  - The Messages tool and the bell (For you / Messages parts): one Inbox link with the total, in the top bar and the Menu, with the parts on the Inbox tabs (D3, INB-01/02). The guest bell is gone on purpose (registry reason).
  - The logo: Home (the Hub signed in, the homepage for guests; D5, NAV-10).
- **Hamburger:**
  - Places and rows: hub, puzzles, marketplace, ladder, players, events, stopwatch, faq and feature requests.
  - The guest `hub` link is the search-only `hub_guest` (R11).
  - The "New" badges are gone on purpose (launch badges, F-05).
- **"My profile" dropdown:** all 15 routes are Menu rows (Your puzzling, Your puzzles, Account, Help & learn) or the account menu / Log out row.
  - The XP rows keep the XP gate and the opt-outs (`AchievementsShown`, `XpParticipant`, `XpVisible`).
  - `affiliate_dashboard` narrows to members or programme players (README §4.12 Account). Others reach it from their own header's ⋯ menu (`OWNER_MENU_ONLY`, decisions log, checked on the own profile).
- **WJPC strip:** expired on 2026-09-21; gone on purpose.
- **Organiser:** the old chrome had nothing organiser-specific. Now there is "Events you organize".
- **Native app:** the old header was already inside `{% if is_web() %}` at P0 (`524a3305:base.html.twig:483`), so NAV-06 removes nothing from the app.
- **Statement budgets:** the old header carried no statement of its own. Its counts read the memoised `inbox_badge()`, the profile row and voters, which the Menu reads too, so the pins are unchanged. The gate's ParaTest (`gates/Removal/paratest.txt`) ran 11,088 tests: OK, with 27 PHPUnit notices and 5 skipped.

**Cleanup is complete; tests updated with the same intent.**
- **NAV-06 Verify grep:** zero matches in `base.html.twig`. No `navbar*`, `topbar-*`, toggler, `legacy-chrome`, `competition-panel`, `no-caret`, `_unread_badge` or `header-height` controller remains in `templates`, `assets`, `src` or `tests`; only comments and the vacuous `.navbar` assertion in `ParticipantsSheetPageTest:121`, next to its new-bar assertions.
- **Deleted `_navbar.scss`:** every class it defined was scoped under `.navbar` / `.topbar`. No template uses `.navbar`, so dropping `bootstrap/scss/navbar` is safe.
- **Critical CSS:** no code ever set `.css-loading`. The stylesheet is render-blocking (`encore_entry_link_tags` in `<head>`, no media/onload trick), so dropping `.collapse:not(.show)`, the old icon codepoints and the unused utilities changes no paint. The `.nav-link` / `.fade:not(.show)` duplicates went.
- **Changed tests:** none weakened.
  - `InboxTest` now also checks the Menu row, plus the parts on the Inbox tabs.
  - `NotificationsControllerTest` checks the total on every page and the parts on `/en/messages`.
  - `PuzzlePickerControllerTest`: the counts drop by exactly the dropdown item.
  - `ModeratorAccessTest` is page-wide again.
  - `CriticalCssIconCodepointsTest` keeps pinning any inlined icon rule and now also asserts the top bar uses no icon font.
  - `LanguageSwitchUrlsTest`, `NavChromeAssertions`, `BarFreePagesTest` and `NavigationRegistryTest` drop only `.legacy-chrome`.
  - `HelpOpenerLinksTest`: its "at least 4" threshold is unchanged; the top bar's ? replaces the old topbar link among the counted openers.

**NAV-12 / MENU-04 mechanics.**
- **NAV-12 pages, roles and fixtures:**
  - The pages are those of plan §NAV-12: `/en/faq`, `/en/hub`, `/en/community`, `/en/ladder` (Community chips), `/en/puzzle` (Puzzles chips), plus the Help sheet fetched with `Turbo-Frame`.
  - It runs for all 6 roles, on the same fixtures as the baseline (`PLAYER_PRIVATE`, `PLAYER_WITH_STRIPE`, `PLAYER_REGULAR`, `PLAYER_WITH_FAVORITES` with the moderator role, `PLAYER_ADMIN`).
- **NAV-12 link matching:**
  - Hrefs are matched to canonical route names (`_canonical_route`, paths decoded, so the Japanese paths work).
  - Language-switch links are excluded; external links are skipped.
- **NAV-12 exemptions:**
  - Search-only entries count only when that viewer's page search lists them, filtered by `data-viewer-audience` plus `#nav-search-private`.
  - `OWNER_MENU_ONLY` is one entry. It requires a registry Menu row and the link in the own ⋯ menu.
  - The mutation is recorded: removing the wjpc row and its footer link fails all 6 roles.
- **MENU-04 structure test:** every entry is a row on a surface or has a reason there, a search-only entry with an existing row as parent (the same on both surfaces), or not-in-Menu with both reasons.
- **MENU-04 open test:** it follows `nav_go` and redirects (up to 5 hops), requires 200, and rejects the login page for signed-in viewers. The member is also an event owner, so "Events you organize" is opened; the admin covers the moderator and time-verifier rows.
- **MENU-04 allowlist:** `GUEST_FOOTER_ONLY` is asserted exact, so it fails as stale. The mutation (events + past_events removed: 8 of 9 fail) is recorded.

**ChromeWhitespace on today's templates.**
- **Compile time only:** it rewrites `TextNode` data in `enterNode`, so a request pays nothing. In prod the cache is built fresh, and the FrankenPHP worker loads the compiled PHP.
- **What it never touches:**
  - printed values (`PrintNode` / expressions), `source()` output and child templates' block overrides (other source names);
  - `{% embed %}` bodies, which are judged by the embedding template's name.
- **Edge cases in the regexes:**
  - They are ASCII-only, so UTF-8 bytes and NBSP stay.
  - A whitespace run is never removed entirely, so the space between inline elements survives.
  - Line breaks are kept, so `//` comments and ASI behave as before.
- **What the listed templates contain:**
  - No multi-line attribute value in the listed templates (979 checked), so `content`, `data-*` JSON, `srcset`, `title` and the values tests read keep their exact strings.
  - No double space at a text node's edge next to a tag.
  - The only `white-space: pre-line` target (`.msp-confirm-message`) is an empty `<p>` filled by JS.
  - JSON-LD stays valid: newlines only.
  - The remaining inline script (`_menu_slot`) is one line with no template literal.
  - No `{% trans %}` block, `{% set %}` capture or `{% verbatim %}`.
- **Template list:** the list and every file in it exist (asserted). Shared partials (`_player_avatar`, `XpRing`) are correctly left out. No listed template is rendered into e-mails or print pages.

**Inline script builds.**
- **Hashes:** the five source hashes equal `sources.json`.
- **Asset-failure source:** `assets/inline/asset_failure.js` is the original `<script>` body. The only changes are `{# #}` → `/* */` and `{{ path('asset_load_failure') }}` → `__ASSET_LOAD_FAILURE_URL__`.
- **Stale-document source:** `stale_document.js` is the same kind of move.
- **Builds read against their sources:** stale_document, nav_layout, platform_os and standalone are semantically identical. `asset_failure`'s build runs in `AssetFailureScriptTest` from the rendered page.
- **Path placeholders:** `|replace` fills them with `path()` of two constant, non-localized routes (`/-/asset-load-failure`, `/-/stale-document`), which are the values the old `{{ path() }}` printed. So each script's text is the same on every page and in every locale, and Turbo never re-runs them.
- **Timestamp:** it stays only in `<meta name="msp-rendered-at">`, and the script directly follows it (`base.html.twig:36-37`, checked by `StaleDocumentScriptTest`'s regex).
- **Head order (§3.11):** unchanged.
- **Safety:**
  - No `</script`, `<!--`, `` ` ``, `=>`, `let` / `const` or Twig delimiters in the builds, so Symfony's template cache warmer can read them harmlessly.
  - `terser` with `ecma: 5` never adds ES2015 syntax to ES5 input.
- **Environment:**
  - No CSP pins inline scripts.
  - The service worker is untouched.
- **Docker and CI:**
  - Docker: `tools/` is in `.dockerignore`, and the builds are committed under `templates/`.
  - `npm run build` does not call `inline-scripts`.
  - `terser` is a pinned devDependency (5.31.0) already in the lock (`dev: true`).
  - CI needs node only for the existing harnesses, never terser.

**Sprite.**
- **Paths:** the paths of all 18 symbols are byte-identical before and after.
- **Each conditional symbol follows the rule of its only user:**
  - `arrow-left` ↔ `_topbar`'s `back = not location.rootPage`, from the same `nav_location()`, memoised on the request;
  - `person` / `person-fill` ↔ `PlacesBuilder` (avatar when signed in, person otherwise);
  - `inbox` / `chevron-down` ↔ the signed-in Inbox tool and account menu.
- **JS:** no JS builds a `<use href="#nav-i-…">`.
- **Menu:** Menu rows use the `bi` font, not the sprite.
- **Pages without bars:** the bar-free pages empty `header` and `nav_chrome` together.
- **Test coverage:** `ChromeWeightBudgetTest::testTheSpriteCarriesTheSymbolsThePageUses` pins sprite = used symbols on guest `/`, guest `/en/faq`, member `/en/faq`, member `/en/hub` and admin `/en/faq`, which covers root, non-root, guest and signed in.

**Inbox strings (code).**
- **Which link carries them:** the header block renders before `nav_chrome`, so the top bar's Inbox link is the first and carries the strings. On the `menu` page in the app, with no top bar, the Menu row is the first.
- **The Menu row:** a hidden or inert top-bar link is still read through `dataset`. Nothing replaces the link: the `inbox_badge` stream carries numbers only, and no frame, stream or Live component renders an Inbox link.
- **State:** the flag lives on the main request, so the extension holds no state. Guests never call it.

**Weight measure.**
- **Same method as P0-02:** `ChromeWeightBudgetTest` uses the same fixtures (guest with the cookie jar cleared, `PLAYER_WITH_STRIPE`), the same test env and the second request with `disableReboot()`, measuring `strlen` (uncompressed).
- **Same baselines:** 87,119 and 87,854 match `baseline/chrome-weight.json`.
- **Same budgets:** 20 × 1024 and 30 × 1024 are README §4.12's.
- **Honest records:** `removal/weight.txt` (NAV-06 without the switch) and `weight-budget/weight.txt` / `table.txt` (the diet's per-part numbers) agree with the commit message and `state.md`.
