# A single person and relays in the results import (plan of record, 2026-11-17)

Follows README D10 ("A pair at a teams event") and "Not in scope". Requested by the organizer (2026-11-16): a single
person who competes at a team event, and relay events. Jan's decisions are listed below. Delivered as separate PRs, in
the order **claiming leftovers → single person → relays**, because the last two touch the same code.

Not built, by decision:
- **Weekly automatic sync.** The organizer imports herself on the Results import page, so it is dropped (Jan, 2026-11-17).
- **Double-sided puzzles.** A double-sided puzzle is one catalogue entry (one box, one piece count), so results under one
  side are correct and nothing changes (Jan, 2026-11-17).

## 1. A single person (Team Size 1) - shape A

**Built 2026-11-17** - the as-built summary is README "A single person (Team Size 1) and Solo events". What the build
decided beyond this plan:
- The unclaimed single person on the puzzle's solo tab is shown like an imported team nobody claimed (name + "This
  was me", ranked) - one anonymous spot `s:<time id>` (`UnclaimedSolo`) in every read model, also the claim log's key.
  The result detail's standing counts them on the solo board like the tab; a player's rank on the puzzle
  (`GetRanking`, profiles) does not (D4) - the two can differ by the unclaimed single people faster than the player.
- A Solo event takes Team Size 1 only (2+ = `team_size_does_not_fit_category`); `Individual` / `Single` read as Solo.
- The batch readers of every solo time (baselines, skill, improvement ratios, derived metrics, prediction
  reconstruction, pending predictions, insights profiles, snapshots, close calls) gained `player_id IS NOT NULL`.

The spike (2026-11-17) compared the two possible shapes:

- **Shape A (chosen): an ordinary solo row without its player.**
  - The row: `puzzling_type = solo`, `player_id` NULL, `team` NULL, `puzzling_team_id` NULL, `created_via = event_import`.
  - It keeps "solo = no team" true for the ~29 readers that rely on it.
  - Once claimed, it is an ordinary solo result of the claimer (imported, so `keepsItsRound()`).
- **Shape B (rejected): a one-member anonymous `puzzling_team`.**
  - It breaks about 15 readers after a claim (`team IS NULL` = solo).
  - It loses the sheet's name when one player claims two solo results: the composition keys are equal, so the teams
    merge in `replaceMemberOfTime()`.

### The person's name

- The sheet's name is stored on `event_imported_result.name` (display name, ≤ 50, nullable for old rows).
- `puzzle_solving_time` stays untouched.
- It is shown only while the row has no player. After a claim the player is shown, as for any solo result.

### Import

- **Where Team Size 1 is accepted:**
  - At a Teams event and a Pairs event: as a solo result, linked to the tab's one round (D10 path, never re-derived).
  - At a Solo event (`Competition Type: Solo`): every row.
- `team_size_does_not_fit_category` stays for 0 and for > 2 at a Pairs event.
- **Size changes in the sheet:**
  - A row changing between 1 and N is the same result in place: solo ↔ group.
  - While nobody claimed it, the anonymous group is replaced or dropped.
  - A claimed result is never touched (as today).
- **Content hash:** a tab without size-1 rows hashes exactly as before (`EventResultsImportParityTest` unchanged).

### Claim and release

- **Claim:** `EventResultClaimability` offers "This was me" on an unclaimed solo row.
  - `ClaimEventResultSlotHandler` takes a solo branch: no slot conversion, only `slotClaimedBy()`.
  - The log slot key is `s:<timeId>`.
  - The same gates apply: one spot per round, rate limit, the "same result" swap, first try, blocks.
- **Release:** `EventResultSlotRelease` takes a solo branch, `slotReleasedBy()`: the row is nobody's again and loses its
  first try.
- **Deleting an account:** `DeletePlayerHandler::importedResultsOf()` also finds imported rows by `pst.player_id`, so a
  claimed solo is released, never kept with a deleted player.

### Readers known to need care (spike)

- **`GetRoundResults`:**
  - Its owner assertion fails on a row without a player.
  - `identification()` folds all unclaimed solos into one; key them by the time id instead.
- **`GetPuzzleSolvers`:**
  - The solo tab inner-joins the player while its count goes by type (N+1 mismatch). Decide: drop the row from the count,
    or show it like the team rows.
  - The unfinished rows at its `team IS NOT NULL OR tracker.id IS NOT NULL` condition need a fix too.
- **`GetPuzzleResultDetail`, `GetEventResultClaims`:** the name and the open-slot rule; `competitionsWithImportedSpot`
  also by `pst.player_id`.
- **Rows that drop cleanly, as wanted (D4: shown only once claimed):** solo leaderboards, rankings, MSP rating, Hub
  feed, profiles.
- **`TrackerlessTimeQueryCoverageTest`:**
  - The `SOLO` reason ("an imported result is always a pair's or a team's") is no longer true. Re-check its 32 files.
  - Files that `GROUP BY player_id` without a player filter would group NULLs together.
- **`TrackerlessTimeCanaryTest` + `TrackerlessTimeSeeding`:** add a Solo round, size 1, and an unclaimed solo row. Cover
  the solo tab, round page, statistics, solo boards, and the claim/release round trip.

## 2. Relays - two linked results (Jan, 2026-11-17)

**Built 2026-11-17** - the as-built summary is README "Relays". What the build decided beyond this plan, or where the
plan was not what the code needed:
- `relay_result` holds `id`, `seconds` and `created_at` only - the tab and the team key are on `event_imported_result`
  (a row per result, new `leg` 0 / 1, unique `tab_id, team_key, leg`), which the upsert already keys by.
- The foreign key `puzzle_solving_time.relay_result_id` has no `ON DELETE` action: SET NULL would turn a result into a
  relax solve on the puzzle page, CASCADE would bypass the graveyard. The import deletes the results first, then the
  relay. Partial index `custom_pst_fk_relay_result_id` instead of Doctrine's full one.
- The entity's invariant ("only an unfinished result has a time spent") gained the relay exception explicitly.
- One lock per relay team: every read model and every claim screen goes through the team's first result (the lowest
  id), so `ClaimEventResultSlot::lockKeyOf()` stays as it was.
- Never a first try, no "same result" swap; claimed, released and removed once in the claim log and the notifications.
- The puzzle's statistics (`puzzle_statistics`: "Completed N×" also on catalogue lists) leave relays out - they are the
  puzzle's counts; global and personal statistics count them.
- The result detail of a relay result is not found (it is the puzzle's page); profiles link the relay's round instead.
- `Template…` tabs: any tab whose trimmed title starts with "template" is never an event.
- A tab that turns into a relay or back after its import is `puzzle_changed_after_import`.
- The round page reads the relay teams in the same statement as the round's results, so no other round page runs a
  statement more (`OfficialRoundResultsPageTest`).
- Not done: a claimed relay result reads as a relax solve on the Hub feed, the team page, API v1 and the exports
  (docs/TODO.md).

A relay is a team event where one team solves **two puzzles at the same time**, and only the **total time** is known.

Jan's decision:
- **Not on the puzzle detail pages.** The total is not a real time of either puzzle.
- **On profiles** as two linked results.
- **Above all on the competition**, ranked by the total.

### Model

- A relay team = **one result per puzzle**, linked by a new `relay_result` row.
  - `relay_result` holds `id`, the total `seconds`, and the tab item / team key.
  - Each `puzzle_solving_time` row of the relay carries `relay_result_id` (nullable FK).
  - Every other row has NULL, and every existing query keeps its plan.
- **Each puzzle's row:** finished but without its own time.
  - `seconds_to_solve` NULL and `pieces_placed` NULL. This is a solve (`CountsAsSolve`), like a relax solve.
  - `seconds_spent` = its share of the total, so the team's time spent counts the total once (split between the
    puzzles; any rounding goes to the first puzzle).
  - The team is anonymous, as for any imported team. Both rows share one anonymous `puzzling_team` (the same people).
- **Statistics:**
  - Counted: puzzles completed and pieces puzzled for every member, as for any team result, and time spent through
    `seconds_spent`.
  - Never: leaderboards, ranks, medians, difficulty, rating or predictions, because `seconds_to_solve` is NULL.
- **Puzzle detail pages (Jan):** the puzzle page tabs, the result detail of the puzzle and the puzzle's counts leave
  out `relay_result_id IS NOT NULL` rows.
  - One rule class `IsRelayResult` (SQL fragment), with a coverage test like `TrackerlessTimeQueryCoverageTest`:
    every reader of the puzzle page family uses it, or is listed with a reason.
- **Profiles:** the two rows are shown as one linked entry: "Relay · <event> · 2 puzzles · total 1:23:45". The exact
  surface is decided in the PR.
- **Competition:**
  - The round is a teams round with **both puzzles** as round puzzles (toolkit `puzzle_format = total`, unchanged).
  - The round results page ranks the relay teams by `relay_result.seconds`, one row per team listing the two puzzles.
  - The one-round-per-category-per-puzzle invariant holds: each puzzle belongs only to that round.
- **Claiming:** claiming a relay team claims its slot on **both** rows at once, under the same lock and gates. Releasing
  releases both.
- **Unfinished relay teams** (time ran out): not in v1. Such a row is a `problem` (`relay_needs_time`).

### Sheet format (additive)

- **A relay tab:** an optional **`Link to MySpeedPuzzling 2`** column on row 2 next to `Link to MySpeedPuzzling`.
  - A tab with it filled is a relay of those two puzzles.
  - `Competition Type` stays `Teams` (or `Pairs`).
  - Everything else is unchanged: Name, Time (the total), Team Size.
- **Puzzle checks:** both puzzles must be on MySpeedPuzzling, otherwise `waiting_for_puzzle` names the missing one.
  The same puzzle twice is a `problem`.
- **Content hash:** the second link enters it only when present, so every existing tab hashes as before.
- **For the organizer** (mail text, made-up data): "Duplicate any tab, keep the columns, and on row 2 add the second
  puzzle's MySpeedPuzzling link in a new column `Link to MySpeedPuzzling 2`. Put each team's total time in Time."

## Coordination

- The competition toolkit session (2026-11-17) owns these areas; this work stays out of them:
  - the wizard;
  - the judge page, proposals and results protocol (`HasOfficialResult`, `RecordRoundResults*`);
  - standings;
  - the new commentator "studio" page.
- `TrackerlessTimeQueryCoverageTest` stays the single source for how trackerless rows are read. Tell that session when
  the solo shape lands.
- Generate migrations last, after a rebase on `origin/main`, against a scratch DB built from committed migrations.
