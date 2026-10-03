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
| Free at the cap | Adding another one offers a **swap** (never a dead end) + one quiet line about membership. |
| D1 Results layout (3+ subjects) | **Three views behind an icon-only switch**, remembered per player (`player.comparison_view`): **Cards** (default; per puzzle a ranked mini-leaderboard), **Table** (matrix: sticky puzzle column, one column per subject, horizontal scroll), **Duel** (rows of the two highlighted subjects + "3rd of 5 · fastest X" strip). With exactly 2 subjects there is no switch - always duel rows. |
| D2 Launcher | **Floating pill** bottom-right on every page except the comparison itself: 3 newest mini avatars + "Compare" + count. Shown once any line-up holds someone other than you. |
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

## Page `comparison` - `/{locale}/compare/` (cs `/porovnani/`, de `/de/vergleich/`, es `/es/comparar/`, fr `/fr/comparer/`, ja `/ja/比較/` - pick final slugs not colliding with existing routes), `IS_AUTHENTICATED_REMEMBERED`, `noindex`

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
- Pickers never offer something the handler would refuse (unrevealed private players only by exact #code and only
  when revealed; guests are never subjects on their own).

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
