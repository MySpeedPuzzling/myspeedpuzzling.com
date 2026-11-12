# Event results import - implementation plan

Design of record: [README.md](README.md). Five phases, each deployable on its own. Phases 1 and 2 change nothing
visible: no unfinished and no trackerless row exists before phase 3.

## Status (2026-11-12, PR #342, draft)

- **Order changed:** trackerless times (phase 2) and the import (phase 3) first; unfinished results (phases 1 + 5) become
  one stream after claiming - the organizer's sheets have no unfinished teams today, and the counting split only makes
  sense together with the rows' display. Until then the import keeps a row with "Pieces placed" out
  (`unfinished_not_supported_yet`).
- **Done:** phase 2 (domain, migration, every reader, canary + coverage test, audit in
  [trackerless-audit.md](trackerless-audit.md)); phase 3 (parser, sheet reader, per-tab upsert, sync, console command,
  internal API). Checked against the organizer's real sheet locally: as it is today all four tabs are a `problem`
  (no `Start time` / `Time limit` columns); with them all 50 teams parse, one tab is importable, three wait for their
  puzzle link.
- **Phase 4 (claiming) done** - as built in [README.md](README.md) "Claiming a spot" / "Gates": `ClaimEventResultSlot` /
  `ReleaseEventResultSlot` / `RemoveEventResultClaim`, slot-aware `PuzzlingTeamMemberConversion` (by result, splits a
  result off a shared pair/team), `EventResultSlotRelease` (release, removal, account deletion), rate limiter
  `event_result_claim`, notifications `EventResultClaimed` / `EventResultClaimRemoved`, the claim / release / removal
  modals, buttons on the puzzle page, round results and result detail, "Remove from my profile" for every edit and delete
  link of an imported result. The follow-ups found in phases 2-3 are done: `isEditableBy()` of the read models is false
  for an imported result, the puzzle page shows an unclaimed team's name once (no team badge next to it), the first-try
  toggle lives in the claim modal. Tests: `EventResultClaimTest`, `EventResultClaimControllersTest`, a case in
  `DeletePlayerHandlerTest` and `ApplyEventResultsTabHandlerTest`, the canary with a partly claimed result.
- **Left open after phase 4:**
  - the organizer's ⋯ menu on the event page has no "Not on our team" yet - the organizer's team uses the result
    detail's links (shown to them like to a claimer); admins likewise (no internal API endpoint yet);
  - no admin UI to clear `player.event_claims_blocked_at` (`Player::unblockEventClaims()` exists);
  - `result_claim_log.player_name` keeps the name of a deleted account (the log outlives the player by design) - decide
    whether account deletion should blank it;
  - `CommunityStatsCalculator::resultsSql()` drops members when the tracker is NULL - harmless, as an imported result
    with a member always has a tracker (the first claimer, then the next one);
  - claimed results show on profiles like any team time, but without "+N" for the anonymous rest (`GetTeamPlayers`
    leaves people without a name and id out) - fine for now;
  - `DismissFirstTryReview` / `UnmarkFirstAttempt` still refuse imported results: a claimer fixes a wrong first try by
    releasing and claiming again.

Public repo: made-up event, team and puzzle names only in code, tests, docs, commits and PR text. The organizer's
spreadsheet id goes into the database through the internal API, never into the repo.

## Phase 1 - unfinished results: the counting split

The foundation of [unfinished-results-plan.md](../competitions-management/unfinished-results-plan.md) step 1, with D9.

- `CountsAsSolve::sql()` (`pieces_placed IS NULL`) on every "completed" count of that plan's tier 2 list (re-audit
  first - the list is from 2026-09-18).
- Pieces and time sums (`GetStatistics::globally()` / `globallyInMonth()`, `GetPlayerStatistics` x3,
  `ComputeStatistics` / `PerCategoryStatistics`): `CASE WHEN pieces_placed IS NULL THEN puzzle.pieces_count ELSE
  pieces_placed END` and `COALESCE(seconds_to_solve, seconds_spent)`.
- Migration: `puzzle_solving_time.seconds_spent` (generated), CHECK constraints of the old plan + `seconds_spent IS
  NULL OR pieces_placed IS NOT NULL` (hand-written, `NOT VALID` + `VALIDATE`, mirrored in `tests/bootstrap.php`) -
  **needs Jan's OK** for the hand-written part.
- Tests: the old plan's differential `UnfinishedResultsAreInvisibleTest`, rewritten for D9 (completed counts and
  every performance number unchanged; pieces and time grow by exactly `pieces_placed` / `seconds_spent`), and the
  `SolvingTimeReadersAreClassifiedTest` ratchet.

## Phase 2 - trackerless times

From the audit of 2026-10-30 (174 readers of `puzzle_solving_time`). No row exists yet; this makes one safe.

1. `PuzzleSolvingTime::$player` → `null|Player` (generated migration: `player_id` nullable). `canBeModifiedBy()`,
   `memberPlayerIds()`, `moveToPuzzle()`, `PuzzleSolvingTimeDeleted` / `PuzzleSolvingTimeMovedToOtherPuzzle`
   (`null|UuidInterface $playerId`), `SolvingTimeGrave::$playerId` nullable, `RemovedResultSnapshot`.
2. Handlers of `PuzzleSolved` (routed `sync` - the import's flush would throw): `RemoveFromWishListWhenPuzzleSolved`,
   `NotifyWhenPuzzleSolved`, `DetectDuplicateResultsOnSave` skip a time without tracker.
3. Background jobs exclude the rows at the source: `GetSuspiciousTimeCandidates` (`player_id IS NOT NULL`),
   `PuzzleIntelligenceRecalculator::getPlayersWithSolves()`, `XpChainRecomputer::participantsOf()` (no participants
   = nothing to do), `RecalculateIncrementalPuzzleIntelligenceOnSolvingTimeChange`, `GetSolvingTimePrediction`.
4. Read side: `GetPuzzleSolvers` + `PuzzleSolversGroup::$addedByPlayerId` nullable; `PuzzlesSorter::calculatePlayersIdentification()`
   and `GetRoundResults::identification()` keyed by `puzzling_team_id` (anonymous members would all read `"..."`);
   `GetRoundResults` + `GetPuzzleResultDetail` LEFT JOIN the tracker (+ `PuzzleResultAttempt::$trackedByPlayerId`
   nullable); `GetParticipantsSheetState`; `PuzzleStatisticsCalculator` groups by `COALESCE(puzzling_team_id,
   player_id, id)`; `GetSolveTimeDistribution`; `SiteSnapshotReader`.
5. Kept out on purpose (D4, already true through their inner joins - pinned by tests, not by luck): `GetFastestGroups`,
   `GetFastestPairs`, `GetRecentActivity::latest()`.
6. `TrackerlessTimeQueryCoverageTest` - a copy of `SuspiciousTimeQueryCoverageTest`: every `src/` file reading
   `puzzle_solving_time` mentions `player_id IS NOT NULL`, a solo-only condition, a LEFT JOIN on the tracker, or is
   listed with a reason (seeded from the audit's buckets).
7. A fixture: one anonymous team time (`.claude/fixtures.md`), and a canary test opening every page that lists times
   for a puzzle, a round and a team with it present.

## Phase 3 - the import

1. Entities + generated migration: `EventResultsSheet`, `EventResultsSheetTab`, `ImportedResult`;
   `SolvingTimeSource::EventImport`.
2. `GoogleSheetReader` (`src/Services/EventResultsImport/`): `htmlview` → gids + titles, CSV per gid; bounded
   timeouts, a failure = `last_fetch_error` + one warning per change. Pure parser `EventResultsTabParser` →
   `ParsedEventTab` (event fields, rows, problems) - unit-tested on made-up CSVs, including the shapes seen in the
   first organizer's sheet (`1:10:13` and `24:48`, `team Size`, trailing spaces, rows not in time order).
3. Messages: `SyncEventResultsSheet` (`HandledOutsideTransaction`: fetch + parse only) → `ApplyEventResultsTab` per
   changed tab (own transaction, `SerializedByLock` on the tab):
   - status + problems + hash stored first; warning when they changed;
   - competition: create (one-time event under the organization, `addedByPlayer` = its creator,
     `OrganizationApprovalPolicy`, published) or update (rename keeps the slug); one round (category from Competition
     Type, `team_size` = the most common Team Size, `minutesLimit`, `startsAt`) + `SetCompetitionRoundPuzzles`;
   - rows upserted by `team_key`: create (anonymous slots, `source = event_import`, `firstAttempt = false`,
     `tracked_at` = `finished_at` = round start), update the result / size (fewer slots than claimers = `problem`
     row, untouched), delete a removed unclaimed row through `PuzzleSolvingTimeRepository::delete()`, keep a removed
     claimed row (warning);
   - a changed puzzle link moves the tab's times (`moveToPuzzle()`).
4. Console command `myspeedpuzzling:sync-event-results-sheets [--sheet=] [--dry-run]` dispatches only; cron hourly on
   lily.srv (add the row myself).
5. Internal API: `GET|POST /internal-api/event-results-sheets`, `DELETE …/{id}`, `GET …/{id}/tabs` (statuses and
   problems), `POST …/{id}/sync`. OpenAPI + `internal-api.md`.
6. Backfill: register the organizer's sheet, `--dry-run`, read the statuses, sync.

## Phase 4 - claiming

1. `ClaimEventResultSlot` / `ReleaseEventResultSlot` / `RemoveEventResultClaim` (+ handlers, `SerializedByLock` on
   the time), `PuzzlingTeamMemberConversion::anonymousSlotToPlayer()` + `slotToAnonymous()`, `result_claim_log`,
   `player.event_claims_blocked_at`, rate limiter `event_result_claim` (10/day).
2. `canBeModifiedBy()` refusal for `event_import`; profile "Remove from my profile"; `DeletePlayerHandler` releases slots.
3. Claim modal (Turbo Frame `modal-frame`, explicit form action): event, team, time, claimers, first-try toggle
   (`FirstTryAssessor`), duplicate question; 422 on refusals.
4. Buttons on the puzzle page Teams/Pairs tab, round results, result detail; "Not on our team" for claimers; organizer
   ⋯ menu removal; notifications `EventResultClaimed` (other claimers) and `EventResultClaimRemoved` (the removed one).
5. Display: team name + event + "N puzzlers" / claimers + "+N" (one partial), "This was my team" only for signed-in
   viewers without a spot in the round.
6. Translations in all 6 locales; canaries (`BlocklistCanaryTest`, `PrivateProfileCanaryTest`) cover the new rows.

## Phase 5 - unfinished results in the add form

The old plan's steps 2-4 (display, write path, form - competitions only), with `seconds_spent` set by the form, plus
"Pieces placed" on imported rows rendered by the same partial.

## Open

- Venue series (D8) - ask the organizer.
- Solo events with anonymous single slots - when an organizer has them.
- The Sheets API key fallback - only if `htmlview` breaks.
