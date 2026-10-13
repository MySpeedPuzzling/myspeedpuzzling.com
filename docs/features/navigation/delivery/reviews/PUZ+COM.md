# Phase review: PUZ + COM

- **Scope:** commit `f2f3a1c6` on `nav/puz-com` (worktree C), which covers:
  - COM-01: `CommunityPreview`, `CommunityLanding`, `GetEventOccurrences::upcoming()`, the landing template, `community` in `sitemap-static`;
  - COM-02: `SectionChipsBuilder`, `SectionChip(s)`, `NavigationSectionsTwigExtension`, base's `section_chips` block, `_section_chips.html.twig`, `section_chips_controller.js`, `scroll_active_into_row.js`;
  - PUZ-01: `<main data-puzzles-section>`, `data-puzzles-sections` on the Puzzles place links, `assets/puzzles_section.js`;
  - the tests, canaries, budget test, node harnesses and 10 keys × 6 locales.
- **Checked against:**
  - `requirements.md`: Conventions, and the Done when / Verify of PUZ-01, COM-01 and COM-02.
  - `implementation-plan.md`: §3 (CLAUDE.md digest, chrome selector rule, `data-nav`, native-ready checklist, translations, budgets, read models and canaries, guest caching, privacy), §4 F-02 (sections), the PUZ + COM sections, and decisions 27, 58 (D-5), 74 and 75. GEST-03 and GEST-04 were read for what they expect from the place links (`aria-current` server-only, the tab's current `href` = start page).
  - `README.md`: D1, D6, D8, D16, D21, §3 place mapping, §4.3, §4.9, §4.14. D1–D22 were not reopened.
  - `CLAUDE.md`:
    - blocklist (`HiddenPlayers`) and private profiles (`is_private` / `PrivateProfileAccess`);
    - drafts and pending items (`IsCompetitionPubliclyVisible::SQL_CONDITION`, `DraftCanaryTest`);
    - suspicious times;
    - guest HTML shared-cacheable;
    - query budgets, `ResetInterface`, translations in 6 locales.
- **Method:**
  - Read the whole diff, then the full files at the commit in worktree C, plus the code they call:
    - `NavigationPlaceMap` / `NavigationPlaceResolver` (every Community-section route and the four Puzzles start pages);
    - `PlacesBuilder` (place `href` and `aria-current`) and `MenuBuilder::row()` (the docked place row's `href`);
    - `GetEventOccurrences::fetch()` / `hydrate()`, `OccurrenceDates::status()` / `sessions()`, `EventOccurrence`, and `EventRowFactory::row()` / `titleOf()`;
    - `GetFastestPlayers::window()` with `TimesBoardGuestCache`, `GetSpotlightPeople::forScope()`, `IsCompetitionPubliclyVisible`, `IsOrganizationPubliclyVisible`;
    - `_leaderboard_player`, `_player_avatar`, `event_parts/*`;
    - `ComparisonController`, `public/robots.txt`, the bar-free templates (`nav_chrome_off`), the print templates (standalone), and `menu_layer_controller.js` (the only other reader of `a[data-nav-place]`, scoped to `#bottom-nav`).
  - Reasoned through the time-zone bound of `upcoming()`: zones UTC−12 to UTC+14, round `starts_at` in UTC, `date_from`/`date_to` as local dates at 00:00. The SQL bound (`today_UTC − 1 day, 00:00`) is a superset of what `status()` keeps, so no live or coming occurrence is cut by SQL.
  - Translations: parsed all 6 `messages.*.yml` before and after the commit with a script.
    - 10 keys were added in every locale; none was removed or changed.
    - No value is equal to its English value.
    - Plural forms follow the surrounding keys (legacy interval syntax, not ICU; the files are not `+intl-icu`). The cs/en/de/es/fr forms have no `{0}`, and `GetSpotlightPeople` lists only `solves_this_month > 0`.
  - Read the recorded EXPLAIN (`var/navigation-delivery/COM-01/explain.txt`): 0.30–0.35 ms on the clone (194 competitions, 168 rounds).
  - No tests, builds or docker commands were run.
- **Result:** 0 blocker · 0 major · 2 minor · 6 questions.

## Findings

### F1 · minor · `src/Navigation/PlacesBuilder.php:90-95`, `assets/puzzles_section.js:100-118` (`applyPuzzlesSection()`)
**Problem.** After PUZ-01 rewrites the Puzzles place link to the remembered section, the link can point at the page it is on while it still says `aria-current="true"` ("deeper inside the place").
- `PlacesBuilder` sets `aria-current` by comparing the **server** `href` with the current path:
  - for players, the server `href` is their own `puzzle_library`;
  - for guests, it is `puzzles`.
  - So on All puzzles, Marketplace and What next? (players), and on Marketplace and What next? (guests), it renders `"true"`.
- On those pages `applyPuzzlesSection()` first records the page's own section, then sets the tab's `href` to that same page. `aria-current` stays `"true"`.
- Decision 27 says "page" on the exact page the link leads to. GEST-04 makes `aria-current` server-only, so the browser must not repair it.
- The rail, the 768–1023 px places and the tab bar share the partial, so all three report "current item" for a link to the current page. The opposite case cannot happen: where the server says `"page"`, the page records exactly the section the server linked.

**Reproduction.**
1. Sign in at 360 and open `/en/marketplace`.
2. Inspect `#bottom-nav a[data-nav-place="puzzles"]`.
3. Its `href` is `/en/marketplace` (the current page), and its `aria-current` is `"true"`. The same happens for a guest on `/en/what-to-solve-next`.

**Fix (server-side, 0 statements).**
- In `PlacesBuilder::build()`, for `NavPlace::Puzzles`, return `'page'` when `$location->isStartPage` and `$location->section` is one of `NavPlace::Puzzles->sections()`. The four start pages record themselves, so after `turbo:load` the tab always leads to the current page there.
- With blocked storage the tab keeps its server `href` while saying `"page"`. That is acceptable, or keep today's rule when the section is the default one.
- Add rows to `PuzzlesSectionChipsTest`: `aria-current="page"` on the tab, rail and compact places on each start page, for a player and a guest.

### F2 · minor · `src/Navigation/MenuBuilder.php:232-237` with `src/Navigation/NavigationRegistry.php:310-311` (`puzzles_place` → `RouteTarget::route('puzzles')`), `templates/navigation/_menu_row.html.twig:39`, `tests/puzzles-section-harness.mjs:85-86`
**Problem.** The docked column's Puzzles place row is server-rendered with All puzzles (`/en/puzzle`) for **signed-in** players. The plan's default for every Puzzles place link is the player's own `puzzle_library` (PUZ-01: "default own `puzzle_library` / guests `puzzles`").
- The tab, the rail and the compact places use `PlacesBuilder`, which links My library. Only the docked row comes from the registry target. The harness pins the difference ("The docked Menu's place row links the catalogue on the server").
- At ≥ 1440 px, docked by default, the rail is `display: none` (critical CSS, `:root[data-nav-layout=docked] … > .bottom-nav`). So the docked row is the desktop player's only Puzzles place link.
- Before `app.js` has run, or when the bundle fails (the asset self-heal case), a player with nothing remembered lands on All puzzles there and on My library everywhere else. The plan accepts the default for a tap before `turbo:load`, but the default should be the same one.

**Reproduction.**
1. Sign in and clear `localStorage` (or block the `app.js` request).
2. Load `/en/hub` at 1440.
3. `#site-menu a[data-nav-place="puzzles"]` has `href="/en/puzzle"`, while `#bottom-nav a[data-nav-place="puzzles"]` (hidden here; visible at 1280 in rail mode) has `/en/puzzle-library/<own id>`.

**Fix.**
- In `MenuBuilder::row()`, give the `puzzles_place` row the place's default `href`: the viewer's `puzzle_library` when `$viewer->profile` is set, else `puzzles`. Alternatively, read it from `SectionChipsBuilder::puzzlesSections()` (`library ?? all`). Both cost 0 statements (profile row).
- Keep the registry target and `menuRowId` as they are; `href` only.
- Update the harness's `dockRow` default, and add the docked row to `PuzzlesSectionChipsTest::assertPlaceLinksCarry()` as "server `href` = the tab's".

## Questions

**Q1 (Compare for guests).** The Puzzlers card shows "Compare" to everybody, which the tests pin for guests (`NavInstrumentationMarkupTest:112`, `CommunityControllerTest:125`). But:
- the registry entry `compare` is `NavVisibility::SignedIn`;
- `ComparisonController` is `IS_AUTHENTICATED_REMEMBERED`, so a guest's tap ends on the sign-in page (with `return`).

It costs no crawl budget (`robots.txt` disallows `/{locale}/compare`), and the footer already shows Compare to guests (`rel="nofollow"`). Is "Compare → sign in" on the guests' landing intended, or should the card follow the registry audience? Showing it to players only keeps the guest page shared-cacheable, since every guest still gets the same HTML.

**Q2 (favorites star in the previews).** `_leaderboard_player` puts a star in front of a name that is in the viewer's favorites. So the leaders and puzzlers rows do differ per viewer. It costs 0 statements (profile row; the "player with favorites" budget row is unchanged) and guests are unaffected. Still:
- `CommunityLanding` / `CommunityPreview` say "never anything of the viewer's own";
- `CommunityLandingPreviewsTest::testMembersGetTheSamePreviewsAsGuests` passes only because PLAYER_WITH_STRIPE follows none of the fixture leaders.

Keep the star and reword the docblocks and the test? Or pass a `show_favorite: false`?

**Q3 (ongoing events).** The preview keeps only `Live` and `Upcoming`. So these never appear, though `/en/events` lists them under "Ongoing":
- a span over 31 days without rounds (e.g. a month-long online challenge running now);
- undated online one-time events.

This matches the plan's "live/coming dated occurrences" and the "Coming up" title. Is it intended?

**Q4 (stale place links without a new `turbo:load`).** `applyPuzzlesSection()` runs only on `turbo:load`. Two cases keep an older section until the next visit:
- a document restored from the browser's back/forward cache after a full-page navigation (`data-turbo="false"` or external links; `app.js` has no `pageshow` / `persisted` handler);
- another tab that records a section (no `storage` listener).

The effect is small: one tap opens the previously used section. Worth a `pageshow` (persisted) and `storage` re-apply, and a note for GEST-14/15? Instant Back will restore documents without `turbo:load` too.

**Q5 (a guest's section survives sign-in).** A guest who last browsed the Marketplace and then signs in opens the Marketplace from the Puzzles tab, not My library: `msp:puzzles-section` is per browser, not per account. This is the literal D6 ("My library … default when nothing is remembered"). Confirm it is wanted, rather than resetting on the first signed-in page?

**Q6 (cherry-pick onto the delivery branch).** C is based on `00f5250f`, before Removal and the ADD phase.
- `PuzzlePickerControllerTest` now counts 5 links to `/en/what-to-solve-next` ("Header button + dropdown item + Menu row + footer link + the Puzzles chip"). NAV-06 removes the first two.
- `base.html.twig` (`<main>` line) and `NavLayoutCssTest` touch lines Removal also edits.

Re-measure these at the cherry-pick rather than taking the numbers over.

## Checked and fine

**Privacy of the three previews.**
- **Leaders** (`GetFastestPlayers::window(500, null, 3)`):
  - `pl.is_private = false`, so private players are left out for everybody, the friend included (global rankings rule);
  - `HiddenPlayers::sqlExclude('agg.entry_id')`, both block sources, applied before ranking, so ranks close up;
  - `pst.suspicious = false` in both the aggregate and the time pick;
  - the opted-out tier is moot (`show_tier: false`).
  - The shared one-minute cache is used only when there is no viewer row and no hidden player (`$notHidden === ''`), so a blocker never reads a cached list.
  - The private player's fixture time (1500 s, 500 pieces) is among the fastest, so the `PrivateProfileCanaryTest` row is not vacuous in practice. `BlocklistCanaryTest` seeds a winning time and checks a bystander first.
- **Puzzlers** (`GetSpotlightPeople::forScope(world)`): public profiles only for everybody, and `HiddenPlayers` inside each ranked list. `community_player_stats` is the Players page's suspicious-free source. `PlayersPageCanaryTest` puts the player on top first (`rebuildWithOnTop`), and `.community-puzzlers` is in `PEOPLE_SECTIONS`.
- **Events:**
  - `IsCompetitionPubliclyVisible::SQL_CONDITION` excludes drafts (the event's and, for an edition, its series'), pending events, rejected events and editions of a pending or rejected series. This holds for admins and organisers too.
  - An organization's state hides nothing (documented rule); the row renders no organization name (`titleOf()` = event or series name, `subtitle()` = edition/session), so a non-public organization is never named.
  - `DraftCanaryTest` covers guest and player with the draft dated tomorrow.
- **Guest page:** no session, flash or CSRF, `Cache-Control: public` and no cookies (`CommunityControllerTest`). Guests' previews are the same for every guest.

**`GetEventOccurrences::upcoming()`.**
- **"Not over"** = any of `date_from`, `date_to` or a round's `starts_at` ≥ UTC today − 1 day, 00:00.
  - This is a superset of `status()` in the occurrence's own zone. The local day differs from the UTC day by at most one, and round times are UTC instants.
  - Multi-day spans pass through `date_to`, and sessions through any later round.
  - Undated rows never match.
- **Rounds are aggregated for every round of the qualifying competitions**, so sessions are cut correctly; past sessions are dropped in PHP (`Past`).
- **One row per series (by `seriesId`) and per one-time event (by `competitionId`, so one session of a multi-session event).** The first kept occurrence is the next one, because `fetch()` sorts by start and live occurrences sort first. Online dated events are included, as on the events page's Everywhere.
- **Cost:** one statement. The rounds pre-filter mirrors `forSeries()`. `DraftVisibilityCoverageTest` is satisfied (it uses the constant).
- `testASeriesShowsItsNextEditionOnly` was mutation-checked (per the commit).

**COM-02 chips.**
- **The resolver is the single source.** Every `community/<section>` route gets the three chips with its own section current, and a provider test iterates the router.
  - Covered: `ladder_*`, MSP rating, level/AP/karma boards, achievements, players / directory / per-country, comparison, `events*`, archive, organizations, series/edition/event (`event_` / `edition_` prefixes, round results), organiser tools and WJPC.
  - No chips on: the landing, others' player and team pages, own player pages, and other places.
- **Bar-free pages:** live results, participants sheet and round stopwatch set `nav_chrome_off`, which the block checks (`BarFreePagesTest`). The print pages are standalone documents.
- **Only base renders the block** (a test greps the templates). It is placed before the flashes, so `full_content` overrides get it.
- **Attributes:** `aria-current` is `"page"` / `"true"` with the same path comparison as `PlacesBuilder`, so Japanese paths compare decoded on both sides.
- **Markup:**
  - `data-press`, `data-nav="chips/<place>/<item>"`, `data-ptr-ignore`;
  - no `.nav-link` / `.nav-pills` / heading / form (§3.2);
  - added to `NavInstrumentationMarkupTest`.
- **Scrolling:** horizontal on phones with no wrap or truncation; 44 px on phones, 36 px on desktop; `touch-action: manipulation`.
  - The current chip is centred in the row only (`scrollActiveIntoRow`, no `scrollIntoView`), and the row is the `offsetParent`.
  - `leaderboards_nav_controller.js` behaves exactly as before.
- **Turbo:** the snapshot cache is off, so chips are re-rendered and re-scrolled on every visit.
- **Native app:** chips stay in the app (content, not chrome; nothing in README §4.14 says otherwise).

**PUZ-01.**
- **Chips:** only on the four start pages. "My library" is first for players and absent for guests. The library chip is `rel="nofollow"`.
- **Recording:** own list pages record `library` without chips; object pages and others' libraries record nothing (`|community`, `OWNER_FROM_CONTROLLER` for `collection_detail`).
- **Stored value:**
  - validated against the four sections, on both read and write;
  - the JSON's hrefs are accepted only as same-site paths (`/…`, not `//`);
  - storage that throws or is missing keeps the server `href`.
- **Viewers:**
  - a guest ignores a remembered `library`;
  - another player signing in on the same device gets their own library (the JSON is per viewer, server-rendered; the stored value is only a section name).
- **Selectors and requests:**
  - `a[data-nav-place="puzzles"][data-puzzles-sections]` reaches the tab = rail, the 768–1023 px places and the docked row;
  - the other reader of `data-nav-place` (`menu_layer_controller`) is scoped to `#bottom-nav`;
  - no fetch, and 0 statements (profile row).
- **Guest HTML:** identical for two guests and `public` (tested).
- **Native app:** inside the app only the `menu` page's row can carry the attribute, and rewriting it is harmless.

**Budgets, state, translations.**
- **Budgets:** `CommunityPageQueryBudgetTest` pins guest 3, player / member / player-with-favorites 6, admin 7. The requirement's ceilings are guest ≤ 4 and member ≤ 6.
- **Chrome:** chips and section attributes add no statement elsewhere (`RetrieveLoggedUserProfile` is memoised).
- **State:** `CommunityPreview` is stateless and in `EXTRA_CLASSES`; `SectionChipsBuilder` and the Twig extension are readonly.
- **Translations:** 10 keys × 6 locales, none equal to English, none removed.
  - The plan named `{empty, more}`; the commit reuses the existing `cta` and adds `preview` (harmless).
  - Tone is neutral or matches each file.
  - Plurals guard against 0 by the query.
- **Sitemap and robots:** `community` is in `sitemap-static` (6 locales; `where_did_it_go`, `menu`, `help` and `add_intro` asserted absent); `RobotsTxtTest` has crawlable rows for `community` and `where_did_it_go`.
