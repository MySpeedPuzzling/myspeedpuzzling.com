# Official round results

The organiser's own record of a round: who finished in what time, who placed how many pieces, who did not start, who
qualified, and at which table everybody sat. Decided 2026-10-07 ("best of both worlds"), replacing PR #136's
`official_round_result` table and its claiming of results onto players' profiles. Scenarios it serves:

1. mass participant management before the event (the participants spreadsheet, a later PR, reuses this write path),
2. **fast result entry live during the event** - a referee's phone: find by table / name / name-tag QR, enter, confirm,
3. **who qualified between rounds** - a results desk: rank, mark qualified, advance them into the next round(s), seat them.

This document is the design of record **as built for the core** (data model, write path, guards, ranking, read
models, JSON API). The organiser pages (live entry, results desk, seating, name tags) and the public round page build
on it - see their own sections once they ship.

- Live result entry on a phone + name tags with QR: [live-results.md](live-results.md)
- Results desk, results overview, qualification helpers, advancing and "Seat them now": [results-desk.md](results-desk.md)

Vocabulary: a **round entry** is `CompetitionParticipantRound` (a person in a solo round) or `CompetitionTeam` (a
pair/team of a pair/team round). An **official result** is what the organiser recorded for a round entry. A **time** is
a player's own `PuzzleSolvingTime` - official results never create, change or delete times.

## Data model

Columns of `HasOfficialResult` (a trait on both round entry entities - an embeddable cannot hold the `entered by`
relation):

| Column | Meaning |
|---|---|
| `result_seconds` int null | finished, total seconds (1..86399) |
| `result_pieces_placed` int null | did not finish: pieces placed when the time ran out (main's vocabulary, never "missing") |
| `result_did_not_start` bool | did not start |
| `result_entered_at`, `result_entered_by_id` (FK player, `SET NULL`) | last change of the result - also a clearing |
| `qualified_at` timestamp null | the organiser's qualified mark - always an explicit decision, never derived |
| `table_number` smallint null | the entry's table in this round (1..9999), unique within the round |

At most one of seconds / pieces placed / did not start; none = no result yet. `RoundEntryResult` is the one value
object for it (wire format below). In a pair/team round the team carries the result; the members' round entries keep
these columns empty.

- `competition_participant_round` has a unique `(participant_id, round_id)` (production 2026-10-07: 0 duplicates).
  Every writer (import applier, Live participant editor, join, quick add, `AdvanceQualified`) creates a row only for
  a person not in the round yet.
- `competition_round.results_published_at` (public while set), `results_first_published_at` (the first publish - the
  players are told once), `table_numbers_off` (the organiser said the round does not use table numbers).
- `notification.target_competition_round_id` (`CASCADE`) for `NotificationType::OfficialResultPublished`.
- Table numbers are unique per round **in the write path, under the participants lock** - not by an index, so a swap
  or a renumbering in one change set works.

## Ranking (computed, never stored)

`OfficialResultsRanking` is the one implementation (organiser tools and the public page): finished by seconds, then
unfinished by pieces placed (most first); equal results share a rank and the next rank skips ("1, 2, 2, 4"); did not
start and no result yet are unranked (`null`). Rows hidden from a viewer are dropped **after** ranking, never renumbered.

**Advancement seed** (`AdvancementSeeding`, reused by seating): rank within the own round first (every winner before
every second); the same rank across rounds (or a tie) ordered finished before unfinished, then by the result relative
to that round's winner (time ÷ winner's time; unfinished: pieces placed ÷ piece count); then the organiser's order of
the rounds, name, id. Entries without a ranked result last.

## One write path

All writes take `CompetitionParticipantsLock::key($competitionId)` (`SerializedByLock`; the same key as the participant
import and registrations), validate everything before changing anything, and check that every round/entry belongs to
the competition the caller was authorised on (`CompetitionEditVoter`).

- **`RecordRoundResults`** (`competitionId`, `roundId`, `actingPlayerId`, list of `RoundResultChange`, `dryRun`) -
  results, table numbers, qualified marks and entrants typed in at the venue. Each change: `clientChangeId` (UUID, for
  idempotent replays), an existing entry (`participant_round:<id>` / `team:<id>`) **or** a new one (`clientEntryId` +
  kind + name + members; created with that id, never matched by name), `field` (`result` | `table_number` |
  `qualified`), `from` (what the device last saw), `to`. Three-way per change against the working state of the round:
  current = `to` → `unchanged` (a replay); current = `from` → `applied`; otherwise `conflict` (current value + who/when
  returned). Invalid → `rejected` with a reason key (`official_results.reason.*`). Table numbers are checked after the
  whole set; a change making a number shared is refused and the set planned again. A new entry is created only when
  one of its changes goes through. Returns `RecordedRoundResults` (HandledStamp).
- **`AssignTableNumbers`** (`competitionId`, `roundId`, `[{entry, number|null}]`) - seating in one write, validated as
  a whole (entries of the round, listed once, 1..9999, no shared number afterwards) or refused entirely
  (`InvalidTableNumbers` with problems). Returns the refs whose number changed.
- **`ChangeRoundTableNumbersUsage`** (`off`) - "this round doesn't use table numbers" and back.
- **`PublishRoundResults` / `UnpublishRoundResults`** - `published_at`; the first publish ever also sets
  `first_published_at` and records `OfficialRoundResultsPublished` (async) → `NotifyWhenOfficialRoundResultsPublished`:
  an in-app notification to every player linked to an entry with a **finished** result (solo: the connected
  participant; pair/team: connected members), skipping who has it already; results unpublished before the handler
  runs tell nobody. No e-mail.
- **`AdvanceQualified`** (`sourceRoundIds[]`, `targetRoundIds[]`, `distribution` `single` | `balanced` | `by_source`,
  `targetBySource`, `dryRun`, `planHash`) - qualified entries of the sources into the targets, same category only.
  Solo: the person joins the target round; pair/team: a new team with the same people and name. Skipped:
  `already_in_target` (the person / that exact set of people is there), `member_already_in_target`, `qualified_twice`.
  `balanced` = serpentine by seed (1→T1, 2→T2, 3→T2, 4→T1, …). The dry run returns the exact plan + `planHash`
  (assignments, skips, target entries, distribution); applying requires the hash and re-plans under the lock - any
  difference → `AdvancementPlanChanged` (409), nothing written. Advancing twice adds nobody; unmarking never removes.

Qualified marks are written only through `RecordRoundResults` changes, so helper pre-selections (Top N, best of each
country) made in a page surface conflicts like any other change.

## Guards - official data never disappears as a side effect

`OfficialResultsGuard` answers who holds official data (a result or a qualified mark - own, or the pair's/team's).
Refusals throw `OfficialResultsProtected` (409, reason `official_results.guard.*`):

| Change | Rule |
|---|---|
| Delete a pair/team (`DeleteCompetitionTeamHandler`, teams page) | refused while it has a result or qualified mark |
| Take a person out of a round (Live participant editor, `EditCompetitionParticipantHandler`) | refused when they hold data in that round; nothing of the save is written |
| Remove a person from the event (`SoftDeleteCompetitionParticipantHandler`) | refused when they hold data anywhere in the event |
| Import (planner `SiteSnapshot::hasAnyResult()` / `teamHasOfficialResult()`) | official results count like players' times (D11): people kept, entries kept, emptied pairs/teams with data kept (warning) |
| `LeaveCompetition`, switching identity in `JoinCompetition` | a self-joined row holding data is disconnected, not deleted |
| Change a round's category (`EditCompetitionRoundHandler`, also internal API PATCH) | refused while any entry has a result |
| Delete a round | internal API: 409 when it has player times **or official results**; web: a confirmation listing the official results, bound to the list (`confirmedOfficialResultsHash`, re-checked under the lock) |
| Move a person between pairs/teams (`AssignParticipantToTeamController`) | allowed; a warning that the result now belongs to the new line-up |

## Read models

- `GetRoundResultEntries::forRound()` / `byRefs()` - every entry of a round (organiser tooling: participant names as
  recorded, no blocklist), ranked and ordered (rank, did not start, no result; ties by table number, name), with
  members, countries, linked player, table, result, qualified, entered by/at. `RoundResultEntry::jsonSerialize()` is the
  JSON every endpoint and Mercure update uses.
- `GetRoundResultsOverview::forCompetition()` / `forRound()` - every round with stopwatch, publication, piece count of
  a single-puzzle round, `tableNumbersOff` and the counts (entries, with table number, with result, qualified) - the
  seating readiness line "Tables: 180 / 200 assigned". One statement.
- `GetOfficialResultRecipients` - the notification fan-out.

## JSON API (organiser pages)

Routes under `/{_locale}/official-results/`, JSON in and out, `Cache-Control: private, no-store`, never a redirect
to the login page (401 `sign_in_required`), 403 `forbidden` without `COMPETITION_EDIT`, writes need
`Content-Type: application/json` (415) and the stateless CSRF token `csrf_token('official_results')` in the
`X-CSRF-Token` header (403 `invalid_csrf_token`) - `OfficialResultsApi`.

| Method + path | Message | Answer |
|---|---|---|
| `GET rounds/{roundId}` | - | `serverNow`, `topic`, `competition`, `round`, `rounds`, `entries` |
| `POST rounds/{roundId}/changes` | `RecordRoundResults` | `dryRun`, `outcomes`, `entries` (400 unreadable set) |
| `POST rounds/{roundId}/publish` / `unpublish` | `PublishRoundResults` / `UnpublishRoundResults` | `round` |
| `POST rounds/{roundId}/table-numbers` | `AssignTableNumbers` | `changed`, `entries` (422 `problems`) |
| `POST rounds/{roundId}/table-numbers-usage` | `ChangeRoundTableNumbersUsage` | `round` |
| `POST competitions/{competitionId}/advance` | `AdvanceQualified` | the plan (409 `plan_changed`, 422 `reason`) |

Live updates: after the commit the controller publishes a **private** Mercure update on `/round-results/{roundId}`
(`OfficialResultsLiveUpdates`; a Mercure failure is a logged warning, never a failed write): `official_results.entries`
(the changed entries + the round), `official_results.refresh` (more than 50 entries changed - fetch the state again),
`official_results.round` (publication / table numbers usage). A page subscribes by adding the topic with
`MercureTopicCollector::addTopic(OfficialResultsLiveUpdates::topic($roundId))` in its controller (organisers only); the
base layout's `mercure-hub` controller then dispatches each update as a `mercure:message` event on `document`.

## Seating

Table numbers before each in-person round - the seating page (`round_seating`: type, swap, drag and renumber, clear,
"this round doesn't use table numbers"), auto-assign as a proposal first (earlier rounds through `AdvancementSeeding`,
MySpeedPuzzling times, a draw, by name), the printed lists and the readiness line: [seating.md](seating.md).

## Not built yet (follow-ups in docs/TODO.md)

Unfinished results onto profiles (after unfinished-results phase 1b), several puzzles per round, deriving table numbers
from the table layout tool, results from stopwatch-less timing devices.
