# Unboxed per puzzle (SPWS 1:1 demo, package B part 1, 2026-11-24)

Builds on [world-series-rules.md](world-series-rules.md) §7 / "As built: W2" ([round-formats.md](round-formats.md)
"Unboxed rounds"). Part 2 of package B (the creation wizard) follows in its own document.

## Why
"Unboxed" is a round switch today (`competition_round.unboxed`): no box picture of **any** of the round's puzzles until
the round's results are first published. The Speed Puzzle World Series (Toronto, June 2027) runs back-to-back blocks as
ONE "Solve all 2" round. Teams' block "Challenges 3 + 4" = Challenge 3 (Boardwalk random cut, boxed) + Challenge 4
(Buffalo, UNBOXED). With a round switch the organiser can only hide both pictures or neither - Challenge 3's box must
stay visible once it is revealed (at the stopwatch start), Challenge 4's must not.

## Options considered
| Option | For | Against |
|---|---|---|
| A. `competition_round_puzzle.unboxed` + keep `competition_round.unboxed` = "every puzzle of the round"; effective = round OR puzzle | additive, existing rows unchanged (default false = today's behaviour byte for byte), a puzzle added later to a whole-round-unboxed round is unboxed too, Duplicate keeps copying the round switch (it copies no round puzzles) | two places to read - but both only inside the one rule |
| B. Per puzzle only; migrate the round flag onto its rows | one place | a puzzle added later to an "unboxed round" is boxed (surprise), Duplicate would lose it (no puzzles copied), a data migration of live rows, `ChangeRoundUnboxed` changes meaning |
| C. A per-round list of unboxed positions/labels | survives a puzzle swap | positions are not stable ids; nothing else keys by position |

**Chosen: A.** The moment stays the round's (`results_first_published_at` - one stopwatch, one publish per block).

## Data model
- Migration (additive): `competition_round_puzzle.unboxed BOOLEAN DEFAULT false NOT NULL`.
- `CompetitionRoundPuzzle::$unboxed` (`#[Immutable(PRIVATE_WRITE_SCOPE)]`), changed only through `changeUnboxed(bool)`.
- `CompetitionRoundPuzzle::isUnboxed()` = `round->unboxed || unboxed` (the PHP meaning of "this puzzle is unboxed").
- `CompetitionRound::keepsPicturesUnboxed()` = not first-published yet AND (round switch OR any puzzle unboxed). The
  published check runs first, so an already-published round never loads its puzzles; a first publish of a round that is
  not unboxed at all loads its round puzzles once (≤ a few rows; the publish path is not statement-pinned).

## The one rule (every surface)
- SQL `RoundPuzzleReveal::sqlImageHidden($crp, $cr)`:
  `({hidden} OR (({cr}.unboxed OR {crp}.unboxed) AND {cr}.results_first_published_at IS NULL))`. Every caller already
  passes the competition_round_puzzle alias of that round (byTagId, `GetCompetitionPuzzles` ×2,
  `sqlCompetitionKeepsPictureJoin()` → marketplace at the event + the offers picker).
- PHP `RoundPuzzlesVisibleNow`: `(cr.unboxed OR crp.unboxed) AND cr.results_first_published_at IS NULL AS
  round_keeps_pictures_unboxed` (same column name - nothing else of the statement changes). Every toolkit surface
  inherits it: displays/overlays, competitor page + state, analytics studio (visibility hash includes `imageHidden`),
  judge chips, event/edition round timeline, round results page, published entry "Add to my profile" per puzzle, public
  API v1 competition detail.
- Site-wide `SecretPuzzleHides::hideOf()`: `$row->round->unboxed` → `$row->isUnboxed()` (a secret new puzzle of an
  unboxed challenge keeps `hide_image_until` never-yet until the first publish, then max(reveal, publish)).
- `PublishRoundResultsHandler` / `PublishRoundResultsController` / `ResyncUnboxedRoundPicturesWhenPublished`: through
  `keepsPicturesUnboxed()` / "round or any puzzle unboxed" - a round without any unboxed puzzle keeps today's path.
- A catalogue puzzle keeps its own page's picture (inherent, W2-b) - unchanged, the help says so already.

## Write path
- `ChangeRoundUnboxed(competitionId, roundId, unboxed, ?list<string> $unboxedRoundPuzzleIds = null)` - `null` = the
  per-puzzle flags stay as they are (every existing caller: sandbox demo, tests); a list = exactly these round puzzles
  of the round are unboxed, the others not. An id that is not a puzzle of this round → `CompetitionRoundPuzzleNotFound`
  (nothing written). Same locks as today (`CompetitionParticipantsLock` via `SerializedByLock` + `lockRoundsForChange()`),
  one resync of the round's secret puzzles when anything changed; nothing changed = nothing written.
- With the round switch on, the per-puzzle flags are irrelevant (every puzzle is unboxed); the organiser form sends an
  empty list then, so switching the round off later does not resurrect stale picks.

## Organiser UX (round puzzles page `manage_round_puzzles`)
The existing "Unboxed" card becomes one form with three choices (radio, no JS needed):
- "Boxed - the pictures are shown" (default)
- "Every puzzle of this round is unboxed" (= today's switch)
- "Only some puzzles are unboxed" + a checkbox per round puzzle ("Challenge 4 – Buffalo 1000") - offered only when the
  round has 2+ puzzles (one puzzle = the round switch).
Help as today + "In a back-to-back block, tick only the unboxed challenge - the other puzzles show their box once
revealed." Save → `ChangeRoundUnboxed` with the list; flash "Unboxed: Challenge 4" / existing texts. The confirm on
switching off while pictures are held stays (also when going from some → fewer). Each puzzle card shows an "Unboxed"
badge (`data-round-puzzle-unboxed`) when effective. Hub card "Round formats": "· Unboxed" (whole round, as today) or
"· Unboxed: Challenge 4" (labels of the unboxed puzzles; `GetCompetitionRoundsForManagement` gets one more column, a
`string_agg` over the round's unboxed rows in label order, NULL when none - +0 statements).

## "Add to my profile" (solving time's `unboxed` flag)
Today the official-entry prefill (`OfficialEntryTimePrefill` → `OfficialEntryTime`) never sets the add form's
"Solved without looking at the box image" - not even for a whole-round unboxed round. New: `OfficialEntryTime::$unboxed`
= the round's switch or the entry puzzle's row flag (one statement `IsRoundPuzzleUnboxed::of(roundId, puzzleId)`, only
on the add form when an official entry prefills it - GET only, the player still sees and may untick it). A pick round's
chosen puzzle uses its own row; a `total` one-puzzle round its one row.

## Edge cases
- Puzzle removed from the round: its flag goes with the row. Puzzle merge (`moveToPuzzle()`): the row keeps its flag.
- Duplicate: copies the round switch (as today), never per-puzzle flags (no round puzzles are copied). Known edge, in
  docs/TODO.md.
- Shared puzzle across rounds (S2): each round's row has its own flag; the SQL rule is per row, so a puzzle unboxed in
  round A and not in round B is hidden on surfaces of A only - byTagId/`GetCompetitionPuzzles` hide it when any row says
  so (they already OR over rows of the competition).
- Unpublish never hides again (the moment is `results_first_published_at`).
- Internal API: no unboxed field exists today (round or puzzle) - not added (package C uses messages).

## Back-compat / opt-in
Default false everywhere; untouched events run the same statements with one more `OR crp.unboxed` term inside existing
expressions; goldens (`RoundResultsStateGoldenTest`, `OfficialRoundResultsGoldenTest`, `CompetitionNameTagsGoldenTest`,
`RecordRoundResultsStatementPinTest`, `DetailPagesQueryBudgetTest`) unchanged.

## Tests
- `UnboxedRoundTest`: new case - only `API Visible` unboxed: its picture absent on every surface, the other puzzles'
  pictures present on the toolkit surfaces; publish → all back. Organiser form: "some" with one id → DB rows; "every" →
  list cleared; a foreign round-puzzle id refused. Hub card label.
- `SecretPuzzleHidesTest`: a secret puzzle unboxed per row (round switch off) → never-yet picture until publish.
- Prefill: `OfficialEntryTimePrefill`/add-form test - unboxed round's entry opens with the box ticked; a boxed one not.

## Review amendments (independent review 2026-11-24 - binding)
- R1 (merge): `ApprovePuzzleMergeRequestHandler` drops a merged puzzle's row when the survivor is already in that round -
  an unboxed dropped row makes the kept row unboxed (`changeUnboxed(true)`); a moved row keeps its flag. A merge revert
  leaves the kept row's flag on (docs/TODO.md).
- R2 (leftover flag): the "Only some puzzles" choice is shown whenever a row is flagged, also on a round that has one
  puzzle now, so a leftover flag can always be seen and cleared.
- R3 (hub card): a count, not labels - "· Unboxed: 1 puzzle" (`COUNT(...) FILTER (WHERE crp.unboxed)`, +0 statements);
  labels would need `RoundPuzzleLabel::effective()` per row.
- R4 (prefill cost): the add-time controller asks `IsRoundPuzzleUnboxed` only while the form opens from an official entry
  (the GET block that fills it in) - never on the save; `OfficialEntryTimePrefill`/`OfficialEntryTime` unchanged.
- R5 (nothing changed): the handler compares both levels - the round switch and every row's flag; "every puzzle" with an
  empty list clears stale picks.
- R6 (safety net): `ResyncUnboxedRoundPicturesWhenPublished` folds `(cr.unboxed OR crp.unboxed)` into its one statement
  (no collection load); it now reads that statement for every published round (async, cheap).
- R7 (confirm): the page asks only when a picture held back now would come out (whole round → not, or an unboxed puzzle
  unticked) - `data-was-unboxed` on the ticked boxes.
- R8 (duplicate): per-puzzle flags are not copied (no round puzzles are) - docs/TODO.md, not on the duplicate notice yet.
- R9 (sandbox): package C seeds a per-puzzle flag with `ChangeRoundUnboxed(competitionId, roundId, false, [roundPuzzleId])`.
- R10: keys in all 6 locales; round-formats.md "Unboxed rounds", world-series-rules.md §7 note and the toolkit rule file
  updated. During a blue-green deploy the old release ignores a per-puzzle flag until the switch completes (accepted -
  nobody has set one before the release).
