# Phase review: PROF

- **Scope:** four commits on `nav/prof` (worktree C), reviewed at `c76ac482`:
  - `16a2d08a` PROF-05a + PROF-05d: the Results page (`PlayerResultsController`, `PlayerResultsCriteria`, `PlayerResultsBuilder`, `PlayerResultsPage`, `player/results/*`), the search (`GetPlayerResultsSearchMatches`), `PlayerSolvedPuzzles` reduced to a legacy wrapper;
  - `a52f7dc6` PROF-05b + PROF-06 + PROF-07 + PROF-05e: paging + the `fragment=more` stream, rank on rows, SEO, old profile links and the team page's "Results with this pair/team";
  - `2d9bcd8d` PROF-03 + PROF-04 + PROF-02 + PROF-01: the Overview (`GetPlayerOverviewNumbers`, `player/overview/*`), Statistics receiving the chart and the standing cards (`player/_standing.html.twig`), the header tabs, the own profile;
  - `c76ac482` PROF-05c + PROF-08: history and Back restore (`player_results_controller.js`, `player_results_state.js`, `restore_reload.js`, `app.js`), canaries.
- **Checked against:**
  - `requirements.md`: Conventions and the Done when / Verify of PROF-00…08.
  - `implementation-plan.md`: §3 (all of it, in particular §3.4 layer checklist, §3.5 native-ready, §3.7 budgets, §3.9 canaries, §3.10 guest caching), the PROF sections, risk 11, decisions 69, 71, 72, 97 and 98.
  - `README.md` §4.7 and D9–D11, D13. D1–D22 not reopened.
  - `delivery/state.md`: the PROF rows and the PROF decisions of 2026-10-13 (PROF-05a, 05d, 05b, 07, 05e, 03, 04, 05c). Behaviour changes logged there are not findings.
  - `CLAUDE.md`: blocklist (`HiddenPlayers`), private profiles (`PrivateProfileAccess`), suspicious times, guest caching, budgets only down, `ResetInterface`, Turbo rules, Live gotchas, translations in 6 locales.
- **Method:**
  - **Trees.** `git archive` of `c76ac482` and of `16a2d08a^` into the scratchpad; every file below read there, the four diffs read in full.
  - **Parity.** The old `PlayerSolvedPuzzles` (filters, gates, grouping, every sort, members-only rules, `getAvailable*()`) read line by line against `PlayerResultsBuilder` (both modes), `PlayerResultsCriteria` and the legacy wrapper; every `this.*` the component's two templates read is still served.
  - **Privacy.** Read `GetPlayerSolvedPuzzles` (solo/duo/team), `GetPlayerOverviewNumbers`, `GetRanking::rankings()`, `GetPlayerResultsSearchMatches`, `GetPuzzlingTeamDetail` (members' `isPrivate`), `GetAffiliateSupporters`, `AnonymousCacheHeadersSubscriber`, `PrivateProfileRevealedCacheSubscriber`, the head-name partial and both controllers' private / blocked branches.
  - **History.** Read `player_results_controller.js` against `layer_history.js` (`pause` / `resume` / `restart` / `pushPageState` / `replacePageState` / `onPopState`) and Turbo 8.0.23's dist in C's `node_modules` (`History.update` pushes whether started or not; `Visit.changeHistory()` runs at render; `FrameController#loadFrameResponse` → `turbo:frame-missing` → "Content missing" + throw, no `turbo:frame-load`; `#visit` cancels the previous frame fetch with an AbortError that dispatches nothing).
  - **Translations.** Parsed the six `messages.*.yml` before and after with PyYAML: 40 keys added, 6 removed; checked presence, values equal to English, placeholders, plural forms per locale and a tone grep (de du/dein, fr tu/ton/ta, es usted/su, cs ty-forms).
  - **Budgets.** Read the three budget tests and recomputed every profile drop vs Statistics rise.
  - Browser evidence taken from `state.md` (PROF-01…08 rows); no tests, builds, docker or containers were run.
- **Result:** 0 blocker · 2 major · 5 minor · 7 questions.

## Findings

### F1 · major · `src/Results/PlayerResultsPage.php:184-195` (`categoryCriteria()` / `listCriteria()`), `src/Services/PlayerResults/PlayerResultsBuilder.php:112-115, 210-228`, `templates/player/results/_frame.html.twig:72`, `templates/player/results/_filters.html.twig:14, 19-21`
**Problem.** While a pair/team filter is on, the Solo tab cannot be opened.
- A tab link leaves `category` out when the tab is the history's default (Solo). The builder resolves a missing `category` with the pair/team filter in mind (`defaultCategory($history, $team)` → that pair's or team's tab, decision PROF-05a).
- So the Solo link under `?team=T` is `?team=T`, which renders the Pairs tab again. The solo list is not narrowed by the team filter (`withTeamFilter: false`), so the Solo tab is shown as a live link with its count.
  - Without JS: a click reloads Pairs.
  - With JS: from `?team=T` the link equals the frame's `data-url` (`sameState`), so the click does nothing; from `?category=duo&team=T` it reloads Pairs and pushes a history entry.
- Same root cause on a Solo list reached by `?category=solo&team=T`: its view/sort/chip links and the filter form's hidden `category` (`keep = listCriteria.toQuery`) drop `category`, so any change jumps to Pairs.
- This is the main entry path: the team page's "Results with this pair/team" and the old profile links (PROF-05e) both land on `?category=duo&team=T`.
- Parity: the old component switched to Solo with the team filter set (`changeResultsCategory('solo')`, solo results unfiltered by team). README §4.7 / D11 keep every existing filter's behaviour.

**Reproduction.**
1. `GET /en/player-results/<PLAYER_REGULAR>?category=duo&team=<puzzling_team_id of TIME_12>` (the fixture of `PlayerResultsControllerTest::testTeam`).
2. `a[data-results-category="solo"]` → `href` = `/en/player-results/<id>?team=<pairId>` (no `category`).
3. `GET` that href → `[data-results-category="duo"][aria-current="page"]`; Solo is never current.

**Fix.**
- Name the tab in every link while `criteria->team !== null` (or compare against the default the link's own criteria resolve to: `defaultCategory($history, $team)` for links that keep the filter, the history default for links that drop it, such as Clear all and the team chip).
- Add to `testTeam`: follow the Solo link from `?category=duo&team=…` → Solo current, the pair chip still on; and submit the form from `?category=solo&team=…` → still Solo.

### F2 · major · `assets/controllers/player_results_controller.js:147-194` (`more()`), `:307-321` (`write()`), `:737-761` (`replaced()`)
**Problem.** Show more's stream is not tied to the list or to the page it was asked for. After its `fetch` resolves, `more()` renders the stream and writes the history entry unconditionally: nothing checks that the list is still the one the link belonged to, that the controller is still connected, or that the page is still Results. The `fetch` is a plain `fetch` (no AbortController), so neither a frame load nor a Turbo visit cancels it.
- **(a) Another list landed first.** A tab, sort, chip, filter or Back loads the frame (`load()` does not look at `this.loading`); if that frame renders before the stream returns:
  - the old list's next 50 rows are appended to the new list's `#player-results-rows`;
  - its footer ("Showing 100 of …", Show more for the old list) replaces the new one;
  - `write('more', …)` replaces the entry just pushed for the new list with the old list's `?page=N+1`, so the address names one list and the page shows another.
- **(b) Another page landed first.** A Turbo visit (a header tab, a puzzle name, the bottom bar) renders before the stream returns:
  - `renderStreamMessage` finds no targets and the old footer is detached, so `replaced()` resolves at once;
  - `write()` then compares the new page's URL with the Results URL and calls `layers.replacePageState('results', …?page=N+1)`;
  - the visited page's own history entry is rewritten to the Results URL (address bar, reload, share and Back all point at Results), and `pause('results')` stops Turbo's history again for a controller that no longer exists. Nothing resumes it: the next Back goes through `layer_history`'s release-and-restore path.
- The window is the stream's server time. It is largest on the heaviest histories, where Show more rebuilds the whole history, while the Overview (5–11 statements) renders much faster. "Show more, then the Overview tab" fits it.

**Reproduction.** On a Results page with > 50 rows, in the console:
```js
const f = window.fetch;
window.fetch = (u, o) => String(u).includes('fragment=more') ? new Promise((r) => setTimeout(() => r(f(u, o)), 3000)) : f(u, o);
```
1. (a) Click Show more, then the Pairs tab. After 3 s: Pairs rows followed by 50 solo rows, the solo footer, `location.search` = the solo `?page=2`.
2. (b) Click Show more, then the header's Overview tab. After 3 s: the Overview is shown, `location.href` = `/en/player-results/<id>?page=2`, `history.state.mspPage === 'results'`; a reload opens Results.

**Fix.**
- After `await response.text()`, drop the answer unless `this.element.isConnected`, the captured `footer.isConnected` and `sameState(startedFrom, this.shownUrl)` all hold (the footer is replaced by any frame render or page change); reset `loading` in that branch.
- Better still: an AbortController per Show more, aborted in `load()`, `onBeforeVisit()` and `disconnect()`.
- A node or WebDriver case for both orders.

### F3 · minor · `assets/controllers/player_results_controller.js:196-217` (`all()`), `:294-340` (`change()` / `load()`), `:342-373` (`onFrameLoad`)
**Problem.** A superseded Show all leaves `this.loading` true for the rest of the page's life.
- `all()` sets `loading` and passes an `after` callback that alone resets it. `load()` replaces `this.pending` whole, so a tab/sort/chip/filter change or a Back while Show all is loading drops that callback (the cancelled frame fetch dispatches nothing).
- `loading` is otherwise reset only in `more()`, `onFrameError` and `onBeforeCache`.
- From then on `more()` and `all()` call `preventDefault()` and return on `if (this.loading)`: Show more and Show all do nothing, not even their no-JS link, until a reload or a visit.

**Reproduction.** Network throttled to "Slow 3G"; click Show all, then a sort option before the rows arrive; once the sorted list shows, click its Show more: nothing happens (`this.loading === true` on the controller).

**Fix.** Reset `loading` (and the busy link) whenever a load supersedes a pending one, or simply in every `onFrameLoad` / `onFrameError`; keep `after` only for the focus.

### F4 · minor · `assets/controllers/player_results_controller.js:221-263` (`onClick`, `onInput`, `onChange`), `:587-611` (`syncForm`)
**Problem.** A pending typing debounce survives a discrete change, so one of the two is lost.
- `onInput` starts a 300 ms timer; `onClick` and `onChange` start their own load without `clearTimeout(this.typingTimer)` (only submit, popstate, before-cache and disconnect clear it).
- **The click's frame renders first (fast server):** `syncForm` writes the server's `q` (empty) into the search field, because a real click moved the focus off it, so the typed text is erased. When the timer fires, `formUrl()` equals the shown list and nothing happens.
- **The timer fires first:** `formUrl()` still has the old tab/view/sort in the hidden fields (synced only after a frame load). The typing load replaces the pending click's load (Turbo cancels it), so the click is undone and the entry is replaced, not pushed.

**Reproduction.** Type a letter into the search and click the Pairs tab within 300 ms with the mouse (WebDriver Actions, not `element.click()`, which keeps the focus). Either the letter disappears from the field, or the list shows Solo with `q=<letter>`.

**Fix.** On a discrete change, clear the timer and carry the search field's current value into the change (link href + the typed `q`), or flush the typing load first.

### F5 · minor · `assets/controllers/player_results_controller.js:80-83` (connect), `:375-384` (`onFrameError`)
**Problem.** A frame answer without `<turbo-frame id="player-results">` is not handled.
- The controller listens for `turbo:fetch-request-error`, which fires only for network failures. A 404 / 302 / 5xx answer goes through `requestFailedWithResponse` → `turbo:frame-missing`.
- Turbo's default then puts "Content missing" into the list region and throws `TurboFrameMissingError`. `turbo:frame-load` never fires, so `pending` stays set; if it was Show all, `loading` stays set too (F3's dead buttons).
- Real triggers:
  - the viewer blocks the player in another tab (404);
  - the player turns their profile private and the viewer is not allowed (302 → the profile, whose HTML has no such frame);
  - the player deletes the account;
  - a 502/503 during a deploy, or a CrowdSec page.
- `players_card_controller.js:92` and `event_manage_menu_controller.js:105` already handle `turbo:frame-missing`.

**Reproduction.** Open `/en/player-results/<id>` signed in as V; in another tab V blocks the player; back in the first tab change the sort: the list region reads "Content missing" and the console shows `TurboFrameMissingError`.

**Fix.** On the list frame: `turbo:frame-missing` → `event.preventDefault()`, clear `pending` / `loading`, `event.detail.visit(event.detail.response)` (a full visit of what the server answered: the profile's notice, the 404, the error page).

### F6 · minor · `tests/BlocklistCanaryTest.php:151-185`, `tests/PrivateProfileCanaryTest.php:80-83, 217-238`
**Problem.** Show more's stream is a third answer of the Results route that names co-solvers, and neither canary requests it.
- The blocklist canary checks "the page" and "the list alone for its frame"; the private-profile canary checks the page and the frame.
- The stream runs its own builder path (`appendPage`: rows and ranks sliced from `appendFrom`) and its own template (`_more.stream.html.twig`).
- Conventions: "every new or changed player-listing surface is added to" both canaries.

**Reproduction.** `grep -n "fragment" tests/BlocklistCanaryTest.php tests/PrivateProfileCanaryTest.php` → nothing.

**Fix.** `?category=duo&fragment=more` (page 1: `appendFrom` = 0, so the stream carries page 1's rows, no seeding needed) as a third answer in `testBlockedPlayerIsNowhereOnACoSolversResults` and in `testATeammatesResultsFrameNamesHerForTheFriendOnly`, with the friend's `no-store` asserted there too.

### F7 · minor · `templates/player/results/_rows_every.html.twig:18`
**Problem.** The month headings of Every result are `<th colspan="3" scope="colgroup">` in a table without `<colgroup>`.
- HTML requires a `th` in the column group header state to be anchored in a column group, so this is a conformance error.
- Screen readers either ignore the scope or associate the month with columns instead of the rows below it.

**Reproduction.** `grep -n "colgroup" templates/player/results/_frame.html.twig` → nothing; validate the rendered page (`/en/player-results/<id>?view=every`) with the Nu validator.

**Fix.** Drop `scope` (a plain heading row), or render one `<tbody>` per month with `scope="rowgroup"` (the stream would then append `<tbody>` elements to the table instead of rows to `#player-results-rows`).

## Questions

**Q1 ("All N results" opens one tab).** `player_profile.html.twig:85-91` and `player/overview/_numbers.html.twig:17` link "All N results" / "N Results" to `?view=every`.
- N is solo + pairs + teams (`totalResults()`), but the page opens the default tab only (Solo), so its rows are `soloResults`, not N; the other two counts sit on the tabs.
- This is what plan §PROF-03 prescribes. Keep it, word it "N results" with the tabs as the explanation, or give Every result an all-categories tab?

**Q2 (two different "fastest" for one preset).**
- A personal best never uses a time awaiting verification (`GetPlayerOverviewNumbers:93`). Its row opens `?pieces=<preset>&sort=fastest`.
- That Results row leads with the player's fastest attempt even when it awaits verification (`bestFirst()` does not skip `suspicious`), so it may show a faster time than the best that linked to it (with the "Verification needed" badge), next to a rank chip computed without it.
- The old profile list did the same, and own results may show such times (CLAUDE.md). Intended?

**Q3 (Results rows show the main title only).**
- Results now searches other names, EANs and brand codes (PROF-05d), but `_row.html.twig:53` prints `puzzleName` only: no `puzzle/_name.html.twig` second line and no "Matched: …".
- A row found by an other-language name or an EAN shows no trace of what matched.
- CLAUDE.md's puzzle-names rule lists "lists" and "search results"; the old profile list lacked it too. Add it (and weigh the 400 KB page-1 budget)?

**Q4 ("N of M" vs the tab count on Pairs/Teams).**
- On By puzzle Pairs/Teams the tab label counts distinct puzzles (`countOf()`), while the chips' "N of M" and the footer's "Showing N of M" count rows (puzzle × pair/team).
- One screen can say "Pairs (5)" and "7 of 9". The old footer already counted rows; the chip line is new. Intended?

**Q5 (`?page` renders up to every row for anybody).**
- `?page=10000` is clamped to the last page and renders rows 1…all: the heaviest clone history is ≈ 44 pages, a size at which the old profile list rendered ~6 MB in ~1 s.
- Any extra parameter bypasses the 60 s edge cache, so one anonymous GET costs that much.
- The plan's risk names only the crawl of cumulative pages (LAUNCH-05 watch, `rel="nofollow"` fallback). Is the cost of a hand-made URL accepted, or should the full render be capped (e.g. beyond Show all's 300 rows only for the owner)?

**Q6 (Results budget compared with stale profile pins).**
- `PlayerResultsQueryBudgetTest::provideViews()` asserts each row ≤ 14 / 18 / 23 / 22, labelled "the profile's pin for the same view (PlayerProfileQueryBudgetTest)".
- PROF-03/04 lowered those pins to 7 / 11 / 12 / 11, so the guard "Results cost no more than the profile" is weaker than it reads (the plan says "≤ the P0-02 pin").
- Re-point it at the current Overview pins (all rows still pass: 7 ≤ 7, 10 ≤ 11, 11 ≤ 12, 10 ≤ 11) or relabel?

**Q7 (DOC-01 notes).** `var/navigation-delivery/doc-notes.md` has no PROF lines yet. Docs that now describe the old profile:
- `docs/features/opt-out.md:32` (skill/rating cards are in `player/_standing.html.twig` on Statistics);
- `docs/features/pieces-filter.md:18` and `docs/features/list-difficulty-and-my-list-filter.md:34` ("Profile results (`PlayerSolvedPuzzles`)");
- `docs/features/api/README.md:285` ("the three blocks the profile page shows");
- `docs/features/player-header.md` (tabs);
- CLAUDE.md's "profile 'with…' filter" (pairs & teams), "profile results" sorts and filters (difficulty, pieces).

Should the orchestrator collect them now, so DOC-01 does not have to rediscover them?

## Checked and fine

**Results parity (risk 11).**
- **Sorts.** All eight old sorts are kept under the same values, plus Name, Best rank and Attempts.
  - Easiest / Hardest are stripped for non-members in `PlayerResultsCriteria` and again in the builder (lapsed membership).
  - Best rank applies to Solo By puzzle only, Attempts to By puzzle only; elsewhere the default order (`appliesTo()`, logged).
  - Every comparator ends on puzzle id then result id, and the three SQL `ORDER BY`s end on `id`.
- **Filters.**
  - Free: brand (by name), pieces (`PiecesRange`, chips + custom bounds with `pieces_was`), pair/team (narrows pairs and teams only), search.
  - Members: first tries, unboxed, relax, dates, speed, difficulty (`0` = unrated) — dropped for everybody else, never a chip, the fieldset disabled under the lock.
  - A 0 s time no longer divides by zero in the speed filter.
- **Rows.**
  - By puzzle leads with the best attempt (the best first try / unboxed under those filters) and drops rows without one; Every result keeps one row per matching result.
  - Tab counts: distinct puzzles on Pairs/Teams By puzzle, as on the profile. Tabs without rows are disabled.
- **Legacy wrapper (decision 97).** Same LiveProps, actions and normalisation; dates converted d.m.Y → Y-m-d; the builder's legacy mode reproduces the sort-dependent leading attempt, the pair/team sort-then-group order, the default Fastest and the PHP search. Ranks only on Solo, Show more by `limit`, `pagedList` unchanged. Every `this.*` of `components/PlayerSolvedPuzzles.html.twig` and `_player_solvings.html.twig` is still served. The five component tests are untouched by these commits.
- **Paging (PROF-05b).**
  - `?page` is parsed to 1…10000 and clamped to the last page.
  - The stream appends from the *requested* page, so a list that got shorter appends nothing.
  - Show all only when more rows remain and the tab has ≤ 300 rows; above that the owner gets the export link and a visitor nothing (by player id, not membership).
  - Month headings never repeat across an appended page (`rowBeforeAppended()`).

**Privacy.**
- **Lists.** The solo list and `GetPlayerOverviewNumbers` exclude with `HiddenPlayers::sqlExclude()`, the pair/team lists and the Overview's pairs/teams with `sqlExcludeTeam()` — the same `soloWhere()` / `groupWhere()` fragments and the same INNER joins, so the numbers equal the tab counts for every viewer.
- **Search.** `GetPlayerResultsSearchMatches` reads only `puzzle` over the ids of the already-filtered history.
- **Personal bests.** Solo only, `seconds_to_solve > 0 AND suspicious = false`, image masked by `hide_image_until`, ties to the earlier solve then the lower id.
- **Ranks.** Ranks come from `GetRanking` (suspicious excluded, hidden players and private profiles applied, the ranked player always in his own pool).
- **Viewer kinds** (Results and Overview):
  - a blocked player → 404 from `byId()`;
  - a private profile hidden from the viewer → 302 to the profile (Results), header + notice in one statement (Overview, the showcase gone too);
  - the owner and an allowed friend see everything, with `no-store` from `PrivateProfileRevealedCacheSubscriber`;
  - guest page `public, s-maxage=60`; frame (`Turbo-Frame`) and stream (`text/vnd.turbo-stream.html`) never public (asserted).
- **Head.** `player/_head_name.html.twig` masks by the player's own setting, byte-identical to the old profile head. JSON-LD is unchanged and skipped for a private profile.
- **Team page link (decision 71).** "Results with this pair/team" picks the viewer if a member, else the first registered member who is neither hidden (`HiddenPlayers::isHidden`) nor private to this viewer (`sqlIsPrivate`-masked `isPrivate`). No query; the guest choice is the same for every guest.
- **Canaries (PROF-08).** Results page and frame in both canaries (pairs, teams, every result, EAN search), the card in both, the Overview in both; `menu_counts` and `community` were already there. The coverage tests see the two new `src/Query` classes (the Overview query embeds the fragments and mentions `suspicious`; the search reads no player).

**SEO (PROF-07).**
- **Private profile:** `noindex, nofollow` for every viewer.
- **The list's own URL and `?page=1`:** canonical + hreflang (6 locales + x-default); `index, follow` only above 8 results, otherwise `noindex, follow`.
- **`?page=N` alone:** `noindex, follow`, canonical = the page actually rendered (beyond the end: the last page; the list itself when that is page 1), no hreflang.
- **Variants:** any other parameter, UTM included, is `noindex, follow` with canonical = the list; the `return*` parameters are ignored.
- **Stream:** `X-Robots-Tag: noindex, nofollow`. `robots.txt` leaves `/en/player-results` open. The profile head is unchanged.

**Old links (PROF-05e).**
- The redirect runs before `byId()`: 0 statements, in the request's locale. It drops an invalid team id, turns `team` + `solo` into Pairs and keeps a tab without a team.
- Nothing links `player_profile` with `team:` or `category:` any more (`grep` over templates, src and assets).

**Budgets (§3.7, decision 98 / F25).**
- **Profile pins**, lowered with comments: 11→5, 14→7, 16→10, 18→11, 22→11, 23→12, 21→11, plus the new guest → private at 1.
- **Statistics pins**, created at the measured values: 6→9, 7→10, 10→12, 10→13, 9→16, 10→16, 10→16.
- **Profile drop vs Statistics rise**, per row: −6/+3, −7/+3, −6/+2, −7/+3, −11/+7, −11/+6, −10/+6. Every drop ≥ the rise.
- **Results cost is constant:**
  - It does not depend on the history's size (9 vs 69 puzzles, page vs `?page=2`, the stream on page 2 of a longer history).
  - Rank statements: one on Solo By puzzle (Best rank reads `allForPlayer()`'s memo), none on Every result or Pairs/Teams.
  - The search costs +1, only with `q`.
- **No N+1.** The row partials call no querying Twig function (`lazy_puzzle_image`, `skill_icon`, `difficulty_icon`, `highlight`, `path`, `trans`). Tiers come from one members-only statement over the whole history.
- **Team page:** `resultsPlayerId()` adds no statement.

**Stream and Turbo rules.**
- The stream is selected by an explicit parameter (`fragment=more`), not by `Accept` (Turbo 8 asks every submission for streams) and not by the modal header (it is a GET by `fetch`, outside any frame). Content-Type and robots are set; no link carries the parameter.
- The page and its frame share one URL with `Vary: Turbo-Frame`. No POST, so the 200 rule does not apply.
- Edits from a Results row go through `modal-frame` and end in `refresh`, which reloads the current Results URL; the full-page fallback still returns to the profile (`docs/TODO.md`).

**History (PROF-05c), apart from F2–F5.**
- **Ownership:**
  - No direct `history.*` or `Turbo.session` call; pause / push / replace / resume only through `layer_history.js`.
  - The first entry is replaced on connect (GEST-01 note).
  - Ownership is handed back at `turbo:before-visit` and taken again when the visit was cancelled.
  - `disconnect()` does not resume when a Results page replaced it, so a Results → Results visit keeps one owner.
- **Writing entries:**
  - Each entry is written after `turbo:frame-load` with the server's address (`data-url`); a cancelled or older frame answer writes nothing.
  - Layer pops never reach the page; another page's entry is restored by `layer_history`.
- **Restore mark:**
  - `restore_reload.js` reads the mark once per document and removes it.
  - A restore applies only on the first page of the document (`turbo:visit` sets `visited`) and only when the URL matches the mark or a `back_forward` navigation entry, so a redirect, a reload or a new navigation never restores.
- **sessionStorage:** keys are overwritten on every leave of the same address and removed on restore. They grow by ~150–250 B per distinct address left, for one tab session: bounded in practice.
- **Native app:** `takesOver()` / `historyAction()` / `scrollRestore()` return the neutral result in the app (harness row, `NATIVE_AWARE_MODULES`).
- **GEST-15 switch points** (`isSnapshotRestore()`, `SNAPSHOT_RESTORES`, before-cache teardown) are inert, as recorded.

**Overview / Statistics / header (PROF-01…04).**
- **Overview order:** header · bests · owner cards (review banner, Getting started / country nudge) · numbers · latest 8 (`RecentActivity`, `limit` 8, `showLimit` 0) + "All N results" above 8 · achievements · About.
  - About: visitors see filled fields only; supporters stay public-only and blocklist-filtered (`GetAffiliateSupporters`).
  - A zero number is not a link. The default-tab rule for the numbers' links equals the builder's.
- **Statistics:** `_standing.html.twig` is the old block (four variants) with the chart's switch id renamed; Statistics keeps its private-profile redirect.
- **Header tabs:** the order is Overview · Results · Statistics · Calendar · Achievements · Library · Favorites (owner). No template still passes `tab="profile"`.
- **Menu:** the Results row shares `player_header.tabs.results`.

**Translations.**
- 40 keys added, all in 6 locales, none equal to English.
- Placeholders match en in every locale. Plural forms follow each locale's rule (cs 3, ja 1, others 2; pipe format, the codebase's convention).
- Tone: de "Sie", fr "vous", es "tú"; no ty-forms in cs.
- The 6 removed keys have no user left in templates, src or assets.

**Worker mode.** `PlayerResultsBuilder` memoises histories per player id and implements `ResetInterface` (listed in `NavigationServicesResetTest`). The two new queries and the controllers hold no state.
