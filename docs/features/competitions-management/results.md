# Round Results & Time Claiming

> **Status: implemented** (live timing marked as Phase 2 is not yet built). This document describes the design; the source of truth is always the source code.

Today there is **no results system**. The public event page shows a "seeding" chart derived from connected players' general 500-piece history (last 3 months) — not what happened at the event. `Competition.resultsLink` points off-site. `puzzle_solving_time.competition_round_id` exists but no query reads it.

This feature makes MySpeedPuzzling the results system itself:

1. **Organizers enter official results per round** — for solo, duo, and team rounds — with a fast bulk-entry UI and Excel import
2. **Public standings** appear on the event page once published
3. **Puzzlers claim their results** — a claimed result materializes as a verified `PuzzleSolvingTime` on their profile, flowing into all existing stats, rankings, and activity feeds

### The driving use case

> "I run puzzle contests in Minnesota at least 15 times a month. I don't write down the participant's names — just team names — but I would love to get more people using your site! Even if it's just me providing you teams/pairs times you can add to the site for people to view."

This works end-to-end with zero participant data: the organizer creates a recurring series, adds an edition per contest, types team names + times into the results UI (teams are auto-created), publishes. Puzzlers find their team in the public standings, hit "Claim", and the time lands on their MSP profile. Every published result page is an acquisition channel.

## Data Model

### CompetitionRound — new field

| Field | Type | Purpose |
|-------|------|---------|
| `resultsPublishedAt` | `?DateTimeImmutable` | `NULL` = draft: organizer sees results in the manage UI, public page shows nothing. Set = standings visible publicly |

### New entity: RoundResult

```
RoundResult
  id: UUID (PK)
  round: CompetitionRound (FK, NOT NULL)
  participant: ?CompetitionParticipant (FK)   ← solo rounds
  team: ?CompetitionTeam (FK)                 ← duo/team rounds
  secondsToSolve: ?int                        ← NULL = did not finish in time limit
  missingPieces: ?int                         ← for DNF ranking; also allowed on finished (unfinished pieces convention: 0/NULL)
  solvingTime: ?PuzzleSolvingTime (FK)        ← the materialized claimed time row (see Claiming)
  createdAt / updatedAt
  UNIQUE (round, participant), UNIQUE (round, team)
```

**Invariant:** exactly one of `participant` / `team` is set, and it must match `round.category` (participant for `solo`, team for `duo`/`team`). Enforced in the entity constructor.

**Ranking is computed, never stored:** finished results by `secondsToSolve` ASC, then DNFs by `missingPieces` ASC (NULLs last). Equal values share a rank. Storing rank would rot on every edit; computing it in the query keeps a single source of truth.

**Why not store results directly as `PuzzleSolvingTime`?** A solving time requires a non-null `player` owner. Organizer-entered results routinely have none (unclaimed names, team-name-only entries). `RoundResult` is the authoritative standings record independent of MSP accounts; `PuzzleSolvingTime` rows are materialized only when someone claims (below). Two layers, each doing what it's shaped for.

### Existing structures reused, not duplicated

- **Solo entrants** = `CompetitionParticipant` (existing, name-first, claimable via the existing connect pattern)
- **Duo/team entrants** = `CompetitionTeam` (existing). Teams may have zero members — a team-name-only result is fully supported
- **Claimed team times** use the existing `PuzzlersGroup` JSON mechanism on `PuzzleSolvingTime`, where members without accounts are name-only `Puzzler` entries — exactly how ad-hoc group times already work platform-wide

## Results Entry Console

`/en/manage-round-results/{roundId}` (`CompetitionEditVoter` — competition maintainers and admins alike), linked from the round list next to Puzzles/Teams/Tables/Stopwatch.

This screen is used **live at the venue, on a phone, with flaky Wi-Fi** — so unlike the rest of the management UI it is deliberately NOT a Live Component. It is a Stimulus-driven client-side console backed by small JSON endpoints, with an offline outbox (see "Reliability & Offline Support"). Live Components require a server round-trip per action; this screen must keep working when the round-trip fails.

```
┌─────────────────────────────────────────────────────────────────┐
│ Results — Round 2 (Team) · Ravensburger 500 "Alpine Lake"       │
│ Draft — not visible publicly            [Publish results]        │
│                                                                  │
│ Quick add:  [Team name…        ] [h:mm:ss   ] [missing] [+ Add] │
│                                                                  │
│ ┌────┬──────────────────┬───────────┬─────────────┬───────────┐ │
│ │ #  │ Team             │ Time      │ Missing pcs │           │ │
│ ├────┼──────────────────┼───────────┼─────────────┼───────────┤ │
│ │ 1  │ Puzzle Sharks    │ 0:58:12   │             │  ✏️  🗑    │ │
│ │ 2  │ Team Awesome     │ 1:23:45   │             │  ✏️  🗑    │ │
│ │ —  │ Sunday Slackers  │ DNF       │ 37          │  ✏️  🗑    │ │
│ └────┴──────────────────┴───────────┴─────────────┴───────────┘ │
│                                                                  │
│ [Import Excel] [Export Excel]     2 round entrants without result│
└─────────────────────────────────────────────────────────────────┘
```

- **Entrant list is pre-populated** — every participant (solo) or team (duo/team) assigned to the round appears immediately with an empty time, so at the venue the manager only fills in times, never builds the list first. Entrants without a result sort to the top
- **Quick add for unlisted entrants** — one row: name, time, missing pieces. For team rounds it creates the `CompetitionTeam` on the fly (matched by name if it exists); for solo rounds it creates the `CompetitionParticipant` (upsert by name, same matching rules as participant import) and assigns it to the round. The Minnesota organizer never touches team management — she just types names and times
- **Time input follows the add-time form pattern** — the same hours / minutes / seconds triple input used in `PuzzleSolvingTimeFormType`, familiar from everywhere else on the platform, with big numeric-keypad-friendly fields (`inputmode="numeric"`). A paste/type shortcut field that parses `1:23:45`, `83:45`, and `77` (minutes) fills the triple
- **Missing pieces** — same field concept as the add-time form. **DNF** = time left empty + missing pieces filled (or an explicit DNF toggle)
- **Group by table** — when the round has a table layout, a toggle groups entrants by row/table (using spot assignments), matching the manager's physical walking path through the venue
- Rows re-rank instantly after every entry (client-side sort; server confirms asynchronously)
- **Publish** sets `resultsPublishedAt` and (optional checkbox, default on) notifies connected participants: "Results for {event} are live". **Unpublish** available while corrections are needed; edits while published are instant (audiences at live events benefit from immediacy). Publish/unpublish requires connectivity — it is the one action not queued offline

### Reliability & Offline Support

Venue reality: overloaded conference Wi-Fi, phones dropping to 3G, a manager entering 40 times under pressure. Losing one entered time destroys trust in the whole system, so the console is **offline-first**:

**Client-generated IDs make every write idempotent.** The Stimulus controller generates the `RoundResult` UUID (v7) client-side when a row is first filled. The upsert endpoint is safe to replay: same id + same payload = same outcome, no duplicates. Retrying is always harmless.

**Outbox queue in IndexedDB.** Every mutation (upsert/delete) is written to a local IndexedDB outbox *before* the network call. A queue worker flushes the outbox in order:

- Online + 2xx → remove from outbox, mark row "synced ✓"
- Network error / offline → keep in outbox, mark row "saved on this device — will sync", retry with exponential backoff and on the browser `online` event
- 4xx (validation/permission) → surface the error on the row, do not silently retry

IndexedDB (not localStorage) survives tab kills and large queues. The page reloads its outbox on load — a manager can close the browser, reopen an hour later, and pending entries re-submit automatically.

**Sync status is always visible.** A status pill in the header: "All changes synced" / "3 entries waiting to sync (offline)" / "Sync error — tap to retry". Per-row dots mirror it. The manager always knows whether the server has their data.

**Multi-device concurrency.** Two managers can enter results simultaneously (one per table row at a venue). The server upserts by `(round, entrant)` with last-write-wins on conflict; when online, the console subscribes to the round's Mercure topic (`/round-results/{roundId}`) and merges remote changes into the list live — local unsynced edits always win over remote state for their own rows until flushed.

**Endpoints** (session-authenticated, same voter, controllers dispatch Messenger messages per project convention):

- `GET /round-results-state/{roundId}` — full entrant + result state (also the offline bootstrap; response cached for offline reload)
- `POST /round-results/{roundId}/upsert` — idempotent single-result upsert (client id, entrant ref or name, time, missing pieces)
- `POST /round-results/{roundId}/delete` — by result id

The page itself is added to the service worker's cached routes so it loads from cache when offline (`CACHE_VERSION` bump required).

### Excel import / export

Same pattern and infrastructure as participant import:

| Column | Required? | Description |
|--------|-----------|-------------|
| `name` | Yes | Team name (duo/team rounds) or participant name (solo) |
| `time` | No | `h:mm:ss` or `mm:ss`; empty = DNF |
| `missing_pieces` | No | Integer |

Upsert by name within the round; missing entrants are created; import never deletes. Post-import summary identical in style to the participant import (added / updated / warnings / errors).

## Public Standings

The event/edition detail page gains a **Results section** once at least one round has `resultsPublishedAt` set — replacing the "seeding" chart as the page's centerpiece for finished events (the seeding component stays for upcoming events).

- One tab/accordion per published round (round badge colors reused)
- Standings table: rank, entrant name, member chips (claimed members render as flag + link to profile; unclaimed as plain text), time or "DNF — 37 pieces missing"
- Podium styling for top 3
- Under the table: **"Did you puzzle here? Claim your result"** CTA
- The round's puzzle is shown with the standings (respecting existing hide-until-round-starts rules)

## Claiming — from result to profile

Claiming builds on the existing participant-connect pattern (`ConnectCompetitionParticipant`, same as WJPC prior art). What's new is the materialization step into `PuzzleSolvingTime`.

### Flow

1. **Claim CTA** → adaptive picker (extends the existing join flow):
   - Solo rounds: pick your name from unconnected participants (existing picker)
   - Duo/team rounds: pick your **team**, since members are often not on file. Claiming a team creates a `CompetitionParticipant` (`source = self_joined`, connected to the player) and a `CompetitionParticipantRound` linking them to the round + team
2. **Confirmation screen** lists every `RoundResult` belonging to the claimed identity (their participant in solo rounds, their teams in duo/team rounds): *"Add these results to your MySpeedPuzzling profile?"* with per-result checkboxes (default all checked)
3. **Materialization** (`ClaimRoundResults(playerId, resultIds)` message) — per result:
   - **Solo:** create `PuzzleSolvingTime` (player = claimer, puzzle = the round's puzzle, `secondsToSolve`, `missingPieces`, `finishedAt = round.startsAt`, `competition` + `competitionRound` set, `firstAttempt = true` unless the player has an earlier time on the puzzle). Store the row on `RoundResult.solvingTime`
   - **Team, first claimer:** create ONE `PuzzleSolvingTime` owned by the claimer with a `PuzzlersGroup` containing all team members — claimed members by `playerId`, others as name-only `Puzzler`s. Store on `RoundResult.solvingTime`
   - **Team, subsequent claimer:** no new row — update the existing row's `PuzzlersGroup`, upgrading their name-only entry to a `playerId` entry (via existing `replaceTeam()`). The time now appears in their profile through the existing JSONB-containment queries
4. **Dedupe:** if the player already logged a time for this round themselves (e.g. via the app's stopwatch + API `round_id`), no duplicate is created — the existing row is linked to `RoundResult.solvingTime` instead. If the self-logged time disagrees with the organizer's, the organizer result is authoritative for the public standings; the manage UI flags the discrepancy for the organizer
5. **DNF results** materialize with `secondsToSolve = NULL` + `missingPieces`, matching the existing unfinished-time convention

### Verification semantics

Claimed times are created with `verified = true` — an organizer-attested result is stronger evidence than self-reporting. (Open decision — see README of the proposal summary; flipping this to `false` is a one-line change.)

### Un-claiming

Disconnecting from a participant (existing flow) reverses materialization:

- Solo: the materialized row is deleted (if it was claim-created; a linked self-logged row is just unlinked)
- Team member: their `PuzzlersGroup` entry reverts to name-only. If the disconnecting player owns the row, ownership transfers to another claimed member (existing `transferOwnership()`); if none remain, the row is deleted
- `RoundResult` itself is never touched by un-claiming — official standings are the organizer's record

### Result edits & deletions after claiming

Organizer edits a claimed result → the materialized `PuzzleSolvingTime` is updated in the same handler (single transaction). Deleting a result deletes claim-created rows (never self-logged ones — those are just unlinked).

## Live Timing (Phase 2 of this spec)

Ties the existing round stopwatch to results capture. On `ManageRoundResults`, while the round's stopwatch is running, every entrant row shows a big **"Finished!"** button — tapping it stamps `secondsToSolve` from the shared clock. The organizer walks the floor with a phone, taps teams as they finish, standings build themselves in real time. Combined with a Mercure topic on results (`/round-results/{roundId}`), the public standings page updates live — projectable at the venue next to the existing stopwatch display.

This phase also covers **self-timed capture**: participants using the MSP app stopwatch during the event already can attach `round_id` via the API; those times surface in the manage UI as suggestions the organizer can accept into `RoundResult` with one click.

## Messages & Handlers

- `UpsertRoundResult(resultId, roundId, entrantName | participantId | teamId, ?secondsToSolve, ?missingPieces)` — quick add + inline edit; creates team/participant as needed. `resultId` is client-generated (UUID v7) so replays are idempotent
- `DeleteRoundResult(resultId)`
- `PublishRoundResults(roundId, notifyParticipants)` / `UnpublishRoundResults(roundId)`
- `ImportRoundResults(roundId, file)` — Excel path, reuses upsert
- `ClaimRoundResults(playerId, resultIds)` / un-claim handled inside the existing disconnect handler
- `RecordEntrantFinish(resultId)` — live-timing stamp (Phase 2)

New queries: `GetRoundResults` (standings with computed ranks, member chips, claim state), `GetClaimableResultsForPlayer`, plus extending `GetCompetitionRoundsForManagement` with result counts and publish state.

## Access Control

| Action | Who |
|--------|-----|
| Enter/edit/import/publish results | `CompetitionEditVoter` (maintainer/admin) |
| View published standings | Everyone |
| View draft standings | Maintainers/admins only |
| Claim a result | Any authenticated player (one claim per participant identity, existing guard) |

## Edge Cases

- **Round with multiple puzzles:** v1 materializes against the round's first assigned puzzle and the manage UI warns when a round has 0 or 2+ puzzles (0 puzzles: results display fine, claiming is disabled until a puzzle is assigned — a solving time needs a puzzle)
- **Duplicate team names within a round:** allowed in DB, quick-add matches the first and warns; organizer can rename inline
- **Participant claimed by wrong person:** existing disconnect + re-connect flow covers it; un-claim reverses materialization
- **Private players (`isPrivate`):** claimed member chips respect the existing privacy rules (name shown, no profile link/stats)
- **Legacy events with `resultsLink`:** the external link keeps rendering; once native results are published, it moves below the standings

## Testing Strategy

- Entity invariant tests: participant XOR team, category match
- Ranking query tests: finished order, DNF ordering by missing pieces, ties share rank
- Quick-add upsert tests: existing team matched by name, new team created, solo participant created + round-assigned
- Claim tests: solo materialization field-by-field; team first-claim creates one row with correct `PuzzlersGroup`; second claim upgrades entry without new row; dedupe against self-logged round time; DNF materialization
- Un-claim tests: delete vs unlink vs ownership transfer
- Edit-after-claim propagation tests; delete-result cleanup tests
- Publish gating tests: draft invisible publicly, publish notification respects opt-out/missing email
- Import round-trip test (export → import → unchanged)

## Implementation Sequence

1. `RoundResult` entity + `resultsPublishedAt` + migration
2. `GetRoundResults` query with computed ranking
3. Results entry console — JSON endpoints + messages first (online path), then the Stimulus console UI
4. Offline layer: IndexedDB outbox, sync worker, status UI, service worker route caching
5. Public standings section on event/edition pages
6. Excel import/export
7. Claim flow (picker extension, confirmation screen, materialization + dedupe + un-claim)
8. Edit/delete propagation to claimed times
9. Phase 2: live timing (stopwatch stamp + Mercure live standings + self-timed suggestions + group-by-table view)
