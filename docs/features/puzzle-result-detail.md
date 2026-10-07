# Result detail

One player's - or one exact pair's / team's - results on one puzzle, opened from one of its times (a leaderboard
row, a profile row). Opens in the global modal (`modal-frame`), or as a full page without JavaScript / when shared.

## Route

`puzzle_result_detail` - `PuzzleResultDetailController`, GET only:

| Locale | Path |
|---|---|
| cs | `/vysledek/{timeId}` |
| en | `/en/result/{timeId}` |
| es | `/es/resultado/{timeId}` |
| ja | `/ja/結果/{timeId}` |
| fr | `/fr/resultat/{timeId}` |
| de | `/de/ergebnis/{timeId}` |

`timeId` must be uuid-shaped (`Requirement::UID_RFC4122`). Not `Requirement::UUID`: that one also checks the
version/variant digits, which the test fixture ids do not have - and the leaderboard links every row here, so a
stricter requirement would make URL generation throw on fixture data.

- Request header `Turbo-Frame: modal-frame` → `puzzle_result/_modal.html.twig` (`modal-lg`), otherwise the full page
  `puzzle_result/detail.html.twig`: `noindex, follow`, canonical = the puzzle detail page, no hreflang, back button
  (`?return=` validated by `safe_return_url`, falls back to the puzzle). Both include `puzzle_result/_body.html.twig`.
- The response carries `Vary: Turbo-Frame` (same URL, two bodies).
- Inside the modal every link that leaves the result carries `data-turbo-frame="_top"` - a plain link inside the
  modal frame would load the target page into the frame, find an empty `modal-frame` and just close the modal.
  `_leaderboard_player.html.twig` and `_competition_badge.html.twig` read an optional `links_turbo_frame` variable for
  that (the body sets it to `_top`; it flows into the includes through the context). The edit button keeps
  `modal-frame`: editing replaces the modal content with the edit form.

## Subject

Derived from the time, never from the URL:

- solo time → that player's solo times on the puzzle,
- pair/team time → that exact pair's/team's times on the puzzle: same `puzzle_solving_time.puzzling_team_id` and
  same `puzzling_type` (see [pairs-and-teams](pairs-and-teams/README.md) - a team is the exact set of people).

Attempts are newest first (`COALESCE(finished_at, tracked_at) DESC, tracked_at DESC`), relax solves included (relax
badge, no comparisons). Each timed attempt shows: date, time (★ + bold for the best), ↩ change against the
chronologically previous timed attempt (green faster / red slower), ★ gap to the own best, PPM (pairs/teams also
per person, `ppm(time, pieces, members)`), badges (first try, unboxed, event, suspicious), comment, and for whoever
may edit it (`PuzzleResultAttempt::isEditableBy()` - the tracker or any registered member) the finished-puzzle photo
and the edit button.

## Privacy

All in `GetPuzzleResultDetail::byTimeId()`, every case answers `PuzzleResultNotFound` (404):

- unknown time id,
- a time the viewer may not see because of the blocklist (`HiddenPlayers::sqlExclude()` / `sqlExcludeTeam()`, the
  same fragments as `GetPlayerSolvedPuzzles::byTimeId()` - a group time stays for its own members),
- a solo time of a private player (`PrivateProfileAccess::sqlIsPrivate()`, so the allow list works) unless the viewer
  is that player,
- a pair/team where every registered member is private to the viewer and the viewer is not a member. Otherwise
  private members are rendered masked (`_leaderboard_player.html.twig`).

Suspicious times (`suspicious = true`) are listed for everybody who may see the subject, with the "Verification
needed" badge - like the row on the player's profile they open from, and so a link mailed about one opens signed out
too. They are never the best attempt and never part of the standing. Guards: `BlocklistCanaryTest::testResultDetailOfABlockedPlayerDoesNotExistForTheBlocker`,
`PrivateProfileCanaryTest::testResultDetailOf*`.

## Standing

Free for everyone: "Rank N of M", ① gap to the fastest (`bi-1-circle` - not a trophy, that one means events), ↑ gap to the next faster time (only when that one is not
the leader's). It must equal what the puzzle leaderboard (`PuzzleTimes` + `PuzzlesSorter`, unfiltered) shows for the
subject's row - `GetPuzzleResultDetailTest::testStandingEqualsTheLeaderboard` compares every row of the fixture boards.

`GetPuzzleResultDetail::standing()` is one aggregate statement, the leaderboard is never loaded:

- per subject (solo: `player_id`, pair/team: `puzzling_team_id`) the best time over
  `seconds_to_solve IS NOT NULL AND suspicious = false` for the puzzle + `puzzling_type`,
- hidden players left out inside the aggregate (`HiddenPlayers`),
- private subjects left out like `PuzzlesSorter::filterOutPrivateProfiles()`: solo = private and not the viewer;
  pair/team = no member visible to the viewer (a guest counts as visible, via `puzzling_team_member`) and the viewer is
  not a member,
- rank = 1 + number of subjects strictly faster (ties share the rank), total, leader time, closest faster time.

It is skipped when the subject has no timed, non-suspicious result (relax only).

### Measured (dev DB = production copy, 2026-10-01, London Postcard `262f0052-…`, warm cache)

| Statement | Rows behind it | Execution |
|---|---|---|
| Standing, solo | 5,044 solo times → 1,718 players | ~9 ms (25 ms cold) |
| Standing, pairs | 360 pairs | ~6 ms |
| Attempts (puzzle + subject + attempts) | solo / pair subject | 0.3–1.2 ms |

The solo standing is a bitmap heap scan of the puzzle's times - fine at this size, no index added. The attempts
statement picks the subject's times through a `UNION` per kind (`custom_pst_player_puzzle_type` for solo,
`puzzling_team_id` for pairs/teams): the first version's `OR` join walked every time of the puzzle (5 ms).

Query budget of the modal for a guest: 2 statements (`testModalCostsTwoQueries`).

## Members

The progress chart (`Chart:PlayerPuzzleTimesChart`, ≥ 2 timed attempts) is members-only. Non-members get the locked
placeholder as a link to the membership page (`data-turbo-frame="_top"`) - not the `#membersExclusiveModal` button,
which would stack a second Bootstrap modal on top of this one.

## Layout (2026-10-01, Jan's review)

- **Pinned header** (`data-modal-scrollable`): the puzzle (`_puzzle.html.twig`: image, name = the modal title,
  brand · pieces, ✕) and under it whose results with "Rank N of M" (left) and the best time with its ①/↑ gap chips
  (right) (`_head.html.twig`) never scroll away - only the chart and the attempts scroll. So no extra close button at
  the bottom is needed. The full page shows the same blocks under its h1.
- **Phones: a sheet** (`data-modal-sheet`) - full width, near full height, a strip of the dimmed page above it keeps it
  reading as a layer over the page. Desktop keeps the centered dialog.
- **Back closes it** (`data-modal-history`) without reloading or losing the scroll position - see the hotwire guide
  "Layout and back button".
- Members' chart is 150 px (50 px lower than elsewhere) with flat, thinned-out dates and two dashed reference lines:
  **fastest on the puzzle** (black, `standing.leaderTime`) and the puzzle's **median** (grey, `standing.medianTime` -
  median of every subject's best time from the same aggregate query, equal to the leaderboard's median, guarded by the
  parity test). `PlayerPuzzleTimesChart` takes `height`, `medianTime`, `fastestTime`; each reference dataset carries a
  `referenceCaption` ("Median 00:53:28") that `time_chart_controller.js` writes onto the chart right at its line
  (above it, below when there is no room; no backing, the line's colour) - no legend under the chart; the tooltip ignores the reference lines.
  Non-members get a placeholder of the same height linking to the membership page.
- Attempts: no heading; each line = date (dark, monospace) + `#N` (order solved, #1 = first, small grey) …… time
  (dark, ★ for the best), then PPM …… ↩ change vs the previous attempt, ★ gap to the best. Compact spacing on phones.

## Why it replaced PR #92

PR #92 built a similar page keyed by player + puzzle + category. A pair/team is not identified by one player and a
category: since pairs & teams it is the `puzzling_team` (the exact set of people), so the detail is keyed by a time id
and derives its subject from that time. Ideas kept from #92: relax/suspicious badges, per-person PPM for groups, the
members-only chart.
