# Round formats, puzzle labels and the reveal at the stopwatch start (competition toolkit A1-A5, C10)

How a round's puzzles are solved and when its secret puzzles come out - two opt-in settings of the competition toolkit
([toolkit/STATUS.md](toolkit/STATUS.md); design `toolkit/A-round-formats.md` §2, §4; binding
`toolkit/R-review-and-plan.md` §1.1, §1.5, §1.8, §2.1). Offered to every organiser of the event (`COMPETITION_TOOLKIT_MANAGE` = `COMPETITION_EDIT`). An untouched round (`total`, any other reveal mode) runs exactly the statements and
renders exactly the bytes it did before the toolkit (golden pins: `RoundResultsStateGoldenTest`,
`OfficialRoundResultsGoldenTest`, `RecordRoundResultsStatementPinTest`).

## In short

| Setting | Values | Where it shows |
|---|---|---|
| Round format (`competition_round.puzzle_format`) | `total` - one result for the round (default); `pick` - each entry solves K of the round's N puzzles in any order: "Solve all 3" (K = N), "Pick 2 of 3", "Pick 1 of 3" | round form, round puzzles page ("Order and labels"), hub card "Round formats & reveals" |
| Puzzle label + position (`competition_round_puzzle.label`, `position`) | ≤ 40 characters, the neutral name before the reveal ("Blue box"); falls back to A, B, C … | every toolkit surface shows the label, never the name, until the puzzle is out - organisers too (R §5 decision 2) |
| Reveal mode `stopwatch` (`RoundPuzzleReveal::Stopwatch`) | the puzzle comes out the moment the round's stopwatch starts **with "Reveal" ticked** | "Change reveal" on the round puzzles page; the checkbox on the stopwatch control page and the online control room |

Surfaces of a `pick` round, each reading the same rules (`RoundEntryPuzzles` in PHP, its twin
`assets/official_results_puzzles.js` in the browser):

- **Results protocol** - the `puzzles` field of `RecordRoundResults` (A3 below).
- **Judge page** - puzzle chips on the entry card, grouped result + record saves, "Puzzle missing" (C10, below).
- **Results desk / participants sheet / export** - the per-puzzle editor, the read-only "Puzzles" column, "Puzzle N"
  columns (A4 below).
- **Public round page** - chips per row, "Add to my profile" per puzzle (A5 below).
- **Competitor pages** - "Pick K of N" and the places by label ([competitor-pages.md](competitor-pages.md)).
- **Displays** - puzzle elements follow `RoundPuzzlesVisibleNow` ([displays.md](displays.md)).
- **Standings** - a `pick` entry's own puzzles' pieces for the penalty ([standings.md](standings.md)).

The secret-puzzle model itself (reveal modes `automatic` / `scheduled` / `manual`, `SecretPuzzleHides`,
`SecretRevealPreview`, the site-wide hide) is [README.md](README.md) "Hide Until Round Starts". **Every surface that
shows a round's puzzles reads `RoundPuzzlesVisibleNow`** (the public rule, for organisers and referees too) - an early
reveal would be a disaster (`00-context.md` rule 4); `SecretPuzzleRoutesTest` checks each surface at reveal − 1 s.

## Reveal at the stopwatch start (`stopwatch` reveal mode, competition toolkit A1)

Design: `toolkit/A-round-formats.md` §4, binding fixes `toolkit/R-review-and-plan.md` §2.1 and §1.5.

- **Choosing it:** "When the round's stopwatch starts" on "Change reveal" (`manage_round_puzzles`). Offered to every organiser of the event (`COMPETITION_TOOLKIT_MANAGE` = `COMPETITION_EDIT`). Refused while the round's stopwatch runs or is stopped (`RoundStopwatchAlreadyStarted` - "use Reveal now"); a countdown still ahead is fine (it was started without the tick).
- **Model:** `RoundPuzzleReveal::Stopwatch` - `revealAt()` / `sqlRevealAt()` read `reveal_at` like `scheduled`, so every reader (`sqlHidden`, `RoundPuzzlesVisibleNow`, `SecretPuzzleAccess`, `SecretPuzzleHides`, the series match, the internal API) follows. `null` = hidden like a manual reveal, site-wide `HIDDEN_UNTIL_REVEALED`. The mode stays `stopwatch` after the reveal ("Revealed at the stopwatch start, T"). `CompetitionRoundPuzzle::revealAtStopwatchStart()` / `withdrawStopwatchReveal()` (only a moment still ahead).
- **The start checkbox** "Reveal the puzzle(s) when the stopwatch starts" - on the stopwatch control page (Start, Start in 10 s, Start at) and in the online control room (Start in 10 s, Start at), only while the round has rows waiting (`SecretRevealPreview::byStopwatchStart()`, names + scope). **Unticked by default, always** (also after a browser restores the form): a test run reveals nothing; a forgotten tick leaves the puzzles hidden (warning on the control page + the round puzzles page, "Reveal now" fixes it). Ticked: one `confirm()`, then `reveal=1` + `reveal_hash` (`SecretRevealPreview::stopwatchStartHash()` = the list's hash **and** the stored stopwatch status/start/stop - a page from before a countdown was scheduled, moved or cancelled confirms nothing). No JavaScript = nothing revealed.
- **Handlers** (`RoundStopwatchReveal`): a cheap `RoundUsesStopwatchReveal` EXISTS first, asked for **any secret row** of the round (it runs before the lock, and a secret row may turn to `stopwatch` meanwhile) - a round without a secret puzzle keeps its statements and locks; otherwise `SecretPuzzleHides::lockRoundsForChange()` (round `FOR NO KEY UPDATE`, its secret puzzles, EM cleared). Start / Schedule with the tick: refused before anything changes when the list is empty (`reveal_not_allowed`) or not the confirmed one (`reveal_changed_meanwhile`, 409 as JSON); a start that changes nothing (already running) reveals nothing. **Every schedule, start, cancel, stop and reset first withdraws a reveal still ahead** (a countdown started with the tick), then applies the tick at the new moment (now / the resume moment / the scheduled moment), then re-syncs the puzzles' site-wide hide. So schedule 10:00 ticked → move to 10:30 unticked = still hidden at 10:00 and after; a reset after a real start never hides anything again.
- **Removing rows during a countdown with the tick** (review of A1): removing the puzzle (`RemovePuzzleFromCompetitionRound`), replacing the list (`SetCompetitionRoundPuzzles`, the internal API PUT), deleting the round, the event or the series first calls `RoundStopwatchReveal::withdrawBeforeRemoving()` on the rows going, under the change's locks (rounds, then puzzles) and after its checks: the reveal still ahead is taken back and the puzzle re-synced to `HIDDEN_UNTIL_REVEALED` **while the rows exist** - with no row left the dates stay as they are (`SecretPuzzleHides`), so the puzzle stays hidden instead of coming out at the old moment. The round's other rows keep their reveal; a moment already passed is never taken back. Only for rounds with a secret puzzle - every other round runs the statements it ran before (`StopwatchRevealRemovalTest` pins them). `MoveRoundToCompetition` keeps the rows (and refuses a scheduled or running stopwatch).
- **Never started with the tick:** stays hidden like a manual reveal - no automatic fallback (a late reveal is harmless, an early one is not).
- **Saying so once the round's time is over** (competition toolkit G3, A §4.4): the round state's round carries
  `stopwatchRevealMissed` = how many of its `stopwatch` rows are still hidden without a moment, **only** when that is > 0
  and the time is over - the stopwatch runs or ran without the tick (`RoundStopwatchStatus::hasStarted()`, the round
  puzzles page's "use Reveal now" notion), or it was never started and the round's start + limit (its start, without a
  limit) has passed; a countdown still ahead is not over. Counted in the statement `GetRoundResultsOverview` runs
  anyway (no statement more; a count, never a name - the state reaches referees). The **results desk** shows "Still
  hidden: the stopwatch was never started with “Reveal”…" with a link to the round puzzles page ("Reveal now"), only on a
  round using the reveal mode (`OfficialResultsRound::$usesStopwatchReveal` from the round list's statement - every other
  desk renders as before) and kept live with the state.
- **"Also reveal the hidden puzzles now" when publishing** (G3, R §2.1.5): the desk's publish dialog of such a round asks
  `official_results_missed_reveal` (GET, `COMPETITION_EDIT` - organisers only, never referees) for
  `SecretRevealPreview::byRevealingWhatTheStopwatchMissed()` - names, scope, until when elsewhere - and its `hash()`.
  The box is **unticked by default, always** (publishing alone reveals nothing: the published page names a hidden puzzle
  nowhere; and an organiser running the same puzzles at another venue later must not lose them by a reflex click).
  Ticked: the publish body carries `revealMissed: true` + `revealHash`; the controller dispatches `PublishRoundResults`
  first, then **in a dispatch of its own** `RevealPuzzlesTheStopwatchMissed` (`SecretPuzzleHides::lockRoundsForChange()`,
  the list computed again under the lock, `hash` compared - a list changed meanwhile reveals **nothing**,
  `StopwatchMissedRevealChanged`; then each row `revealNow()` like "Reveal now" + `resync`). All of the rows or none;
  the publish stands either way. Answer `reveal: revealed | changed`, then the reveal push
  (`OfficialResultsLiveUpdates::puzzlesRevealed()`). Tests `StopwatchMissedRevealTest`.
- **Reveal push** (R §1.5): after a start/schedule with the tick, "Reveal now" and a saved reveal setting, the controller publishes `official_results.refresh` on both private round topics (`OfficialResultsLiveUpdates::puzzlesRevealed()`, after the commit). The public `/round-stopwatch/{roundId}` topic and state never carry anything of the puzzles (`StopwatchRevealPagesTest` pins the keys).
- **Blue-green:** an old release reading a `stopwatch` row would fail `RoundPuzzleReveal::from()`. It was admin-only during the deploy that introduced it (#406); every organiser is offered it since 2026-11-17, when every running release reads it.
- Tests: `StopwatchRevealTest` (handlers, the reschedule matrix), `StopwatchRevealRemovalTest` (every removing writer, statement pins), `StopwatchRevealPagesTest`, `SecretPuzzleRevealInvariantTest` + `SecretPuzzleRoutesTest` (scheduled − 1 s), `RoundsInternalApiTest`.

## Round format and puzzle labels (competition toolkit A2)

Design: `toolkit/A-round-formats.md` §2.1, §2.5, §2.6, §6.1; binding contract `toolkit/R-review-and-plan.md` §1.1, §1.5, §1.8.

- **Model:** `competition_round.puzzle_format` (`RoundPuzzleFormat`: `total` = one result for the round, the default and every round before the toolkit; `pick` = each entry solves `puzzles_to_solve` K of the round's N puzzles, any order, 1 ≤ K ≤ N - "Solve all 3" is K = N, "Pick 2 of 3" K < N). `CompetitionRound::changePuzzleFormat()` / `assertPuzzleFormat()` / `assertKeepsEnoughPuzzles()`. A new or duplicated round is `total` (`DuplicateCompetitionHandler` copies no puzzles).
- **Positions and labels:** `competition_round_puzzle.position` (1..N) and `label` (≤ 40 characters, the neutral name before the reveal - "Blue box"). **Computed on read, never backfilled:** the order is `position NULLS LAST, id` (`RoundPuzzleLabel::sqlOrder()` / `sort()`), so rows never arranged keep their add order and a puzzle added after an arrangement comes last; the label falls back to the letter of the place (A, B, C … Z, then 27, 28 …). Never ordered by name - that would hint at a secret puzzle. `ArrangeRoundPuzzles` (the "Order and labels" box on the round puzzles page) gives every row a position.
- **Where it is set:** the round form field "How are this round's puzzles solved?" (+ "Puzzles each entrant solves") - only for `COMPETITION_TOOLKIT_MANAGE`, or for every organiser once the round uses `pick` (to switch it back). Sent as `EditCompetitionRound::$puzzleFormat` (null = keep, every other caller). The round puzzles page shows the format line and "Order and labels" behind the same voter; the hub card ("Round formats & reveals") lists each round's format and reveal mode.
- **Guards** (`RoundPuzzleFormatRefused`, 409, nothing changes): K outside 1..N (`to_solve_range`); removing a puzzle (`RemovePuzzleFromCompetitionRound`, `SetCompetitionRoundPuzzles` incl. the internal API PUT) that would leave N < K (`too_few_puzzles` - lower K first); `pick` on a round with online timing (`online_timing`), and online timing is not offered for a `pick` round (`OnlineTimingAvailability::PickFormat`).
- **Round JSON** (`official_results_round_state` `round` / `rounds[]`, Mercure `official_results.round` / `.entries`, the desk's actions - everything built by `GetRoundResultsOverview`): `puzzleFormat: {mode: "pick", toSolve, puzzles: [{roundPuzzleId, position, label, hidden, revealsAt, puzzleId, name, brand, imageUrl, piecesCount}]}` **only on `pick` rounds** (`RoundPuzzleFormats`, no statement for an event without one - `RoundResultsStateGoldenTest` unchanged). Built from `RoundPuzzlesVisibleNow`'s slots, so the public rule holds for organisers and referees too: a hidden puzzle comes by label/position only (`puzzleId`, `name`, `brand`, `imageUrl` null). `label` is always the effective label (never null). `piecesCount` goes to organisers and referees always (`GetRoundPuzzlePiecesCounts`, the parity with the round's `piecesCount`). A format change or a new arrangement pushes the round to the open pages.
- **Internal API:** read-only `puzzleFormat`, `puzzlesToSolve`, per puzzle `position`, `label`, `effectiveLabel`.
- The judge's puzzle choice: "Judge page" below (C10). The desk's per-puzzle editor, the sheet and the export: A4 below. The public round page's chips: A5 below.
- Tests: `tests/CompetitionToolkit/RoundPuzzleFormatTest.php` (model, guards, labels), `RoundPuzzleFormatPagesTest.php` (state JSON for organiser and referee, form, hub card, puzzles page).

## Per-puzzle results of `pick` rounds (competition toolkit A3)

Design: `toolkit/A-round-formats.md` §2.2-2.5, §6.2-6.3; binding `toolkit/R-review-and-plan.md` §1.1, §1.8.

- **Model:** `result_puzzles` jsonb on both entry tables (`HasOfficialResult`, `recordPuzzles()` / `officialPuzzles()`), value `RoundEntryPuzzles` = `[{roundPuzzleId, done, atSeconds?, piecesPlaced?}]` in solving order, ids are `competition_round_puzzle.id`, null = nothing recorded. A recorded choice is official data (`hasOfficialData()`, `OfficialResultsGuard` SQL twins, `ConvertCompetitionToSeriesHandler`, the participant import's site snapshot).
- **Write:** `RecordRoundResults` change `field: "puzzles"` (three-way `from`/`to`, receipts, conflicts carry the current value). Rules 1-5 per change (`puzzles_not_used`, `unknown_round_puzzle`, `puzzle_listed_twice`, `too_many_puzzles`, `invalid_split`, `splits_out_of_order`, `invalid_pieces_placed`), rule 6 on the entry as the whole set leaves it (`puzzles_mismatch` - DNS = no record; finished = K items all done, last split = the time; unfinished = Σ pieces of done items + `piecesPlaced` of the others, ≥ 1 not done), rule 7: a `result` + `puzzles` change of one entry in one set apply together or not at all (`group_refused` on the partner). Referees (`resultsOnly`) may send `puzzles`. An unfinished result without a record on a `pick` round is bound by K × the biggest puzzle − 1. Only a set with a `puzzles` change, or a `result` change on a `pick` round, reads the round's format FOR SHARE and its puzzles FOR KEY SHARE (`PickRoundRecords::lockForRecording()`); every other set runs today's statements (`RecordRoundResultsStatementPinTest` unchanged).
- **Read:** entries of a `pick` round carry `puzzles` in their JSON (`GetRoundResultEntries`, +1 statement for a `pick` round only); every other entry's JSON is unchanged (`RoundResultsStateGoldenTest`). Ranking stays `OfficialResultsRanking` on the total. The advancement seed divides an unfinished result by the entry's own puzzles once all K are recorded, else by the round's K biggest (`RoundResultEntry::$piecesCount`).
- **Round-side guards** (`RoundPuzzleFormatRefused`): removing a round puzzle a record names (`puzzle_in_results`; remove: row locked FOR UPDATE then checked; set puzzles: under the round's FOR NO KEY UPDATE), and a format/K change that would break a record (`conflicts_with_results`; `pick → total` with any record). Both only on `pick` rounds - a `total` round's removals keep their statements.
- **Browser:** `assets/official_results_puzzles.js` (`itemsRefusal`, `consistencyRefusal`, `validate`, `total`, `duration`, `unfinishedMaximum`) is the twin of `RoundEntryPuzzles`, pinned by `OfficialResultsPuzzlesParityTest` - judge page, desk and competitor page import it, never re-implement it.
- Tests: `tests/CompetitionToolkit/RoundEntryPuzzlesRecordingTest.php`, `tests/Value/RoundEntryPuzzlesTest.php`, `tests/OfficialResultsPuzzlesParityTest.php`.

## Results desk, participants sheet and export of `pick` rounds (competition toolkit A4)

- **Results desk** (`results_desk_controller.js`, pure half `assets/official_results_desk_puzzles.js`): the result cell of a `pick` round opens the **per-puzzle editor** - the outcome (finished / didn't finish / did not start / no result) and K rows in solving order: the puzzle by its label (the name only once the round state shows it - the public rule, organisers too), finished or not, "finished at" (the last finished puzzle's = the result's time) or pieces placed. The total is `total()` / `validate()` of `official_results_puzzles.js` (never re-implemented); the server's refusal reasons show before anything is sent. Save = `result` + `puzzles` as **one group** with the `from` each showed when the editor opened. "Clear per-puzzle record" sends `puzzles → null` alone (the result stays); "Total only" opens the plain editor, offered only for an entry without a record. Rows show the record as chips under the result ("B ✓ 0:45:10 · A 350 pcs"). A conflict on both of a group = one line "Saved meanwhile by Eva: 1:22:30 (B ✓ … · A ✓ …)".
- **Groups in `PendingChanges`** (`official_results_pending_changes.js`, shared by the desk and the sheet): cells set with the same `group` are taken only together (all queued, none on its way, room in the request - a group never straddles two requests), and keep mine / try again / discard on one act on the whole group. Cells without a group behave exactly as before.
- **Participants sheet**: the state carries `puzzleFormat` on `pick` rounds (`RoundPuzzleFormats::viewsFor()`, +2 statements only for an event with such a round) and `puzzles` on their entries; live updates merge `puzzles`. A result typed or pasted for an entry **with a record** takes the consistent record along in the same group and undo step (`sheet_puzzles.js` `withPickRecords()` → `consistentRecord()`: the chosen puzzles kept, recomputed by the judge page's `recordFor()`; when nothing fits - did not start, a time with fewer than K chosen, pieces that cannot be split - the record is cleared and a notice says so; undo brings both back), so it never dead-ends with `puzzles_mismatch`. A read-only **"Puzzles"** column shows the record by label. Per-puzzle times are edited on the desk. Texts only for an event with a `pick` round.
- **Export** (`RoundResultsExporter`): a `pick` round adds per position 1..K "Puzzle N" (label, `label - name` once out), "finished at", "own time", "pieces placed"; every other round exports exactly the 11 columns it always did.
- Tests: `tests/CompetitionToolkit/PickRoundDeskAndExportTest.php`, `tests/official-results-desk/puzzles.mjs` (via `OfficialResultsDeskHelpersTest`), `tests/participants-sheet-round/puzzles.mjs` (via `ParticipantsSheetRoundScriptsTest`), `ParticipantsSheetRoundTextsTest::testPickRoundTextsOnlyForAnEventWithAPickRound`.

## Public round page of `pick` rounds + the entrants view (competition toolkit A5)

Design: `toolkit/A-round-formats.md` §2.7-2.8, §3.4 (extraction); binding `toolkit/R-review-and-plan.md` §2.3, §1.5.

- **`OfficialEntrantsView`** (`src/Query/`) is the one place that turns official round entries into what the public may see - the round page (`GetPublishedRoundResults`), the competitor pages (B4) and the standings (A6) read entries only through it: published rounds only (`results_published_at` checked in the statement), entries with a ranked result, people removed from the event left out, ranked with `OfficialResultsRanking` over every ranked entry **then** the rows hidden from the viewer (`HiddenPlayers`, main's group rule) dropped without renumbering, private players masked (`PrivateProfileAccess::sqlIsPrivate()`; organiser's name only). API: `forRound(roundId, category, viewer)` / `forRounds([roundId => category], viewer)` → `OfficialRoundEntrants` (`entries` in rank order, `rankedCount`, `rankOf(RoundEntryRef)`, `isEmpty()`, the viewer's link/name/country). At most two statements for any number of rounds (one for solo rounds, one for pair/team rounds); one for the round page. Drafts are the caller's (404 first). A `pick` entry carries its record (`PublishedRoundEntry::$puzzles`, round puzzle ids only).
- **Chips:** on a published `pick` round each row lists the chosen puzzles in solving order (`round_results/_entry_puzzles.html.twig`, `PublishedEntryPuzzle::listOf(record, slots)`): label, the name only when `RoundPuzzlesVisibleNow`'s slot shows the puzzle to everybody (the public rule, organisers included), ✓ + the cumulative split, or the pieces of a puzzle not finished. A hidden puzzle comes by its label only - no name, picture, piece count, catalogue or round puzzle id in the page. An unfinished row's "pieces / total" uses the entry's own puzzles once all K are recorded and visible (`ownPiecesCount()`), else just the pieces. The format and the slots ride on `GetEditionRounds`' statement (`EditionRoundDetail::$puzzleFormat`, `$puzzlesToSolve`, `$puzzleSlots`), the record on the entries statement: a pick round costs no statement more than the same round as `total`.
- **"Add to my profile" per puzzle** (`PublishedRoundEntry::puzzleProfileTime()` - the one rule of the page and the pre-fill), for the viewer's entry or an unlinked one that may be theirs (the same rule as one-puzzle rounds), only for a puzzle shown with its picture: **K = 1** = the chosen puzzle exactly like a one-puzzle round (finished: the result's time; unfinished in a round with a limit: its pieces, the form opens with its switch on); **K ≥ 2** = one button per finished puzzle whose own time is known (its split minus the previous one), "Add Puzzle 14 · 0:30:00"; a puzzle not finished or without splits offers nothing - the entrant (only) reads "Per-puzzle times were not recorded…". "On your profile" per puzzle once the viewer has a time on that puzzle in the round (on an unlinked entry: that puzzle's time). Link: `puzzle_add` `?competition=&official_entry=&round_puzzle=<competition_round_puzzle id>`; `OfficialEntryTimePrefill` requires `round_puzzle` on a pick round and re-runs the page's read model; it travels in the form as hidden `round_puzzle`. `total` rounds: no parameter, nothing changed.
- **Unchanged for `total` rounds, byte for byte:** `OfficialRoundResultsGoldenTest` (published guest / entrant with an offer / published pairs / unpublished) - the chips are included at the end of an existing template line.
- Tests: `tests/CompetitionToolkit/PickRoundPublicPageTest.php`, `tests/Controller/OfficialRoundResultsGoldenTest.php`, the pick-round cases in `BlocklistCanaryTest` / `PrivateProfileCanaryTest`.

## Judge page (competition toolkit C10)

As built in [live-results.md](live-results.md) step 4 "Pick rounds" (`assets/live_entry_puzzles.js`): a chip per round
puzzle (label always, name and picture only once `hidden: false` - a name that came for a hidden slot is not shown),
the known choice preselected, one tap picks (K = 1) or taps in solving order (K ≥ 2); Save sends `result` + `puzzles` as
one outbox group (`enqueueGroup()`, never split - rule 7); "Save puzzle choice" records the choice alone; Find's
"Puzzle missing (N)" filter. Split times: "Judge split times" below (real events W5); the desk can enter `atSeconds` too.

## Judge split times - "Finished now" per puzzle (real events W5)

Design: `world-series-rules.md` "Review amendments" (a back-to-back block = ONE "Solve all 2 (order recorded)" round,
challenge 2's own time = split₂ − split₁). Pure logic `assets/live_entry_splits.js` (harness
`tests/live-entry-splits-harness.mjs` via `LiveEntrySplitsScriptsTest`), small hooks in `live_results_controller.js`.

- **Where:** the entry card of a `pick` round with K ≥ 2 (`splitFormat()`; K = 1 and `total` rounds: nothing changes),
  "Finish time per puzzle" under the chips (`splits` target, an entry step - hidden on the confirm step). Shown once a
  split is recorded, or while the stopwatch runs and the entry has no result; otherwise the card is as it was.
- **Rows** (`splitRows()`): the puzzles finished so far in solving order - ✓ split, "own" time from the 2nd on
  (`duration()`), ✎ typed correction; then, only while the entry has **no result**, the puzzles that may still be
  finished (`candidates()`: once K are chosen on the card or in the record only those, else every puzzle not finished,
  the chosen first) - "Finished now" while the stopwatch runs, ✎ a typed time (noted on paper).
- **A tap** freezes `serverNow()` at the tap (the round's Finished now rule - the D2 monotonic clock, works offline,
  `stopwatchTrusted()` re-check after; the tap moment is kept, never the receipt). Not the K-th puzzle
  (`splitStep()`): the record alone is saved at once (`puzzles`: finished items with `atSeconds`, then the other chosen
  puzzles not done), toast "Saved: Anna · Challenge 1 finished at 0:41:10" + Undo, the card stays open (the card's
  `shownFrom.puzzles` = the saved record, so the next change's `from` is what it shows; while the first change waits
  the outbox chains from it). A tap within 350 ms of a split save is ignored (double tap). **The K-th puzzle** goes to
  the card's confirm step with the elapsed time as the result (Finished now note, "+ pieces missing", 350 ms guard,
  draft restore after a reload); Save = `puzzles` + `result` as one outbox group (rule 7, `enqueueGroup()`).
- **The last split = the result** (`withFinishSplit()` / `finishFields()` on the card's Save): a finished time whose
  record has every earlier puzzle's split gets the last split = the time (rule 6). Records without splits (the judge
  never tapped per puzzle) are saved exactly as before. The round's own "Finished now" on a card with K − 1 puzzles
  finished (each with a split) and one left finishes that one (`finishChoice()`).
- **Typed correction:** ✎ on a finished puzzle replaces its split (the record alone, `splits_out_of_order` checked on the
  device first: "That time does not fit the order …"); never the last split of a finished entry - that is the result,
  the time field changes both (the last split follows by `withFinishSplit()`).
- Not here: the competitor page / proposals (a proposal still proposes the round's end); the desk's per-puzzle editor
  is unchanged. Server: nothing new - a `puzzles` change of finished items with splits and no result was always valid
  (rule 6 "no result yet = anything"); `RoundEntryPuzzlesRecordingTest::testPuzzlesFinishedOneByOneThenTheResultWithTheLastSplit`.
- Chaos suite `s14_puzzle_splits` (seed round "Chaos Solve All"): offline tap on puzzle 1, offline reload, online tap
  on puzzle 2 - both splits = the taps, 3 applied receipts.

## Missing pieces and time's up (reliability hardening T1)

Jan: "if they do not finish in time we know which puzzle did not finish in time and how many pieces were left ... when
time runs out we count missing pieces to show how many pieces were placed".

- **Format names** (`competition_toolkit.round_format.name.*`): "One result for the round" / "Solve all N (order
  recorded)" / "Pick K of N" on the round form, the hub card and the round puzzles page (`_round_format_summary`). The
  round form offers three choices: "Solve all" is `pick` with K = N set on save (`CompetitionRoundFormType` SUBMIT
  listener, `CompetitionRoundFormData::$puzzleFormatChoice` - no column), read back as "Solve all" whenever K = N; K is
  typed only for "Pick". The competitor page keeps its own wording ("Solve all 3 puzzles", "Pick 1 of 2 puzzles");
  the creation wizard keeps its "Competitors solve" field (another lane's files).
- **Order**: the record's order is the solving order (2 → 3 → 1 is `[B, C, A]`); a puzzle not finished comes after the
  finished ones (rule 5). **Several puzzles not finished** ("abandoned one, started another") are allowed by rule 6 and
  taken by the write path, the desk, the export and the public chips (`SolveAllMissingPiecesTest`); one with nothing
  placed has no `piecesPlaced`.
- **Missing | Placed** (`assets/official_results_missing.js`, shared by the judge page and the desk; pure, harness
  `tests/live-results-harness.mjs`): the judge types what is missing, the page shows "missing 37 → placed 963 of
  1000" and saves `piecesPlaced` (no server change). Missing needs the piece count (unknown → only Placed). One-puzzle
  rounds: 1..pieces − 1. Pick rounds: a row per chosen puzzle with a finished / not finished switch (default: the last
  chosen not finished; it follows the last chip tapped until the judge switches a row), a count per one not finished
  (0 placed allowed there as long as the entry placed something); placed = Σ finished puzzles' pieces + Σ the others'
  placed; `recordFor(…, notDone)` builds the record. The choice is kept per phone (`msp.liveResults.prefs`
  `piecesMode`, default Missing); drafts keep the mode, the rows not finished and each count. Switching converts what
  was typed (37 missing ↔ 963 placed).
- **Time's up** (`timesUp()`, `opensUnfinished()`): the round has a limit and the stopwatch ran past it (running, or
  stopped after it) - an entry without a result, without a time proposal waiting and without a refused value opens
  straight in Didn't finish / Missing with the focus in the count (a pick round without a choice: the chips first;
  after the K-th chip the first empty count). **A default, never a restriction**: "Back to the time" is one tap (a time
  written down during the round). Taps after time's up: table (unique) → type missing → Review/Enter → Save = 2 taps
  (before: + the "Didn't finish" tap and the subtraction in the head = 3); a finished time: table → Back to the time
  → type → Review → Save = 3 taps (before: the focus was on "Finished now", so a tap into the time field = 3).
- **Results desk**: "Didn't finish (missing pieces)" in the result editor (rounds with a piece count) and the
  per-puzzle editor (per puzzle; the preview lists both numbers per puzzle); switching between the two Didn't-finish
  modes converts the counts. **Participants sheet**: unchanged - its result cell keeps pieces *placed* (`480p`,
  `480/500`); "-" already means did not start, so no missing syntax was added. Its recompute of a record
  (`consistentRecord()` → `recordFor()`) keeps every puzzle not finished open and gives the change to the last of them.

## Known edges and open items

- A round duplicated with its event is `total` (`DuplicateCompetition` copies no round puzzles) - **said** on the
  duplicate form ("Puzzle formats reset … Round 2 was “Pick 2 of 3”", `data-duplicate-formats-reset`) and in a warning
  flash after the copy (competition toolkit G3). A relaxed guard (a `pick` round with fewer than K puzzles) was not
  chosen: every reader would have to handle that state. Copying the puzzles themselves is not trivial: secret puzzles
  would need their reveal moments shifted by the date shift and re-synced site-wide (`SecretPuzzleHides`), so a copy
  could hide a public puzzle again or keep one hidden by the old date - left out on purpose.
- The JS twin `official_results_puzzles.js` checks the item and consistency rules but not every shape rule the PHP value
  object enforces (malformed JSON from a hand-made request) - the server stays the judge (docs/TODO.md "Competition
  toolkit v2").
- A puzzle's `pieces_count` changed in the catalogue after a record was stored can make an existing unfinished record
  inconsistent (`puzzles_mismatch` on the next edit of that entry) - clear the record on the desk ("Clear per-puzzle
  record") and enter it again.
- Never started with the tick = hidden like a manual reveal; "Reveal now" on the round puzzles page fixes it, the desk
  says so and its publish dialog offers the reveal (G3, above).
- Deferred to v2 (R §3): `every` as its own mode (= pick K = N), internal API writes of format and labels, the sheet's
  per-puzzle popover. (Judge split times: built, real events W5 above.)
