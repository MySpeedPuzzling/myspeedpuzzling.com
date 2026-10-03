# Compare: a line-up of players, pairs and teams

Replaces the old 1:1 page `compare_players` ("you vs one player, solo best times, no filters").
Solves feature requests [Filter for Comparing Times](https://myspeedpuzzling.com/en/feature-requests/019d1f2f-9193-7225-ba37-441a10e6b566)
and [Compare First Try times only with a friend](https://myspeedpuzzling.com/en/feature-requests/019dbf3d-fa7c-7222-8c99-d3a6df2b06bc).
Supersedes the stale PR #131 (session bucket, no blocklist/allow-list handling - not merged).
Design canvas (Jan's picks recorded below): https://claude.ai/artifact/N15GRFoqXfDUqdgZ9pVihD

## Why

Comparison is a high-engagement, high-intent moment. Turning it from a 1:1 dead end into **"a league table
among my friends"** drives retention, gives well-timed membership upsells and is a puzzling-specific
differentiator (no generic stats tool compares pairs/teams). The line-up is **persistent** (stored per player,
every device), so you keep adding people while browsing and open the comparison from a floating launcher.

## Decisions (Jan, 2026-10-03)

| # | Decision |
|---|---|
| Kinds | **Like with like**: three separate line-ups - **Solo** (players' solo times), **Pairs** (exact pairs = `puzzling_team.size = 2`), **Teams** (exact teams, size ≥ 3). A segmented switch Solo · Pairs · Teams (with counts) picks which one is compared. Never mixed. |
| Cap | Per kind. Members **10** subjects (yourself included when present). Free: **Solo = you + 1 other** (you cannot remove yourself), **Pairs = 2**, **Teams = 2**. |
| Yourself | You are a row like any other (added automatically the first time you open Solo). Members may remove themselves and compare other people. |
| At the cap | Adding another one offers a **swap** (never a dead end) - free players and members alike, from the page's add sheet and from the entry points (`?swap=`). Members may swap themselves out; free players stay in their Solo line-up. Free players also get one quiet line about membership. |
| D1 Results layout (3+ subjects) | **Three views behind an icon-only switch**, remembered per player (`player.comparison_view`): **Cards** (default; per puzzle a ranked mini-leaderboard), **Table** (matrix: sticky puzzle column, one column per subject, horizontal scroll), **Duel** (rows of the two highlighted subjects + "3rd of 5 · fastest X" strip - only the puzzles **both of them solved**, "N puzzles you both solved"; the league table above still describes the whole line-up; `ComparisonBuilder::build(…, highlightedPairOnly: true)`, never on the Charts tab). With exactly 2 subjects there is no switch - always duel rows, following "puzzles to show". |
| D2 Launcher | **Floating pill** bottom-right on every page except the comparison itself: 3 newest mini avatars + "Compare" + count. Shown once any line-up holds someone other than you. A page opts out with `{% set hide_comparison_launcher = true %}` at its top (read by `base.html.twig`): the comparison, every page whose main content is a form (add/edit time incl. relax/collection, puzzle change proposal, edit profile + its settings and list-settings pages, marketplace/collection/wishlist/lend-borrow forms, feedback/contact/feature-request forms, event/round/series forms, voucher, API access request), a chat (conversation, new message), a bottom bar (multiscan) or a running clock (stopwatches) - the pill would sit over their controls. New form pages add the line too. |
| D3 First tries | Filter is **members-only** (consistent with the profile). Everyone sees the "1st try" badge / "best of N" on every time. |
| D4 Charts | **Members-only**. Free users get the head-to-head card / league table. |
| D8 "Someone at your speed" | Members-only "Roll the dice": a random player of similar skill. **Never use the word "rival"** in UI or code (reserved for another feature). |
| D9 Old URL | `/compare-with-puzzler/{id}/` → **302** to the no-write preview `?with=<you>,<them>` with "Keep in line-up". |
| D10 Default "puzzles to show" | 2 subjects → **solved by both**; 3+ → **solved by 2+**. Reason (measured on the prod copy): two random active players share a median of 3 puzzles (25 % none) and 10 heavy players have 5,242 puzzles in their union but only 1,504 solved by ≥ 2 and 4 by all 10 - "everyone" is near-empty for big line-ups, "all" is mostly one-subject rows that compare nothing; 2+ keeps every row a real comparison. When the default yields nothing, an empty state offers one tap to "Show all puzzles" (never a silent switch). |
| D11 Profile entry | ⋯ menu keeps "Add to comparison" / "Remove from comparison" + "Open comparison"; **plus a visible icon-only compare button** in the header actions (44 px, label in `aria-label` + `title`). Not in your line-up → POST add (stay on the page, flash with "Open comparison"); already in → link to the comparison. "Favorite" collapses to a star-only button below a breakpoint **measured in all 6 locales** so the action row never wraps at 320 px (record the numbers in this doc). |
| D12 Pairs & teams search | Add sheet in Pairs/Teams searches **any visible pair/team** by member name/#code or team name. Your own pairs/teams are listed first and marked **"You're in it"** (legit: compare your own groups to see which is fastest). |
| D13 Share | "Share" on the page copies a link with the subjects + filters (`?with=…`). Opening it (signed-in) shows that comparison as a **preview** (no write) with "Add to my line-up" (merge up to the cap). The recipient's own visibility rules and caps apply. |
| D14 | No feature flag - public immediately. |
| D15 | All 6 locales in the same delivery. |

### Header actions (D11) - measured 2026-10-03 in all 6 locales (real CSS, Rubik, mobile emulation)

Smallest width where every label stays on one line with full padding (Message present, worst of favorite/favorite_on):

| Row | en | cs | de | es | fr | ja | all 6 |
|---|---|---|---|---|---|---|---|
| Fav label + Msg + compare icon + ⋯ | 344 | 364 | 343 | 341 | 330 | **379** | **379** |
| Star + Msg + compare icon + ⋯ | 291 | 277 | 298 | 287 | 291 | 306 | **306** |
| Fav label + Msg + compare text + ⋯ | 405 | **424** | 423 | 407 | 396 | 406 | 424 |
| No Message: Fav label + compare icon + ⋯ | 234 | 267 | 226 | 234 | 220 | 254 | 267 |

So: **compare is icon-only everywhere** (a text label fits all locales only from 424 px, and at 768-991 px it would
squeeze the name to 164 px in Czech); **Favorite becomes star-only below 380 px** when the row also holds Message
(`max-width: 379.98px` + `:has(> .player-head-message)`; the label sits in its own span, `aria-label`/`title` carry
the name). 360 px was not enough: ja "お気に入り"/"メッセージ" and cs "V oblíbených" wrap up to 378 px. The own-profile
row (Share + Edit profile + ⋯) is unchanged.

## Page `comparison` - `/{_locale}/compare` (the same English slug in every locale, like other newer routes), `IS_AUTHENTICATED_REMEMBERED`, `noindex`

Top to bottom (mobile first, 320-390 px; desktop: line-up + league/head-to-head in a left column, list right):

1. Title "Compare" + Share icon button.
2. Kind switch Solo · Pairs · Teams (look of `.copuzzler-switch`, counts per kind, one line at 320 px).
3. Line-up strip of the active kind: chips (avatar or people icon, short name, "You're in it" tag for own pairs/teams, ×), dashed "+ Add", "n / cap". A subject no longer visible to the viewer = neutral "No longer available" chip (row id only).
4. Summary: 2 subjects → **head-to-head card** (wins split bar in coral #fe4042 / indigo #4e54c8, "N puzzles you both solved · X is N % faster on the median puzzle"); 3+ → **league table** (# · subject · Wins · Solved · Gap = median % behind the fastest; your row tinted). Free for everyone.
5. Tabs Puzzles | Charts (Charts: members; free users see the existing members placeholder pattern with one button).
6. Quick filter row: Filters (n) · First tries (members, lock for free) · period ▾ · sort ▾; view switch (3+ subjects) on the list header row.
7. Results (50 per "Show more"). Tapping a time opens the existing `puzzle_result_detail` modal (all attempts).
8. Highlight picker ("You vs Kateřina ▾", or any two when you're not in the line-up) drives Duel view, the lead/lag sort and the 2-series charts.

### Filters (URL is the state; flat scalar Live props + `normalizeState()` like `PuzzleSearch`; members-only values stripped server-side)

| Filter | Values | Free | Members |
|---|---|---|---|
| Puzzles to show | both/everyone · 2+ · all (default per D10) | ✓ | ✓ |
| Times | best · first tries only | lock | ✓ |
| Solved in | all · 12 · 6 · 3 months | ✓ | ✓ |
| Solved in - custom range | from-to | lock | ✓ |
| Pieces | `PiecesRange` chips + custom | ✓ | ✓ |
| Brand | multi-select with logos | lock | ✓ |
| Difficulty | tier chips (reuse `SearchPuzzle::difficultyFilter` semantics incl. unrated) | lock | ✓ |
| Sort | recent · biggest lead · biggest lag · name · pieces | ✓ | ✓ |
| Sort - difficulty | | lock | ✓ |

Date and first-try filters apply to times **before** aggregating ("best" = best within the filter).
Lead/lag = highlighted A minus B.

### Charts (members; built in PHP from the same aggregate rows - no extra query; only rendered on the Charts tab)

Emphasis encoding: highlighted A coral `#fe4042`, highlighted B indigo `#4e54c8`, everyone else gray `#c3c8d1`.
(a) Who's ahead, puzzle by puzzle - horizontal diverging bars sorted lead → lag (top/bottom 10 + count);
(b) A's time vs B's time - scatter + dashed parity line; (c) pace by piece count - dot plot, % vs line-up median per
bucket; (d) form over time - monthly median pace (% vs line-up) lines, last 12 months; (e) head-to-head grid (3+) -
HTML table heat map (one-hue indigo ramp). Legends always (HTML legend like the leaderboard chart), texts in ink colours.

### "Someone at your speed" (members)

Nearest 50 players by `player_skill.skill_percentile` at 500 pc (fallback: `player_baseline` at the viewer's most
solved piece count), shuffled with a seed, first that passes: ≥ 5 shared solo puzzles, a solo solve in the last 12
months, public profile (`is_private = false` - global-ranking semantics), `ranking_opted_out = false`, no
`user_block` row in either direction, not the viewer, not in the Solo line-up. `MATERIALIZED` CTEs + lazy `LATERAL`
check (the naive shape took 820 ms; measured shape 1.5-12 ms). Card says why: similar speed (tier / top N %),
N puzzles in common, solves this month. "Add to line-up" / "Roll again". No candidate → friendly empty text.

## Data rules

- Solo subject: `pst.player_id = :p AND pst.puzzling_type = 'solo'`. Pair/team subject: `pst.puzzling_team_id = :t`.
- Valid time: `pst.suspicious = false AND pst.seconds_to_solve IS NOT NULL`. Puzzle `hide_until > now` excluded,
  `hide_image_until > now` masks the image.
- Day: `COALESCE(pst.finished_at, pst.tracked_at)`, tie-break `pst.tracked_at`, then `pst.id`.
- First try: `first_attempt = true`, earliest if several (legacy duplicates); none → "—".
- Per (subject, puzzle): attempts, best time + its id + day, first-try time + id + day.
- Wins: the unique fastest subject of a puzzle solved by ≥ 2 subjects (ties = no win).
- Gap: median over the subject's compared puzzles of (time / fastest time − 1).

## Visibility (fail towards hiding; re-checked on every read, never only at add time)

- Player subject: blocked in either direction → gone; private → only when revealed to the viewer (allow list,
  `PrivateProfileAccess`).
- Pair/team subject: **any** member hidden by the blocklist → hidden, even when the viewer is in it (shape of
  `GetCoPuzzlers` `hidden_member`); all registered members private-and-unrevealed → hidden unless the viewer is a
  member; otherwise private members masked (name → "Private puzzler"-style mask used elsewhere).
- Handlers resolve visibility with **explicit** ids (viewer id, `GetUserBlocks`, allow list) - never the ambient
  security-token based `HiddenPlayers`/`PrivateProfileAccess` (they see nobody under async/console).
- Pickers never offer something the handler would refuse: a private player hidden from the viewer is never found
  (the pairs/teams search does not match them even by the exact #code - that would tie the code to their pairs);
  revealed ones (allow list) and the viewer are found like anybody else; guests are never subjects on their own.

## Model

- `comparison_subject`: `id` uuid7, `player_id` (owner, FK CASCADE), `subject_player_id` (nullable FK → player,
  CASCADE), `subject_team_id` (nullable FK → puzzling_team, CASCADE), `added_at`. Two unique constraints:
  `(player_id, subject_player_id)` and `(player_id, subject_team_id)`.
- Team merges (`PuzzlingTeamMemberConversion::mergeInto()`) repoint rows to the surviving team before deleting the
  merged one (skip owners who already have the survivor); `DeletePlayerHandler` deletes the owner's rows explicitly.
- `player.comparison_view` (cards|table|duel, default cards) via `ChangeComparisonView`.
- The viewer's line-up rides on the profile row (`GetPlayerProfile::byUserId`, one sub-select like
  `hidden_player_ids`): subject ids per kind + the 3 newest for the pill (avatar/initial/tint or people icon) →
  launcher, header button state and team page state cost **0 extra queries**.

## Performance budgets (asserted in tests)

- Compare page query count is **identical at 2 and 10 subjects**: line-up + one aggregate statement (all
  subject×puzzle rows, filters applied, ≤ ~9k rows worst case, measured 20-50 ms) + one hydration statement for the
  50 shown puzzles (+ list insights for members). Builder/sort/summary/charts in PHP.
- Profile, team and every other page: **+0 queries** for the launcher/header state.
- No new index needed (`custom_pst_intelligence`, `idx … puzzling_team_id` cover it).

## Guards

`BlocklistQueryCoverageTest`, `PrivateProfileQueryCoverageTest` registrations; bespoke `BlocklistCanaryTest` /
private-profile cases for the compare page (blocked subject disappears, private subject only for allow-listed
viewer); `RobotsTxtTest` + `robots.txt` for the new paths (old Disallow lines stay); `PlayerHeaderTest` retargeted to
the new button/menu item; query budgets.

## As built (2026-10-03)

Built in one PR from four parallel streams (write side, read side, page, charts + similar speed, entry points),
then integrated and checked in a browser at 375 px and 1280 px against a copy of the production data.

### Write side
- `ComparisonSubject` entity (`comparison_subject`, two unique constraints), `ComparisonSubjectRepository`,
  `player.comparison_view` (`ComparisonView`, default cards).
- Messages `AddComparisonSubject(playerId, subjectRef, ?replaceSubjectId)`, `RemoveComparisonSubject`,
  `ChangeComparisonView`. Exceptions are Symfony HTTP exceptions (they arrive unwrapped from the bus):
  `ComparisonSubjectNotAvailable` 404, `ComparisonLineUpFull` 409 (kind + cap, drives the swap prompt),
  `ComparisonSubjectNotFound` 404, `CanNotRemoveYourselfFromComparison` 403.
- `ComparisonSubjectVisibility` decides with **explicit** ids as seen by the owner: only the owner's own blocks
  hide (being blocked by someone never makes them unavailable - the blocked side must never be able to tell);
  private players only when revealed (allow list counts only without a block either way); a pair/team with a member
  the owner blocked is unavailable even when the owner is in it.
- First Solo add into an empty Solo line-up adds the owner too. Already-present → no-op before the cap check.
  `AddComparisonSubject` is `SerializedByLock` (`comparison-line-up-<owner>`): two adds of one owner never overlap,
  so the cap cannot be exceeded by a race.
- `PuzzlingTeamMemberConversion::mergeInto()` repoints rows to the surviving team; `DeletePlayerHandler` deletes the
  player's rows (own and as someone else's subject).
- The viewer's line-up rides on `GetPlayerProfile::byUserId` (`PlayerProfile::$comparisonLineUp`, one sub-select):
  `count()`, `hasOthers()`, `countForKind()`, `contains()`, `rowIdOf()`, `refsForKind()`, `recent()` (3 newest for the
  pill, `isMasked` for blocked/unrevealed), `newestKind()`.

### Read side
- `ComparisonCriteria::fromUserInput()` (+ `ComparisonShow/Times/Period/Sort`), `normalized()` reflected back into the
  URL, `toQueryParameters()` for share links. Members-only values from free players are dropped silently.
- `GetComparisonSubjects::byRefs()` (identities, viewer visibility, `isAvailable`), `GetComparisonResults::forSubjects()`
  (one aggregate statement for up to 10 refs of one kind; 2 typical players ~2 ms, 10 heaviest 40-49 ms on the prod
  copy), `GetComparisonPuzzles::byIds()` (hydrates the ≤ 50 shown), `ComparisonBuilder` (pure: ranks, ties = no win,
  wins, league, head-to-head with the geometric median of time ratios, highlight pair, sorting, paging),
  `ComparisonChartsData` + `ComparisonChartsFactory` (Chart.js models), `FindSimilarSpeedPuzzler` (seeded, 5-8 ms),
  `SearchComparisonTeams` (`search()` by team name or member name/#code, `forViewer()`).

### UI
- Page: `ComparisonController` (`comparison`, `/{_locale}/compare`, `noindex`, `private, no-store`) + Live component
  `Comparison` (URL-mapped flat props, add/remove/swap/view/preview actions, listens to `comparisonAddSubject`),
  partials in `templates/comparison/`, `_comparison.scss`, Stimulus `comparison_page|sheet|add`.
  `ComparisonTeamSearchController` (`/{_locale}/compare/teams.json`, `private, no-store`).
- Charts: `ComparisonCharts` (Twig component) + `comparison_chart_controller.js` + `_comparison-charts.scss`.
  `ComparisonSimilarSpeed` (Live component; no query before the first roll; membership checked on every roll).
- Entry points: `AddComparisonSubjectController` / `RemoveComparisonSubjectController` (POST, stateless CSRF ids
  `comparison_add` / `comparison_remove`, PRG with flash, `return` validated; a full free line-up redirects to
  `comparison?kind=…&swap=<ref>`), header compare button + Favorite star rule (`PlayerHeader::$compare`), ⋯ menu,
  team page button, floating pill `templates/comparison_entry/_launcher.html.twig` (not on the compare page, multiscan,
  stopwatches, form and chat pages; hidden while a modal or the site search is open).
- Legacy `compare_players` (old 6 locale paths) → `LegacyComparePlayersController` 302 to the `?with=` preview.
- Removed: `ComparePlayersController`, `PlayersComparison`, `Value\Comparison`, `compare_players.html.twig`.

### Add sheet (feedback round, 2026-10-03)

`templates/comparison/_add_sheet.html.twig` + `comparison_add_controller.js`; nothing is fetched before the sheet opens
(the page's first render runs no query for it - `ComparisonAddSheetTest`).

- **Solo lists** - `comparison_people` (`/{_locale}/compare/people.json`, `ComparisonPeopleController`):
  `{favorites, coPuzzlers}` from **one** statement (`GetComparisonPeople::forViewer()`): **every** favorite by name
  (the sheet shows 8, then "Show all (N)" and, from 9 favorites on, a client-side filter "Search your favorites" - no
  request) and up to 6 non-favorite people the viewer shares the most pair/team results with ("People you puzzle with";
  pairs/teams with a blocked member or archived by the viewer count for nobody, guests never). Only who an add would
  accept: nobody the viewer blocked, no private profile hidden from them (allow list reveals), never the viewer.
- **Solo search** - `comparison_player_search` (`/{_locale}/compare/players.json?query=`, `ComparisonPlayerSearchController`):
  `SearchPlayers::fulltext()` (15), a leading `#` is stripped (codes), private players hidden from the viewer are left
  out even by exact code. Pairs/Teams keep `comparison_team_search`.
- **One JSON shape** for both (`ComparisonPersonOptions`): `ref, id, label, code, country, countryName, avatar, favorite`
  + `tier` **only for members** - the leaderboards' rule (`_leaderboard_player.html.twig`): the 500-piece tier,
  `unknown` without one, `locked` for a player who opted out of rankings; one statement for every list of a response
  (`GetComparisonPeople::skillTierIcons()`). Query count is flat in the number of favorites.
- **Drawing**: avatars are `_player_avatar.html.twig` in JS (`.cmp-avatar--lg` + `.lb-avatar-lg`: photo + corner flag,
  else the round flag `lb-avatar-flag`, else the initial on the id's tint); tier icons are `<template>`s rendered once by
  `skill_icon()` in the sheet (members: every tier + unknown + locked, everybody else only `locked`) and cloned; the
  global search's `ci-star-filled text-warning` marks favorites in the search results. Whoever is already in the line-up
  stays listed as **"Added"** (disabled, also in the search) - rows never move under a finger. Rows are 44 px, built once
  per load and reused by the filter (images never reload); three placeholder rows hold the space while loading.

### Pull-to-refresh vs. scrolling (installed PWA)

`pwa_lifecycle_controller.js` armed its pull-to-refresh on every touch while `window.scrollY === 0` and then called
`preventDefault()` on every downward `touchmove`. Inside an open modal the page behind stays at 0, so a sheet scrolled
down could never be scrolled back up ("after Add, I can't scroll up"), and a sideways swipe over a table drifting a
few pixels down lost its scroll ("touch works worse in the PWA"). The decisions now live in `assets/pull_to_refresh.js`
(tested under node, `PullToRefreshGestureTest`): nothing is armed while a modal / offcanvas / `dialog` / the site search
is open, nor when the finger lands in an element scrolled itself, in a sideways-scrolling container or under
`[data-ptr-ignore]`; the first 8 px decide the direction and only a clearly vertical downward move (≤ 1:2 sideways,
`touchmove` still cancelable) becomes a pull - other gestures are never prevented. The browser (non-PWA) has no
pull-to-refresh of ours; `.modal, .modal-body { overscroll-behavior-y: contain }` (app.scss) keeps the sheet's scroll
from chaining to the page.
