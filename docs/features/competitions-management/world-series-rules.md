# Speed Puzzle World Series rules - format gaps (design)

Status: **design of record** (2026-11-17, reviewed - see Review amendments), architect pass over `origin/main` at `c72030417`. Nothing here is built.
Event: Speed Puzzle World Series (SPWS), Toronto, 2-5 June 2027; demo to the organisers ~2 December 2026.
Frame: `.claude/rules/features/competition-toolkit.md`, [standings.md](standings.md), [official-results.md](official-results.md),
[round-formats.md](round-formats.md), [seating.md](seating.md), [judge-offline.md](judge-offline.md),
[live-results.md](live-results.md), [competitor-pages.md](competitor-pages.md), [displays.md](displays.md),
`toolkit/STATUS.md` "Reliability hardening", `toolkit/R-review-and-plan.md` §1 + §4.1.

## Review amendments (binding - orchestrator, 2026-11-17, after the independent design review)

Where this section and the body differ, this section wins.

**Back to back (§5) - the chained round is NOT built now.** The review found four blockers in it (an old page's outbox
item is accepted with a set-level `chainAware` flag; mirroring round 1's Stop pauses challenge 2 for everyone still
solving; pairs/teams have no line-up key without competitor pages; half-built states between W4a/b/c). Its own
should-fix #4 shows the simpler equivalent: **a back-to-back block = ONE round "Solve all 2 (order recorded)"**
(`puzzle_format = pick`, K = N = 2, existing), recorded with per-puzzle splits (`result_puzzles[].atSeconds`, cumulative
on the round's one shared stopwatch). Challenge 2's own time = split₂ − split₁ - exactly "challenge 2 starts when the
challenge-1 time is officially recorded, disassembly counts" (gap 0), the organisers' likely answer; the round total =
T1 + T2 = the cumulative standing's input, so the World Series standing is correct with no new scoring. Nothing on the
stopwatch, judge outbox or proposal path changes semantics - the chaos suite keeps its meaning.
- **W4 (replaces W4a-c) - "challenges" read side of a solve-all block:** per-puzzle (challenge) time = its split minus
  the previous split, shown as "Challenge 1 / Challenge 2" (= the round puzzle's label, editable by the organiser) on the
  public round results page (chips exist - add the per-challenge time + a per-challenge rank view: rank by that puzzle's
  own time among entrants who finished it, unfinished after; `?challenge=<label>` view or a second table), results desk,
  export ("Puzzle N time" columns next to the existing split columns), the standing page (a block round shows its two
  challenge times in the round cell's detail) and the displays' results element (option "rank by challenge" if cheap,
  else documented v2). Penalties per challenge: missing pieces per puzzle exist (T1); the standing total stays the
  round's (limit + penalty × all missing of both puzzles at time's up = a no-show of challenge 2 when challenge 1 was not
  finished - matches the SPWS rule when the block limit = L1 + L2).
- **W5 - judge "Finished now" per puzzle** (per-puzzle split tap on solve-all/pick rounds, `atSeconds` from the tap's
  moment on the server-synced clock, same outbox group as the `result` change - rule 7) - taken over from the v2
  orchestrator's item 5 (main session, 2026-11-17). Built on top of v2's judge-speed packages J1+J2 (same files); new
  logic in new pure modules with node harnesses, small hooks in `live_results_controller.js`; chaos suite re-run before
  the push.
- **Assumption (unconfirmed by SPWS):** challenge 2's time includes taking puzzle 1 apart and opening bag 2 (split₂ −
  split₁). If the organisers say otherwise, a per-round fixed deduction or a "bag opened" tap would be added - NOT built
  now.
- Time limit: one limit for the block (the SPWS schedule gives 90 min for challenges 1+2). Open question to the
  organisers: per-challenge limits? If yes → the chained-round design (§5 + review blockers fixed) becomes v2.
- §5 stays in this document as the v2 design with the review's blockers B1-B4 listed under it (see "Chained round - v2
  notes" at the end).

**W1 no-show (`as_zero_pieces`) - amendments:**
- W1-a. Line-ups whose composition changed between rounds (substitutes) form separate standing rows; with
  `as_zero_pieces` both would be ranked with heavy penalties. The organiser standings page shows a warning "N line-ups
  appear in only some rounds - check substitutes" when `as_zero_pieces` is on and such rows exist; `t:<teamId>` keys
  (teams without members) are never scored as no-shows (they stay missing/incomplete).
- W1-b. Publishing a round counted by an `as_zero_pieces` standing: the publish readiness line says "N entries without a
  result will count as no-shows in <standing>".
- W1-c. Defect pieces: help line only (Q-O3 open); no new field.
- The v2 orchestrator has confirmed: W1 owns `as_zero_pieces` + `StandingScoreStatus::NoShow` ("no_show"); v2's public
  standing JSON passes statuses through.

**W2 unboxed - amendments:**
- W2-a. Surfaces that use the SQL reveal rule, not `RoundPuzzlesVisibleNow`, must honour unboxed too: the event page
  "Puzzles" section (`GetPuzzleOverview::byTagId()` from `EventPagePuzzles.php`), `GetCompetitionPuzzles` (event page,
  `wjpc_hub.html.twig`), `marketplace/event_offers_picker.html.twig`, `results_import/_event_card.html.twig` - one
  `RoundPuzzleReveal::sqlImageHidden()` (the rule lives in `RoundPuzzleReveal`, `RoundRevealMomentComputedOnlyHereTest`).
  The PHP branch in `RoundPuzzlesVisibleNow` must apply unboxed outside the `if ($hideUntilRoundStarts)` gate (a
  non-secret unboxed round). All of them in `UnboxedRoundTest`.
- W2-b. A catalogue puzzle keeps its picture in the catalogue (inherent; organiser help says "use a new secret puzzle for
  a truly unboxed challenge").

**W3 same tables - amendments:** the optional `CopyRoundTableNumbers` confirm hash covers each target's entry set and
table count; the proposal path ("Same tables as round X" source) is the must-have.

**Order:** W1, W2, W3 independent; W4 (read side) independent of W5; W5 (judge splits) after v2's J1+J2 land on main.

Binding conventions for everything below: CQRS (controllers dispatch, handlers write, repositories never flush), opt-in
per competition / round / standing (an untouched competition renders byte-identically and runs the same statements -
goldens `RoundResultsStateGoldenTest`, `OfficialRoundResultsGoldenTest`, `CompetitionNameTagsGoldenTest`,
`RecordRoundResultsStatementPinTest`, `DetailPagesQueryBudgetTest` stay unchanged), migrations additive and generated by
`doctrine:migrations:diff`, every new key in all 6 locales, no feature flags.

## The SPWS format in toolkit terms

| SPWS | Toolkit |
|---|---|
| Individual / Pairs (2) / Teams (4) championships | three categories (`RoundCategory` solo / duo / team), one `sum` standing each ("Create one standing per category") |
| Every challenge, no heats | one round per challenge, everybody entered in every round of their category |
| Winner = lowest cumulative time, live after each challenge | standing `order_rule = total_only`, published rounds count (public page "Provisional · after 3 of 5 rounds") |
| Finished, pieces missing: +5 s per piece | `result_missing_pieces` + standing `penalise_finished_missing = on`, penalty 5 |
| 2+ adjacent missing = manufacturing defect, no penalty | the judge does not record those pieces (§4.3) |
| Time's up: limit + 5 s per remaining piece (3+ connected = placed) | "Didn't finish" with the judge's missing count (T1) → `result_pieces_placed`; scored limit × 60 + 5 × missing - **already built** |
| No-show: limit + 5 s × every piece, stays ranked | **new**: `missing_rule = as_zero_pieces` (§4) |
| Challenges 1+2 back to back, challenge 2 starts per entrant | **new**: chained round (§5) |
| The same table for every challenge | **new**: seating source "Same tables as round X" (§6); table tents already event-wide |
| Some challenges unboxed | **new**: round switch "Unboxed" (§7) - `ImageOnly` does not cover it |

---

## 1. Findings (what is built, file:line, the gap)

### 1.4 No-show + penalties

- `StandingCalculator::score()` (`src/Services/CompetitionToolkit/StandingCalculator.php:188-246`):
  - finished (`:190-206`): `seconds + (penaliseFinishedMissing ? penalty × missingPieces : 0)` - the SPWS
    "finished with pieces missing" rule **maps exactly** with the switch on.
  - unfinished (`:208-226`): `round->minutesLimit × 60 + penalty × (piecesCount − piecesPlaced)` - uses the **round's
    time limit**, never the entry's time. The SPWS time's-up rule **maps exactly** (the judge's "Didn't finish" mode
    types MISSING pieces, `official_results_missing.js`, placed = pieces − missing; the judge counts singles and pairs
    as missing, 3+ connected as placed - a counting instruction, no code). A round without a limit cannot score it
    (`UnfinishedWithoutLimit`) - every SPWS round must have a limit.
  - DNS / no result / not in the round (`:228-235`) → no score → `missing`.
- `StandingMissing` (`src/Value/StandingMissing.php`) has only `Incomplete`: an entrant with a missing round is listed
  after every complete entrant **without a rank** (`StandingCalculator::compare()`, `ranked()`). SPWS wants the no-show
  ranked with limit + 5 s × every piece. **Gap.**
- That exact rule is already designed and deferred: `toolkit/A-round-formats.md` §3.3 "`as_zero_pieces`: a missing
  round scores like unfinished with 0 pieces placed (limit + P × all pieces). That needs a limit, else it stays
  missing." (R §3, `docs/TODO.md:48`, standings.md "Deferred to v2"). **SPWS no-show = `as_zero_pieces`; there is
  nothing to invent and no second enum case to add** - see §4.1 for the coordination with the v2 orchestrator.
- Who is "missing" where: organiser view (`GetStandingResults::organiserView()`, `src/Query/GetStandingResults.php:57-96`)
  reads every entry of every round through `GetRoundResultEntries` (an entry without a result = `NoResult`, DNS =
  `DidNotStart`); a round counts once anything is recorded (`anyRecorded()`), published or not. Public view (`:98-192`)
  reads only published rounds through `OfficialEntrantsView` (ranked results only) - a no-show is simply absent →
  `NotInRound`. So "no-show" must cover `DidNotStart`, `NoResult` and `NotInRound`, but `NoResult` in a round that is
  still being recorded is "not yet", not "no-show" (§4.2).
- Pieces of an absent entrant: `GetStandingResults::publicPiecesCount()` (`:269-280`) - `pieces_total` for a `total`
  round; a `pick` round needs the entry's chosen puzzles, which an absent entrant has none of (§4.2).
- The adjacent-defect rule: there is one count per finished entry (`result_missing_pieces`, official-results.md
  "Finished with pieces missing") and one switch per standing. No field tells "defect, not penalised" from "missing,
  penalised". **Gap, solved by a recording rule, not a field** (§4.3).

### 1.5 Back-to-back challenges

- A round has exactly one stopwatch (`CompetitionRound::$stopwatchStartedAt/$stopwatchStatus/$stopwatchStoppedAt`,
  `src/Entity/CompetitionRound.php:88-99`; writers `startStopwatch()` `:439`, `scheduleStopwatchStart()` `:484`,
  `cancelScheduledStart()` `:520`, `stopStopwatch()` `:538`, `endStopwatch()` `:550`, `resetStopwatch()` `:573`; handlers
  `StartRoundStopwatchHandler`, `ScheduleRoundStartHandler`, `CancelRoundStartHandler`, `StopRoundStopwatchHandler`,
  `ResetRoundStopwatchHandler`). A pause moves `stopwatchStartedAt` forward by the pause (`:457-466`). Nothing links two
  rounds; nothing gives an entrant its own start. **Gap.**
- Judge "Finished now" (`assets/controllers/live_results_controller.js:2751-2783`): `tappedAt = serverNow()` (server-synced
  monotonic clock, `round_stopwatch_clock.js` `createServerClock()`), `elapsedSeconds(tappedAt)` (`:2350-2358`) =
  `floor((tap − startedAtMs) / 1000)` of the round's own stopwatch. Only `seconds` is saved - **the tap's wall-clock
  moment is not stored server-side** (it lives only in the tab draft, `live_entry_draft.js`, for a restored confirm
  step). The round-1 "finish moment" is therefore only derivable as round-1 start + round-1 seconds (whole seconds), or
  - better, see §5.1 - as "round-1 seconds on the shared clock".
- Time's up on the judge card (`roundTimesUp()`, `:2800-2807`) and the clock line (`tickClock()`, `:2361-2414`) use the
  round's stopwatch and `round.minutesLimit` - one moment for every entrant.
- Competitor page: clock = `stopwatchView()` of the round's stopwatch (`competitor_page_live.js:71-90`); a proposal is
  the tap moment and the server derives the seconds from the stored start: `ProposeRoundTimeHandler.php:158`
  `proposedSeconds = intdiv(tappedAtMs − startedAtMs, 1000)`, timing refusals `timingRefusal()` `:180-215` (`times_up` =
  start + limit). All per round, never per entrant. **Gap.**
- Offline: the outbox is one IndexedDB store per signed-in player for **all** rounds (`official_results_outbox.js`
  header, `OUTBOX_DATABASE`), and `shownValue(entry, field, outboxItems)` (`official_results_live.js:386`) is the one
  "what the card shows" rule - so a round-2 page on the same device can see a round-1 save still in the outbox. Snapshots
  (`live_entry_snapshot.js`) store the state answer per round (prefetch ±12 h, max 4) - round 1 and 2 both.
- Entries carry their cross-round identity already: `RoundResultEntry::$participantId` (solo) and `$lineupId`
  (`CompetitionLineupId`, exact member set; `src/Results/RoundResultEntry.php:33,64`).
- Live updates: `OfficialResultsSubscription::forRound()` (`src/Services/OfficialResultsSubscription.php:50`) issues a
  token for a list of topics - adding the source round's topic costs nothing on the server.
- Tag landing: `LiveResultsCurrentRound::pick()` (`src/Services/LiveResultsCurrentRound.php:25-38`) picks the latest
  started running round - two rounds started at the same moment tie. **Gap** for back-to-back (§5.6).

### 1.6 Same table for every round

- Table numbers are per round (`HasOfficialResult::$tableNumber`, unique per round in the write path). The seating
  proposer (`SeatingProposer::propose()`, sources `src/Value/SeatingSource.php`: `earlier_rounds`, `msp_times`,
  `random`, `name`) never copies tables - `earlier_rounds` orders by results. **Gap** (small).
- Printing is already event-wide where it matters: table tags kind `t` = "a card per table for the whole competition"
  (competitor-pages.md table, `GetCompetitionTableTags::usedNumbers()`), tents layout `?layout=tents`; line-up tags `g`
  = one tag per exact member set across rounds; person tags `p` per participant. With identical tables in every round
  one print run covers the event. **No gap.**
- Three rounds running at once with overlapping table numbers make a table tag ambiguous
  (`CompetitionTagResolver::resolve()` picks among the rounds using that table by `LiveResultsCurrentRound::pick()`).
  SPWS must give each championship its own number range (seating already supports `&first=201`). A recommendation, no
  code.

### 1.7 Unboxed rounds

`PuzzleHideMode::ImageOnly` (`src/Value/PuzzleHideMode.php`) means: **before** the round's reveal the puzzle is shown by
name without its picture; **at** the reveal the picture comes out (`RoundPuzzlesVisibleNow.php:136-147`: `imageHidden =
revealAt === null || now < revealAt`). So during the round the box image is **shown** everywhere - `ImageOnly` is the
opposite of "unboxed". The only workaround (manual reveal never made + `ImageOnly`) shows the name before the round,
which a secret SPWS challenge cannot accept. **Gap.**

Surfaces and whether they honour a hidden picture (i.e. would honour an "unboxed" rule placed in `RoundPuzzlesVisibleNow`):

| Surface | Source of the picture | Honours `imageHidden` / null image |
|---|---|---|
| Displays + overlays payload | `GetCompetitionDisplayState.php:100` `imageUrl` only when `puzzleImage !== null` (via `RoundPuzzlesVisibleNow`) | yes |
| Competitor page | `CompetitorPageRound.php:112-121`, `templates/competitor_page/_puzzle.html.twig:3-9` (eye-slash tile) | yes |
| Analytics studio | `AnalyticsStudioStateBuilder.php:148` (image only when not null), visibility hash `AnalyticsStudioVisibility.php:27` includes `imageHidden` (cache keys follow) | yes |
| Judge chips (pick rounds) | `RoundPuzzleFormats.php:85-86` image URL only when `puzzleImage !== null`; `assets/live_entry_puzzles.js:81` | yes |
| Event / edition page rounds | `GetEditionRounds.php:91-95` → `templates/event_parts/_round_puzzle.html.twig:12-31` ("picture hidden" note) | yes |
| Round results page | `RoundResultsPageBuilder` → `GetEditionRounds`; `templates/round_results/_puzzle_card.html.twig:12` placeholder for a null image; difficulty corner only with an image (`:15`) | yes - **but** the card links `puzzle_detail` (`:9`, `:20`) |
| Published entry "Add to my profile" per puzzle | `PublishedEntryPuzzle.php:100` (puzzle id only when the image is not hidden) | yes |
| Live result entry (judge, `total` rounds) | no puzzle pictures | n/a |
| Public puzzle detail page | `PuzzleDetailController.php:94` - `puzzle.hide_image_until` only | only for a puzzle the competition keeps secret site-wide |
| Catalogue, recent activity, comparisons, insights, exports | `puzzle.hide_image_until` (`GetRecentActivity.php:273`, `GetComparisonPuzzles.php:47`, `ExportablePuzzle.php:26`, `InsightsPuzzleRef.php:65`, `PuzzleOverview.php:73`) | only for a site-wide secret |

Site-wide hiding is `SecretPuzzleHides::hideOf()` (`src/Services/SecretPuzzleHides.php:224-260`) over the rows with
`CompetitionRoundPuzzle::$hidesEverywhere` (`src/Entity/CompetitionRoundPuzzle.php:66-71`): **only a puzzle that was not
public when the round made it secret** is hidden site-wide; a public catalogue puzzle never is. So for an unboxed
challenge on an existing catalogue puzzle, its catalogue page keeps the box picture - inherent (the name is out, the
picture is one search away); the toolkit surfaces are what must hold it (§7).

### 1.8 Scale

350 entrants per round, three rounds at once with 150+ teams each. Measured today (STATUS.md "Reliability hardening"):
save p95 42 ms, event lock ~20 ms per save. Per-save cost of each choice below is listed in §8.

---

## 2-7. Design per item

## 4. No-show = `missing_rule = as_zero_pieces`

### 4.1 Model (one enum case, shared with v2)

- `src/Value/StandingMissing.php`: add `case AsZeroPieces = 'as_zero_pieces';` - the value name from A §3.3, unchanged.
  The column `competition_standing.missing_rule` is already `varchar(32)` with an enum type
  (`CompetitionStanding.php:85-86`) - **no migration**.
- `src/Value/StandingScoreStatus.php`: add `case NoShow = 'no_show';` - `scores()` true, `isMissing()` false;
  `timeSeconds` = limit × 60, `missingPieces` = every piece, `penaltySeconds` = P × pieces.
- **Coordination with the v2 orchestrator** (owner of the standings overlay, public standing JSON and bracket stages):
  `as_zero_pieces` is the SAME rule as the SPWS no-show. Exactly one implementation: whoever lands first adds the case;
  the other rebases onto it and does not add a sibling. The v2 consumers (overlay element, `public_standing_state`) must
  treat `perRound[].status = "no_show"` as a scored cell (time + pieces, like `unfinished`). Files both sides touch:
  `StandingMissing.php`, `StandingScoreStatus.php`, `StandingCalculator.php`, `standing/_total.html.twig`,
  `standing/_rules.html.twig`, `StandingTable::toArray()`. Hand the v2 orchestrator this section.

### 4.2 The rule (`StandingCalculator::sum()`)

`StandingRoundInput` gains two fields (constructor defaults keep every existing caller):

```php
// The round is final: its absent entrants are no-shows (as_zero_pieces). Public view: always true (only published
// rounds count). Organiser view: the round's results are published.
public bool $final = true,
// The pieces an entrant who did not appear would have had to place (as_zero_pieces); null = unknown
public null|int $noShowPiecesCount = null,
```

With `missing = AsZeroPieces`, a counted round gives an entrant **without a score** in it:

| Status in the round | Score |
|---|---|
| `DidNotStart` (the judge's explicit "Did not start") | `NoShow` - always (an explicit record) |
| `NoResult` (entered, nothing recorded), `NotInRound` | `NoShow` when `final`, else stays missing ("not yet") |
| `UnfinishedWithoutLimit`, or no limit at all | stays missing (as A §3.3: "needs a limit") |
| `NotCounted` | nothing (unchanged) |

`NoShow` score = `minutesLimit × 60 + P × noShowPiecesCount` (unknown pieces → no penalty part, like unfinished).
Ordering stays the configured `order_rule`; with every round scored the entrant is `complete` and ranked. An entrant
whose **every** score is `NoShow` is left out (extends "an entrant with no score at all is left out") - this keeps the
organiser view equal to the public view (an absentee in every round is in the organiser's entries but never in a
published round) and pinned by `PublicStandingsTest`'s "ranks = preview" assertion. With `Incomplete` nothing changes
(goldens).

Why `NoResult` waits for `final`: the organiser preview counts a round as soon as anything is recorded - 340 entrants
still solving would otherwise show as no-shows with huge totals in the live preview.

`noShowPiecesCount` (`GetStandingResults`):
- `total` round: `pieces_total` (organiser) / the same in `publicPiecesCount()`.
- `pick` round: the K **largest** puzzles' pieces (`RoundEntryPuzzles::roundPiecesBound()` already exists for the bound -
  check it returns the largest-K sum; else add `largestPiecesSum()`) - a no-show is never better than anyone who sat
  down. Open question Q-J4.

`organiserView()` passes `final: $round['published']`; `publicView()` keeps the default `true`.

### 4.3 How the judge records each SPWS case

| Case | Judge page | Stored | Standing (penalty 5, `total_only`, switch on, `as_zero_pieces`) |
|---|---|---|---|
| Finished, complete | "Finished now" (or typed) → Save | `result_seconds` | seconds |
| Finished, 1 single piece missing | "Finished now" → "+ Pieces missing" = 1 → Save | `result_seconds`, `result_missing_pieces = 1` | seconds + 5 |
| Finished, 2+ adjacent pieces missing (defect) | "Finished now" → Save, **pieces not recorded** | `result_seconds` | seconds |
| Finished, 2 adjacent (defect) + 1 single elsewhere | "+ Pieces missing" = **1** | `result_missing_pieces = 1` | seconds + 5 |
| Time's up | "Didn't finish" (opens by itself after the limit) → type MISSING pieces, counting singles and pairs as missing, groups of 3+ connected as placed | `result_pieces_placed = pieces − missing` | limit × 60 + 5 × missing |
| No-show | "Did not start" (or nothing - after publishing it counts) | `result_did_not_start` / none | limit × 60 + 5 × all pieces, ranked |

Decision: **no new field for defect pieces.** "Pieces missing" means "pieces that cost the penalty under this event's
rules"; the defect case is a judge's ruling made at the table, before typing. Cost of the alternative (a second count
`result_defect_pieces`, a second field in `RecordRoundResults`, judge/desk/sheet/export UI, 6 locales) is not worth a
number nobody ranks by. One text change makes it explicit: the "+ Pieces missing" field on the judge card and desk gets
the help line `live_results.finished_missing_help` "Only pieces that cost a penalty under your rules" - shown only when
some standing of the round's category has `penalise_finished_missing` on (the state answer gets
`round.penalisedMissing: true`, additive, absent otherwise - goldens kept). Open question Q-O3 (does the organiser want
the defect visible?).

SPWS standing settings (document in the organiser help + the demo script): penalty 5 s, order "total only", "pieces
missing from a finished puzzle are penalised" on, missing rounds "count as nothing placed (limit + penalty × all
pieces)".

### 4.4 UI, JSON, texts

- Standing form (`add_/edit_competition_standing`): the "Missing rounds" choice gets its second option
  `competition_toolkit.standings.missing_rule.as_zero_pieces` = "Count as nothing placed: time limit + penalty × all
  pieces" (the first: "List after the complete entrants, without a rank"). "Create one standing per category" keeps the
  default `incomplete`.
- `standing/_rules.html.twig`: one more sentence when `as_zero_pieces`: "A round without a result counts as the time
  limit + 5 s × all pieces."
- Round cell: `NoShow` renders "No-show" + the time (limit + penalty) like an unfinished cell; `_total.html.twig`
  unchanged (it already sums time + penalty + pieces).
- Organiser JSON (`StandingTable::toArray()`): `perRound[].status = "no_show"` (additive; existing values unchanged).
- 6 locales for every new key.

### 4.5 Tests

`StandingCalculatorTest` (no-show scored, ranked; `NoResult` not final → missing; DNS not final → scored; no limit →
missing; all-no-show entrant left out; pick round largest K), `CompetitionStandingsTest` (form round-trip, duplicate
copies the rule), `PublicStandingsTest` (published round with an absentee: same rank/total as the preview).

---

## 5. Back-to-back challenges: the chained round

### 5.1 One formula serves both organiser answers

Let *E(t)* be the elapsed time of the **shared clock** (round 1's stopwatch, pauses excluded) at a tap *t*, and *T1* the
entrant's recorded round-1 seconds. Then:

- Model A "challenge 2 starts for each entrant when their round-1 time is recorded" (+ optional fixed offset *o*, e.g. a
  disassembly allowance): `T2 = E(t) − (T1 + o)`.
- Model B "one stopwatch for both, time 2 = total − time 1": `T2 = E(t) − T1` = model A with *o* = 0.

They are the same arithmetic. Using the shared clock's **elapsed** (not wall-clock moments) is also the only
pause-correct form: a pause moves `stopwatchStartedAt` forward (`CompetitionRound.php:457-466`), so "tap − (start + T1)"
with the moved start stays right, while "tap − stored round-1 tap moment" would count the pause. Therefore: **no stored
tap moment is needed**; the entrant's start on the shared clock is `S = T1 + o` (seconds).

Round-1 outcomes → `S`:
- finished: `S = T1 + o`;
- didn't finish (time's up): `S = L1 × 60 + o` (round 1's limit - the moment they stopped);
- DNS / nothing recorded: no start - "Finished now" on round 2 refuses ("Record challenge 1 first"); a typed time is
  still accepted (the judge's own stopwatch).

The organisers' third possible answer - "challenge 2 starts when bag 2 is opened, after disassembly, not counted" - is
a per-entrant moment the system cannot derive. Default covers it approximately with the fixed offset; the exact form is
the optional extension §5.8 (a "Start challenge 2" tap that stores `S` itself). Same column, same arithmetic.

### 5.2 Model

Migration (additive, by diff):

- `competition_round.starts_after_round_id` uuid null, FK `competition_round(id)` **ON DELETE SET NULL**, index.
  "This round's challenge starts for each entrant after their result of that round."
- `competition_round.chain_offset_seconds` smallint not null default 0 (0..600).
- `result_chain_start_seconds` int null on both entry tables (`HasOfficialResult`): the entrant's start of this round on
  the shared clock (`S`) that the recorded time was measured from. Null = typed / not chained.
- `competition_time_proposal.chain_start_seconds` int null: the `S` the proposal's seconds were computed with.

`CompetitionRound` (named methods, not property writes from outside):
`chainAfter(CompetitionRound $source, int $offsetSeconds)` / `unchain()`; `isChained(): bool`.

Rules (`ChainRoundRefused::$reason`): `same_round`, `other_event`, `other_category`, `online` (either round),
`source_chained` (depth 1 - a chain of three is not needed by SPWS: Pairs 1+2, 3+4, 5; Teams 1+2, 3+4), `already_chained`
(the source already has a follower - one follower per source keeps "the round after" unambiguous), `started` (either
round's stopwatch ran - chaining changes how times are measured), `stopwatch_reveal` (the chained round's secret puzzles
use `RoundPuzzleReveal::Stopwatch` - see §5.3), `invalid_offset`.

### 5.3 The shared clock = mirrored stopwatch

Chained round's stopwatch **mirrors** its source's: every source stopwatch write is applied to the follower in the same
handler and transaction. So every existing reader of a stopwatch (displays, projector page, competitor page, analytics
studio "before the original start", `LiveResultsCurrentRound`, reveal moments, `RoundClock` hashes) sees the shared clock
with no change - exactly "the display timer shows the shared clock".

- `StartRoundStopwatchHandler`, `ScheduleRoundStartHandler`, `CancelRoundStartHandler`, `StopRoundStopwatchHandler`,
  `ResetRoundStopwatchHandler`: after the source's write, `CompetitionRoundRepository::followerOf($roundId)` (one
  indexed lookup; `null` for every round of every existing event - 1 extra statement per stopwatch action, never per
  result save) and the same entity method with the same moment. The controllers publish the stopwatch topic for both
  rounds after the commit (`AbstractRoundStopwatchController`).
- The follower's own stopwatch controls (stopwatch control page, results overview) are replaced by "Follows Round 1's
  stopwatch" + a link; its handlers refuse `RoundFollowsAnotherStopwatch` (a stale page).
- Reveal: the mirrored start **never reveals** - the tick's confirmation hash (`SecretRevealPreview::stopwatchStartHash()`)
  covers only the source's list, and an unconfirmed reveal is the one disaster the toolkit forbids. A chained round's
  secret puzzles use automatic / scheduled / manual reveal (the reveal-mode form hides "Stopwatch" for a chained round;
  chaining refuses `stopwatch_reveal`). Open question Q-J6.
- Shared-clock limit: the follower's own `minutes_limit` is **its challenge's limit per entrant** (counted from `S`), so
  the shared clock of a chained round shows **no limit** - every builder of its public stopwatch state passes no limit:
  `RoundClock::fromRound()`, `RoundStopwatchState::ofRound()`, and the SQL-built ones (`GetRoundStopwatchState.php:49`,
  `GetCompetitorPage.php:261`, `GetCompetitionDisplayState.php:148`, `AnalyticsStudioRound.php:72`) through one helper
  `RoundStopwatchLimit::sql('r')` = `CASE WHEN r.starts_after_round_id IS NULL THEN r.minutes_limit END`. A display
  pinned to the follower counts up without "Time's up"; a display following the source shows the source's limit as
  today. Standings keep using `minutes_limit` (time's up of challenge 2 = L2 × 60 + penalty - per entrant, consistent).
  Open question Q-O2 (per-challenge limit vs one block limit).

### 5.4 Settings and organiser UI

- Toolkit hub card "Round formats" (or the round edit form - implementer's choice, toolkit hub preferred: opt-in, behind
  `COMPETITION_TOOLKIT_MANAGE`): per round "Starts after: [none / Round X]" + "Fixed gap (seconds)" with the help text
  "Back to back: each entrant's time for this round starts when their result of Round X is recorded (plus the gap). One
  clock runs for both rounds."
- Message `ChainCompetitionRound(roundId, sourceRoundId|null, offsetSeconds)` → handler under
  `CompetitionParticipantsLock` (rounds' shape; the same key as `MoveRoundToCompetition`/`DeleteCompetitionRound`).
- `DuplicateCompetitionHandler` copies the chain (round id map) and the offset; `MoveRoundToCompetition` refuses a
  chained round or a source with a follower; deleting the source → FK SET NULL (the follower keeps its stopwatch state).
- Event pages: nothing new (a chained round is a round).

### 5.5 Judge page (live entry) and results desk

State answer (`official_results_round_state`, `RoundResultsStateController`), **only for a chained round** (every other
answer keeps its shape - `RoundResultsStateGoldenTest`):

```json
"chain": {
  "sourceRoundId": "…", "sourceName": "Challenge 1", "sourceMinutesLimit": 40, "offsetSeconds": 0,
  "sources": { "p:<participantId>": {"ref": "participant_round:…", "result": {"seconds": 1812}},
               "l:<lineupId>": {"ref": "team:…", "result": {"piecesPlaced": 480}} }
}
```

- `sources` = the source round's entries (`GetRoundResultEntries::forRound(source)` - +1 statement on a chained round's
  state only; 350 rows), keyed by the cross-round identity the entries already carry (`participantId` / `lineupId`),
  value = `RoundEntryResult` wire format. Referee masking: no names, nothing private - only keys, refs and results.
- `mercure` = `OfficialResultsSubscription::forRound()` with the source's results topic added (organiser or referee
  variant) - the page merges source-round `official_results.entries` updates into `chain.sources` (route by `roundId`).
- Offline: the snapshot is the state answer (chain included); the source round's pending saves are in the same outbox
  store - the page overlays them with `shownValue(sourceEntry, 'result', outbox.all())`.

New pure module `assets/official_results_chain.js` (DOM-free, node harness `tests/official-results-chain-harness.mjs`
driven by `OfficialResultsChainScriptTest`; imported by `live_results_controller.js`, the desk and the competitor page -
never re-implemented):

```js
// S in seconds on the shared clock, or null (no source result yet)
export function chainStartSeconds(chain, entry, outboxItems) { … }      // finished → T1 + o; unfinished → L1×60 + o
export function chainedElapsed(sharedElapsedSeconds, startSeconds) { … }  // max(0, E − S)
export function chainedTimesUp(sharedElapsedSeconds, startSeconds, minutesLimit) { … }
export function chainKey(entry) { … }                                     // 'p:' + participantId | 'l:' + lineupId
```

Judge card on a chained round:
- Header line under the clock: "Challenge 1: 30:12 · this challenge 12:47" (running, per entry); before the source result:
  "Waiting for Challenge 1's result".
- "Finished now": `tappedAt` exactly as today (server-synced monotonic tap), `E = elapsedSeconds(tappedAt)` on the
  mirrored stopwatch, `S = chainStartSeconds(...)`; `S === null` → the existing "finishedNowStopped"-style refusal with
  `live_results.chain.record_source_first`; else review `{seconds: E − S}` (≥ 1, else the same refusal as `elapsed < 1`).
  The confirm text shows "12:47 (since challenge 1 at 30:12)".
- Save sends `result` + `chain_start` (`to: S`) as one outbox group (`enqueueGroup()`, like `result` + `missing_pieces`).
  A typed time sends `result` only (`chain_start` untouched).
- Time's up default ("Didn't finish" opens by itself, `opensUnfinished()` at `:2607`): `roundTimesUp()` becomes per entry
  on a chained round - `chainedTimesUp(E, S, round.minutesLimit)`.
- Tab draft (`live_entry_draft.js`) stores `S` with the `finishedNow` value, so a restored confirm step saves the same.

Write path (`RecordRoundResults`, owner lane A files):
- New `RoundResultField::ChainStart = 'chain_start'`, three-way `from`/`to` (int|null) like `missing_pieces`; allowed
  only on a chained round (`chain_start_not_chained`), 0..86399 (`invalid_chain_start`); grouped with `result` like
  `missing_pieces` (`group_refused`). `recordResult()` clears `result_chain_start_seconds` with any non-finished result.
  A set without the field runs exactly today's statements (`RecordRoundResultsStatementPinTest`).
- **Stale-page guard**: a page built before chaining existed would save the SHARED elapsed as round 2's time - silently
  wrong. Every updated writer (judge, desk, sheet) sends the set-level flag `chainAware: true` (like `expectedPlayerId`);
  a set touching a **chained** round without it is refused as a whole, `client_outdated` (the outbox keeps the change;
  the page says "This page is out of date - reload it"). Unchained rounds never look at the flag.
- A proposal confirmation (`proposalId` on the `result` change) copies the proposal's `chain_start_seconds` server-side.

Results desk (round 2): a column "From" (`S`) and a warning when the stored `result_chain_start_seconds` differs from
`chainStartSeconds()` of the current source result ("Challenge 1 was changed after this time was taken - 25:10 with the
new value · Apply"); Apply = one group `result` + `chain_start` with `from` = shown values (the desk's `PendingChanges`).
Never automatic: correcting round 1 never rewrites round 2 (no cross-round business logic in the handler; the organiser
decides).

Participants sheet / export: read-only; export gets a column "Started at (shared clock)" only for chained rounds.

### 5.6 Tag landing and the round switch

- `LiveResultsCurrentRound::pick()` (both rounds run on the same clock - a tie): among running rounds, a source and its
  follower → the **follower** when the tag's entry has a source result, else the source. Needs the tag's entries -
  `CompetitionTagResolver::resolve()` has them (`GetCompetitionTagEntries`). Without an entry (round switcher, no tag) →
  the source until its first result, then unchanged (`latestStarted`, ties by schedule order then id - deterministic).
- After a Save on a source round's card with a follower, the confirmation toast offers "→ Challenge 2" (opens the
  follower's live entry with `?entry=` of the matching entry - matched by `chainKey`, resolved from the snapshot when
  offline).

### 5.7 Competitor page and time proposals

- `GetCompetitorPage`: a chained round's card gets `chainStartSeconds` (null until the source result exists) and
  `chainSourceName`. The source result is in the same statement when the tag's rounds include the source (person `p`,
  line-up `g`, table `t` with identical tables); otherwise (entry tag `e`) +1 statement **only when a shown round is
  chained** (`CompetitorPageQueryBudgetTest` gets a chained case; the unchained budget stays ≤ 4).
- `competitor_page_live.js`: the card's clock = `chainedElapsed(view.elapsedMs/1000, S)`; `remainingMs` from the
  per-entrant limit; before `S`: "Starts when your Challenge 1 time is recorded" and no Stop button. While the entrant's
  chained round waits for `S` and the source runs, poll at `ACTIVE_POLL_MS` (15 s ±20 %, visible tab only) even with a
  live stream - the stopwatch topic does not carry results. A competitor who proposed round 1 already knows `S`
  provisionally from their own proposal (shown as "about"), replaced by the official value.
- `ProposeRoundTimeHandler` for a chained round: look up the entrant's source entry (by participant / exact member set -
  `TimeProposalOwnership` already resolves the entry; +1 indexed statement only on chained rounds); no result there →
  refuse `chain_not_started`; `S` from it; `proposedSeconds = intdiv(tap − startedAtMs, 1000) − S` (≥ 0),
  `receiptSeconds` likewise; store `chain_start_seconds`. `timingRefusal()` gets the per-entrant end: `times_up` when
  `tap > start + (S + limit × 60) × 1000`; `not_running` before `start + S × 1000`. `TimeProposalTiming` (heartbeat
  evidence) is unchanged - it is about the tap, not the start.
- Judge card shows a chained proposal as "12:47 (since challenge 1)"; confirming sends `result` (+ `proposalId`) and the
  server copies `chain_start` from the proposal (§5.5).

### 5.8 Optional extension (only if the organisers say "challenge 2 starts when bag 2 is opened")

Round setting `chain_start_mode` = `derived` (default, above) | `tapped`. `tapped`: the judge card shows "Start challenge
2 now" (server-synced tap, `E` at the tap) which saves `chain_start` alone (allowed without a result in this mode); the
competitor clock and proposals use the stored value. Cost: one more save per entrant (350 per round) and the start lives
on the server only once synced - a phone offline at the tap still has it in its outbox. **Not in the first packages;**
built only on the organisers' answer.

### 5.9 Chaos suite

`tools/judge-chaos/`: seed a second round chained to "Chaos Round" (`seed.sql`), new scenario `s14_chained_round`:
offline → record round 1 (in the outbox) → switch to round 2 → "Finished now" 60 s later → reload offline → back online →
DB has round 1 = T1, round 2 = E − T1 (±1 s) and `result_chain_start_seconds = T1`, exactly once. Re-run s02, s03, s04,
s07, s13 (outbox groups, offline reload, two judges, proposals offline, tap moment kept) - all must pass unchanged.

---

## 6. Same table for every round

Decision: a fifth seating source, **no event-level table model** (an event-level table would be a second truth next to
the per-round `table_number` that every reader - judge "find by table", tags, desk, print, proposer - already uses).

- `SeatingSource::SameTables = 'same_tables'` + query `?from_round=<roundId>` (default: the latest earlier round of the
  same category with any table number; `SeatingProposer` uses the identity of `earlier_rounds` - same participant / exact
  member set). Matched entrants keep their table; the rest get the tables after the highest ("N entries were not in
  Round X"). A matched table already used by an unmatched entrant of this round → that entrant moves to the end.
- Default source on a round whose category has an earlier round with tables and the event's previous round was seated
  with `same_tables`? Keep it simple: `same_tables` becomes **the default ("best available") when ≥ 90 % of the entrants
  match an earlier round with tables**, else today's rule. Open question Q-J7.
- Apply = the existing one `AssignTableNumbers` write (all or nothing, `from` per assignment) - **no new write path**.
- Convenience for 15 rounds: on the seating page of a round with all tables set, "Use these tables in later rounds of
  this category" → a confirm listing the rounds and the counts → `CopyRoundTableNumbers(sourceRoundId, targetRoundIds)`
  (one handler under `CompetitionParticipantsLock`, all or nothing over every target, refused per target with
  `changed_meanwhile` semantics if a target got numbers since the confirm was shown - the confirm sends the targets'
  table-count hash). Optional within the package (cut if the day runs out; the proposal path alone is enough).
- Tags/tents: nothing to build - kind `t` tents print once per event; the doc gets one line "same tables in every round
  = print table tents once". The seating print list "Find your table" of round 1 serves the e-mail the organisers send
  beforehand (the e-mail itself is the organisers'; Q-O7).
- Recommendation in the doc and demo: give each championship its own table range when rounds run at once.
- Tests: `SeatingProposerTest` (same tables, unmatched, collision), `SeatingProposalIntegrationTest` (pairs by exact
  member set), page test for the source option, `SeatingJsTest` if the page JS changes.

---

## 7. Unboxed rounds

### 7.1 Model

- Migration: `competition_round.unboxed` boolean not null default false.
- Meaning: "the box picture is not shown until the round's results are published" - the name follows the round's
  normal reveal (secret or not); the picture follows `max(the normal reveal, results_first_published_at)`.
  `results_first_published_at` (official-results.md) is the moment - unpublishing later never hides it again.
- `CompetitionRound::changeUnboxed(bool)`; message `ChangeRoundUnboxed(roundId, unboxed)` (toolkit hub "Round formats"
  card, per round checkbox; also on the round puzzles page next to the reveal settings). Duplicate copies it.

### 7.2 One rule, every surface

- `RoundPuzzlesVisibleNow` (`src/Query/RoundPuzzlesVisibleNow.php`): select `r.unboxed` and
  `r.results_first_published_at` (same statement); `imageHidden ||= unboxed && results_first_published_at IS NULL`.
  Every toolkit surface in the §1.7 table inherits it. `nextRevealAtMs` gets no moment (publishing is not scheduled) -
  publishing pushes instead (below).
- Site-wide (`SecretPuzzleHides::hideOf()`): for a `hidesEverywhere` row of an unboxed round with no first publish, the
  picture's moment is "never yet" (`CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED`); with a first publish, `max(reveal,
  first publish)`. `PublishRoundResultsHandler` calls `SecretPuzzleHides::resync()` for the round's secret puzzles **only
  when the round is unboxed** (an unboxed-free event pays nothing); `ChangeRoundUnboxed` resyncs too.
- A public catalogue puzzle keeps its picture on its own page and in the catalogue (§1.7 - inherent). The round results
  card's link to `puzzle_detail` (`_puzzle_card.html.twig:9,20`) stays: the round page only exists for published rounds'
  results anyway, and before publishing the round page shows the round by name only. State it in the organiser help:
  "Unboxed hides the picture on this event's pages, screens and phones; a puzzle already in the catalogue keeps its
  picture there - use a new (secret) puzzle for a truly unboxed challenge."
- Pushes on the first publish of an unboxed round: `OfficialResultsLiveUpdates::puzzlesRevealed()` (judge/desk) and the
  displays' `puzzles` signal (`CompetitionDisplayLiveUpdates`) - the competitor page refetches on its normal results
  path; analytics studio's cache key includes `imageHidden` (`AnalyticsStudioVisibility.php:27`) - no stale picture
  beyond its 2 s TTL. Check `SecretPuzzleRoutesTest`/`DisplaySecretPuzzleTest` patterns.
- Tests: `UnboxedRoundTest` - one fixture round with a secret puzzle, assert no picture (and no image path/EAN) on:
  display payload, competitor page + state JSON, analytics studio state, judge state (pick round chips), event page,
  round results page, public API `CompetitionDetailResponseProvider`, puzzle detail page (secret puzzle); then publish →
  all show it. `SecretPuzzleHidesTest` case. Goldens unchanged (default false).

---

## 8. Scale: per-save work added

| Choice | Per result save | Per state fetch / page | Elsewhere |
|---|---|---|---|
| `as_zero_pieces` | 0 | 0 (computed on read of the standing pages, pure) | - |
| Defect recording rule | 0 | `round.penalisedMissing`: 1 cheap EXISTS on the state answer only when the competition has standings (cache per request) | - |
| Chained round - judge | `chain_start` column in the same UPDATE (group); `chainAware` flag check = 0 statements (the round is loaded already) | +1 statement (source entries, ≤ 350 rows) on a **chained** round's state only; judges refetch every 60 s → ~6 judges × 1/min | Mercure: 0 server work (the page subscribes to one more topic); stopwatch actions +1 lookup |
| Chained round - proposals | +1 indexed statement on a chained round's proposal | competitor pages of a chained round waiting for `S` poll every 15 s: worst case 350 open visible phones = ~23 req/s of `competitor_state` (≤ 5 statements, guest) for the minutes between round-1 finish and the result reaching the phone | - |
| Same tables | 0 | 0 | one proposal read; optional bulk copy = one write per target round |
| Unboxed | 0 | 1 more column in `RoundPuzzlesVisibleNow`'s one statement | resync on publish of unboxed rounds only |

Nothing above takes the event lock longer than today's ~20 ms per save. Re-run `tools/competition-load/` with a chained
pair at 350 entrants (judge saves with `chain_start`, 350 heartbeats + 15 s polls) before the demo.

---

## 3. Implementation packages

Each ≤ ~1 agent-day, its own worktree and container, 6 locales and the doc/rule update inside the package
(`standings.md`, `round-formats.md`, `seating.md`, `judge-offline.md`/`live-results.md`, `competitor-pages.md`,
`.claude/rules/features/competition-toolkit.md`), CI gates per package. Hot files per R §4.1.

| Pkg | Scope | Main files | Tests | Chaos re-run |
|---|---|---|---|---|
| **W1** No-show (`as_zero_pieces`) + defect help line | §4 | `StandingMissing`, `StandingScoreStatus`, `StandingRoundInput`, `StandingCalculator`, `GetStandingResults`, standing FormType/FormData, `standing/_rules`, round cell partial, `RoundResultsStateController` (`penalisedMissing`), judge card + desk help text | §4.5; goldens unchanged | no |
| **W2** Unboxed | §7 | migration, `CompetitionRound`, `ChangeRoundUnboxed`(+handler), `RoundPuzzlesVisibleNow`, `SecretPuzzleHides`, `PublishRoundResultsHandler`, hub card / round puzzles page, `DuplicateCompetitionHandler` | `UnboxedRoundTest`, `SecretPuzzleHidesTest`, `SecretPuzzleRoutesTest` stays green | no |
| **W3** Same tables | §6 | `SeatingSource`, `SeatingProposer`, `SeatingProposalController`, seating page/JS, optional `CopyRoundTableNumbers` | §6 tests | no |
| **W4a** Chained round model + mirrored stopwatch | §5.2-5.4 | migration (3 columns + proposal column), `CompetitionRound`, `ChainCompetitionRound`(+handler, refusals), 5 stopwatch handlers + controllers, `RoundStopwatchLimit`, 6 stopwatch-state builders, hub UI, duplicate/move/delete | `ChainedRoundTest` (refusals, mirroring of start/schedule/cancel/stop/pause/reset, reveal never mirrored, limit null on every public clock, duplicate); `StopwatchRevealTest` unchanged | no |
| **W4b** Judge + desk on a chained round | §5.5-5.6, §5.9 | `official_results_chain.js` + harness, `live_results_controller.js`, `live_entry_draft.js`, `RoundResultsStateController` (`chain`), `OfficialResultsSubscription`, `RoundResultField::ChainStart` + parser/handler, `chainAware` guard, desk warning/Apply, `LiveResultsCurrentRound`, `CompetitionTagResolver` | `OfficialResultsChainScriptTest`, `RecordRoundResults…ChainStart` tests, `RecordRoundResultsStatementPinTest` unchanged, `RoundResultsStateGoldenTest` unchanged, tag-landing goldens unchanged for unchained events | **yes** - new s14 + s02/s03/s04/s07/s13; `LiveEntryBundleTest` budget |
| **W4c** Competitor page + proposals on a chained round | §5.7 | `GetCompetitorPage`, `CompetitorPageRound`, `competitor_page_live.js` (+ harness), `ProposeRoundTimeHandler`, `timingRefusal()`, proposal entity column, judge proposal display | `CompetitorPageQueryBudgetTest` (chained case), `TimeProposalEndpointsTest` (chained: refused before source, seconds − S, times_up per entrant), `CompetitorProposalsScriptTest`, competitor harness | **yes** - s07, s13, s14 |

Order: W1, W2, W3 in parallel (independent lanes; W2 holds `CompetitionRound` + `SecretPuzzleHides`). W4a after W2
(same entity + stopwatch/hides lane). W4b after W4a. W4c after W4b (proposal confirmation copies `chain_start`). Final:
the orchestrator's combined chaos run (`QUICK=1` then full) + `tools/competition-load/` with a chained pair, 375×812
walk of judge/competitor pages, demo script (§9).

Risks:
- **A stale judge page on a chained round** saves the shared elapsed as round 2's time - mitigated by `chainAware`
  (refused, visible in the outbox) - keep it, do not "simplify" it away.
- Round-1 correction after round-2 recording: round 2 stays as measured until the organiser applies the desk's
  recomputation - by design; the desk warning must not be missable (count in the readiness line of the round).
- `NoResult` in an unpublished round is not a no-show: an organiser expecting the preview to rank absentees sees them
  "incomplete" until publishing - say so on the standings page ("No-shows count once the round is published").
- Overlapping table numbers across concurrent rounds make `t` tags ambiguous - a recommendation only.
- `as_zero_pieces` collision with the v2 orchestrator - one case, coordinate before either merges.
- Hotwire Native / iOS Safari offline still unwalked (TODO) - the chained page adds no new storage, only one more topic.

## 9. Demo script (2 Dec)

Pairs championship of 5 challenges with challenges 1+2 and 3+4 chained, penalty 5, `total_only`, switch on,
`as_zero_pieces`; tables copied from challenge 1; challenge 5 unboxed. Show: judge Finished now on 1 then 2 (offline in
between), "+ Pieces missing", time's up with missing pieces, a no-show ranked, the published standing after each
challenge, the display's shared clock, a competitor phone's per-entrant clock.

---

## 10. Open questions

### For the organisers (defaults chosen so either answer works)

| # | Question | Default built |
|---|---|---|
| Q-O1 | Back to back: one stopwatch per entrant for both challenges, or two? When does challenge 2's time start - when time 1 is recorded, when bag 2 is opened? Does taking puzzle 1 apart count? | Challenge 2 = shared clock − challenge-1 time − fixed gap (gap default 0 = disassembly counts). "Bag opened" tap only on request (§5.8) |
| Q-O2 | Is there a time limit per challenge, or one for the back-to-back block (e.g. 90 min for both)? | Per challenge, counted from each entrant's start; displays of the chained round show the shared clock without a limit |
| Q-O3 | Defect pieces (2+ adjacent missing): should they be recorded/shown at all, or simply not counted? Does the adjacency rule also apply at time's up? | Not recorded; at time's up the 3+-connected rule decides |
| Q-O4 | No-show in challenge 1 of a back-to-back pair - may they still do challenge 2 (from which start)? | Challenge 2 needs a challenge-1 result for "Finished now"; a typed time works |
| Q-O5 | Late arrival - is it a no-show or a late start with the same end? | Not modelled: judge records what happened |
| Q-O6 | Unboxed: picture hidden until the results are published, or until the challenge ends? New puzzles or catalogue puzzles? | Until first publish; catalogue pictures stay (inherent) |
| Q-O7 | Tables: the same number for an entrant across all challenges of all days? Separate ranges per championship if rounds overlap? Who sends the table e-mail? | Same tables per category via copy; separate ranges recommended; the organisers send it (print list "Find your table") |

### For Jan

| # | Question | Default |
|---|---|---|
| Q-J1 | Implement `as_zero_pieces` now in W1 (it is A §3.3's v2 item) and tell the v2 orchestrator to build on it? | Yes - one case, no sibling |
| Q-J2 | Defect pieces: help line instead of a second field OK? | Help line only |
| Q-J3 | `NoResult` counts as a no-show only once the round is published (preview shows "incomplete" before) OK? | Yes |
| Q-J4 | Pick-round no-show pieces = the K largest puzzles? | Yes |
| Q-J5 | Chain depth 1 and one follower per source OK? | Yes |
| Q-J6 | A chained round never reveals at the stopwatch tick (scheduled/manual reveal instead) OK? | Yes |
| Q-J7 | "Same tables" becomes the default seating source when ≥ 90 % of entrants match? | Yes |
| Q-J8 | Round 1 corrected after round 2 was timed: desk warning + one-tap Apply, never automatic - OK? | Yes |
| Q-J9 | Competitor phones of a chained round waiting for their start poll every 15 s (≈ 23 req/s worst case at 350) OK, or wait for a per-entry topic (v2)? | 15 s poll |
| Q-J10 | Creation wizard: offer "back to back" / "unboxed" there too, or toolkit hub only? | Hub only (wizard later) |


## As built: W1 no-show (`as_zero_pieces`) - Real events W1, 2026-11-17

As designed in §4 with the amendments W1-a/b/c; the as-built summary is in [standings.md](standings.md) "No-show".
Deviations / details:
- The judge card's help line (W1-c) is **not** built - it needs logic in `live_results_controller.js` (the page fills
  from the state; another lane's file at the time). The desk's result editor shows it. `round.penalisedMissing` is NOT added to
  `official_results_round_state` (the judge page's hot path - no statement more per poll); the judge-card help line
  would ride on an existing statement later (v3).
- `penalisedMissing` needs `penalise_finished_missing` on **and** a penalty > 0 (with 0 nothing costs a penalty).
- The no-show penalty always uses the round's no-show pieces (`StandingRoundInput::$noShowPiecesCount`), also for an
  explicit "Did not start" with chosen puzzles - the public view never sees that entry, so ranks stay = preview.
- An entrant with only missing rounds and no-shows (no finished/unfinished score) is left out, extending "every score a
  no-show".
- `round.noShowStandings` (names) is on the desk's server-rendered state only; the JSON state endpoint carries
  `penalisedMissing` only. One statement (`GetRoundStandingRules`) on each.
- The old static note `competition_standing.form.missing_rule` is no longer rendered (replaced by the choice; its text is
  `competition_toolkit.standings.missing_rule.incomplete_help`).

## Chained round - v2 notes (from the design review, to fix before the chained round is ever built)
- B1: no set-level `chainAware` flag - every finished `result` change on a chained round travels in a group with a
  `chain_start` change built at queue time (old bundle / old snapshot can never produce it); refused otherwise.
- B2: mirror only Start/Schedule/Cancel; Stop/Reset of round 1 confirm "also pauses Challenge 2 - N still solving";
  Reset refused while round 2 has results.
- B3: chain key for pairs/teams = `CompetitionLineupId::of()` always (not only with competitor pages / QR mode).
- B4: the refusals ship with the model package.
- Should-fix: typed times ask which clock was read; a tap without a known S waits in the draft; round-1 DNF before the
  limit; current-round tie-break prefers round 1; server-side refusal of stopwatch reveal on a chained round; stopwatch
  messages under a row lock.
