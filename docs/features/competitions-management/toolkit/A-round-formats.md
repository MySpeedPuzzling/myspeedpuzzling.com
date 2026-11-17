# A - Round formats: multi-puzzle rounds, standings across rounds, reveal on stopwatch start

Architect A, 2026-11-17. Design only, grounded in `main` at `0ad091b37`. Consumers: B (QR / competitor page /
proposals), C (judge live entry), D (displays / overlays) - see "Contract for B/C/D" (section 6).

Everything here is opt-in per round or per competition. A competition nobody touched keeps every column at its default,
every JSON key absent and every statement count the same (section 8).

---

## 1. Findings - what exists today

### 1.1 Several puzzles in one round

- `CompetitionRound::$roundPuzzles` (`src/Entity/CompetitionRound.php:57`) is a plain OneToMany. `CompetitionRoundPuzzle`
  (`src/Entity/CompetitionRoundPuzzle.php:32-58`) has **no position, no label** and nothing that says how the puzzles
  are solved.
- **One official result per entry, whatever the puzzle count.** `HasOfficialResult` (`src/Entity/HasOfficialResult.php:21-103`)
  holds `resultSeconds | resultPiecesPlaced | resultDidNotStart`. The docs say the same thing: "A round with several
  puzzles has one total result (open detail)" (`docs/features/competitions-management/participants-spreadsheet.md:756`),
  "Not built yet: Several puzzles per round" (`official-results.md:358`), and `docs/TODO.md:873` + `:72` (relay events:
  two puzzles, one total time).
- The pieces bound only works for one puzzle. `CompetitionRound::singlePuzzlePiecesCount()` (`CompetitionRound.php:274-283`)
  returns null when there are several, and then `RecordRoundResultsHandler::valueRefusal()` (`:455-485`) falls back to
  `MAX_PIECES_PLACED = 100000` (`:57`). `GetRoundResultsOverview` sends `pieces_count` only when `COUNT(*) = 1` (`:51-52`).
  `AdvanceQualifiedHandler` passes `singlePuzzlePiecesCount()` to `AdvancementSeeding` (unfinished ratio, else raw pieces).
- **"Add to my profile" is single-puzzle only.** `GetPublishedRoundResults.php:150-156`: `profilePuzzleId` is set only when
  the round has exactly one puzzle and the viewer can see its image. Otherwise the button never shows
  (`templates/round_results/_official.html.twig:57`).
- **Time → round linking already handles several puzzles.** `SolvingTimeRoundResolver::resolveFor()`
  (`src/Services/RoundResults/SolvingTimeRoundResolver.php`) picks the round of the competition that has the time's
  puzzle in the time's category. Each puzzle of a multi-puzzle round links its own times to that round, and the
  players'-times part of the round page already shows one card per puzzle (`templates/round_results/_puzzle_card.html.twig`).
  The invariant (one round per category per puzzle per competition, `PuzzleAlreadyInCompetitionRoundCategory`) still
  holds for "pick 1 of 3": each of the 3 puzzles belongs to that round only.
- **Ranking**: `OfficialResultsRanking` (`src/Services/OfficialResultsRanking.php`) sorts finished results by seconds,
  then unfinished results by pieces placed descending. Ties share a rank. DNS and "no result" are unranked. The browser
  twin is `assets/official_results_ranking.js`, pinned by `OfficialResultsRankingParityTest`.

### 1.2 The write path (must not break)

- `RecordRoundResults` (`src/Message/RecordRoundResults.php`) implements `SerializedByLock` with
  `CompetitionParticipantsLock::key()`. Its changes are `RoundResultChange` items: `{clientChangeId, entry|newEntry,
  field, from, to}`. `RoundResultField` has `result | table_number | qualified`. `RoundResultChangesParser` reads the
  wire format: an unknown `field` refuses that one change with `invalid_change`, and a missing `clientChangeId`
  refuses the whole request.
- The handler (`RecordRoundResultsHandler.php:74-155`) plans every change three-way against the current state. It loops
  the plan while table-number clashes exclude changes (`:97-103`), applies the plan, writes receipts
  (`RoundResultChangeReceipt` = idempotency) and returns `RecordedRoundResults` (one outcome per change: applied /
  unchanged / conflict / rejected + reason).
- `RoundEntryResult` wire format: `null | {"seconds": n} | {"piecesPlaced": n} | {"didNotStart": true}`, with
  **exactly one key** (`RoundEntryResult::fromWire()`). Every client parses it: the desk, the sheet, the live entry,
  the outbox (`assets/official_results_outbox.js`) and the ranking parity.
- Entry JSON = `RoundResultEntry::jsonSerialize()` (`src/Results/RoundResultEntry.php:131`). Round JSON =
  `RoundResultsOverview::jsonSerialize()` (`src/Results/RoundResultsOverview.php:60`). Both are served by
  `official_results_round_state` (`src/Controller/OfficialResults/RoundResultsStateController.php`), sent on the
  round's private Mercure topic and bootstrapped by `live_results`. Referees get them through `RefereeEntriesView`
  (private players withheld).
- `ConfirmOnlineResult` calls `RecordRoundResultsHandler` inside its own transaction and lock (online review).

### 1.3 Qualification today

- Qualified marks are always the organiser's explicit decision: `HasOfficialResult::$qualifiedAt`, the desk helpers
  Top N and best of each country (`assets/official_results_qualification.js`), plus "Apply" = ordinary `qualified`
  changes.
- `AdvanceQualified` (`src/Message/AdvanceQualified.php`) takes source rounds → target rounds, `AdvanceDistribution`
  (`single | balanced | by_source`), an optional `bestOfEachCountry`, and runs as a dry run → `planHash` → apply
  (`AdvancementPlanChanged` on drift). Solo entries move by participant. Pairs/teams move as a new `CompetitionTeam`
  with the same member set (`AdvanceQualifiedHandler::identity()`, `:312`).
- `AdvancementSeeding` orders entries across several rounds: rank within the round, then relative to the round's winner.
- **Missing:** the structure is not declared anywhere. Each advance is a one-off dialog: nothing stores "groups A-D
  feed S1/S2, top 25 each", nothing draws the bracket, and nothing gives a final classification across stages. There is
  no competition-level standing of any kind (no sum).

### 1.4 Secret puzzles and the reveal

- `RoundPuzzleReveal` (`src/Value/RoundPuzzleReveal.php:23-27`) has three modes. `automatic` = `startsAt` + the round's
  `revealDelayMinutes`. `scheduled` = `reveal_at`. `manual` = no moment until "Reveal now". PHP `revealAt()` (`:47-54`)
  and SQL `sqlRevealAt()` / `sqlHidden()` (`:96-118`) are the only places that compute the moment
  (`RoundRevealMomentComputedOnlyHereTest`). Twelve readers use the SQL form (GetCompetitionPuzzles, GetPuzzleOverview,
  SeriesEditionMatch, OccurrenceRounds, SecretPuzzleAccess, …).
- `CompetitionRoundPuzzle::isHiddenAt()` (`:74-83`) and `revealNow()` (`:111-118`) work like this: revealNow sets mode
  `scheduled` with `revealAt = now` and records `CompetitionRoundsChanged`, so series picks match by puzzle.
- `SecretPuzzleHides::hideOf()` (`src/Services/SecretPuzzleHides.php:221-258`) computes the site-wide
  `puzzle.hide_until` / `hide_image_until`. A row without a moment counts as `HIDDEN_UNTIL_REVEALED` (9999-12-31).
  `resync()` writes them. `lockRoundsForChange()` (`:285-293`) and `lockRoundPuzzle()` (`:311-330`) are the lock
  protocol (round, then puzzles, then EM clear).
- `SecretRevealPreview` + `hash()` drive the "what would this reveal" confirmation (`docs/.../README.md:361`).
  `ChangeRoundPuzzleRevealHandler` (`:68-80`) refuses an automatic reveal whose moment changed meanwhile
  (`AutomaticRevealChangedMeanwhile`) and a moment already passed (`RevealMomentAlreadyPassed`).
- **Stopwatch start knows nothing about puzzles.** `StartRoundStopwatchHandler` (`:33-41`),
  `ScheduleRoundStartHandler` (`:34-43`) and `CancelRoundStartHandler` (`:31-36`) only call the entity methods
  (`CompetitionRound.php:338-425`). `ResetRoundStopwatchHandler` (`:43-64`) is the only one serialised by the
  participants lock. All stopwatch controllers go through `AbstractRoundStopwatchController::act()` (`:61`): form POST or
  JSON from the online control room (`assets/controllers/online_control_controller.js`), Mercure published after the
  dispatch.
- The internal API exposes `revealMode` / `revealsAt` per round puzzle as read-only output (`docs/features/internal-api.md:866-889`).
  It never sets a per-puzzle mode.
- Referees are not organisers, so `SecretPuzzleAccess` hides secret puzzles from them until the reveal. A judge's
  "which puzzle" picker therefore **cannot show names** before the reveal. That is why rows get a neutral label (2.1).

---

## 2. Multi-puzzle rounds

### 2.1 Data model (additive)

`competition_round`:

| column | type | default | meaning |
|---|---|---|---|
| `puzzle_format` | varchar enum `RoundPuzzleFormat` | `'total'` | `total` = today: one result per entry, whatever the puzzles (also the relay case, TODO:72). `every` = every puzzle of the round is solved, with optional per-puzzle detail. `pick` = the entry picks `puzzles_to_solve` of the N puzzles, any order |
| `puzzles_to_solve` | smallint null | null | only for `pick`: K, 1 ≤ K < N. K = 1 is "pick one of N" |

`competition_round_puzzle`:

| column | type | default | meaning |
|---|---|---|---|
| `position` | smallint null | null | the organiser's order, 1..N. Null on old rows: they keep today's order, and readers order by `position NULLS LAST, id` |
| `label` | varchar(40) null | null | the neutral name judges and participants see before the reveal ("Blue box", "A"). When null, the UI shows "Puzzle {position}" |

Entry tables (`competition_participant_round`, `competition_team`), through the `HasOfficialResult` trait:

| column | type | default | meaning |
|---|---|---|---|
| `result_puzzles` | jsonb null | null | the per-puzzle record (2.2). Written only by `RecordRoundResults` |

**Why a jsonb column on the entry, not a new table.** It is written with the result in the same change set and
compared three-way as one value, so it travels through the outbox, the receipts and Mercure exactly like `result`. It
needs no new entity, repository or lock, and it doubles no statements on the reads that already load the entry row.
Integrity is guarded explicitly (2.5). The ids it stores are `competition_round_puzzle.id`, not puzzle ids, so a
catalogue merge (`CompetitionRoundPuzzle::moveToPuzzle()` keeps the row id) never breaks a record.

New entity methods:

- `CompetitionRound::changePuzzleFormat(RoundPuzzleFormat, ?int $toSolve)`, which guards the invariant.
- `CompetitionRound::puzzlesToSolveCount()`: N for `every`, K for `pick`, null for `total`.
- `CompetitionRoundPuzzle::placeAt(int $position, ?string $label)`.
- `HasOfficialResult::recordPuzzles(?RoundEntryPuzzles)`.
- `hasOfficialData()` also becomes true when `result_puzzles` is set: a recorded choice is official data, so it is never
  dropped as a side effect.

### 2.2 The per-entry record: `RoundEntryPuzzles` (value object)

Wire format (canonical: the parser normalises key order, and the list order is meaningful):

```json
[
  {"roundPuzzleId": "0196…a1", "done": true,  "atSeconds": 2710},
  {"roundPuzzleId": "0196…c3", "done": false, "piecesPlaced": 312}
]
```

- **List order = solving order.** For `pick`, the items are the puzzles the entry chose.
- `done: true` means the puzzle is finished. `atSeconds` (optional) is the elapsed round time when it was finished. It
  is a cumulative split, which is what a judge's "Finished now" naturally produces. A puzzle's own duration is
  `atSeconds − previous atSeconds`.
- `done: false` means chosen or in progress. `piecesPlaced` (optional) is the count when time ran out.
- `null` means nothing recorded, which is every entry of every round today.

Rules, checked in `RecordRoundResultsHandler` on the **final state** of the entry after the whole change set:

1. Only rounds with `puzzle_format` `every|pick` accept it. Otherwise the reason is `puzzles_not_used`.
2. Each `roundPuzzleId` is a round puzzle of this round (`unknown_round_puzzle`) and appears once
   (`puzzle_listed_twice`).
3. Count ≤ K (`pick`) or ≤ N (`every`), else `too_many_puzzles`.
4. `atSeconds` is between 1 and `RoundEntryResult::MAX_SECONDS` (`invalid_split`). Given splits strictly increase in list
   order, and a `done:false` item never comes before a `done:true` one (`splits_out_of_order`).
5. `piecesPlaced` is between 1 and that puzzle's piece count − 1 (`invalid_pieces_placed`, the existing reason).
6. Consistency with `result` (`puzzles_mismatch`):
   - DNS → `puzzles` must be null.
   - Finished `{seconds}` with a non-null record → exactly K (`pick`) or N (`every`) items, all `done`. The last
     item's `atSeconds`, when given, equals `seconds`.
   - Unfinished `{piecesPlaced: P}` with a non-null record → P = Σ(piece count of done items) + Σ(`piecesPlaced` of
     not-done items), and at least one item is not done.
   - No result yet → anything that passes rules 1-5. This allows a choice recorded at the table and splits during the
     round ("finished box 1 at 41:10"). The result comes later.
7. `result` and `puzzles` changes for the **same entry in one request form a group**: they apply together or not at
   all. A partner of a refused or conflicting change gets `rejected` + `group_refused`. Planning loops like the
   table-number clash loop (`:97-103`): a new `groupRefusals()` pass is added to the `do … while`.

The unfinished upper bound in `valueRefusal()` changes for `every|pick` rounds:

- with a record: the rule-6 sum;
- without one: K × max piece count of the round's puzzles − 1 (`pick`), or Σ piece counts − 1 (`every`).

`total` rounds keep `singlePuzzlePiecesCount()` exactly as today.

### 2.3 Ranking - one rule, no new code path

**The ranking stays `OfficialResultsRanking` on `result`, unchanged.** That keeps the JS parity, the desk, the public
page, the export, the seeding and the standings in agreement for free.

- Finished means all K (or N) puzzles are done, ranked by the final time.
- Unfinished entries rank by **total pieces placed**, where each finished puzzle counts its full piece count. With
  equal piece counts (the normal "2 of 3 boxes of 500" case) this is exactly "more puzzles completed, then more pieces".
  With unequal piece counts it is "more pieces placed overall", which is the fairer reading.

Nothing is configurable here, because the real world does not vary on this point. The splits are information, never a
ranking input. A future tie-break by "finished the first puzzle earlier" would be one comparator option; it is
deliberately not built (open question Q1).

Seeding: `AdvancementSeeding` gains an optional per-entry piece total (from the record, or the round bound) instead of
`piecesCountByRound` for `every|pick` rounds. `total` rounds pass what they pass today.

### 2.4 Change protocol - `RoundResultField::Puzzles`

- New enum case `Puzzles = 'puzzles'`. `RoundResultChangesParser::value()` reads it through
  `RoundEntryPuzzles::fromWire()`, where null or a list is allowed and any other shape is `invalid_change`.
- `RoundResultChange::$from/$to` widen to `…|RoundEntryPuzzles`. `same()` compares with `RoundEntryPuzzles::equals()`.
- **`resultsOnly` referees may send `puzzles`.** The check `resultsOnly && field !== Result` (`:285`) becomes
  `!in_array(field, [Result, Puzzles])`. The puzzle choice is part of the result.
- **Old clients are unaffected.** They never send `puzzles`. A `result` change from an old cached page on a `pick`
  round still goes through rules 6/7. If it contradicts a stored record (e.g. a total that does not match the splits),
  it comes back `rejected: puzzles_mismatch`, which the outbox already shows as a refusal with the reason.
- **Blue-green window.** A new page sending `puzzles` to an old pod gets `invalid_change` for that change only. The
  outbox keeps it as refused, so it is never lost silently. Acceptable, because only opted-in rounds send it.
- Receipts and idempotency are unchanged: a `puzzles` change has its own `clientChangeId`.
- **Locks.** Only when the change set contains a `puzzles` change, the handler runs one extra
  `SELECT … FROM competition_round WHERE id = :round FOR SHARE` (the format cannot change meanwhile) and
  `SELECT id, puzzle_id FROM competition_round_puzzle WHERE round_id = :round FOR KEY SHARE` (no referenced row can be
  deleted meanwhile, see 2.5). Change sets without `puzzles` run exactly today's statements.
- `RecordedRoundResults` stays as is. `OfficialResultsLiveUpdates` sends the entry JSON, which now carries `puzzles` (6.2).

### 2.5 Guards on the round side

- **Removing a round puzzle that an entry's record references is refused** with `RoundPuzzleHasResults` (409, a form
  error, internal API `409 round_puzzle_has_results`). This applies to `RemovePuzzleFromCompetitionRoundHandler`,
  `SetCompetitionRoundPuzzlesHandler` (checked first, nothing changes) and `MoveRoundToCompetitionHandler` (which
  already refuses entries). The check runs **after** locking the row `FOR UPDATE`, so it serialises against the
  `FOR KEY SHARE` above. The query is `… WHERE result_puzzles @> jsonb_build_array(jsonb_build_object('roundPuzzleId', :id))`
  on both entry tables of the round.
- **Changing the format or K is refused** while it contradicts a stored record (`RoundFormatConflictsWithResults`). For
  example, lowering K below a record's item count, or `pick → total` while records exist. The fix is to clear the
  records first. `EditCompetitionRoundHandler` already takes `FOR NO KEY UPDATE` on the round, which conflicts with
  the `FOR SHARE` above.
- **A `pick` round needs N > K.** Removing a puzzle so that N ≤ K is refused (`RoundFormatNeedsMorePuzzles`). `every`
  needs N ≥ 2, but N = 1 is only a warning (it behaves like `total`).
- **Online timing** (`OnlineRound`) is refused on `every|pick` rounds in v1 (`EnableOnlineRound` → `OnlineRoundNeedsOnePuzzleFormat`).
  The compete page, its evidence and its review are built around one puzzle. That is a later phase.

### 2.6 Organiser UI (behind the toolkit flag, see 9)

- **The round form** (`CompetitionRoundFormData`, `EditCompetitionRoundController`) gets "How are this round's puzzles
  solved?":
  - "One result for the whole round" (default, today's behaviour)
  - "Every puzzle, with times per puzzle"
  - "Each entrant picks [K] of them"
  
  Shown only when the flag voter says so, or when the round already uses a non-default format. Internal API
  `RoundInput` adds `puzzleFormat` (`total|every|pick`) and `puzzlesToSolve`; left out of a PATCH means unchanged.
- **The round puzzles page** (`manage_round_puzzles.html.twig`): position (drag or arrows) and label per puzzle.
  `SetCompetitionRoundPuzzles` keeps list order = position. Internal API `PUT …/puzzles` with `puzzleIds` order sets
  positions; new optional `labels` map.
- **Results desk** (`results_desk_controller.js`): the result cell of an `every|pick` round opens a per-puzzle editor
  (choose puzzle(s) by label, per puzzle finished-at or pieces). It computes the total and sends `result` + `puzzles`
  as one group. A row shows chips "B ✓ 41:10 · A ✓ 1:22:03". The plain total editor stays available, but only for an
  entry without a record.
- **Participants sheet**: the result cell shows the total plus a per-puzzle popover with the same group write. Typed
  totals on an entry with a record come back `puzzles_mismatch` with the text "per-puzzle times say 1:22:03 - change
  them or clear them".
- **Export** (`RoundResultsExporter`): `every|pick` rounds add per position the puzzle label/name, finished at, own
  duration and pieces. `total` rounds export the same columns as today.

### 2.7 Public round page, official ranks, "Add to my profile"

- `GetPublishedRoundResults` reads `result_puzzles`. For `every|pick` rounds, each row shows the chosen puzzles in
  solving order (thumbnail + name, **only** when the puzzle is not hidden from the viewer: `sqlHidden` per row, else
  the label) and the splits in an expandable line. Ranks are unchanged (2.3). The rows' "pieces / total" text uses the
  entry's own piece total.
- **"Add to my profile" (proposal).** A player's solving time is per puzzle, so the button is offered per puzzle the
  entry finished, when its own time is known:
  - `pick` with K = 1: the chosen puzzle, time = the result's seconds. An unfinished result is offered like today:
    pieces placed, with the round's limit as the time spent. This is exactly today's single-puzzle behaviour,
    `profilePuzzleId` = the entry's chosen puzzle.
  - `every`, or `pick` with K ≥ 2: one "Add {puzzle} · 41:10" per **done** item whose duration is derivable (the
    first item needs `atSeconds`; later items need their own and the previous one's). An unfinished last puzzle is not
    offered in v1, because its time spent would be "limit − previous split", which the add form's unfinished mode
    (round limit) cannot express. See open question Q2.
  - Without splits: no per-puzzle button. The line "Per-puzzle times were not recorded" is shown to the entry's own
    viewer only.
  - The state is per puzzle: "On your profile" when the viewer has a time with `competition_round_id` = this round on
    that puzzle. `OfficialEntryTimePrefill` / `OfficialEntryTime` get `$roundPuzzleId`, and the `official_entry` query
    parameter gains `&round_puzzle=<id>`. Nothing changes for `total` rounds (no parameter, same rule).
- `SolvingTimeRoundResolver` is unchanged: each puzzle's time links to the round by its own puzzle. The unofficial
  players'-times cards stay per puzzle.

### 2.8 Secret puzzles in pick rounds

- A pick-one round with 3 secret puzzles works with today's per-row reveal. "On stopwatch start" (section 4) reveals
  all of them at once.
- Before the reveal, every surface that asks "which puzzle" (judge picker C, competitor page B, overlays D, the desk
  for referees) shows **labels/positions only**. The round JSON's `name`, `puzzleId`, `imageUrl` and `piecesCount` are
  null for a row hidden from the viewer: `SecretPuzzleAccess::hiddenFromViewerAmong()` for names, and the row's
  `isHiddenAt()` for public surfaces.
- Piece counts of hidden puzzles go to organisers and referees only. Today's round JSON already carries `piecesCount`
  to referees, so this does not get worse.

---

## 3. Standings across rounds

### 3.1 What real competitions do (from knowledge; assumptions marked)

- **WJPC (World Jigsaw Puzzle Championship, Valladolid)**: there is no sum. Individual qualification groups → semifinals
  → final. Final classification = final rank, then semifinalists by semifinal result, then the groups. Pairs and teams
  are single rounds or qualifiers → final. The country quota is the `bestOfEachCountry` rule that already exists.
- **National championships (AEPUZZ, USAJPA, Czech/Slovak, UK)**: mostly a single round, or heats → final. Unfinished
  entries are ranked by pieces placed. That is today's per-round rule.
- **Multi-round cumulative events** (Jan's "World Series", 6 solo + 6 pairs + 6 teams). *Assumption:* the winner has
  the lowest sum of the six round times. An entry that did not finish a round is either (a) charged the time limit,
  sometimes plus a penalty per missing piece, or (b) ranked behind everybody who finished more rounds.
  *Assumption:* both variants exist across organisers, and leagues sometimes drop the worst round.
- The design makes (a) and (b) one setting with a sensible default, and leaves "drop worst" for later (Q4).

### 3.2 Data model

`competition_standing` (new entity `CompetitionStanding`):

| column | type | meaning |
|---|---|---|
| `id` | uuid v7 | |
| `competition_id` | FK | one-time event or edition. A season-wide series standing is out of scope (Q4) |
| `category` | `RoundCategory` | solo / duo / team. Every round of the standing has it |
| `name` | varchar(80) | "Overall - Individuals" |
| `slug` | varchar | unique per competition, kept on rename (like round slugs) |
| `mode` | enum `StandingMode` | `sum` / `stages` |
| `round_ids` | jsonb list | ordered. `sum` = the rounds summed. `stages` = unused (taken from the steps, 3.5) |
| `unfinished_penalty_seconds_per_piece` | smallint, default 0 | `sum`: unfinished round = time limit + P × missing pieces, P 0..600 |
| `order_rule` | enum `StandingOrder`, default `finished_rounds_first` | `finished_rounds_first` / `total_only` |
| `missing_rule` | enum `StandingMissing`, default `incomplete` | `incomplete` / `as_zero_pieces` |
| `published_at` | timestamp null | the standing is public while set |
| `position` | smallint | the order of standings on the event page |

`round_ids` is jsonb rather than a join table because it is a short ordered list owned by one row and validated on
write. A deleted round is pruned by `DeleteCompetitionRoundHandler`, and `MoveRoundToCompetition` refuses a round that
is part of a standing.

### 3.3 The `sum` rule (the one function: `StandingCalculator::sum()`, pure)

Who is "the same entrant" across rounds:

- Solo: the `competition_participant` (soft-deleted ones left out).
- Pair/team: the **exact set** of participant ids. This is `AdvanceQualifiedHandler::identity()` and the site-wide
  exact-set rule for pairs & teams. A substitute makes a different line-up. The name shown is the team name of the
  latest included round.

Each line-up's score in each included round:

| official result | round score |
|---|---|
| finished | `seconds` |
| unfinished | `minutesLimit × 60 + P × missingPieces`. `missingPieces` = the entry's piece total (2.2 bound) − `piecesPlaced`. A round without a limit cannot score an unfinished result, so that round counts as missing for the entry. The settings page warns before saving |
| DNS / no result / not in the round | **missing** |

Ordering:

1. `missing_rule = incomplete` (default): line-ups with any missing round are listed after every complete line-up,
   **without a rank**, labelled "3 of 6 rounds", and ordered among themselves by rounds counted, then score.
   `as_zero_pieces`: a missing round scores like unfinished with 0 pieces placed (limit + P × all pieces). That needs a
   limit, else it stays missing.
2. `order_rule = finished_rounds_first` (default): rounds finished descending, then the total score ascending.
   `total_only`: total score ascending.
3. Equal keys share a rank, and the next rank skips (the same "1, 2, 2, 4" as `OfficialResultsRanking`). No hidden
   tie-break.

Defaults rationale. A pure sum with P = 0 makes "999 of 1000 placed" equal "10 of 1000 placed" in that round, and
`finished_rounds_first` rewards completion first, which is the reading most organisers expect. An organiser who
publishes "time limit + 5 s per missing piece" picks `total_only` with P = 5. Both are exactly one form.

### 3.4 Publishing rules

- **Public view** = `published_at` set **and** computed only over included rounds whose `results_published_at` is
  set. Unpublished rounds show "–" in their column, and the header says "Provisional · after 3 of 6 rounds" until all
  are published. A round with no published results contributes nothing, and nobody is "missing" a round that is not
  public yet.
- **Organiser view** (desk JSON) computes over every round, published or not, marked "includes unpublished rounds".
- Read-side guarantees on the public page: drafts (`IsCompetitionPubliclyVisible`), `HiddenPlayers` applied **after**
  ranking without renumbering (main's group rule: a line-up with a hidden linked member is hidden unless the viewer is
  in it), `PrivateProfileAccess` masking (organiser's name only) and soft-deleted participants left out. The row
  masking of `GetPublishedRoundResults` (`isHiddenFromViewer`, private links) is extracted into a shared
  `OfficialEntrantsView` so the standing and the round page cannot drift.

### 3.5 Brackets ("spider") - declared advancement steps

`competition_advancement_step` (new entity `CompetitionAdvancementStep`) persists exactly what `AdvanceQualified`
already takes, plus how many qualify:

| column | meaning |
|---|---|
| `id`, `competition_id` | |
| `name` | "Groups → Semifinals" |
| `source_round_ids` jsonb (ordered), `target_round_ids` jsonb (ordered) | same category, same competition, disjoint |
| `distribution` | `AdvanceDistribution` |
| `target_by_source` jsonb | `by_source` only |
| `top_per_source_round` smallint null | Top N of each source round. Null = the organiser marks by hand |
| `best_of_each_country` smallint null | the existing country rule |
| `position` | |

The rounds and steps of one category form a DAG. A cycle is refused, and so is a round targeted by two steps
(`InvalidAdvancementStep`). The **stage** of a round = the longest path from a round with no incoming step (groups =
stage 1, semis = 2, final = 3).

Behaviour - qualification stays the organiser's decision; nothing advances by itself:

- **Results overview + desk**: the desk of a source round shows "Step: Groups → Semifinals - top 25 of each round + best
  1 of each country".
  - "Mark top 25" pre-selects with the existing Top N helper (review dialog, ties highlighted).
  - "Advance" opens `advance_qualified_controller.js` **prefilled** from the step (sources, targets, distribution,
    country rule). Dry run, plan hash and apply work as today. The step is a preset, not a new write path.
- **Bracket view** (organiser overview and public): columns = stages, cards = rounds (name, start, status, entries), and
  lines = steps labelled "top 25 →".
  - Public cards show the round's qualified entries only once that round's results are published (qualified marks are
    part of the published results today).
  - Rendered as server-side HTML with CSS grid plus inline SVG connectors. No new JS library.
- **`stages` standing** (final classification across the bracket):
  - Entrants who reached the deepest stage, ranked by that round's official rank. With several parallel final rounds,
    they are grouped per round without a combined number.
  - Then, per earlier stage, the entrants eliminated there, grouped under "Semifinal", "Groups" with their own round
    rank. **No invented numbers across stages by default.**
  - Optional `numbered_across_stages = true` numbers everybody (deeper stage first, then `AdvancementSeeding` across the
    parallel rounds of a stage). Organisers who publish an overall "1-200" list want that, and WJPC-style
    classification is exactly that.
  - Publishing follows 3.4: a stage is classified publicly once all its rounds are published.

### 3.6 Pages and routes

| route | path | who |
|---|---|---|
| `competition_standings_manage` | `/{_locale}/manage-event-standings/{competitionId}` | `COMPETITION_EDIT` + toolkit flag voter: list, create/edit standings and steps, publish |
| `official_results_standing_state` | `GET /{_locale}/official-results/standings/{standingId}` | JSON for the desk, overview and overlays (D). `COMPETITION_RESULTS_ENTRY` organiser view; the public variant below |
| `event_standing` | `/{_locale}/events/{slug}/standings/{standingSlug}` | public, `noindex` until published |
| `edition_standing` | `/{_locale}/series/{s}/{e}/standings/{standingSlug}` | public |
| `public_standing_state` | `GET /api/competition-standings/{standingId}` | public JSON of the published view (D's overlays). `Cache-Control: public, max-age=10`. 404 while unpublished or a draft |

Linked from:

- The event/edition page's rounds timeline (`templates/event_parts/`): a "Standings" row above the rounds, one link per
  published standing.
- Each included round's results page ("Part of Overall - Individuals →").
- The results overview (organisers).
- The sitemap (published standings of public events).

Live updates: when a round result changes (`OfficialResultsLiveUpdates`), also publish `official_results.standing` on
the private topic of each standing containing the round. Public pages refresh on the existing 60 s visible-only
pattern (like the live activity feed). There is no public Mercure topic in v1.

Messages (all CQRS, no participants lock - they do not write entries):

- `CreateCompetitionStanding`, `EditCompetitionStanding`, `DeleteCompetitionStanding`
- `PublishCompetitionStanding` / `UnpublishCompetitionStanding`
- `SetCompetitionAdvancementSteps` (whole list, like `SetCompetitionRoundPuzzles`)

`DuplicateCompetition` copies standings (unpublished) and steps, remapping round ids.

Queries:

- `GetCompetitionStandings` (list).
- `GetStandingResults`: per included round `GetRoundResultEntries::forRound()`, `StandingCalculator`, then
  `OfficialEntrantsView`. That is 2 statements per round. The budget is pinned at a 6-round standing ≤ 14 statements.

---

## 4. Reveal on stopwatch start

### 4.1 Model

New case `RoundPuzzleReveal::Stopwatch = 'stopwatch'`. A row in this mode **has no moment until a stopwatch start
with "Reveal" ticked writes `reveal_at`**.

- `revealAt()`: `self::Stopwatch => $scheduledAt` (the stored `reveal_at`, null until then).
- `sqlRevealAt()`: `WHEN 'stopwatch' THEN {rp}.reveal_at`.

That is all: every SQL reader (`sqlHidden`), `isHiddenAt()`, `SecretPuzzleHides::hideOf()` (null → `HIDDEN_UNTIL_REVEALED`),
`SecretPuzzleAccess` and the series match follow automatically. `RoundRevealMomentComputedOnlyHereTest` stays green,
because the moment is still computed only in `RoundPuzzleReveal`.

The mode stays `stopwatch` after the reveal. `reveal_at` holds the moment, so the management page can say "Revealed when
the stopwatch started, 10:02:13". This differs from `revealNow()`, which turns rows into `scheduled`.

Entity methods on `CompetitionRoundPuzzle` (each records `CompetitionRoundsChanged`):

- `revealAtStopwatchStart(DateTimeImmutable $startMoment)`: only for a mode-stopwatch row still hidden. Sets
  `reveal_at = start moment` (UTC).
- `withdrawStopwatchReveal(DateTimeImmutable $now)`: only while `reveal_at > now` (a cancelled countdown). Sets
  `reveal_at = null`. A moment that has passed is never withdrawn ("never hidden again once it is out").

### 4.2 The start actions

`StartRoundStopwatch` and `ScheduleRoundStart` gain:

- `revealPuzzles: bool = false`
- `revealListHash: ?string`: the `SecretRevealPreview::hash()` of the list the organiser saw. Required when
  `revealPuzzles`.

Handler flow when `revealPuzzles` is true (when false, the flow is today's, statement for statement):

1. `SecretPuzzleHides::lockRoundsForChange([$roundId])` first. That is the round `FOR NO KEY UPDATE`, then its secret
   puzzles, then EM clear (the documented lock order).
2. Load the round and compute the start moment: `now`, or the scheduled moment. Skip the reveal when the start is a
   no-op (already running).
3. `SecretRevealPreview::byStopwatchStart($round, $moment)` lists the rows of mode `stopwatch` still hidden, with scope
   (everywhere / name everywhere / event). If its `hash()` ≠ `revealListHash`, throw
   `RoundStopwatchRefused(RoundStopwatchRefusal::RevealChangedMeanwhile)`. **Nothing starts.** The page re-renders
   with the new list unticked.
4. `$round->startStopwatch()` / `scheduleStopwatchStart()` (unchanged), then `revealAtStopwatchStart($moment)` per row,
   then `$secretPuzzleHides->resync(...$puzzles)`.

Reset, cancel, resume and pause:

- `CancelRoundStartHandler` and the scheduled branch of `stop()` / `ResetRoundStopwatchHandler` (a countdown that never
  ran) call `withdrawStopwatchReveal($now)` on the round's rows and then resync, under the same lock order. They take
  that lock only when a cheap `EXISTS` finds a mode-stopwatch row in the round with `reveal_at > now`, so untouched
  rounds keep their exact statements (section 8).
- **A reset after a real start never hides anything again.** The puzzle is out.
- A test run is a start without the tick → nothing revealed → Reset → the real start with the tick.
- **Resume after a pause with the tick** reveals at the resume moment. That is legitimate: the organiser forgot at the
  first start.

New `RoundStopwatchRefusal` cases: `RevealChangedMeanwhile`, and `RevealNotAllowed` (a `revealPuzzles` request on a
round with no such row, e.g. a stale page). Translations go in the existing `competition.stopwatch.flash.*`.

### 4.3 UI

- **Round puzzles page** ("Change reveal" radio): a new option "When the round's stopwatch starts (you tick 'Reveal
  the puzzles' at the start - a test run reveals nothing)".
  - Status lines: "Hidden until the stopwatch starts with Reveal ticked", "Revealed at the stopwatch start, {zoned}".
  - `ChangeRoundPuzzleRevealHandler`: choosing `stopwatch` while the round's stopwatch status is running/stopped is
    refused with `StopwatchAlreadyStarted` ("use Reveal now"). Otherwise it would wait for a start that never comes.
    The `RevealMomentAlreadyPassed` check treats `stopwatch` like `manual` (no moment).
- **Manage stopwatch page** (`templates/manage_round_stopwatch.html.twig:92` start form, `:103`/`:144` schedule forms):
  - Only when the round has hidden mode-stopwatch rows: a checkbox "Reveal the puzzles now: {labels/names, scope}"
    plus a hidden `reveal_hash`.
  - **Unticked by default**, always. A forgotten tick leaves the puzzles hidden (a nuisance, fixable with "Reveal now").
    A wrong default during a test run would reveal them, which is the disaster Jan named.
  - The ticked button reads "Start and reveal 3 puzzles", with a confirm() naming the scope ("visible to everybody,
    for good").
  - After a start without the tick, while rows stay hidden, a warning card with the round puzzles' "Reveal now" links.
- **Online control room** (`templates/online_rounds/control.html.twig`, `online_control_controller.js`): the same
  checkbox next to "Start in 10 s" / "Start at". The JSON body gains `reveal: true, revealHash`. A `409
  reveal_changed_meanwhile` re-fetches the control state. The reveal list goes only into the page render and the
  organiser-only control state, **never into the public `/round-stopwatch/{roundId}` topic or `GetRoundStopwatchState`
  public JSON**.
- **Internal API**: `revealMode` output can be `stopwatch`, with `revealsAt` null until the start.
  `docs/features/internal-api.md` adds the value. The API never starts stopwatches, so there is nothing else to add.

### 4.4 The stopwatch is never started

The row behaves exactly like `manual`: hidden until "Reveal now", for every surface, the whole site included.
**There is no automatic fallback**, because a late reveal is harmless and an early one is not.

To make the forgotten case visible:

- The round puzzles page and the results desk show "Still hidden: the stopwatch was never started with Reveal" once
  `startsAt + minutesLimit` has passed.
- `PublishRoundResults` on a round with such rows asks "The puzzles are still hidden - reveal them now?" (a checkbox
  that dispatches `RevealRoundPuzzleNow` per row after the publish, in separate messages).

---

## 5. What B/C/D get - and what they must not do

- The entry's per-puzzle record and the round's puzzle list are in the round state (6.1, 6.2), and writes go through
  `RecordRoundResults` only (6.3).
- C's judge puzzle choice = a `puzzles` change with `done:false` items (before or during the round). Its "Finished now"
  on a multi-puzzle entry adds `done:true, atSeconds` to the item. On the K-th done item, C sends `result: {seconds}`
  in the same request (a group).
- B's participant proposals carry the same `puzzles` shape. A judge's "confirm proposal" turns the proposal into one
  `result` + `puzzles` group.
- D (overlays) reads published standings from `public_standing_state`, and live (organiser-chosen, private) data from
  `official_results_standing_state` / round state. Puzzle names come only from the `name` fields, which are null while
  hidden.

---

## 6. Contract for B/C/D

### 6.1 Round JSON (`official_results_round_state` → `round` and `rounds[]`; `RoundResultsOverview::jsonSerialize`)

Unchanged keys stay. **Added only when `puzzle_format` ≠ `total`** (absent otherwise):

```json
"puzzleFormat": {
  "mode": "pick",                  // "every" | "pick"
  "toSolve": 2,                    // K ("every": N)
  "puzzles": [
    {
      "roundPuzzleId": "0196…a1",
      "position": 1,
      "label": "Blue box",          // organiser's label or null - show "Puzzle {position}" when null
      "hidden": true,               // hidden from THIS viewer right now (SecretPuzzleAccess / isHiddenAt)
      "revealsAt": null,            // ATOM or null (manual, stopwatch not started yet)
      "puzzleId": null,             // null while hidden from this viewer
      "name": null,                 // null while hidden from this viewer
      "brand": null,
      "imageUrl": null,             // null while hidden (image-only hide included)
      "piecesCount": 500            // organisers + referees only; null on any public surface while hidden
    }
  ]
}
```

`piecesCount` (existing, round level) stays null for several puzzles. Clients use the per-puzzle `piecesCount`.

### 6.2 Entry JSON (`entries[]`, Mercure `official_results.entries`; `RoundResultEntry::jsonSerialize`)

Unchanged keys stay. **`puzzles` is present only for entries of an `every|pick` round** (value null when nothing is
recorded):

```json
{
  "ref": "team:0196…",
  "result": {"seconds": 5025},                  // the ranked total, unchanged wire format
  "rank": 3,
  "puzzles": [                                  // solving order
    {"roundPuzzleId": "0196…c3", "done": true,  "atSeconds": 2710},
    {"roundPuzzleId": "0196…a1", "done": true,  "atSeconds": 5025}
  ]
}
```

Unfinished example: `"result": {"piecesPlaced": 812}`, `"puzzles": [{"roundPuzzleId":"…c3","done":true,"atSeconds":2710},
{"roundPuzzleId":"…a1","done":false,"piecesPlaced":312}]` (500 + 312 = 812).

Choice only, round running: `"result": null`, `"puzzles": [{"roundPuzzleId":"…a1","done":false}]`.

### 6.3 Writing (`official_results_record`, `RecordRoundResults`)

```json
{"clientChangeId": "<uuid>", "entry": "team:<uuid>", "field": "puzzles",
 "from": <the puzzles value the device last saw, or null>, "to": <new list or null>}
```

- Send it **in the same request** as the matching `result` change. Both carry their own `from`.
- New per-change reasons a client must render: `puzzles_not_used`, `unknown_round_puzzle`, `puzzle_listed_twice`,
  `too_many_puzzles`, `invalid_split`, `splits_out_of_order`, `puzzles_mismatch`, `group_refused`.
- `invalid_pieces_placed` and `changed_meanwhile` are unchanged. A `conflict` outcome carries the current `puzzles`
  value like it carries `result` today.
- Referees (`resultsOnly`) may send `result` and `puzzles`.
- Clients compute the total from the items with the 2.2 rule 6. A shared helper `assets/official_results_puzzles.js`
  (pure: `total(items, round)`, `validate(items, round)`, `duration(items, index)`) is built in WP3 and pinned against
  `RoundEntryPuzzles` by a node parity test, like `OfficialResultsRankingParityTest`. B/C/D import it and never
  re-implement it.

### 6.4 Standing JSON (`official_results_standing_state`, `public_standing_state`)

```json
{
  "standing": {"id": "…", "name": "Overall - Individuals", "category": "solo", "mode": "sum",
               "published": true, "provisional": true, "roundsCounted": 3, "roundsTotal": 6,
               "rules": {"penaltySecondsPerPiece": 0, "order": "finished_rounds_first", "missing": "incomplete"}},
  "rounds": [{"id": "…", "name": "Round 1", "slug": "round-1", "published": true}],
  "rows": [
    {"rank": 1, "complete": true, "name": "Jo Doe", "countries": ["cz"], "playerCode": "ABC123",
     "entrants": [{"name": "Jo Doe", "playerId": "…|null", "playerCode": "…|null"}],
     "totalSeconds": 15012, "roundsFinished": 3,
     "perRound": [{"roundId": "…", "result": {"seconds": 5025}, "scoreSeconds": 5025, "rank": 2}]}
  ]
}
```

For `stages`, `rows[]` gain `stage` (1..), `stageName` and `stageRoundId`, and `rank` is null unless
`numberedAcrossStages`. The public variant has the masking of 3.4 applied: no `playerId`/`playerCode` for private
players, and no hidden players.

---

## 7. Migration notes (additive only; generated by `doctrine:migrations:diff`, never run by agents)

- `competition_round`: `puzzle_format VARCHAR(16) NOT NULL DEFAULT 'total'`, `puzzles_to_solve SMALLINT NULL`.
- `competition_round_puzzle`: `position SMALLINT NULL`, `label VARCHAR(40) NULL`. `reveal_mode` is a varchar, so the
  new enum value needs no DDL.
- `competition_participant_round`, `competition_team`: `result_puzzles JSONB NULL`. These are metadata-only ADD COLUMNs.
  No index is needed (the `@>` guard reads one round's rows by `round_id`, which is already indexed).
- New tables `competition_standing`, `competition_advancement_step` (FK `competition_id` ON DELETE CASCADE, so
  `DeleteCompetitionHandler`'s SQL deletes stay valid; verify the handler deletes them first if it uses explicit SQL).
- Blue-green: an old release inserting a round gets `'total'`, and an old release reading a `stopwatch` row would fail
  `RoundPuzzleReveal::from()`. **Deploy order:** ship the enum case in release N (readers accept it, UI does not offer
  it), the option in N+1, or accept that no row uses it during the deploy window because the option is behind the flag
  and admins only. Recommended: one release, flag off. The risk is nil while nobody chooses it during the swap.

---

## 8. What stays byte-identical for untouched competitions

- Round JSON and entry JSON: no `puzzleFormat`, no `puzzles` key (both conditional), so `official_results_round_state`,
  Mercure payloads and the live entry bootstrap are identical.
- `RecordRoundResultsHandler`: no extra statements when no `puzzles` change is present. `valueRefusal()` is unchanged
  for `total`.
- Stopwatch start, schedule and cancel without `revealPuzzles`: same statements. The manage page and control room
  render no checkbox when no mode-stopwatch row exists. (Cancel/reset take the extra round lock only when the round has
  a mode-stopwatch row - a cheap `EXISTS` first, so untouched rounds keep their exact statements.)
- Public round page, "Add to my profile", export and sheet: identical for `total` rounds.
- Event and edition pages: the "Standings" row renders only when a published standing exists. `DetailPagesQueryBudgetTest`
  gets +0 statements for events without standings, because standings ride on the existing rounds statement through a
  `LEFT JOIN LATERAL` count, or are counted in the existing competition query.
- `OfficialResultsRanking`, `official_results_ranking.js` and `AdvancementSeeding` for single-puzzle rounds: unchanged.

---

## 9. Feature flag and permissions

- One voter, `CompetitionToolkitVoter::TOOLKIT_SETTINGS` (subject = competition id), shared by A/B/C/D. It is granted to
  admins always, and to `COMPETITION_EDIT` holders while the organiser flag (retired 2026-11-17) = 1. Pattern:
  `OnlineRoundsVoter` + a `config/services.php` parameter. Whichever WP lands first creates it.
- It gates **settings**: puzzle format, labels, the stopwatch reveal mode, standings and steps. **Use** is never gated:
  recording `puzzles`, the start checkbox and public standings work for anyone with the usual rights once the round or
  competition uses them.

---

## 10. Test plan

**Unit**

- `RoundEntryPuzzlesTest`: wire round-trip, canonical equality, every rule 1-6, totals.
- `RoundPuzzleRevealTest`: `stopwatch` PHP/SQL agreement (extend the existing PHP ↔ SQL agreement test).
- `StandingCalculatorTest`: sum (finished / unfinished / penalty / missing ×2 / no limit / ties / line-up identity /
  substitute = new line-up), stages (depth, grouping, numbered variant).
- `AdvancementStepGraphTest`: cycles, double targets, stage depth.
- Node parity `OfficialResultsPuzzlesParityTest` for `official_results_puzzles.js`.

**Handlers**

- `RecordRoundResultsHandlerTest`:
  - `puzzles` applied / unchanged / conflict;
  - group atomicity (result conflicts → puzzles `group_refused`);
  - mismatch;
  - referee `resultsOnly` may send `puzzles`;
  - `puzzles` on a `total` round is refused;
  - receipts for `puzzles`;
  - an untouched change set's statement count is unchanged (pin it).
- `RemovePuzzleFromCompetitionRoundHandlerTest` / `SetCompetitionRoundPuzzlesHandlerTest`: referenced → refused; N ≤ K
  → refused.
- `EditCompetitionRoundHandlerTest`: format change vs records.
- `StartRoundStopwatchHandlerTest` / `ScheduleRoundStartHandlerTest`:
  - no tick → nothing revealed, statements unchanged;
  - tick + hash → revealed at the start moment (scheduled = future moment, still hidden at now);
  - stale hash → refused, clock not started;
  - running → no-op, no reveal;
  - `resync` writes `hide_until`.
- `CancelRoundStartHandlerTest` / `ResetRoundStopwatchHandlerTest`: a future reveal is withdrawn; a past reveal is
  kept after reset.
- `ChangeRoundPuzzleRevealHandlerTest`: `stopwatch` refused while running.
- `PublishCompetitionStanding*`, `SetCompetitionAdvancementSteps*`, `DuplicateCompetitionHandlerTest` (copies standings
  and steps with remapped ids).

**Controllers / queries**

- `RoundResultsStateControllerTest`: `puzzleFormat` present only for `every|pick`; hidden puzzle names null for a
  referee; organiser sees them.
- `GetPublishedRoundResultsTest`: per-puzzle chips hidden while secret; "Add to my profile" per puzzle (K = 1 = chosen
  puzzle; K = 2 needs splits).
- `OfficialEntryTimePrefillTest` with `round_puzzle`.
- Stopwatch controllers: form and JSON with `reveal`; `409 reveal_changed_meanwhile`; the public stopwatch JSON never
  contains puzzle data (assert keys).
- Standing pages: published / unpublished / draft 404; provisional header; statement budget.

**Canary / coverage tests to extend**

- `BlocklistCanaryTest` and `PrivateProfileCanaryTest`: the public standing page (sum + stages), the bracket view, and
  the round page with per-puzzle rows.
- `DraftCanaryTest`: the standing pages and `public_standing_state`.
- `DraftVisibilityCoverageTest`: the new readers.
- `SecretPuzzleRouteCanaryTest`: the standing JSON and the round page with a pick round of 3 secrets before the
  stopwatch reveal.
- `ImageEstimateSecretPuzzleCanaryTest`, if overlays/images are touched.
- `RoundRevealMomentComputedOnlyHereTest`: stays green unchanged.
- `SerializedByLockMessagesTest`: no new entry-writing message, so it stays unchanged.
- `ClaudeInstructionsTest`: rule-file budget after the as-built summary.

**Browser walk (before merge, per project rule)**

- Desk per-puzzle editor.
- Sheet popover.
- Manage stopwatch start with and without the tick.
- Control room schedule with the tick, then cancel (reveal withdrawn).
- Public pick round page.
- Standings page at 375 px.

---

## 11. Risks

1. **Early reveal** through a new surface: the label/position rule and the per-viewer `hidden` flags. Mitigation:
   canaries above, and the public stopwatch topic never carries the reveal list.
2. **Old cached clients vs a `pick` round with records**: a plain total edit comes back `puzzles_mismatch`. That is
   visible, not silent. Acceptable.
3. **Group atomicity** inside a handler that today treats changes independently: the extra planning pass must not
   change outcomes for change sets without groups. Pin with the existing handler tests unchanged.
4. **Line-up identity for pairs/teams across rounds**: a substitution splits a team in the sum. That is the documented
   rule and shown on the page ("line-up changed in round 4"). If organisers object, a later "same team" link is
   possible.
5. **Statement budgets** of event and detail pages (standings link) and of `RecordRoundResults`. Pin them.
6. **Locks**: the new `FOR KEY SHARE` in `RecordRoundResults` vs `FOR UPDATE` in puzzle removal. The order is always
   round, then round puzzles, which matches the `SecretPuzzleHides` order. No deadlock path with the participants
   advisory lock, because removal does not take it.
7. **Blue-green deploy with the new enum value** (section 7).

---

## 12. Open questions for Jan (defaults chosen; build proceeds with them)

- **Q1.** Unfinished entries in a multi-puzzle round rank by **total pieces placed**, with finished puzzles counting
  their full piece count. For equal-size boxes this equals "puzzles completed, then pieces". OK, or do you want a strict
  "puzzles completed first" even when the boxes differ in size?
- **Q2.** "Add to my profile" in a 2-of-3 round: finished puzzles only, and only when per-puzzle times were recorded.
  Unfinished last puzzle not offered in v1. OK?
- **Q3.** Sum standing default: **more rounds finished first, then the sum**, unfinished = time limit (+ 0 s per missing
  piece, organiser can set e.g. 5 s), a missed round = listed after without a rank. Does that match how the World Series
  actually ranks? If they use "limit + N s per piece, pure sum", the organiser picks it in one form.
- **Q4.** "Best 5 of 6 rounds count" and season standings across editions of a series: not in v1. Needed soon?

The reveal checkbox is unticked by default (decided, see 4.3).

---

## 13. Work packages

| WP | Scope | Size | Depends on |
|---|---|---|---|
| **A1 Reveal on stopwatch start** | `RoundPuzzleReveal::Stopwatch` (PHP + SQL), entity methods, `SecretRevealPreview::byStopwatchStart()`, Start/Schedule/Cancel/Reset handlers + refusals, `AbstractRoundStopwatchController` fields, manage stopwatch page + control room checkbox (JSON body), round puzzles page option + status lines, publish-results prompt, internal API docs, `CompetitionToolkitVoter` + flag + `feature_flags.md`. Tests 10 (reveal parts) | 1.5-2 d | - |
| **A2 Round format model** | `puzzle_format`/`puzzles_to_solve`, round puzzle `position`/`label`, round form + internal API `RoundInput`/`SetRoundPuzzles` (order, labels), guards (`RoundFormatNeedsMorePuzzles`), online-timing refusal, round JSON `puzzleFormat` (6.1) incl. per-viewer hiding | 1-1.5 d | A1's voter (or creates it) |
| **A3 Results protocol** | `RoundEntryPuzzles`, `result_puzzles` column + trait, parser + `RoundResultField::Puzzles`, handler rules 1-7 + group pass + locks, `hasOfficialData`, entry JSON (6.2), `GetRoundResultEntries`, removal/format guards against records, `official_results_puzzles.js` + parity test, seeding piece totals | 1.5-2 d | A2 |
| **A4 Organiser editing** | desk per-puzzle editor, sheet popover (group writes), export columns, overview counters unaffected | 1.5-2 d | A3 |
| **A5 Public round page** | per-puzzle chips/splits (secret-safe), `OfficialEntrantsView` extraction, "Add to my profile" per puzzle (`round_puzzle` param, prefill, states), canaries | 1.5 d | A3 |
| **A6 Standings core** | `CompetitionStanding` + messages, `StandingCalculator` (sum), `GetStandingResults`, organiser page `competition_standings_manage`, `official_results_standing_state`, duplicate support, private Mercure `official_results.standing` | 2 d | A5's `OfficialEntrantsView` (or extract here; whoever is first) |
| **A7 Public standings** | `event_standing` / `edition_standing` / `public_standing_state`, links (event parts, round page, overview, sitemap), publishing rules, canaries + budgets | 1.5 d | A6 |
| **A8 Advancement steps** | `CompetitionAdvancementStep` + `SetCompetitionAdvancementSteps`, graph validation, desk step banner, Top N preset, `advance_qualified_controller.js` prefill | 1.5 d | - (parallel to A6) |
| **A9 Bracket view + stages standing** | overview + public bracket (CSS grid + SVG), `StandingMode::Stages` classification (grouped / numbered), canaries | 1.5-2 d | A7, A8 |
| **A10 Wrap-up** | `docs/features/competitions-management/round-formats.md` + `standings.md` (design of record), as-built line in `.claude/rules/features/events.md`, `docs/TODO.md` (tick TODO:873, relay note), 6-locale translations (orchestrator's final pass) | 0.5 d | all |

Parallel lanes: **A1** ∥ **A2 → A3 → (A4 ∥ A5)** ∥ **A8**, then **A6 → A7 → A9**. C (judge puzzle choice) can start
against the 6.x contract as soon as A3 lands (A2 for the round list). D's standings overlays need A7.
