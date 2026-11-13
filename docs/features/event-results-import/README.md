# Event results import (an organizer's results spreadsheet)

**Status:** 2026-11-12 - trackerless times, the import and claiming built (PR #342, draft); unfinished results next. Progress in [implementation-plan.md](implementation-plan.md) "Status".

An organizer who runs many casual events keeps the results in a Google Sheet: one tab per event, one row per team -
the team's name, its time and how many people it had. Nobody's MySpeedPuzzling account is known. MySpeedPuzzling reads
the sheet on a schedule and turns every tab into a one-time event of the organizer's organization, with one round and
**one solving time per team**. The time belongs to nobody until the puzzlers claim their spots.

These are hobby events, not official results: the imported rows are ordinary solving times (`puzzle_solving_time`),
never `CompetitionTeam` official results, and nothing calls them "official".

## Decisions (Jan, 2026-10-30 - 2026-11-12)

| # | Decision |
|---|---|
| D1 | A result is a real `PuzzleSolvingTime` with **no tracker** (`player_id` NULL) and anonymous slots - not official results shown beside times (they would miss the global statistics), not the organizer as tracker (it would count on her profile), not a hidden account (it would leak as a person everywhere) |
| D2 | A tab whose puzzle is not on MySpeedPuzzling is **skipped with a warning** - the organizer adds the link, the next run imports it. Every run is an **upsert**: nothing twice, changes applied |
| D3 | The sheet's format may be adjusted to our needs: a tab that does not follow it is skipped with a warning |
| D4 | Global "fastest pairs/teams" boards and the Hub's recent activity show an imported result **only once somebody claimed it** |
| D5 | Puzzle page wording: "Puzzle Pals · <event> · 4 puzzlers" |
| D6 | Claims: no approvals, the gates below (one team per round, cap = team size, co-claimers and the organizer's team can remove, two removals = no more claiming, rate limit, audit log) |
| D7 | First try is always false on import; the claim modal has a toggle to mark it |
| D8 | Every tab = a one-time event under the organizer's organization (series per venue maybe later - a small change in the import) |
| D9 | Unfinished results (time ran out) count their **pieces placed and time spent** in global and personal statistics, but are never a completed puzzle, never ranked by time, never in medians - see "Unfinished results" |

## The sheet

### What we read

The sheet must be shared "Anyone with the link: Viewer". Two unauthenticated requests per run, no Google credentials:

1. `GET https://docs.google.com/spreadsheets/d/<id>/htmlview` - the tab list, each tab's **gid** and title (the gid is
   Google's id of the tab: it never changes when the tab is renamed or moved, so it is the tab's identity).
2. `GET https://docs.google.com/spreadsheets/d/<id>/export?format=csv&gid=<gid>` per tab - the values as the sheet
   shows them (`1:10:13`, `10/5/26`).

The `htmlview` page is not a documented API. If Google changes it, the fallback is the Sheets API v4 with a plain API key
(`spreadsheets.get` with `fields=sheets.properties` gives the same gid + title list) - one secret, no OAuth.
The xlsx export is not used: it has no gids.

### Format (one tab)

Header row, matched case-insensitively and trimmed (`Team Size` = `team Size`). Row 2 carries the event's fields and
its own team; every further row is one team.

| Column | Row | Required | Meaning |
|---|---|---|---|
| Name | every | yes | the team's name |
| Time | every | one of Time / Pieces placed | `h:mm:ss` or `m:ss` |
| Pieces placed | every | | **new** - the team did not finish: pieces placed when the time ran out (Time empty) |
| Team Size | every | yes | people in this team (1-10) - a 3-person team at a 4-person event is normal |
| Contest Name | 2 | yes | the event's name |
| Location | 2 | yes | venue |
| City and State | 2 | yes | `Saint Paul, MN` - the country is the organization's |
| Date | 2 | yes | `M/D/YY`, `M/D/YYYY` (the sheet is US) or `YYYY-MM-DD` |
| Start time | 2 | no | `7:00 PM` / `19:00`, local time of the organization's country. Without it the round starts at noon of the day (`competition_round.start_time_known = false`) and pages show the day without a time |
| Time limit | 2 | no | minutes; the round's limit and the time spent of an unfinished team. Without it the round has no limit (`minutes_limit` NULL, shown nowhere) |
| Competition Type | 2 | yes | `Pairs` / `Teams` (later `Solo`) - the round's category |
| Link to MySpeedPuzzling | 2 | yes | `https://myspeedpuzzling.com/<locale>/puzzle/<uuid>` |
| Brand, Puzzle Title, Puzzle Link- manufacturer | 2 | no | informational; the link decides the puzzle |

**What the organizer has to add** to today's sheet: the MySpeedPuzzling link on every tab. `Start time` and `Time limit`
are optional (an unreadable value is still a `problem` - a typo must not drop it quietly). Everything else already
matches. The importer is Sarah Does Puzzles' own (`SarahDoesPuzzlesTabParser`, command
`myspeedpuzzling:sync-sarah-does-puzzles-results`); the data model underneath is not tied to her.

Tolerated without a warning: row order (the ranking is computed from the times), trailing spaces, empty rows.

### Tab outcomes

| Status | When | Warning |
|---|---|---|
| `imported` | every check passed, the event and its results match the tab | - |
| `waiting_for_puzzle` | no link, or the link's puzzle does not exist (deleted puzzles follow `puzzle_redirect`) | yes |
| `problem` | a missing header or required event field, an unreadable time/date, a pairs row with size ≠ 2, a team name twice in the tab, a row with neither Time nor Pieces placed - each listed | yes |
| `detached` | the event made from this tab was deleted on MySpeedPuzzling - it is never re-created | yes |

A tab with a `problem` is not imported at all, so a half-fixed tab never shows half its results. The exceptions: a
team name twice and a row with neither value skip **those rows only** (listed as problems, rest imported).

**A warning is logged when a tab's status or its list of problems changes** - not on every run - so Sentry
gets one issue per thing to fix. The console command prints every tab's status on every run.

## Data model

### New tables

- `event_results_sheet` - a spreadsheet to read: `organization_id` (the events land there; its creator is the events'
  `addedByPlayer`), `spreadsheet_id`, `added_at`, `last_fetched_at`, `last_fetch_error`. Managed through the internal
  API. The spreadsheet ids live in the database only, never in the repo.
- `event_results_sheet_tab` - one row per gid: `title`, `status`, `problems` (jsonb), `content_hash` (of the parsed tab -
  unchanged = nothing to do), `competition_id` (`SET NULL`; null after an import = `detached`).
- `event_imported_result` - the upsert key of a row: `tab_id`, `team_key` (the team name folded like `SearchText::fold()`),
  `solving_time_id` (unique, `CASCADE`). A renamed team = a removed row + a new row.

### `puzzle_solving_time`

- `player_id` becomes **nullable**: a time nobody has claimed yet. Every reader handles it (audit 2026-10-30: 94 files
  never see such a row, 52 count it correctly, ~30 places need a change - [implementation-plan.md](implementation-plan.md) phase 2) and
  `TrackerlessTimeQueryCoverageTest` keeps it that way.
- `source` gains `event_import` (`SolvingTimeSource`): the result belongs to the event, not to its claimers.
- `seconds_spent` (nullable int, new): an unfinished result's time spent - see "Unfinished results".
- `tracked_at` and `finished_at` = the round's start (the event day, not the import day: monthly statistics read
  `tracked_at`).

### Anonymous slots

An imported team of N gets a `puzzling_team` of N members with `member_key = "a:" + <slot uuid>`, no player, no guest
name, and the `team` snapshot holds N puzzlers `{player_id: null, player_name: null}`. The keys are unique, so two
anonymous teams never share a composition key (nameless guests would: `TeamComposition` keys them `g:`, `g:#2`, ... -
every anonymous team of four would become one team). The team's name is the sheet's name (`puzzling_team.name`,
≤ 50 characters - a longer name is a `problem` row, the organizer shortens it).

## Claiming a spot

"This was my team" (`event_result_claim/_claim_button.html.twig`) sits on a result that still has an anonymous slot -
puzzle page Pairs/Teams tab, round results page, result detail - for a signed-in viewer who is not in it and holds no
spot of an imported result of the same event (`EventResultClaimability`: one statement per request, only once such a
row is rendered for a signed-in viewer; guests and every other page view pay nothing). It opens a confirmation in the
modal (`ClaimEventResultSlotController`, `/{_locale}/event-result/{timeId}/claim`, a page of its own without the
modal): the event, the team, the time, who already claimed it, **"This was my first try at this puzzle"** (off) and -
when the viewer has their own result of the puzzle from the day before to the day after the event - **"Is this the same
result?"** with those results to pick from. The POST (stateless CSRF `event_result_claim`) dispatches
`ClaimEventResultSlot` (`SerializedByLock` on the result - claims, releases and removals of one result take turns);
a refusal is answered 422 in the modal with the reason, a success closes the modal and refreshes the page.

`ClaimEventResultSlotHandler` checks everything before anything changes, then:

1. refuses (`EventResultClaimRefused`, reason `EventResultClaimRefusal`) an ordinary result, a player blocked from
   claiming, a "same result" that is not the player's own result of the puzzle, a player already in any result of the
   round (one spot per round - the "same result" about to go does not count), a full team, a first try somebody of the
   result already holds elsewhere; over the rate limit `event_result_claim` (10 a day per player)
   `EventResultClaimLimitReached` (429);
2. deletes the "same result" (`PuzzleSolvingTimeRepository::delete()`, `SolvingTimeDeletedVia::EventResultClaim`) -
   the imported result stays;
3. turns the first anonymous slot (in the order of the result's people) into the player -
   `PuzzlingTeamMemberConversion::anonymousSlotToPlayer()`: member row, composition key and the result's group snapshot
   (`{player_id, player_name: null}`, byte for byte what `PuzzlersGroupDoctrineType` writes). Once every slot is claimed
   the people may be a pair/team that already exists - the result then joins it (the anonymous team is merged into it,
   the name goes over when the existing one has none);
4. the first claimer becomes the tracker (`PuzzleSolvingTime::slotClaimedBy()`);
5. first try: a pair's/team's result is a first try only if it is everybody's - the first claimer's answer sets it
   (checked by `FirstTryAssessor` like a save), a later claimer keeps the tag or takes it off and is never asked while
   the result has none;
6. logs `claimed` in `result_claim_log`; `EventResultSlotClaimed` (async) tells every other claimer
   ("Ann joined Puzzle Pals at <event>", `NotificationType::EventResultClaimed`, nothing for a claimer who blocks the
   new one); achievements and the XP chain of the result are recalculated like after a group edit.

### Gates (D6)

- **The result is the event's.** `PuzzleSolvingTime::canBeModifiedBy()` / `canBeDeletedBy()` refuse it, and so do the
  read models' `isEditableBy()` (`PuzzleSolversGroup`, `PuzzleResultAttempt`, `SolvedPuzzle`, `SolvedPuzzleDetail` -
  they carry `importedFromEvent`): no edit button anywhere. Every edit or delete link of an imported result leads to
  **"Remove from my profile"** (`ReleaseEventResultSlotController`, `/{_locale}/event-result/{timeId}/remove-from-profile`;
  the profile's ⋯ menu shows it instead of Edit/Delete, the result detail next to the people) =
  `ReleaseEventResultSlot`. Only the sheet changes the result; claim-then-delete sabotage is impossible.
- **Releasing** (`EventResultSlotRelease`, the one way for a release, a removal and a deleted account):
  `PuzzlingTeamMemberConversion::playerToAnonymousSlot()` puts a new `a:` slot in the player's place - in place when the
  pair/team has no other result, otherwise (a fully claimed result that joined the people's existing pair/team) the
  result gets a pair/team of its own under the same name and the people's other results keep theirs. The tracker moves
  to the next claimer in the order of the people, or to nobody - then the result is nobody's again and loses a first
  try (D7).
- **Account deletion** (`DeletePlayerHandler`) releases every spot of the player first (`account_deleted`), so the
  rest of the deletion never sees the player in an imported result: it is never deleted, never handed to another
  member, never gets a named guest.
- **Wrong claims:** one spot per person per round; claims ≤ team size; claimers shown publicly; **"Not on our team"**
  (`RemoveEventResultClaimController`, `/{_locale}/event-result/{timeId}/not-on-our-team/{playerId}`, links next to
  every other claimer on the result detail) = `RemoveEventResultClaim`, allowed for another claimer of the result, the
  organizer's team of its event (`GetCompetitionPermissions::canEditCompetition()` - the creator, maintainers, the
  organization's team) and admins; never for the player themselves (that is the release). The removed player is told
  without who did it ("Your spot in Puzzle Pals at <event> was removed", `NotificationType::EventResultClaimRemoved`).
  A player removed twice (`RemoveEventResultClaimHandler::REMOVALS_UNTIL_BLOCKED`) can no longer claim
  (`player.event_claims_blocked_at`, an admin clears it with `Player::unblockEventClaims()` - no UI yet).
- **Audit:** `result_claim_log`, append-only, no foreign keys (copied ids and names, like
  `puzzle_moderation_decision`): `solving_time_id`, `slot_key`, `player_id`, `player_name`, `action` (claimed /
  released / removed / account_deleted), `by_player_id` (null = the system), `reason` (the remover's optional note,
  ≤ 500), `at`.
- Later if needed: claim codes the organizer hands out per team.

### Display

| Surface | Unclaimed | Partly claimed |
|---|---|---|
| Puzzle page Teams/Pairs tab | "Puzzle Pals · <event> · 4 puzzlers" + "This was my team" | the claimers + "+2" |
| Round results page | ranked like any time, the same line | same |
| Result detail | the team's results, no person | same |
| Global fastest pairs/teams, Hub recent activity | **not shown** (D4) | shown |
| Profiles, Players page, ratings, insights, XP | nobody's | the claimer's, like any team time |

## Unfinished results (D9)

Builds on [unfinished-results-plan.md](../competitions-management/unfinished-results-plan.md) (2026-09-18), which stays
the plan of record for the form, validation, the puzzle page's bottom rows and the round page - with **one change**:
its tier 2 said an unfinished result counts nowhere. Jan's decision splits the counts:

| Number | Finished | Unfinished |
|---|---|---|
| Puzzles completed, "Completed N×", most solved, solved flags, wishlist removal, unsolved lists | counts | does not count |
| **Pieces puzzled** (global, monthly, personal) | `puzzle.pieces_count` | `pieces_placed` |
| **Time spent puzzling** (global, monthly, personal) | `seconds_to_solve` | `seconds_spent` |
| Leaderboards, ranks, medians, difficulty, skill, MSP rating, predictions | yes | never (`seconds_to_solve` is NULL) |
| Puzzle page | ranked rows | after every ranked row, by pieces placed (most first), no rank |

`seconds_spent` is stored on the row so the statistics stay one scan without a join: the round's limit for an import;
in the form the round's limit when the round is known, otherwise the player's answer to "How long did you puzzle?";
the later finish time (`finished_later_seconds`) when they finished afterwards.

## Not in scope

- Individual (solo) events - the same slots with N = 1, once an organizer has them.
- Series per venue (D8).
- Writing back to the sheet; the organizer editing results on MySpeedPuzzling (the sheet is the source).
