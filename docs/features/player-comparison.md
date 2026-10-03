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
| D1 Results layout (3+ subjects) | **Two views - Table (default, first) and Cards** - behind a segmented switch with icon + text label ("Table", "Cards"; one line at 320 px in all 6 locales: 134-167 px wide, measured 2026-10-03), remembered per player (`player.comparison_view`, default `table`). **Table**: sticky puzzle column, one column per subject, horizontal scroll inside its box, header row pinned under the site header while the page scrolls. **Cards**: per puzzle a ranked mini-leaderboard. With exactly 2 subjects there is no switch - always side-by-side rows (`_duel_rows.html.twig`), following "puzzles to show". **The Duel view (3+) was removed on 2026-10-03 after user feedback**: it listed only what the highlighted pair both solved under a league table counting the whole line-up, so "N puzzles you both solved" and the wins above it never added up, and people did not understand which pair it followed. Every stored `duel` (and every other value - nobody had chosen deliberately yet) was reset to `table` by `Version20261003113151`. |
| D2 Launcher | **Floating pill** bottom-right on every page except the comparison itself: 3 newest mini avatars + "Compare" + count. Shown once any line-up holds someone other than you. A page opts out with `{% set hide_comparison_launcher = true %}` at its top (read by `base.html.twig`): the comparison, every page whose main content is a form (add/edit time incl. relax/collection, puzzle change proposal, edit profile + its settings and list-settings pages, marketplace/collection/wishlist/lend-borrow forms, feedback/contact/feature-request forms, event/round/series forms, voucher, API access request), a chat (conversation, new message), a bottom bar (multiscan) or a running clock (stopwatches) - the pill would sit over their controls. New form pages add the line too. |
| D3 First tries | Filter is **members-only** (consistent with the profile). Everyone sees the "1st try" badge / "fastest of N tries" on every time, and a line above the list says what every time is: "Each time is their best on that puzzle." / "… their first try on that puzzle." ("best of N" alone was unclear - "which attempts are we comparing?"). |
| D4 Charts | **Members-only**. Free users get the head-to-head card / league table. |
| D8 "Someone at your speed" | Members-only "Roll the dice": a random player of similar skill. **Never use the word "rival"** in UI or code (reserved for another feature). |
| D9 Old URL | `/compare-with-puzzler/{id}/` → **302** to the no-write preview `?with=<you>,<them>` with "Keep in line-up". |
| D10 Default "puzzles to show" | 2 subjects → **solved by both**; 3+ → **solved by 2+**. Reason (measured on the prod copy): two random active players share a median of 3 puzzles (25 % none) and 10 heavy players have 5,242 puzzles in their union but only 1,504 solved by ≥ 2 and 4 by all 10 - "everyone" is near-empty for big line-ups, "all" is mostly one-subject rows that compare nothing; 2+ keeps every row a real comparison. When the default yields nothing, an empty state offers one tap to "Show all puzzles" (never a silent switch). |
| D11 Profile entry | ⋯ menu keeps "Add to comparison" / "Remove from comparison" + "Open comparison"; **plus a visible icon-only compare button** in the header actions (44 px, label in `aria-label` + `title`). Not in your line-up → POST add (stay on the page, flash with "Open comparison"); already in → link to the comparison. "Favorite" collapses to a star-only button below a breakpoint **measured in all 6 locales** so the action row never wraps at 320 px (record the numbers in this doc). |
| D12 Pairs & teams search | Add sheet in Pairs/Teams searches **any visible pair/team** by member name/#code or team name. Your own pairs/teams are listed first and marked **"You're in it"** (legit: compare your own groups to see which is fastest). |
| D13 Share | "Share" on the page copies a link with the subjects + filters (`?with=…`). Opening it (signed-in) shows that comparison as a **preview** (no write) with "Add to my line-up" (merge up to the cap). The recipient's own visibility rules and caps apply. |
| D14 | No feature flag - public immediately. |
| D15 | All 6 locales in the same delivery. |
| D16 Pair picker (2026-10-03) | The highlighted pair (`a`/`b` URL props) is picked **only where it matters**, as a plain sentence with two selects (word order per locale, `_pair_picker.html.twig`): at the top of the **Charts** tab "Compare [You ▾] with [Vanja ▾]", and on the **Puzzles** tab only while the sort is a lead/lag and there are 3+ subjects: "Biggest lead: [You ▾] vs [Vanja ▾]". The sort options say whose ("Biggest lead: You"). Never above the tabs, no explanatory hint; the A/B coral/indigo rings are drawn only where the pair is in play (two subjects, the charts, a lead/lag sort). |
| D17 Clear (2026-10-03) | "Clear" at the end of the caption row ("7 / 10 · Clear"), only when the shown line-up has someone besides you; tapping it asks inline in the same row - "Remove all 6? **Yes, clear** · Cancel" (one render of state, `Comparison::askClear()`; Cancel is a plain re-render, any other action forgets the question). `ClearComparisonLineUp(playerId, kind)` (same `SerializedByLock` key as the adds) deletes the owner's rows of that kind; Solo keeps the owner's own row for everyone - clearing means "nobody to compare with", back to the empty state. Never in a shared preview. |
| D18 "+ Add" first (2026-10-03) | The dashed "+ Add" chip leads the line-up strip, so it is seen without scrolling the strip on a phone. |
| D19 Difficulty on the thumbnail (2026-10-03) | Members see the puzzle's difficulty tier on the bottom-right corner of its thumbnail (cards, rows and the table's puzzle column) instead of a small icon after "Brand · pieces": `_difficulty_corner.html.twig` + `.diff-corner` in `_user.scss` (white disc, tier name as tooltip and accessible name; reusable on any thumbnail inside a `.diff-corner-host` - the site had no thumbnail-corner pattern before). Free players see no tier there, as before. |

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
3. Line-up strip of the active kind: dashed "+ Add" first, then chips (avatar or people icon, short name, "You're in it" tag for own pairs/teams, ×); under it "n / cap · Clear" (D17). A subject no longer visible to the viewer = neutral "No longer available" chip (row id only).
4. Summary: 2 subjects → **head-to-head card** (wins split bar in coral #fe4042 / indigo #4e54c8 with ties in grey, "N puzzles solved by both · 3 ties · X is N % faster on the median puzzle" - the ties are stated so wins + wins + ties visibly make N); 3+ → **league table** (# · subject · Wins · Solved · Gap = median % behind the fastest; your row tinted; "N puzzles had a shared fastest time - nobody won them" under it when there are any). Free for everyone.
5. Tabs Puzzles | Charts (Charts: members; free users see the existing members placeholder pattern with one button).
6. Quick filter row: Filters (n) · First tries (members, lock for free) · period ▾ · sort ▾; under it the pair picker while a lead/lag sort is on (3+, D16); view switch (3+ subjects) on the list header row, then the line saying what every time is (D3).
7. Results (50 per "Show more"). Tapping a time opens the existing `puzzle_result_detail` modal (all attempts). Every time shows the day it was solved and "1st try" / "fastest of N tries" - in the table stacked under the time and its gap, one token per line.
8. Charts tab: the pair picker at its top ("Compare [You ▾] with [Kateřina ▾]", any two when you're not in the line-up) - the 2-series charts follow it.

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
- Wins: the unique fastest subject of a puzzle solved by ≥ 2 subjects (ties = no win). The numbers add up: Σ wins +
  ties (`ComparisonResult::$ties`, a shared fastest time) + puzzles only one subject solved ("all puzzles") = listed
  puzzles; head to head: wins A + wins B + ties = puzzles both solved. Checked on the prod copy 2026-10-03 for real pairs
  (155 and 260 shared, best / first tries / 12 months) and a trio (2+ / all / everyone) - all consistent; ties are rare
  but real (1.7 % of pairs with 20+ shared puzzles have one, at most 2).
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
- `player.comparison_view` (table|cards, default table) via `ChangeComparisonView`.
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
  `player.comparison_view` (`ComparisonView`, default table since 2026-10-03).
- Messages `AddComparisonSubject(playerId, subjectRef, ?replaceSubjectId)`, `RemoveComparisonSubject`,
  `ChangeComparisonView`, `ClearComparisonLineUp(playerId, kind)` (D17). Exceptions are Symfony HTTP exceptions (they arrive unwrapped from the bus):
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
  copy), `GetComparisonPuzzles::byIds()` (hydrates the shown page), `ComparisonBuilder` (pure: ranks, ties = no win,
  wins, ties, league, head-to-head with the geometric median of time ratios, highlight pair, sorting, paging),
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

### Feedback round (2026-10-03, same day)

- **Duel view removed** (D1), views Table (default) + Cards with text labels; migration `Version20261003113151` sets
  the column default to `table` and rewrites every stored value to `table`.
- **Pair picker** moved out from above the tabs to where the pair matters (D16); the "The Duel view, the lead and lag
  sort and the charts follow this pair." hint is gone.
- **Table**: pinned header (`comparison_table_controller.js`): the table scrolls sideways in its own box, which clips
  a sticky `<thead>`, so the real header row is copied into a zero-height sticky strip above the box
  (`top: var(--header-height)`, data-live-ignore, aria-hidden), shown while the real header is under the site header,
  columns sized from the real ones via `<col>`s, `scrollLeft` kept in step; its first column is sticky too. Re-copied on
  every re-render (MutationObserver on the real `<thead>`), re-measured on any size change (ResizeObserver). Checked
  in Chrome at 375 px: pins at 97 px, widths and offsets match, unpins after the table. Cells show the date and
  "1st try" / "fastest of N tries" stacked (columns 84-116 px in the 6 locales).
- **"best of N" → "fastest of N tries"** + the line above the list (D3).
- **Bug: "Show more" stuck at 100.** Live writes every prop into its non-multiple `<select data-model>` after each
  render and reads the select back; a null `sort`/`period` matches no option, the browser falls back to the first
  option `""`, and from then on every request re-sent `updated: {sort: "", period: ""}`. Their `onUpdated` hook
  (`onFilterUpdated`) reset `limit` to 50 *before* `showMore()` added 50 - every click rendered page two again
  (reproduced on the old code: rows 50 → 100 → 100 → 100 of 168). Fix: paging belongs to a list (`$pagedList` = kind +
  compared subjects + normalized filters): the same list keeps its pages whatever a request carries, another list
  starts on page one; no hook resets paging any more, and `onPeriodUpdated` acts only on a real change (the same
  re-sent `""` used to close the members' custom range on the next interaction). Regression test drives the component
  the way the browser does, select read-back included (`ComparisonTest::modelsTheBrowserResends()`).
- **Bug: "wins don't add up".** No counting error (verified on real data, see "Data rules"); the gaps were ties drawn
  only as a grey bar segment and the Duel view's pair-only list under a whole-line-up league. Ties are now stated in
  the head to head and under the league table.
- **Clear** (D17), **"+ Add" first** (D18), **difficulty on the thumbnail** for members (D19).
- League table: the name column takes what the numbers leave and ends in an ellipsis, so no locale's column labels
  push the page sideways at 320 px.
