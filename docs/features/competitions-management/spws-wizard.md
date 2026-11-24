# Creation wizard: every real-event option, nothing forced (SPWS 1:1 demo, package B part 2, 2026-11-24)

Builds on [creation-wizard.md](creation-wizard.md) (paths, "the draft is the wizard's state", composites) and
[spws-unboxed-per-puzzle.md](spws-unboxed-per-puzzle.md) (part 1). Event: the Speed Puzzle World Series, Toronto 2027
(three championships - Pairs, Teams of 4, Individual - each = one cumulative standing over its challenges; back-to-back
blocks = one "Solve all 2" round; some challenges unboxed; secret puzzles revealed at the stopwatch start).

## Audit: SPWS from zero in today's wizard (several-rounds path)
| Need | Today | Gap |
|---|---|---|
| Logo | Basics (collapsed details, `CompetitionFormData::$logo`) | - |
| 3 championships over 4 days, rounds with names, days, times | "Every round counts" (counts per category) → start times (name, day, time typed per round) → schedule table | rounds named "Pairs 1"; one limit per category in the template (per round only later in the table) |
| Back-to-back block = one round, 2 puzzles, "Solve all 2" | Puzzles grid: 4 slots per round + format radio (toolkit) | nothing tells the organiser that a block is ONE round with 2 puzzles; no block in the template |
| Per-puzzle labels "Challenge 1..5" | - | only the round puzzles page (`ArrangeRoundPuzzles`) |
| Unboxed per round / per puzzle | - | only the round puzzles page (`ChangeRoundUnboxed`) |
| Per-round time limits | schedule table | template has one per category |
| Secret + reveal at the stopwatch start | Puzzles grid (`secret`, `revealMode=stopwatch`, per-row override) | - |
| One standing per championship with SPWS rules (5 s/piece, finished-with-missing penalised, no-show = as_zero_pieces, total only) | Structure "add up per category" / Extras "Overall standings" → `CreateCompetitionStanding` with `new StandingRules()` (0 s, finished rounds first, incomplete, off) | no rules in the wizard - the defaults are the opposite of SPWS |
| Per-challenge leaderboards | automatic for solve-all rounds (`?challenge=`) | - |
| Same tables all championship | seating "Same tables as round X" - needs entries | cannot be at creation; say it on Review |
| Competitor pages + QR | Extras | - |
| Divisions (Carnival) | - | organiser page `competition_divisions` (kept as is; out of scope here) |

## Shapes considered for "World Series style"
1. **A preset button** that fills "Every round counts" with fixed SPWS numbers - too specific; blocks still impossible.
2. **Typed lists** ("Challenges per round: 2, 2, 1") - compact but error-prone, hard on a phone.
3. **Block rows (chosen)**: a new Structure template **"Challenges - one total time per championship"**
   (`EventWizardStructureTemplate::Challenges`), rows like the Qualification stages (a known pattern on this page):
   each row = who (Solo / Pairs / Teams + team size), **challenges back to back** (1-4: 1 = a normal round, 2+ = one
   round where every puzzle is solved in order on one stopwatch), **how many such rounds** (×, default 1), **time limit**.
   SPWS = 5 rows: Pairs 2 × 2 (90), Pairs 1 × 1 (60), Teams 2 × 1 (120), Teams 2 × 1 (150), Solo 1 × 5 (75).
   Challenges are numbered per category in row order and name the rounds: "Pairs – Challenges 1 + 2", "Pairs –
   Challenge 5" (editable in the start-times list like every generated name). Below the rows: "Add up the challenges -
   one standing per category" (ticked) with the **standing rules visible and prefilled with the World Series rules**
   (5 s per missing piece, finished-with-missing penalised, total time only, no-shows ranked with every piece missing) -
   each one editable/untickable. Nothing else of the path changes: start times typed per round (never computed), one
   POST writes rounds + standings (`SetUpEventWizardStructure`).

Why rows over a dedicated "SPWS step": the same page, same mental model, works for any "challenges" event (WJPF
style, Munich), 375 px friendly (one row = one small card), and the Qualification/Every-round-counts templates stay
byte-identical.

## Puzzles grid: labels, unboxed, the block's format
- **Label per new puzzle** (`EventWizardPuzzleSlotFormData::$label`, ≤ `RoundPuzzleLabel::MAX_LENGTH`, optional;
  placeholder = the letter it would get). When any new slot of a round has a label, the save adds
  `ArrangeRoundPuzzles` (existing puzzles keep their order and custom labels, new ones appended in slot order with
  theirs) to `SetUpEventWizardRound` (new optional `$arrange`). No label typed = no arrange = today's rows.
- **"Unboxed" per new puzzle** (checkbox per slot, help "no box picture until you publish the round's results"). When
  any new slot is ticked, the save adds `ChangeRoundUnboxed(competitionId, roundId, unboxed, ids)` (new optional
  `$unboxed`): every puzzle of the round ticked/unboxed → the round switch (ids `[]`, so Duplicate keeps it); else the
  list = the round's already-unboxed rows + the ticked new ones. Nothing ticked = no message.
- **The Challenges template's plan travels as a prefill only**: the Structure POST redirects to Puzzles with
  `?challenges[<roundId>]=<n>` (the draft stays the state - a resumed grid simply has no prefill). With it, a row opens
  n slots, prefills labels "Challenge k" (numbered per category in schedule order) and the format "Solve all n (order
  recorded)" for n ≥ 2. Everything stays editable; rows without the parameter behave as today.
- Saved puzzles show their label and an "Unboxed" badge (read from the grid's existing round puzzle read).
- `SetUpEventWizardRound` order: EditCompetitionRound → AddPuzzleToCompetitionRound × → ChangeRoundPuzzleReveal × →
  **ArrangeRoundPuzzles** → **ChangeRoundUnboxed** → format EditCompetitionRound. All in one transaction (the nested
  messages share `CompetitionParticipantsLock`, re-entrant).

## Standing rules wherever the wizard makes standings
One small form part `EventWizardStandingRulesType` / `EventWizardStandingRulesFormData` (penalty seconds per missing
piece 0-600, "Penalise pieces missing from a finished puzzle", "Rank by total time only", "Rank no-shows with every
piece missing") → `StandingRules`. Used by:
- Structure "Every round counts" (under "add up per category") - defaults = `new StandingRules()` (today);
- Structure "Challenges" - prefilled with the World Series rules;
- Extras "Overall standings" - defaults = today.
`StandingsPerCategory::messages()` takes `StandingRules $rules = new StandingRules()`; the Extras controller passes the
form's rules (it built `new StandingRules()` itself). An untouched form creates exactly today's standing.

## Review
Per round: puzzle labels ("Challenge 3 · Boardwalk …"), "Unboxed" per puzzle / round; standings with their rules line
(the standing page's `_rules.html.twig` words). A "Next steps" note when the event has standings or several rounds
per category: "Same table all championship: after adding participants, Seating → Same tables as round X".

## Opt-in / back-compat
Qualification, Every round counts (without touching the new rules), one round, series, backfill and calendar paths are
unchanged. New template = new enum case (entry screen lists it as a fourth answer; `?structure=challenges`). No new
storage, no migration. Toolkit-only parts behind `COMPETITION_TOOLKIT_MANAGE` like today (labels/unboxed/rules shown to
whom the toolkit is granted = every organiser).

## Edge cases
- A Challenges row with 2+ challenges and a puzzle count different from n in the grid: the format radio decides (the
  organiser may switch to "one result"); `RoundPuzzleFormatRefused` errors are the existing row errors.
- Teams rows need a team size (default 4); mixed categories never share a standing (per category).
- A label equal for two puzzles is allowed (RoundPuzzleLabel allows it); empty = letter.
- Re-running the template on a draft with rounds replaces them (existing "replace" confirm) - the plan prefill follows
  the new round ids.
- Unboxed per slot on a round already whole-round unboxed: nothing to do (the badge says so).

## Tests
`EventWizardStructurePlannerTest` (challenge rows → names, categories, limits, plan counts), Structure controller test
(Challenges template end to end: rounds, standings with WS rules; Every round counts unchanged = default rules),
Puzzles grid test (labels → arrange, unboxed → round switch / list, `?challenges` prefill), Extras standings rules,
Review lines. Browser walk desktop + 375 px of entry → Structure (Challenges) → start times → Puzzles → Extras → Review.

## Review amendments (independent review 2026-11-24 - binding)
- R1 (Backfill untouched): label + "Unboxed" are NOT on the shared slot form (`EventWizardPuzzleSlotFormData` is
  Backfill's too) - they are row fields `labels[i]` / `unboxedSlots[i]` of `EventWizardPuzzlesRowFormData`, added by
  `EventWizardPuzzlesFormType` only with `slot_extras` (= COMPETITION_TOOLKIT_MANAGE).
- R2 (arrange): `ArrangeRoundPuzzles(roundId, competitionId, [], labels keyed by the new ids)` - no ids listed, so saved
  puzzles keep their order and the new ones (position NULL, uuid7 in slot order) follow.
- R3 (numbering): the challenge numbers travel from the Structure step (`?challenges[<roundId>]=3,4`, from
  `$planned`), never recomputed from the schedule order.
- R4 (refusals): label `Length(max: RoundPuzzleLabel::MAX_LENGTH)`; `CompetitionRoundPuzzleNotFound` → form error; a
  round already whole-round unboxed never gets a `ChangeRoundUnboxed` (a plain bool cannot say "keep").
- R5 (prefill lifetime): the plan rides on the form's action and the no-JS "+ another puzzle" redirect; Finish later /
  resume drop it - the block knowledge then lives in the round name only (the draft is the state).
- R6 (shape): a Challenges row = the Qualification stage row (`EventWizardStageFormData`/`Type` with `challenges`
  instead of a name, option `challenges`); the enum case sits before `Empty`; the entry answer reads "No - challenges
  added up per championship".
- R7 (defaults): two rule groups (`everyRoundRules` = today, `challengesRules` = World Series), each shown with its
  template; the standings gate covers Challenges.
- R8 (one rule): Extras calls `StandingsPerCategory::messages(..., rules:)`; fields and words reused from the standing
  form (`penaltySecondsPerPiece`, `penaliseFinishedMissing`, `orderRule`, `missingRule`); every combination is valid.
- R9 (tests): the entry pins 4 answers now; the 18-round Every-round-counts test is unchanged and also pins today's
  rules.
- R10 (same tables): a Review line now; a "Get ready" item later (its 2 statements are pinned) - docs/TODO.md.
- R11: the row's limit says "For the whole round - all its challenges".
- R12: `SetUpEventWizardRound` checks `arrange` / `unboxed` belong to the round and event.

## As built (2026-11-24)
As designed + the amendments, and:
- The template's defaults are the World Series exactly: Pairs 2 × 2 (90) + 1 × 1 (60), Teams 2 × 1 (120) + 2 × 1
  (150), Solo 1 × 5 (75); one spare row.
- "Solve all n" prefilled on a round left without puzzles ("Decide later") is not saved - the format waits for the
  puzzles (no `RoundPuzzleFormatRefused` for 0 puzzles).
- `SetUpEventWizardRoundHandler` clears the entity manager before `ArrangeRoundPuzzles` / `ChangeRoundUnboxed`: the round's
  puzzle collection the identity map held from before the adds would miss the new rows (found by the test).
- Review: per puzzle its own label and "Unboxed", per standing its rules line (`standing/_rules.html.twig`) and, when
  the event has standings, the "Same table for a whole championship" hint.
- Browser walk (Selenium, 1280 + 375 px): entry → Basics → Structure (Challenges) → start times → Puzzles (labels
  prefilled, Teams C4 unboxed, secret + stopwatch) → Extras → Review; `cm-delivery/shots/spws-b/b2-*`.
- Diff review (PR #486): "Solve all" is saved only from 2 puzzles (a block filled one at a time stays one result until its
  second, so a puzzle added later on the round puzzles page never leaves "pick 1 of 2"); the plan's next labels follow
  the puzzles already saved; a challenge row filled in without "challenges back to back" is refused (never dropped);
  `?challenges=<string>` is ignored.
- **Known limit**: the plan is a prefill. A block round left "Decide later" and opened again without `?challenges`
  (Review → Edit puzzles, Finish later, resume) shows no "Challenge k" labels and "One result for the round" - the
  organiser types the labels and picks "Solve all" (the round's name still says "Challenges 3 + 4"). docs/TODO.md.
- The event page's note under an unboxed puzzle says "Picture shown once the results are published"
  (`VisibleRoundPuzzle::$pictureWaitsForResults` → `EditionRoundPuzzle`, `event_rounds.picture_after_results`).

# Package D - every SPWS setting everywhere (2026-11-24)

Closes the leftovers packages A ([table-labels-and-plates.md](table-labels-and-plates.md)) and B (this doc +
[spws-unboxed-per-puzzle.md](spws-unboxed-per-puzzle.md)) left, so an organiser - or Claude Code over the internal API -
sets every SPWS option wherever they are, never forced. Everything opt-in; an untouched event/draft/API call behaves
byte-identically. No migration.

## D1. Wizard Extras: "Name the tables like your hall plan" (optional)

**Options.** (a) A step of its own on the several-rounds path - more clicks for everyone, also those who never need it.
(b) The seating page only (today) - SPWS would set it per championship after the wizard. (c) **An Extras row (chosen)**,
next to the other opt-in features; Extras is on every in-person path (one round, several rounds incl. Challenges).

**The row** (toolkit = COMPETITION_TOOLKIT_MANAGE, in-person event, ≥ 1 round using table numbers, no round named yet):
switch "Name the tables like your hall plan" - one line "Like A1, A2 … B1 instead of 1, 2, 3 - judges type them, plates
print them. Off = plain numbers." Switched on:
- the hall once - the seating panel's generator fields and defaults: letters (`A-H`), numbers (`1-22`), order (A1, A2 …
  / A1, B1 …), leave out (`H22`), place style (A1 L / A1 R · A1a / A1b · A1-1 / A1-2);
- **places per table per category** the draft has (Solo / Pairs / Teams, 1-4, default 1) - SPWS: Teams 1 (175 names),
  Pairs 2 and Solo 2 (350). One hall, a list per championship (A's "lists per category" rule);
- a live preview per category ("Pairs: 350 names, A1 L … G22 R") by a small lazy Stimulus controller over the pure
  `generate()` of `assets/table_labels_editor.js` (twin of `TableLabelGenerator`); without JS the server validates.

**Write.** `SaveEventWizardExtras` gets an optional last parameter `tableLabels: list<SetRoundTableLabels>` - one message
per category: every round of the category with table numbers on, `labels` = `TableLabelGenerator::generate()` with that
category's places, `expected` = each round's list now (null). Dispatched in the same transaction after the standings;
the generator runs in the controller first (`InvalidTableLabels` → a field error, nothing written); `TableLabelsRefused`
inside → `EventWizardExtraRefused('tableNames')` (all rolled back, 422). After the commit `roundChanged()` (official
results + displays) per round, like the internal API. `isEmpty()` counts it.

**Coming back.** A list cannot be turned back into generator fields: once any round of the draft has names the row is a
line "Table names: Pairs 350 (A1 L … G22 R) …" + "Change on the seating page" (link to the first named round's seating
page, `?labels` opens the panel) - the standings/displays pattern. Review lists the same line. `tables_note` (under
Extras) changes to "Who sits at which table is set once people are in - the "Get ready" checklist reminds you."
(6 locales; the note was about numbers).

**Reads.** `GetCompetitionRoundsForManagement` gains `cr.table_numbers_off` and `cr.table_labels` columns
(`CompetitionRoundForManagement::$tableNumbersOff`, `$tableLabels: null|TableLabels`) - same statement, +0. Used by
the Extras state, Review and the hub card (D5).

## D2. Resume / "Edit puzzles": a block keeps its "Challenge k" labels and "Solve all n"

The plan was only `?challenges[<round>]=3,4`. **Fallback when the parameter does not name a round**: the round's name is
matched against the names the Challenges template generates - `event_wizard.structure.challenges.round_name_one` /
`round_name_block` with the round's category name, **in each of the 6 locales** (a draft created in Czech and resumed
in English still matches) - exact match of the whole name, the `%numbers%` part = `\d{1,3}( \+ \d{1,3})*` (≤ 4 numbers,
the grid's slots). "Solo 1", "Semifinal 1", "Group A" never match; a renamed round gets no prefill (as today, documented).
`EventWizardDefaults::challengeNumbersOf(name, category): list<int>` (pure over the translator). The derived plan
prefills exactly like the parameter (labels of the slots not saved yet, "Solve all n" for n ≥ 2) and joins the form's
action. Toolkit only, like the parameter. A plain Continue still writes nothing for a round left without puzzles (B's
rule: "Solve all" is saved only from 2 puzzles).

## D3. Duplicate: say exactly what the copy keeps

`DuplicateCompetition` copies rounds without puzzles (never: secret puzzles, a next edition's puzzles differ), so
per-puzzle Unboxed marks and puzzle labels cannot travel - copying puzzles stays out. The page's notice grows from
"Puzzle formats reset" to **"The copy's rounds start without puzzles"** listing per round what the organiser sets again:
its format (as today), "N puzzles unboxed" (`unboxedPuzzleCount`, row has it), "N puzzle labels"
(`COUNT(*) FILTER (WHERE crp.label IS NOT NULL)`, new column of the same statement). Plus one line of what IS kept:
"Kept: each round's settings, whole-round Unboxed, table names, standings, displays, divisions". Same in the flash. The
existing `data-duplicate-formats-reset` / `data-duplicate-format-round` attributes stay. Internal API duplicate: its
doc lists the same (answer unchanged).

## D4. Internal API: read + write unboxed, labels, order, format

Reads (`GET …/competitions/{id}`, every round answer): round `unboxed` (bool), `tableLabels` (list, **only when set** -
A's doc promised it, never built), round puzzle `unboxed` (its own flag). Additive keys.

Writes - one message per call, all checks before writing:
- `PATCH /internal-api/rounds/{id}` + `puzzleFormat` (`total` | `pick`) and `puzzlesToSolve` (pick: 1..N; `"all"`
  not a value - K = N is "solve all") → the existing `EditCompetitionRound::$puzzleFormat/$puzzlesToSolve` (left out =
  kept). `RoundPuzzleFormatRefused` → 409 with `reason`.
- `PUT /internal-api/rounds/{id}/arrangement` `{"puzzleIds": [...] (order, optional), "labels": {"<puzzleId>": "Challenge
  3" | null}}` (optional; ≤ `RoundPuzzleLabel::MAX_LENGTH`, checked in the controller → 400) → `ArrangeRoundPuzzles`
  (puzzle ids mapped to the round's round-puzzle ids; an id not in the round = 400). After the commit: the arrange page's
  signals.
- `PUT /internal-api/rounds/{id}/unboxed` `{"unboxed": bool, "unboxedPuzzleIds": [puzzleId…] (optional; with
  `unboxed: true` ignored = cleared, like the web form), "confirmReveal": bool}` → `ChangeRoundUnboxed`. A change that
  lets a held picture out (round not first-published yet, a puzzle unboxed now would be boxed) → 409
  `pictures_would_show` with the puzzles unless `confirmReveal: true` (the web's confirm). After: `puzzlesRevealed()`.
Order for scripts (package C): puzzles (`PUT …/puzzles`) → `…/arrangement` → `…/unboxed` → `PATCH` format.

## D5. Small TODO items
- Done: toolkit hub "Round formats" card line "· Tables A1 L … G22 R" per named round (from D1's columns, +0).
- Left: Get ready "Same tables" item (its 2 statements are pinned - needs a facts change; the Review line covers it),
  real A4 plates (physical), SPWS's answer on place names (external), editing saved puzzles' labels on the grid (round
  puzzles page does it; the grid shows them).

## Tests
`EventWizardExtrasTest` (row hidden online / without toolkit; Pairs 2 + Teams 1 → two lists; invalid letters → 422
nothing written; named → line), `EventWizardSeveralRoundsTest`/Challenges (resume without `?challenges` prefills; cs
draft resumed in en; "Solo 1" untouched), `DuplicateCompetition*Test` notice lines, `RoundsInternalApiTest` (format
PATCH, arrangement, unboxed + 409 confirm, reads), JS harness for the preview if logic beyond `generate()`.
Browser: Extras + Review + Puzzles (resume) + duplicate page + hub card, 1280 and 375 px.
