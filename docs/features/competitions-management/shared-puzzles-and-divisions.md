# Shared puzzles across rounds + divisions within a round

Status: **design of record** (2026-11-17, reviewed - see Review amendments). Two blockers for the St. Paul Winter Carnival (Feb 2027) and the Speed
Puzzle World Series (June 2027), demo ~2 Dec 2026. Written against `origin/main` at `c72030417`.

- **Blocker 1 - the same puzzle in several rounds of one category.** St. Paul's Teams CHALLENGE (90 min), Teams OPEN #1
  (2 h) and Teams OPEN #2 (Sunday, in parallel with FAMILY) all solve **one puzzle**. Today this is refused because a
  solving time's round is derived from *competition + puzzle + category* alone.
- **Blocker 2 - divisions inside one round.** PAIRS (Regular / Senior 55+) and SOLO (Regular / Senior 55+ / Junior
  under 16) each run as one round (one start, one puzzle) but are ranked and awarded per division. The overall official
  rank stays; a division rank is added.

Both are **opt-in in effect**: a competition that never puts a puzzle into two rounds of one category and never defines
a division renders byte-identically and runs the same statements (goldens `RoundResultsStateGoldenTest`,
`OfficialRoundResultsGoldenTest`, `CompetitionNameTagsGoldenTest`, `RecordRoundResultsStatementPinTest`, the query
budgets `DetailPagesQueryBudgetTest`, `CompetitorPageQueryBudgetTest`, `ParticipantsSheetQueryBudgetTest`,
`AnalyticsStudioQueryBudgetTest` stay unchanged).


## Review amendments (binding - orchestrator, 2026-11-17, after the independent design review)

Where this section and the body differ, this section wins.

**Shared puzzles - the matching rule (replaces the stage order of §2.3):**
- A1. **Exclusions first, never selection by date.** Drop a candidate round when (a) `tracked_at < starts_at` of the
  round and the round's start time is known (a time saved before the round began cannot belong to it), (b) the time's
  day-precision `finished_at` date is before the round's local day (local day in the zone the app uses for the round -
  the `RoundTimezone::resolve` rule, not `COALESCE(timezone,'Europe/Prague')`; if that cannot be expressed in SQL
  exactly, skip (b) rather than approximate), (c) the person's entry in that round is "did not start". Stage D as a
  *selector* is removed: equal dates never choose a round (the add form defaults to today).
  *(S1 as built:)* (a) reads `tracked_at` only for a time saved live (`created_via` form / stopwatch / API) - an
  import's `tracked_at` is made up (spreadsheet import: noon of the solve day, `ImportedResult::trackedAt()`; event
  import: the event's day) and rows older than `created_via` (NULL) are not known, so (a) is not applied to them. (b)
  is skipped for a round whose zone this Postgres does not know (`pg_timezone_names`), never approximated.
- A2. **Stage L is removed** (`created_via = stopwatch` + `tracked_at` is not the start of a solve).
- A3. **Stage E is consistent or nothing**: a round qualifies only when EVERY person on the time (owner + members from
  ONE membership source shared by resolver and reconciler - `pst.team` JSON as `GetPublishedRoundResults` reads it;
  the resolver builds the same list from the entity) who has a (non-DNS, non-deleted participant) entry in ANY candidate
  round has it in that round, and at least one person has one. Stage R (result equals time) may then narrow among
  qualifying rounds. If after exclusions + E (+ R) not exactly one round remains → **NULL** (unlinked). "narrow(C, ∅) =
  C" no longer applies after exclusions: an empty set answers NULL. A time with no entries anywhere and ≥ 2 candidates
  left after exclusions → NULL. (Carnival: teams are registered per round, so linked players resolve by E; the rest
  choose the round on their result.)
- A3b. **Sticky link (main session, 2026-11-18, after the production dry run):** when several candidates remain and the
  evidence does not single one out, a round the time already holds is KEPT while it is a remaining (not excluded)
  candidate (`round_chosen` unchanged) - existing links are never silently unlinked (production: the 50 times of
  "Pairs round 1" of one national championship whose two pairs rounds share a puzzle since a merge). Only a time
  without a current candidate round stays unlinked; evidence (E/R) that singles out another round still moves it. A
  chosen round that is no longer a candidate is not sticky (A4).
- A4. **No "none of these" state.** `round_chosen` (bool) only marks a round the player/API named. Every writer that
  clears `competition_round_id` with raw SQL (`DeleteCompetitionRoundHandler:102`, `DeleteCompetitionHandler:77`,
  `DeleteCompetitionSeriesHandler:65`, `ConvertCompetitionToSeriesHandler:156`, `SeriesEditionReconciler:98`, + any
  other found) also sets `round_chosen = false`; a coverage test greps `src/` for statements that set
  `competition_round_id` to NULL and requires `round_chosen` in the same statement (or an allow-list with reasons). When
  a chosen round stops being a candidate (puzzle removed from it), the reconciler clears the flag AND leaves the time
  unlinked when other candidates remain (no re-derivation onto another round of a time the player placed); with a
  single remaining candidate the normal rule applies. `ChooseSolvingTimeRound` takes a round id only (no null).
- A5. `RemovedResultSnapshot` carries an optional `round_chosen` key (restore keeps the choice); edit keeps a chosen
  round while competition, puzzle and category are unchanged.
- A6. **Production dry run before S1 ships**: S1 delivers a read-only SQL that counts times whose link would change
  (today's answer vs the new rule) per competition; the orchestrator runs it on production and lists the competitions in
  STATUS before pushing.
- A7. **Package order S1 → S3 → S2** (S3 - explicit round on Add to my profile + `LinkToRound` + the chooser - works with
  one candidate too, so it ships before organisers can share puzzles).
- A8. Secret puzzles across sharing rounds: no new rule; S2 shows a notice on the round puzzles page (and the wizard
  line) when rounds sharing the puzzle have different hide settings, explaining the effect (`GetRoundPuzzlesHeldElsewhere`
  keeps a puzzle hidden site-wide while any round still hides it everywhere; a round that reveals it reveals it on its
  own pages). `UnfinishedResultHint`'s "all candidates share a limit" branch checks every candidate's hide state.
- A9. Who may choose a round (`ChooseSolvingTimeRound`): the time's owner or a linked member listed in `pst.team`.

**Divisions:**
- A10. **D1 and D2 ship together** (no organiser page without an effect). D1 also adds an internal API endpoint to set
  entries' divisions in bulk (`PUT /internal-api/competitions/{id}/divisions` for the list, `PUT
  /internal-api/rounds/{id}/entry-divisions` `{entries:[{participantId|teamId, division|null}]}` under
  `CompetitionParticipantsLock`) - the demo fallback while the sheet column (D3) waits for the participant-linking work.
- A11. Entries created at the desk (walk-ins, `RecordRoundResultsHandler`) get no division (documented); `AdvanceQualified`
  copies the division when the target round uses it. A desk "Division" cell is v2 (`docs/TODO.md`).
- A12. `?division=` filter: "N ranked" / "of N" counts follow the division; bracket boxes ignore divisions (documented).
- A13. Division rank = rank within the division computed from the official order BEFORE hidden players are dropped, never
  renumbered (same rule as the overall rank); ties share; unfinished/DNS unranked as overall.
- A14. Import: when an event defines divisions and the file has a `division` column, the preview says which way it was
  mapped (warning line). `CompetitionParticipantRound::changeRound()` (no callers) clears a division the new round does
  not use if it is ever used.
- A15. Standings per division (D7) stay after the demo unless time allows; the v2 public standing JSON leaves room for a
  division field.

---

## 1. Findings

### 1.1 Where the round of a solving time lives

`puzzle_solving_time.competition_round_id` is **stored** and every public reader reads the stored column - not the
rule. The rule only decides what gets stored:

| Writer | File:line | What it does today |
|---|---|---|
| `SolvingTimeRoundResolver::resolve()` / `resolveFor()` | `src/Services/RoundResults/SolvingTimeRoundResolver.php:39-81` | `SELECT cr.id … WHERE competition_id, crp.puzzle_id, cr.category ORDER BY cr.starts_at LIMIT 1` - no date, no person. `keepsItsRound()` (imported event result) short-circuits. Its doc-comment states the invariant. |
| `RoundResultsReconciler::run()` | `src/Services/RoundResults/RoundResultsReconciler.php:68-115` | Set-based twin: link = `DISTINCT ON (pst.id) … ORDER BY pst.id, cr.starts_at` over the same join; unlink = `NOT EXISTS` a round with competition + category + puzzle. Scopes: competition (postFlush `CompetitionRoundsChanged`), series (`SeriesEditionReconciler:57`), all (cron `myspeedpuzzling:reconcile-round-results`, every 15 min). |
| `SolvingTimeRoundResolver::SQL_ROUND_IS_DERIVED` | resolver `:31` | `(created_via IS DISTINCT FROM 'event_import' OR competition_round_id IS NULL)` - honoured by both reconciler statements. |
| `AddPuzzleSolvingTimeHandler` | `src/MessageHandler/AddPuzzleSolvingTimeHandler.php:169-172, 306` | `message->roundId` (API) sets the **competition** from the round, but `:306` then re-derives the round with the resolver - an explicit round is effectively ignored today (it matches only because the rule has a single answer). |
| `SolvingTimeEventDerivation::derive()` | `src/Services/SolvingTimeEventDerivation.php:37` | Edit (`EditPuzzleSolvingTimeHandler`) and "Link my results" (`LinkSolvingTimesToEventHandler`, wizard backfill) re-derive. |
| `UndoAutoRemovalHandler` | `src/MessageHandler/UndoAutoRemovalHandler.php:135` | Restored time re-derives. |
| `KeepDuplicateCopyHandler` | `src/MessageHandler/KeepDuplicateCopyHandler.php:90` | Kept copy re-derives. |
| `UnfinishedResultRound::withRound()` | `src/Services/UnfinishedResults/UnfinishedResultRound.php:153` | `resolveFor(competition, puzzle, type)`; refuses (`UnfinishedResultNeedsTimeLimit`) without a round with a limit. `forNewResult()` with `roundId` (`:62-70`) also throws the round away and re-derives from its competition. |
| `UnfinishedResultHint` | `src/Services/UnfinishedResults/UnfinishedResultHint.php:29,45` | The add form's "I didn't finish" switch is offered by the same rule (through `UnfinishedResultRound`). |
| `PuzzleSolvingTimeRepository::findByCompetitionRound()` | `src/Repository/PuzzleSolvingTimeRepository.php:279-306` | Move-round: linked times **plus unlinked times on one of the round's puzzles in its category** - "can only belong to this round" by the invariant. |
| Event results import | `PuzzleSolvingTime::keepsItsRound()` `src/Entity/PuzzleSolvingTime.php:458` | Imported results keep the round the import gave them (README D10, smaller teams `ApplyEventResultsTabSmallerTeamsTest`). **Unaffected.** |

The invariant is enforced (refusals) at:

| Guard | File:line | Surface |
|---|---|---|
| `GetCompetitionRounds::roundWithPuzzleInCategory()` | `src/Query/GetCompetitionRounds.php:90` | the one check query (8 callers) |
| `AddPuzzleToCompetitionRoundHandler` | `:114-122` → `PuzzleAlreadyInCompetitionRoundCategory` | round puzzles page (`AddPuzzleToRoundController:158` turns it into a form error) |
| `EditCompetitionRoundHandler` | `:141-155` (category change) | `EditCompetitionRoundController:242` |
| `SetCompetitionRoundPuzzlesHandler` | `:94-105` | internal API `SetRoundPuzzlesController:69` (→ 409 `PuzzleInTwoRoundsOfCategory`), wizard |
| `MoveRoundToCompetitionHandler` | `:105-117` → `PuzzleInTwoRoundsOfCategory` | `Restructure/MoveCompetitionRoundController:150`, internal `MoveRoundController`; `RoundNotMovableReason` doc |
| `CreateCompetitionRoundController` (internal API) | `:115-123, 155-156` | 409 |
| `UpdateCompetitionRoundController` (internal API) | `:89-90` | 409 |
| Creation wizard | `EventWizardPuzzleController:318`, `EventWizardPuzzlesController:72,449`, `EventWizardPastPuzzlesController:104`, `EventWizardStructureController:598` | row / form error `event_wizard.puzzles.same_puzzle_in_category` |
| Log level | `config/packages/framework.php:107`, `tests/ClientErrorLogLevelTest.php:101` | 409 at info |

**The invariant is already breakable today:** `ApprovePuzzleMergeRequestHandler:558-576` moves a merged puzzle's
`competition_round_puzzle` rows onto the survivor without the check - two rounds of one category can end up holding
the same puzzle. The resolver/reconciler survive it only because of `ORDER BY cr.starts_at LIMIT 1` (they silently pick
the earliest round - a wrong guess). The design below fixes that case as a side effect.

### 1.2 Readers of the stored link (no change needed once the link is right)

All read `competition_round_id`, not the rule - they stay as they are and are correct as long as the link is correct
(or NULL when unknown):

- `GetRoundResults` (`src/Query/GetRoundResults.php:110`, `WHERE pst.competition_round_id = :roundId`) - the round page's
  "times puzzlers added" list; earliest time per player/group.
- `GetPublishedRoundResults:294-299` - the viewer's times in the round → "Add to my profile" vs "On your profile".
- `GetAnalyticsStudioEntrantHistory:81,101`, `GetAnalyticsStudioLineupHistory`, `GetAnalyticsStudioSimilarHistory` -
  "never this round's own times" = `competition_round_id IS DISTINCT FROM :roundId` + `sqlBefore()` (before the round's
  first stopwatch start). With a shared puzzle a Challenge time (Sat 09:00) **is** history for Open #1 (Sat 14:00) -
  correct: it was solved before. Open #1's own times are excluded only when linked to Open #1, so linking matters.
- `GetParticipantsSheetState:450-470` and `SiteSnapshotReader:136-148` - "rounds in which linked players have times of
  their own" (the sheet's results guard; the import's snapshot).
- `CountCompetitionResults`, `GetAdminCompetitions` (`results_without_round_count`), `GetRecentActivity`,
  `GetNotifications`, `GetPlayerSolvedPuzzles`, `GetRecentIdenticalSolvingTime:68`, `GetDuplicateCandidates:201`,
  `OccurrenceRounds:48`, `GetRelayResultRound`, `GetSuspiciousTimeCaseDetail`, `GetEventResultClaims`,
  `GetCompetitionSlugsForSitemap`, `UnpublishBlockers`, `GetEventWizardStructureLock`, delete handlers
  (`DeleteCompetition*Handler`), `ConvertCompetitionToSeriesHandler`, `RemovedResultSnapshot`.
- `SeriesEditionMatch` (`src/Query/SeriesEditionMatch.php`) matches a series pick to an **edition**
  (`COUNT(DISTINCT d.competition_id)`), not a round: a puzzle in two rounds of one edition is still one edition.
  **Unchanged.** The round inside the edition is then derived by the new rule.
- Standings (`GetStandingResults`, `StandingCalculator`), the results desk, export, competitor page, displays, live entry:
  read **official round entries** (`competition_participant_round` / `competition_team` with `HasOfficialResult`), keyed
  by round - never solving times. Blocker 1 does not touch them.

### 1.3 Where divisions would surface

Official entries: `competition_participant_round` (solo entry; also each member's row in a pair/team round, `team_id`)
and `competition_team` (the pair/team entry). Results on both through `HasOfficialResult`
(`result_seconds`, `result_pieces_placed`, …). The one ranking is `OfficialResultsRanking::rank()`
(`src/Services/OfficialResultsRanking.php`). Surfaces and their source:

| Surface | Source | Division need |
|---|---|---|
| Results desk + state JSON | `GetRoundResultEntries` → `RoundResultEntry`, `RoundResultsStateController`, `results_desk_controller.js` | division column + filter, division rank |
| Export | `ExportRoundResultsController` → `Services/Export/RoundResultsExporter` | "Division", "Division rank" columns |
| Public round page (official section) | `GetPublishedRoundResults` → `OfficialEntrantsView` → `PublishedRoundEntry` | division badge + rank, filter |
| Competitor page | `GetCompetitorPage` → `CompetitorPageEntry` | "Senior 55+ · 2nd in division" |
| Name tags / tents | `GetCompetitionNameTags`, `CompetitionTagSheet` | division label per entry |
| Displays (leaderboard / ticker) | `GetCompetitionDisplayState:426` ranks, `DisplayDesignSanitizer:550` | leaderboard `division` prop |
| Analytics studio | `GetAnalyticsStudioEntrants`, `AnalyticsStudioEntrantBuilder`, `AnalyticsStudioLiveResults` | entrant division + division rank, filter |
| Standings | `GetStandingResults`, `StandingCalculator`, `CompetitionStanding` | optional division filter (later) |
| Advancement presets | `AdvancementSeeding::topOverall()` | none in v1 |
| Participants sheet | `GetParticipantsSheetState`, `ApplyParticipantSheetChanges` (`SheetChangeOp`) | a Division column per round tab |
| Participant import | `ColumnMapping:41-42` maps `division(s)` → `ParticipantImportField::Rounds`; `PlanBuilder`, `ParticipantImportApplier`, preview | a real Division field |
| Participants export | `ExportCompetitionParticipantsController` | per-round division columns (round trip with the import) |
| Duplicate / delete / convert | `DuplicateCompetitionHandler`, `DeleteCompetition*Handler`, `ConvertCompetitionToSeriesHandler`, `ConvertCompetitionForeignKeyCoverageTest` | copy / cascade |

---

## 2. Blocker 1 - shared puzzles

### 2.1 Decision

**Lift the invariant.** A round's candidates for a solving time stay *competition + puzzle + category*. When there is
**exactly one** candidate (every competition today) nothing changes - same answer, same statement count. When there are
**several**, the time is matched by evidence, in a fixed order, and **left unlinked when the evidence does not single
out one round**. Never guess: an unlinked time (competition set, round NULL) is a normal state, the player can pick the
round themselves, and "Add to my profile" always carries its round explicitly.

### 2.2 Data model (one additive column)

`puzzle_solving_time.round_chosen BOOLEAN NOT NULL DEFAULT false` (migration by `doctrine:migrations:diff`; constant
default = instant on PG 18, no rewrite). Entity: `public bool $roundChosen = false` + methods
`chooseCompetitionRound(CompetitionRound $round)` (sets both) and `changeCompetitionRound()` (existing, resets
`roundChosen = false`). `true` = the round was named by the player (Add to my profile, the round picker, the API
`roundId`) and is kept **while it is still a candidate** (round of the time's competition, its category, holding its
puzzle). An imported result keeps `keepsItsRound()` as today (stronger: kept even when no longer a candidate).

No column for the evidence used - it is recomputed on every reconcile (like today).

### 2.3 The rule - one place: `SolvingTimeRoundMatch`

New `src/Query/SolvingTimeRoundMatch.php`, modelled on `SeriesEditionMatch` ("the only place it is written"): static SQL
builders, used by both the resolver (one time, parameters) and the reconciler (set-based). Input per time:

| Input | Resolver (before flush) | Reconciler |
|---|---|---|
| competition, puzzle, category | entity | `pst.*` (`puzzling_type` = `competition_round.category`) |
| current round, chosen | entity | `pst.competition_round_id`, `pst.round_chosen` |
| owner | `player.id` | `pst.player_id` |
| linked members | `PuzzlersGroup` player ids (array param) | `puzzling_team_member.player_id` of `pst.puzzling_team_id` |
| result | seconds / pieces placed | `pst.seconds_to_solve`, `pst.pieces_placed` |
| solve day | `finishedAt` when precision is a day, else NULL | `pst.finished_at::date` when `finished_at_precision IS NULL` |
| live save | `createdVia = stopwatch`, `trackedAt` | `pst.created_via`, `pst.tracked_at` |

Pseudo-code (each stage narrows `C`; a stage with 0 matches leaves `C` as it was):

```
C = rounds r of the time's competition with r.category = category AND puzzle ∈ r.puzzles
if |C| = 0: return NULL
if |C| = 1: return that round                                 # every competition today - byte-identical
if chosen AND current ∈ C: return current                      # the player's own choice
# Stage E - official entry (the person's own entry in the round)
E = { r ∈ C : owner has an entry in r }                        # solo: competition_participant_round of a non-deleted
                                                               #   participant with player_id = owner;
                                                               # duo/team: a competition_team of r with the owner as a member
if E = ∅: E = { r ∈ C : any linked member has an entry in r }
C = narrow(C, E); if |C| = 1: return it
# Stage R - the recorded result equals the time (only rounds with an entry from stage E)
R = { r ∈ C : that entry's result_seconds = seconds OR (pieces_placed NOT NULL AND result_pieces_placed = pieces_placed) }
C = narrow(C, R); if |C| = 1: return it
# Stage D - the solve day (day precision only)
D = { r ∈ C : local day of r.starts_at (COALESCE(r.timezone,'Europe/Prague')) = solve day }
C = narrow(C, D); if |C| = 1: return it
# Stage L - a live stopwatch save (created_via = 'stopwatch', solve day = round's day, seconds NOT NULL)
L = { r ∈ C : | (tracked_at - seconds) - COALESCE(r.stopwatch_first_started_at, r.starts_at) | <= 10 min }
C = narrow(C, L); if |C| = 1: return it
return NULL                                                     # ambiguous - never guess

narrow(C, X) = X if X ≠ ∅ else C
```

Why these stages, in this order:

- **E** is the strongest evidence and covers St. Paul: a team registered in Open #1 only. Owner first, so a teammate who
  also played Challenge on another team does not pull the time away.
- **R** separates "same team in Challenge and in Open #1" (rare but possible): the organiser recorded 1:12:05 in one and
  1:31:40 in the other; the time says which.
- **D** separates Saturday from Sunday (Challenge/Open #1 vs Open #2). Day only - the add form's `finishedAt` is a
  `DateType` (`PuzzleAddFormType:229`), so there is no time of day to use.
- **L** is the only intra-day signal that cannot be wrong by much: a time saved from the site's own stopwatch at the end
  of the solve started within 10 min of the round's real start. A form save (`tracked_at` = moment of typing) is never
  used - a Challenge team typing its time at 15:00 during Open #1 would otherwise be misfiled.
- Same-start parallel rounds on one puzzle (heats, or Open #2 and FAMILY if they share it) are separated only by E/R;
  otherwise the time stays unlinked.

SQL shape (for the reconciler; the resolver feeds the same CTEs from a one-row `VALUES`):

```sql
round_match_candidate AS (            -- time × candidate round, with n = candidates per time
  SELECT t.time_id, cr.id AS round_id, COUNT(*) OVER (PARTITION BY t.time_id) AS n, …evidence flags…
  FROM round_match_time t
  JOIN competition_round cr ON cr.competition_id = t.competition_id AND cr.category = t.category
  JOIN competition_round_puzzle crp ON crp.round_id = cr.id AND crp.puzzle_id = t.puzzle_id
),
round_match_answer AS ( … one row per time: round_id or NULL … )
```

The evidence flags (`EXISTS` on `competition_participant_round` / `competition_team` / `competition_participant`) are
computed **only for rows with `n > 1`** (`CASE WHEN n > 1 THEN EXISTS(…) END`), so the cron's plan for today's data
stays a hash join over the same three tables. Package S1 measures the full reconcile on a production-size copy (today
~1.3 s budget class; must stay within +20 %).

Exposed API:

- `SolvingTimeRoundMatch::sqlCandidates(string $timesCte): string` - the CTEs above.
- `SolvingTimeRoundMatch::sqlAnswer(): string` - `time_id, round_id` (NULL = no / ambiguous), `candidates` (count).
- `SolvingTimeRoundResolver::resolve(PuzzleSolvingTime $time, null|CompetitionRound $named = null): RoundResolution`
  - `RoundResolution { null|CompetitionRound $round; bool $chosen; int $candidates }`. `$named` (Add to my profile, API,
  picker) wins when it is a candidate (`chosen = true`); a named round that is not a candidate is ignored (today's
  behaviour: the derived answer).
- `resolveFor(...)` keeps its signature for the unfinished path but gains the evidence parameters (owner, members, day,
  pieces placed) and the optional named round.

### 2.4 Per-reader change list

| # | File | Change | Pinned by |
|---|---|---|---|
| 1 | `SolvingTimeRoundResolver` | Rewritten over `SolvingTimeRoundMatch`; still **one statement** (candidates + answer) + `get()` of the round; doc-comment states the new rule. `SQL_ROUND_IS_DERIVED` unchanged (import). | new `SolvingTimeRoundMatchTest` (matrix below); `AddUnfinishedPuzzleSolvingTimeHandlerTest:223`, `EditUnfinishedPuzzleSolvingTimeHandlerTest:232`, `ApplyEventResultsTabSmallerTeamsTest:118` unchanged |
| 2 | `RoundResultsReconciler::run()` | Link statement = `SolvingTimeRoundMatch` answer; skips `keepsItsRound` rows (as now) and **chosen rows whose round is still a candidate**; writes `round_chosen = false` when it re-derives a chosen row whose round stopped being a candidate. Unlink statement: also resets `round_chosen`; additionally unlinks a *derived* (not chosen) row whose round is no longer the rule's answer (that is the link statement's job already - `IS DISTINCT FROM`; with a NULL answer the link statement must set NULL too: `matched.round_id` may be NULL, so drop `INNER` semantics → `LEFT JOIN` the answer). | `RoundResultsReconcilerTest` extended; goldens unchanged |
| 3 | `AddPuzzleSolvingTimeHandler:306` | `resolve($solvingTime, named: $competitionRound)`; `chosen` stored. Nothing else in the handler changes (feedback "the add-result form must never break"). | `RoundResultsLinkingTest` |
| 4 | `PuzzleAddController` | When `official_entry` is valid on POST, pass the entry's round (`GetPublishedRoundResults::roundOfEntry()` already returns `round_id`) as `roundId` of `AddPuzzleSolvingTime` (today only `competitionId`). | new case in `OfficialResultsAddToProfileTest` (or the existing add-to-profile test) |
| 5 | `SolvingTimeEventDerivation` (edit, Link my results) | Edit keeps the choice: `resolve($time, named: $time->roundChosen ? $time->competitionRound : null)`. Link my results: derived only. | `EditPuzzleSolvingTimeHandlerTest` new case |
| 6 | `UndoAutoRemovalHandler:135`, `KeepDuplicateCopyHandler:90` | Same as 5 (keep a chosen round). | their tests + one shared-puzzle case each |
| 7 | `UnfinishedResultRound` | `forNewResult()` with `roundId`: use that round when it is a candidate (today re-derived). Otherwise the full rule with evidence; ambiguous → new `UnfinishedResultRoundNotIdentified` (subclass of `UnfinishedResultNeedsTimeLimit` so existing catches keep working) with its own message: "This puzzle is in several rounds - add it from the round's official results or choose the round on the result afterwards." `UnfinishedResultHint` offers the switch only when the rule names a round with a limit, or all candidates have the same limit (then the save needs a named round - from Add to my profile). | `AddUnfinishedPuzzleSolvingTimeHandlerTest` new cases |
| 8 | `PuzzleSolvingTimeRepository::findByCompetitionRound()` | Unlinked times are taken only when this round is their **only** candidate (`NOT EXISTS` another round of the competition, same category, same puzzle). | `MoveRoundToCompetitionHandlerTest` |
| 9 | `MoveRoundToCompetitionHandler:105-117` | **Keeps the refusal** (`PuzzleInTwoRoundsOfCategory`) - moving a round into an event that already holds its puzzle in that category would re-derive its times by weaker evidence (a moved round has no entries - refused at `:91`). Restructuring is rare; the refusal is safe. Doc-comment updated. | `MoveRoundToCompetitionHandlerTest:117` unchanged |
| 10 | `AddPuzzleToCompetitionRoundHandler`, `EditCompetitionRoundHandler`, `SetCompetitionRoundPuzzlesHandler` | Remove the refusal. `PuzzleAlreadyInCompetitionRoundCategory` deleted. | `SetCompetitionRoundPuzzlesHandlerTest:58`, `RoundResultsLinkingTest:117` **flip** to "accepted" |
| 11 | `AddPuzzleToRoundController:158`, `EditCompetitionRoundController:242` | Remove the catch; after a successful add, an **info flash** "Also used in round Challenge - times are matched to the round by each player's entry and day." (`competition.round_puzzles.shared_notice`). | controller tests |
| 12 | Internal API `CreateCompetitionRoundController`, `SetRoundPuzzlesController`, `UpdateCompetitionRoundController` | Remove the 409s; the response gains `"sharedWithRounds": ["Challenge"]` on a round puzzle that is in another round of the category (additive JSON). `docs/features/internal-api.md` updated. | internal API tests |
| 13 | Wizard (`EventWizardPuzzleController:318`, `EventWizardPuzzlesController:72,449`, `EventWizardPastPuzzlesController:104`, `EventWizardStructureController:598`) | Remove the refusal and `event_wizard.puzzles.same_puzzle_in_category`; the grid shows an info line under a row whose puzzle is chosen in another row of the same category ("Same puzzle as Challenge"). The "choose twice on the grid" check is removed too (it is the same invariant). | wizard controller tests |
| 14 | `GetCompetitionRounds::roundWithPuzzleInCategory()` | Renamed `roundsSharingPuzzleInCategory()` → `list<string>` names, used only for the notices in 11-13. Doc-comment no longer claims the invariant. | - |
| 15 | `GetPublishedRoundResults:294-299` (viewer's times) | Same statement, extended: also the viewer's **unlinked** time on a puzzle of the round in its category in this competition → `OfficialEntryProfileState::LinkToRound` ("This is my time - link it to Open #1") instead of a second "Add to my profile" (which would create a duplicate). Guests: no change (viewer-only branch). | `OfficialRoundResultsGoldenTest` unchanged; new viewer test |
| 16 | New `ChooseSolvingTimeRound(timeId, roundId\|null, playerId)` + handler | The player's (or a teammate's) pick for an unlinked/ambiguous time: `roundId` must be a candidate; `null` = "none of these" (sets `round_chosen = true`, round NULL - the reconciler must not re-link it: treat `chosen AND round IS NULL` as "kept unlinked"). Surfaces: the result detail modal (`PuzzleResultDetailController`) shows "Round: not identified - choose" when `competition_id` set, round NULL, ≥ 2 candidates; the round page's `LinkToRound` button posts the same message. | handler test; result detail controller test |
| 17 | `ApprovePuzzleMergeRequestHandler` | No change - after a merge the reconcile now leaves an ambiguous time unlinked instead of filing it in the earliest round. | `RoundResultsReconcilerTest` merge case |
| 18 | `config/packages/framework.php:107`, `ClientErrorLogLevelTest:101` | `PuzzleInTwoRoundsOfCategory` stays (move only). | unchanged |
| 19 | `.claude/rules/features/events.md` "Round results", `round-results.md`, `official-results.md`, `creation-wizard.md`, `toolkit/W-creation-wizard-research.md` pain 9 | Rewrite the invariant sentences. | `ClaudeInstructionsTest` |
| 20 | Coverage tests `SolvingTimeReadersAreClassifiedTest`, `SuspiciousTimeQueryCoverageTest`, `TrackerlessTimeQueryCoverageTest`, `DraftVisibilityCoverageTest` | Classify `Query/SolvingTimeRoundMatch.php` like `RoundResultsReconciler` (WRITE/NEUTRAL). Suspicious times are linked like any other (today's behaviour). | those tests |

### 2.5 `SolvingTimeRoundMatchTest` - the matrix (S1)

Fixture: competition with Challenge (team, Sat 09:00, 90 min), Open #1 (team, Sat 14:00, 120 min), Open #2 (team, Sun
10:00, 120 min) sharing puzzle P; FAMILY (team, Sun 10:00) on puzzle Q; a solo round on P (other category). Built by
direct `competition_round_puzzle` inserts in S1 (the handlers still refuse until S2).

| Case | Expected |
|---|---|
| solo time on P | the solo round (single candidate - today's path, one statement) |
| team time, owner in Open #1's team only | Open #1 (E) |
| owner in Challenge and Open #1 teams, time = Open #1's recorded result | Open #1 (R) |
| owner in Challenge and Open #2, no results yet, finished Sun | Open #2 (D) |
| owner in Challenge and Open #1, no results, finished Sat, form save | **NULL** |
| same, saved from the stopwatch 14:00 + seconds ± 5 min | Open #1 (L) |
| same, stopwatch 14:00 + seconds + 25 min | NULL |
| no entries, finished Sun | Open #2 (D) |
| no entries, finished Sat | NULL |
| no entries, precision month | NULL |
| owner has no entry, a linked teammate has one in Challenge only | Challenge (E, members) |
| chosen Challenge, owner's entry in Open #1 | Challenge (chosen wins while a candidate) |
| chosen Challenge, P removed from Challenge | re-derived (Open #1 by E), `round_chosen = false` |
| chosen NULL ("none of these") | stays NULL |
| imported event result in Challenge | Challenge (keepsItsRound) |
| entry of a deleted participant | ignored |
| resolver and reconciler | **the same answer for every row above** (parity loop) |

### 2.6 What players and organisers see

- **Round results page, Open #1** (`/events/…/results/open-1`): official results = Open #1's entries (unchanged);
  "times puzzlers added" = times linked to Open #1 only. A Challenge team's time never shows on Open #1.
- **Add to my profile on Open #1**: the saved time carries Open #1 (`round_chosen`), whatever the team also did in
  Challenge. Afterwards Open #1 says "On your profile"; Challenge still offers its own.
- **A time the rule cannot place** (no entry, Saturday, typed on the form): saved, linked to the event, in no round. The
  result detail says "Round: not identified" with a "Choose the round" picker (candidate rounds only). It appears in no
  round's list until chosen. Organisers see it in the admin's `results_without_round_count`.
- **Same team in Challenge and Open #1** solving the same puzzle twice: two times; first-try rules already handle the
  second solve (not a first try); the duplicate detector needs the same second and the same day - two real solves
  differ.
- **Analytics studio**: history for Open #1 includes the entrant's Challenge time (solved before the round's start) -
  correct and useful ("solved it this morning in 1:12:05"). Open #1's own times are excluded only when linked to Open
  #1; an unlinked Open #1 time after the start is excluded by `sqlBefore()` anyway.
- **Standings**: unaffected (official entries per round). A "Challenge + Open" standing is a normal standing.
- **Secret puzzle**: a puzzle shared by Saturday and Sunday rounds and kept secret by Open #2 stays hidden site-wide
  until Open #2's reveal (`GetRoundPuzzlesHeldElsewhere`) - Saturday rounds' pages leave it out and nobody can log it
  until then. Intended (no spoiler for Sunday); the round puzzles page says so (existing text).
- **Series edition** (SPWS): edition match unchanged; round inside the edition by the new rule.
- **Online round**: same rule; online participants have entries (`online_token` participants), so E resolves.
- **Moved round**: refused into an event that already holds the puzzle in the category (unchanged message).

---

## 3. Blocker 2 - divisions

### 3.1 Decisions

1. **The list is per event, the use is per round.** `competition_division` = the event's list (name, short label,
   order). A round uses a subset (`competition_round_division`). A round without rows has no divisions = today. St.
   Paul: list = Regular, Senior 55+, Junior U16; PAIRS uses Regular + Senior, SOLO all three, team rounds none.
2. **The division is on the entry** - what is ranked: `competition_participant_round.division_id` for a solo entry,
   `competition_team.division_id` for a pair/team entry (members' own rows keep NULL). A person can be Senior in SOLO
   and Regular in PAIRS (a pair with a younger partner); a pair's division is the pair's.
3. **No implicit default.** An entry without a division in a round that has divisions has the overall rank only; the
   desk and the sheet count them ("12 entries without a division") and the sheet offers "Fill empty with …" (one
   change group).
4. **Overall rank stays the official rank**; the division rank is added beside it (`OfficialResultsRanking` over the
   entries of one division - same rules, ties share, DNS / no result unranked). Hidden rows are dropped after ranking,
   never renumbered (as now).
5. **Opt-in = data**: no list → no columns, no keys in JSON, no filters. Goldens unchanged.

### 3.2 Model + migration (generated by diff)

```
competition_division            id uuid PK, competition_id FK (ON DELETE CASCADE), name varchar(60),
                                short_name varchar(12) NULL, position smallint default 0, created_at timestamp
competition_round_division      id uuid PK, round_id FK (CASCADE), division_id FK (CASCADE),
                                UNIQUE (round_id, division_id)
competition_participant_round   + division_id uuid NULL FK → competition_division (ON DELETE SET NULL)
competition_team                + division_id uuid NULL FK → competition_division (ON DELETE SET NULL)
```

Entities `CompetitionDivision`, `CompetitionRoundDivision` (separate entity - **`CompetitionRound` is not touched**, a hot
file), `CompetitionParticipantRound::$division` / `CompetitionTeam::$division` as lazy `ManyToOne` (no fetch join - the
`RecordRoundResultsStatementPinTest` read counts stay 4/5) with `changeDivision(?CompetitionDivision)`. Repositories
plain `readonly`. Max 12 divisions per event. Names unique per event (case-insensitive, checked in the handler; no DB
unique on `lower()` - no custom index needed).

Coverage: `ConvertCompetitionForeignKeyCoverageTest` (new FKs to `competition` / `competition_round`),
`DeleteCompetitionHandler` / `DeleteCompetitionRoundHandler` / `DeleteCompetitionSeriesHandler` raw deletes,
`ConvertCompetitionToSeriesHandler`, `DuplicateCompetitionHandler` (copies the list and each round's subset - rounds
are copied, entries are not), `MoveRoundToCompetitionHandler` (a round with entries never moves; its subset is dropped -
it references the source's list).

### 3.3 Messages (all `SerializedByLock` on `CompetitionParticipantsLock` - `SerializedByLockMessagesTest`)

- `SetCompetitionDivisions(competitionId, actingPlayerId, list<{id, name, shortName}>, array<roundId, list<divisionId>> rounds, bool confirmClearing)`
  - one write for the list + the per-round subsets (the organiser page saves both). Removing a division, or a division
  from a round, that entries still use → `DivisionsInUse(count)` (422 on the page with "N entries lose their
  division - save anyway?") unless `confirmClearing`, then those entries' division is cleared in PHP (entities, not
  bulk SQL - counts are small).
- Entry division writes go through the existing paths only:
  - sheet: new `SheetChangeOp::Division` `{roundId, participantId | teamId, from, to}` (to = division id or `''`) inside
    `ApplyParticipantSheetChanges` → `ParticipantImportApplier` (the net diff path), rule in `ParticipantRules`
    (division must be in the round's subset; a team round sets the team's, a solo round the participant-round's).
  - import: §3.5.
  - Registration (managed) does not ask for a division in v1.
- Internal API: `PUT /internal-api/competitions/{id}/divisions` = `SetCompetitionDivisions` (so Claude Code can set St.
  Paul up), and the import/sheet paths for entries.

### 3.4 Surfaces

| Surface | Change | Opt-out guarantee |
|---|---|---|
| Organiser: **Divisions page** | `competition_divisions` (`/{_locale}/manage-event-toolkit/{id}/divisions`, `COMPETITION_TOOLKIT_MANAGE`), a hub card. Rows: name, short label, order; a checkbox grid round × division. | hub card only adds a card (hub page has no golden) |
| `GetRoundResultEntries` → `RoundResultEntry` | `division_id` read in the existing entries statements (LEFT JOIN, no new statement); `RoundResultEntry::$division` (`null\|RoundDivision{id,name,short}`), `$divisionRank`. Ranks per division via new `OfficialResultsRanking::rankWithin(array $results, array $groupKeyOf)` (pure). Round's subset: one cheap statement **only for rounds that have divisions** - flag read with the round (`EXISTS` subquery in the round query). | `RoundResultsStateGoldenTest`: keys `division`/`divisionRank` emitted only when the round has divisions |
| Results desk | Division column + chip filter (All / each / "No division"); rank cell "3 · S 1". `results_desk_controller.js` renders the column only when the state carries `divisions`. | state JSON unchanged without divisions |
| Export (`RoundResultsExporter`) | "Division", "Division rank" after "Rank" when the round has divisions. | export test unchanged without |
| Public round page (`GetPublishedRoundResults`, `OfficialEntrantsView`, `PublishedRoundEntry`, `templates/round_results.html.twig`) | Division badge + "2nd Senior 55+" under the rank; filter links `?division=<id>` (server-side; the filtered list shows the division rank as the big number, overall rank small). Filter row only when the round has divisions. Hidden/private rules unchanged (division computed before dropping). | `OfficialRoundResultsGoldenTest` unchanged; canaries: the filtered page is a new surface variant → `BlocklistCanaryTest`, `PrivateProfileCanaryTest`, `DraftCanaryTest` gain the `?division=` URL |
| Competitor page (`GetCompetitorPage`, `CompetitorPageEntry`) | Per round row: division label + division rank. Same statement (entries come from `OfficialEntrantsView`). | `CompetitorPageQueryBudgetTest` 4/5 unchanged |
| Name tags / tents (`GetCompetitionNameTags`, `CompetitionTagSheet`) | Entry tags (kind `e`) and line-up tags (`g`) print the division short label; person tags list "Solo: Senior 55+" when any entry has one. | `CompetitionNameTagsGoldenTest` unchanged |
| Displays | Leaderboard + ticker prop `division` (`'all'` default \| division id): `DisplayDesignSanitizer` + `assets/displays/schema.js` + `tests/fixtures/display-design-cases.json`; `GetCompetitionDisplayState:426` ranks within the division when set; editor offers the event's divisions. | default `'all'` = today's payload |
| Analytics studio | Entrant JSON `division` + `divisionRank` (keys only when the round has divisions); page filter chips; S2 fixtures extended. | `AnalyticsStudioQueryBudgetTest` unchanged |
| Participants sheet | Round tab column "Division" (select of the round's subset) when the round has divisions; People tab unchanged. `GetParticipantsSheetState` adds the division lists to the existing rounds statement + the entries' `division_id` (no new statement → `ParticipantsSheetQueryBudgetTest` 7 unchanged). Client: `assets/participants_sheet/` column + editor; `from` = shown value. | budget unchanged |
| Participants export | Per round with divisions: "<round> division" column. | unchanged without |
| Standings (later, D7) | `competition_standing.division_id` NULL; a standing with a division counts entrants whose entry carries that division in at least one counted round; "Create one standing per category" unchanged; organiser form gets a Division select when the event has divisions. | default NULL = today |
| Advancement | none in v1 ("top N per division" is a later preset). | - |

### 3.5 Participant import - the Division column (small, separate change)

Today `ColumnMapping::HEADERS` maps `division` / `divisions` to `ParticipantImportField::Rounds`
(`src/Value/ColumnMapping.php:41-42`, preview doc D5) - organisers who call their rounds "divisions" (WJPC lists)
must keep working.

- New `ParticipantImportField::Division = 'division'` and `DivisionInRound` (`division_in_round:<roundId>`, mirrors
  `TeamInRound`).
- **Header detection:** `ColumnMapping::detect($headers, $rounds, $divisions)` - `division`, `divisions`,
  `division name`, `age group`, `age category` → `Division` **only when the event has a division list**; otherwise
  `division(s)` stay `Rounds` (byte-identical for every existing event). `<round> division` / `division: <round>` →
  `DivisionInRound` (like `teamRoundOf()`). `fromQuery()` accepts `division` and `division_in_round:<id>` for rounds
  with divisions. The preview's field dropdown lists "Division" only when the event has divisions.
- **Values:** matched case-/accent-insensitively to a division's name or short label. `Division` applies to each of the
  row's entries in rounds whose subset holds it; `DivisionInRound` to that round only. Empty = leave as is (an import
  never clears a division; the sheet does).
- **Preview errors / warnings** (`ParticipantImportPreviewBuilder`, translations `participant_import.division.*`):
  unknown value → row error "Unknown division "Senor" - this event has: Regular, Senior 55+, Junior U16" (row not
  applied, as other row errors); a division the round does not use → warning, skipped for that round; pair/team members
  whose rows disagree → warning "Members of "Puzzle Pals" are in different divisions - the team's division is left as
  it is"; all members agree → the team gets it.
- **Files touched:** `src/Value/ColumnMapping.php`, `src/Value/ParticipantImportField.php`,
  `src/Services/ParticipantImport/Plan/PlanBuilder.php`, `PlanRow.php`, `PlanEntry.php`, `ParticipantImportApplier.php`,
  `ParticipantImportPreviewBuilder.php`, `SiteSnapshotReader.php` + `Plan/SiteSnapshot.php` (current divisions for the
  diff), preview template, `docs/features/competitions-management/participant-import-preview.md` (D5 + new D),
  `tests/Value/ColumnMappingTest.php`, `tests/Services/ParticipantImport/*`.
  **The participant-linking orchestrator is editing `ColumnMapping`, `ParticipantImportField`, `PlanBuilder`,
  `ParticipantImportApplier`, `SiteSnapshotReader` and `CompetitionParticipant` right now - this package starts only
  after its import package (L-import) is merged, rebased on it.**

---

## 4. Implementation packages

Each ≤ ~1 agent-day, own worktree, own `docker compose run` container, CI gates per package (phpstan, cs, paratest,
schema validate, cache warmup). New translation keys in `messages.en.yml` under the package's prefix; the 5 other
locales in package **H** before the merge to main. Goldens/pins/budgets listed in the intro must stay unchanged in
every package.

### Lane 1 - shared puzzles

| Id | Scope | Files | Tests | Acceptance |
|---|---|---|---|---|
| **S1 Rule + column** | `round_chosen` column + entity methods; `SolvingTimeRoundMatch`; resolver over it (`RoundResolution`); reconciler link/unlink rewritten; coverage-test classification; EXPLAIN + timing of the full reconcile on a prod-size dump | `Entity/PuzzleSolvingTime.php`, `Query/SolvingTimeRoundMatch.php`, `Services/RoundResults/*`, migration (diff), 4 coverage tests | new `SolvingTimeRoundMatchTest` (§2.5, resolver ⇔ reconciler parity), `RoundResultsReconcilerTest` + merge case; all existing round-results tests green unchanged | single-candidate times: same answer, same statement count; full reconcile ≤ +20 % |
| **S2 Lift the invariant** | §2.4 rows 8-14, 18-19; notices; internal API `sharedWithRounds`; wizard info line | handlers/controllers listed in §2.4, `GetCompetitionRounds`, wizard templates, `internal-api.md`, rule/doc files | flip `SetCompetitionRoundPuzzlesHandlerTest:58`, `RoundResultsLinkingTest:117`; `MoveRoundToCompetitionHandlerTest` unchanged; wizard + internal API cases | St. Paul set-up (3 team rounds, one puzzle) can be created through the wizard, the round pages and the internal API |
| **S3 Explicit round** | §2.4 rows 3-7, 15-16: named round on add (Add to my profile, API), edit keeps choice, unfinished with a named round, `LinkToRound` state on the round page, `ChooseSolvingTimeRound` + result detail picker | `PuzzleAddController`, `AddPuzzleSolvingTimeHandler` (one line), `SolvingTimeEventDerivation`, `UndoAutoRemovalHandler`, `KeepDuplicateCopyHandler`, `UnfinishedResult*`, `GetPublishedRoundResults`, `OfficialEntryProfileState`, new message/handler/controller, result detail template | handler tests per row; `OfficialRoundResultsGoldenTest` unchanged (guest); viewer test for `LinkToRound`; browser walk of add-to-profile on two sharing rounds (feedback: the add form must never break) | Add to my profile from Challenge and from Open #1 by the same player files each time in its own round; an ambiguous time can be placed from its detail |

### Lane 2 - divisions

| Id | Scope | Files | Tests | Acceptance |
|---|---|---|---|---|
| **D1 Model + organiser page** | entities, migration (diff), repositories, `SetCompetitionDivisions` + handler + `DivisionsInUse`, divisions page + hub card, internal API endpoint, duplicate/delete/convert coverage | `Entity/CompetitionDivision.php`, `CompetitionRoundDivision.php`, `CompetitionParticipantRound.php`, `CompetitionTeam.php`, `Message/SetCompetitionDivisions.php`, `MessageHandler/…`, `Controller/CompetitionToolkit/CompetitionDivisionsController.php`, `templates/competition_toolkit/divisions.html.twig`, `DuplicateCompetitionHandler`, delete handlers | handler test (limits, unique names, in-use refusal/confirm), `SerializedByLockMessagesTest`, `ConvertCompetitionForeignKeyCoverageTest`, duplicate test, `RecordRoundResultsStatementPinTest` unchanged | St. Paul's list + subsets saved; nothing else changes |
| **D2 Ranking, desk, export** | `OfficialResultsRanking::rankWithin()`, `GetRoundResultEntries`/`RoundResultEntry`, state JSON keys, desk column + filter, export columns | `Services/OfficialResultsRanking.php`, `Query/GetRoundResultEntries.php`, `Results/RoundResultEntry.php`, `RoundResultsStateController`, `results_desk_controller.js`, `Services/Export/RoundResultsExporter.php` | `OfficialResultsRankingTest` (within groups, ties, DNS), `RoundResultsStateGoldenTest` unchanged + a divisions state test, export test | desk shows overall + division ranks for SOLO with 3 divisions |
| **D3 Sheet column** | `SheetChangeOp::Division`, parser, `ParticipantRules`, applier branch, state, client column + "Fill empty with …" | `Value/SheetChangeOp.php`, `SheetChangesParser`, `ParticipantRules`, `ParticipantImportApplier` (**coordinate with linking**), `GetParticipantsSheetState`, `assets/participants_sheet/*` | sheet handler tests (three-way, wrong round subset, team vs solo), `ParticipantsSheetQueryBudgetTest` unchanged, JS harness for the column | organiser assigns divisions in the sheet; conflicts visible |
| **D4 Import Division** | §3.5 | §3.5 file list | `ColumnMappingTest` (old `division` → Rounds without a list; → Division with one; `<round> division`), planner/preview tests | an existing WJPC-style file imports exactly as before; a St. Paul file with a Division column fills entries |
| **D5 Public round page, competitor page, tags** | `OfficialEntrantsView` + `PublishedRoundEntry` division + rank, `?division=` filter, competitor page row, name tags label | `Query/OfficialEntrantsView.php`, `GetPublishedRoundResults.php`, `Results/PublishedRoundEntry.php`, `CompetitorPageEntry.php`, `GetCompetitionNameTags.php`, templates | goldens unchanged; canaries gain the filtered URL; `CompetitorPageQueryBudgetTest`; a public page test with divisions | St. Paul SOLO round page shows "Senior 55+" filter and division ranks |
| **D6 Displays + analytics studio** | leaderboard/ticker `division` prop (PHP + JS twin + fixtures), display state ranking, studio entrant keys + filter | `DisplayDesignSanitizer`, `assets/displays/schema.js`, `tests/fixtures/display-design-cases.json`, `GetCompetitionDisplayState`, `AnalyticsStudio*`, `assets/analytics-studio/*` | `DisplayDesignSanitizerTest` + `DisplaySchemaScriptTest` parity, display state test, studio budgets | a display can show "Senior 55+ top 10" |
| **D7 Standings division** (after the demo) | `competition_standing.division_id`, calculator input filter, organiser form, public page label | `CompetitionStanding`, `GetStandingResults`, `StandingCalculator` input, forms | `StandingCalculatorTest`, `CompetitionStandings*Test`, `PublicStandingsTest` | - |
| **H Translations + docs** | 5 locales for every new key; `.claude/rules/features/events.md` + `competition-toolkit.md` entries; `docs/TODO.md` loose ends | translations, rule files | `ClaudeInstructionsTest`, missing-translations check | no locale falls back to English |

### Order

```
Lane 1:  S1 → S2 → S3
Lane 2:  D1 → D2 → D5 → D6
              ↘ D3 (after the linking orchestrator's sheet/applier changes land) → D4 (after its import package)
Both:    H last; D7 after the demo
```

Lanes are independent (no shared file except `messages.en.yml` - append-only blocks, and `.claude/rules/features/events.md`
- only in H). For the 2 Dec demo the must-haves are **S1, S2, S3, D1, D2, D3, D5**; D4 if the linking import package is
in, otherwise divisions are set in the sheet or through the internal API.

### Risks

- **Reconcile cost** (S1): the 15-min cron over all times. Mitigation: evidence evaluated only for `n > 1`; measured on a
  production-size copy before merge; if over budget, scope the ambiguous branch to competitions that have a shared
  puzzle (`EXISTS` two rounds of one category with one puzzle) via a CTE.
- **Wrong link** - the one thing that must not happen. Mitigation: no stage uses a form save's moment; stages narrow,
  never pick on a tie; the parity test runs the resolver and the reconciler over every matrix row.
- **Entry changes do not trigger a reconcile.** Participant/entry/result writes (`ApplyParticipantSheetChanges`,
  import, `RecordRoundResults` - statement pin) do not record `CompetitionRoundsChanged`; a time saved *before* the
  organiser added the entry is placed by the cron within 15 min. Accepted (keeps `RecordRoundResultsStatementPinTest`).
  Times saved after the entries exist are placed at once by the add handler.
- **Hot files with the participant-linking work** (`ColumnMapping`, `ParticipantImportField`, `PlanBuilder`,
  `ParticipantImportApplier`, `SiteSnapshotReader`, `CompetitionParticipant`): D3/D4 wait and rebase; D1 deliberately
  puts the division on `competition_participant_round` / `competition_team`, not on `competition_participant`.
- **Add form** (S3): only the controller passes a round id and the handler passes it on; browser walk before merge.
- **Duplicate "Add to my profile"** when the player's own time is unlinked: covered by `LinkToRound` (row 15).

---

## 5. Open questions for Jan (with the default chosen)

1. **Allow sharing silently, or ask for a confirmation when a puzzle is added to a second round of a category?**
   Default: allow, with an info notice "Also used in round X" (organiser typos are rare; heats need it too).
2. **Moving a round into an event that already holds its puzzle in that category** - keep refusing? Default: keep the
   refusal (§2.4 row 9).
3. **Stage L (live stopwatch save within ±10 min of the round's start)** - acceptable evidence? Default: yes, only for
   `created_via = stopwatch`. Without it, live savers at a same-day pair of rounds stay unlinked until they choose.
4. **Unlinked times**: show them anywhere public (e.g. an "unassigned" list on the event)? Default: no - only the
   player's own result detail offers "Choose the round".
5. **Divisions per event with per-round subset, division on the entry** (not on the person). Default as written; a
   person can be Senior in SOLO and Regular in PAIRS.
6. **Entries without a division in a division round**: count in no division (default) or fall into a marked default
   division ("Regular")? Default: none + "Fill empty with …" in the sheet.
7. **Public filter vs split**: one ranked list with a division filter (default) or separate tables per division on the
   round page?
8. **Registration**: should self-registration ask for a division (with an age rule)? Default: not in v1; organisers
   assign in the sheet / import.
9. **Standings per division** (D7) - needed for St. Paul or SPWS? Default: after the demo; SPWS has no divisions.
10. **Eligibility checks** (e.g. Senior = 55+ by birth year): MSP has no birth dates. Default: no checks - the organiser
    decides who is in which division.

---

## As built: S1 (rule + column, 2026-11-17)

- **Column** `puzzle_solving_time.round_chosen BOOLEAN NOT NULL DEFAULT false` (migration `Version20261117215528`,
  generated by diff, additive). Entity: `$roundChosen` (private write scope), `chooseCompetitionRound()`,
  `changeCompetitionRound()` (clears the flag), `roundResolved(round, chosen)` - what every resolver caller applies.
- **The rule** `src/Query/SolvingTimeRoundMatch.php`: `sqlCandidates($timesSelect)` + `sqlAnswer()`. The times select
  has the same columns for the resolver (one row of parameters, `team` JSON written by `PuzzlersGroupDoctrineType` -
  the one membership source) and the reconciler (`puzzle_solving_time`). Evidence is computed in a `LATERAL` gated by
  `WHERE n > 1` (a one-time filter: never evaluated for a single candidate). A3 "consistent for every person" is a
  bit mask per round (bit 0 = owner, bit k = the k-th puzzler of the JSON; > 62 puzzlers share the last bit): a round
  qualifies when its mask equals the union over the remaining rounds. The round's local day uses
  `RoundTimezone::sqlResolve()` - `resolve()` written as SQL (PHP's zone list + `CountryCode::defaultTimezone()`), so
  exclusion (b) is exact rather than skipped; a zone PHP knows but Postgres does not is never passed to `AT TIME ZONE`
  (it would fail the whole reconcile) - (b) is skipped for it.
- Decisions inside A1-A4 (after the code review): exclusion (a) only for `created_via` form / stopwatch / API (see A1);
  "people with an entry" are counted over ALL candidate rounds, excluded ones too (a time saved before Open #1 began
  by a team entered only in Open #1 → no round, not Challenge), a qualifying round must not be excluded; a chosen
  round counts only while it is a round of the time's competition and category (`SolvingTimeRoundMatch::sqlChosen()`,
  the resolver the same in PHP - a time moved to another event is placed by the rule again); with nobody
  entered, exactly one remaining round is the answer (a time saved before Open #1 began → Challenge); a chosen round is
  kept even when an exclusion would drop it (A4 "while it is a candidate"); a DNS entry of *any* person of the time drops
  the round; the R stage compares the time with the entry of any person of the time in that round.
- **Resolver** `resolve($time, $named = null): RoundResolution` (round, chosen, candidates): the time's own
  `roundChosen` round counts as named, so an edit / Keep this one / Link my results keep a named round while it is a
  candidate (rows 5-6 come with S1 for free). A `$named` that is not a candidate costs a second statement and is
  ignored. `resolveFor()` keeps its signature (no evidence: a shared puzzle answers no round - S3 row 7).
- **A3b sticky link**: `sqlAnswer()` = COALESCE(the evidence's answer, the current round when it is a remaining
  candidate) for every time not decided by n = 1 or by a chosen round - resolver and reconciler alike (matrix rows:
  ambiguous + current candidate → kept; ambiguous + current excluded → none; evidence elsewhere → moved; the merge
  test keeps a time without evidence in its round).
- **Reconciler**: link statement = the answer for every time with a candidate (a round or NULL, flag kept or cleared),
  counted by `RETURNING`; unlink statement unchanged + `round_chosen = false`. Note: the A4 transition (chosen round
  lost, ≥ 2 candidates → unlinked, flag cleared) is one step - the next run treats the time as an ordinary derived one,
  so evidence may still place it later.
- **A4 writers**: `DeleteCompetitionRoundHandler`, `DeleteCompetitionHandler`, `DeleteCompetitionSeriesHandler`,
  `ConvertCompetitionToSeriesHandler`, `SeriesEditionReconciler` reset the flag in the same statement;
  `tests/RoundChosenResetCoverageTest.php` scans every string literal/heredoc under `src/`.
- **A5**: `RemovedResultSnapshot` key `round_chosen` (written only when true); `UndoAutoRemovalHandler` names the
  snapshot's round again when it was chosen.
- **A6**: `docs/features/competitions-management/shared-puzzles-dry-run.sql` - read-only (`BEGIN TRANSACTION READ ONLY` …
  `ROLLBACK`), generated from the rule, works without the new column; per-competition counts, every changed time, and
  the rounds sharing a puzzle today.
- Tests: `tests/Query/SolvingTimeRoundMatchTest.php` (the §2.5 matrix adjusted to A1-A4, resolver ⇔ reconciler parity on
  every row, statement count, named round, unfinished path, puzzle merge).
