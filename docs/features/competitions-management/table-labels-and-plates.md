# Table labels (A1, A1-1, …) and name plates with time boxes

SPWS 1:1 demo, package A (2026-11-24). Design of record; "Review amendments" (binding) and "As built" at the end.

## Why

The Speed Puzzle World Series (Toronto, June 2027) seats everybody in one hall whose tables are labelled like a floor
plan: columns A-H × rows 1-22 (row 22 only A-G) = 175 tables, one per team. Pairs and individuals are up to 350, so
**two** entrants share one table - how the two places are told apart (A1-1 / A1-2, A1 L / A1 R, …) is still being asked.
Everybody keeps the same table for the whole championship. They also want a **name plate** on the table: name, country
flag, table, pair/team name, QR - and "ideally a space to record the times by pen" (one box per challenge).

Today a round entry's table is an integer 1..9999 (`HasOfficialResult::$tableNumber`, smallint), unique per round,
read and written by ~25 surfaces (seating page + print + auto-assign + same tables copy, results desk, participants
sheet, judge page incl. offline snapshots and My tables, QR table tags `/live/{cid}/t/{n}`, name tags/tents, competitor
page, displays, analytics studio, export, time proposals, Get ready).

## Options considered

1. **A free text label per entry** (`table_label` next to / instead of `table_number`). Any label without setup - but
   every write path (`AssignTableNumbers`, `RecordRoundResults`' `table_number` field, `CopyRoundTableNumbers`,
   `AdvanceQualified`, sheet, judge quick add, internal API) would take strings, uniqueness and "natural sort" would be
   re-implemented in SQL and JS, auto-assign ("fastest at table 1") needs an ordered list of tables anyway, and the
   frozen QR path `/live/{cid}/t/{n}` (route requirement `[1-9]\d{0,3}`) cannot carry a label. Highest risk.
2. **A hall layout model** (rows × columns × seats entities, like the legacy "Table layout" planner
   `manage_round_tables` - `TableRow` / `RoundTable` / `TableSpot`, a separate planner whose spots are never connected
   to the round entries). A second truth next to `table_number` (world-series-rules.md §6 rejected an event-level
   table model for exactly this reason), and SPWS's open "two per table" answer would be a schema question.
3. **Chosen: a per-round list of labels that names the table numbers.** The integer stays the identity, the sort key and
   the wire format everywhere; an optional ordered list `["A1-1", "A1-2", "A2-1", …]` on the round says that table
   number *n* is called `labels[n-1]`. Labels are a display + typing layer:
   - every write path, the three-way `from`, uniqueness per round, `SeatingProposer` ("fastest at the first table"),
     same-tables copy + hash, `?first=`, the QR path `/live/{cid}/t/{n}`, offline snapshots, `landingOf()`, service
     worker paths - unchanged, they keep working on numbers;
   - "natural order" = the list's order (the organiser - or the generator - decides it, e.g. A1, A2, … A22, B1, …);
   - one label per **place**, not per table: two entrants at table A1 are two numbers named "A1-1" / "A1-2" (or
     "A1 L" / "A1 R", "A1a" / "A1b" …). SPWS's pending answer becomes a different generator setting, not a schema change;
   - a round without a list (every round today) is byte-identical: no JSON key, no new statement, same HTML.

   The legacy "Table layout" planner stays as it is (separate page, not linked to entries); this design does not reuse
   or touch it.

### Scope: per round (with "also for these rounds")

Labels must differ per round: Teams use 175 labels (A1…G22), Pairs/Individuals 350 (A1-1…G22-2) in the same event.
So the list lives on `competition_round`; the editor writes the same list to several rounds at once (default: every
in-person round of the same category that uses table numbers). An event-level list cannot serve both.

## Data model

- `competition_round.table_labels` jsonb NULL (migration generated, additive; NULL = plain numbers = today).
  `list<string>`, 1..9999 items. Item *i* names table number *i + 1*.
- `Value\TableLabels` (pure, PHP) + its JS twin `assets/table_labels.js` (pure ES module, node harness), pinned by a
  shared fixture `tests/fixtures/table-labels-cases.json` (parity test):
  - `TableLabels::fromList(list<string>)` validates: each label trimmed, inner whitespace collapsed to one space,
    1..12 characters, `^[\p{L}\p{N}](?:[\p{L}\p{N} .\-\/]*[\p{L}\p{N}])?$` (starts and ends with a letter or digit;
    commas, newlines and other symbols never - they separate labels in the editor), at most 9999; **folded keys
    unique** (below) - else `InvalidTableLabels` with the problem per label (`empty`, `too_long`, `characters`,
    `duplicate` + the first label, `too_many`).
  - **Folded key** = the label split into segments - runs of letters, runs of digits; spaces, `-`, `.`, `/` only
    separate - upper-cased (Unicode), joined by `.`; digit runs without leading zeros. `"A1-1"` → `A.1.1`,
    `"a1 1"` → `A.1.1`, `"A1L"` / `"A1 L"` → `A.1.L`, `"B07"` → `B.7`, `"12"` → `12`. Two labels with the same key are
    duplicates ("A1-1" and "A1 1").
  - `labelOf(int $n): string` - the label, else (n beyond the list) the number as text - never blank.
  - `numberOf(string $typed): null|int` - the number whose key equals the typed text's key (exact), else null.
  - `count()`.
- `Value\TableLabelGenerator` (PHP) + `generate()` in `table_labels.js` (twin, same fixture): `letters` ("A-H", or
  "A, C, E"), `numbers` ("1-22"), `seats` 1..4, `seatStyle` `dash` (A1-1, A1-2) | `letter` (A1a, A1b) | `side` (A1L,
  A1R - 2 seats only), `order` `letters` (A1, A2 … A22, B1 …) | `numbers` (A1, B1 … H1, A2 …), `skip` (labels left out,
  "H22"), and `prefix`/none. Seats always run inside a table (A1-1, A1-2, A2-1 …). Result ≤ 9999 else refused.
  SPWS teams: `A-H × 1-22, 1 seat, skip H22` = 175; pairs: the same with 2 seats = 350.

### Writes

- `SetRoundTableLabels(competitionId, roundIds: list<string>, labels: null|list<string>, expected: array<roundId, hash>)`
  → `SetRoundTableLabelsHandler`, under `CompetitionParticipantsLock` (it changes what the entries' numbers mean and is
  checked against the entries' numbers): for every round of the list (same competition, else `CompetitionRoundNotFound`),
  all or nothing:
  - `expected` = the hash of the round's labels the editor showed (`TableLabels::hashOf(null|list)` = sha1 of the JSON,
    `none` for null) - another organiser's change meanwhile → `TableLabelsRefused(changed_meanwhile)`;
  - a round where some entry sits at a number beyond the new list → `TableLabelsRefused(too_few, round, highest)`
    ("Round X has a table 360 - give at least 360 labels");
  - `labels = null` removes the list (back to numbers; nothing else changes).
  - `CompetitionRound::changeTableLabels(null|TableLabels)`. Entries are never renumbered: a seated entry whose number
    gets another label simply shows it (the editor warns first, below).
- Every table write keeps working on numbers, plus one rule: on a labelled round a number beyond the list is refused -
  `AssignTableNumbers` (`InvalidTableNumbers` problem `no_label`), `RecordRoundResults` field `table_number` (refused
  like an out-of-range number), `CopyRoundTableNumbers` (`TableNumbersCopyRefused` reason `no_label` per target). Their
  handlers already load the round entity - no statement more.
- Duplicate copies the labels (a hall plan is setup, like `tableNumbersOff`); `MoveRoundToCompetition` keeps them (the
  column travels with the round).
- Internal API: `PUT /internal-api/rounds/{id}/table-labels` `{"labels": [...]}` | `{"generate": {letters, numbers,
  seats, seatStyle, order, skip}}` | `{"labels": null}`, `"alsoRounds": [ids]` optional → the message (expected = the
  current hashes, read in the controller); `GET` returns `{labels, count}`. Round JSON of the internal API gains
  `tableLabels` (only when set).

### Reads (no statement more)

`cr.table_labels` is read as a column of the statement that already reads the round (`GetRoundResultsOverview`,
`GetCompetitorPage` rounds, `GetParticipantsSheetState`, `GetAnalyticsStudioRound`, `GetCompetitionDisplayState`
round row, tags/print queries) and carried as `null|TableLabels`. JSON keys (`tableLabels: [...]`, per entry
`tableLabel`) **only when the round has labels** - goldens (`RoundResultsStateGoldenTest`, `CompetitionNameTagsGoldenTest`,
`OfficialRoundResultsGoldenTest`, displays) unchanged. `GetParticipantsSheetVersion` hashes `table_labels` too (else
the sheet would not refresh). The studio's/displays' cache keys: labels ride in the round row they already hash;
`SetRoundTableLabels` sends `OfficialResultsLiveUpdates::roundChanged()` for each round + the displays' `round` signal.

## Every surface

Display rule everywhere: `labelOf(n)`; HTML-escaped (labels are organiser text; every JS interpolation goes through
`escapeHtml` / `textContent` - today `tableHtml()` interpolates the number raw). Max 12 characters keeps chips/prints in
shape; CSS lets a long label shrink (`font-size` step) on tents.

| Surface | Change on a labelled round |
|---|---|
| Seating page (`round_seating`) | New **"Table names"** panel (collapsed card above the lists; organisers only): "Plain numbers" (default) / "Names like A1, A2" → generator form (letters, numbers, seats per table + style, order, leave out) with a live preview ("175 tables: A1, A2 … G22") and an "Edit the list" textarea (one per line or comma-separated); "Use for: ☑ this round ☑ other rounds of <category>…" (default: same category, using table numbers); Save → `official_results_table_labels` (POST, JSON, `OfficialResultsApi`). Before saving over seated entries: "12 seated entries will show another name (Anna: A5 → B1)". Lists, inputs, find box, swap/renumber bars, "Seat them at …", "Renumber A1-1 … H22-2", auto-assign proposal ("first table" accepts a label), toasts and the copy confirm show labels; inputs are text (`autocapitalize=characters`), typed labels resolve by `numberOf()`; an unknown label: "There is no table "A23" in this round's list". |
| Seating print | Labels in the table column / "Find your table". |
| Results desk | Table column + editor show/accept labels; sort = number order. |
| Results overview / round list / stopwatch / advance dialog | Only counts "Tables x / y" - unchanged. Advance dialog "Seat them now" unchanged. |
| Participants sheet | Round tabs' table column shows/accepts labels (`sheet_model`/`round_common`), sort by number. |
| Judge page (live entry) | Find: typed text matches labels by folded key (below); chips show labels; card header label; "Table A1-1" texts (`table`, `tableEmpty`, conflict texts); keyboard defaults to text on a labelled round; quick add's table field accepts a label. Offline snapshots carry `tableLabels` in the round state - nothing more to store. |
| Judge "My tables" | Stored per event as text (unchanged storage). On a labelled round the text is read as labels: `A, B` = every label whose key starts with segment `A` / `B` (a whole column); `A1-1..A5-2` (`..` or ` to ` or an en dash) = a range in list order; `C3` = the labels of table C3 (C3-1, C3-2) or C3 itself. Resolved per round into the same number ranges the rest of the code uses (`labelRanges(text, labels)`); errors `syntax` / `unknown` ("No table "Z9"…"). Plain-number text keeps today's parser. |
| QR table tags (kind `t`, `/live/{cid}/t/{n}`) | Path frozen, still the number. Tents/tags of kind `t`: the label of `?round=`; without a round the label when every labelled round using that number agrees, else the number. `MAX_TABLES` 300 → 400 (SPWS 350). `?from=`/`?to=` accept labels of the chosen round. |
| Name tags / tents (people, line-ups, entries) | "Table A1-1" (label of the printed round). |
| Competitor page | "Table A1-1"; JSON `tableLabel` (only when set). |
| Displays + overlays | `DisplayResultRow` gains `tableLabel` (only when set); `render.js` shows the label when present. |
| Analytics studio | Entrant `tableLabel` (only when set); page shows it. |
| Export (CSV/XLSX) | "Table" column = the label when the round has labels (a column "Table number" is added after it then). |
| Time proposals (judge) | Proposal list's table = label. |
| Get ready checklist, wizard | Counts only - unchanged. Wizard Extras (own small commit): optional row "Table names (hall plan)" → generator fields → `SetRoundTableLabels` for every in-person round of the draft (off by default). |

### Typing a label on the judge page (speed, C7)

`searchEntries` / `uniqueTable` on a labelled round compare **folded keys**:
- exact = an entry whose label key equals the typed key;
- a label *continues* the typed text when the typed segments are a prefix of its segments, the last typed segment
  allowed to be the start of the label's segment (`A.1` continues `A.1.1`, `A.12`; not `B.1`);
- **opens without Enter** when exactly one entry has the exact label and no other label of the round continues the typed
  text - the rule of numbers today, over keys.

Over the SPWS sets (node harness `tests/table-labels-harness.mjs` runs all 350/175 labels for each style):
- 1 seat `A1…G22`: `a5` opens A5 (A5x does not exist above 22? A5 has no A50) - ✓; `a1` / `a2` wait for the second digit
  (A10-A19 / A20-A22) exactly like numbers today; `a1` + Enter opens A1.
- `dash` (A1-1): the seat needs a separator - `a1 1` / `a1-1` opens; `a11` is table A11 → waits / lists A11-1, A11-2.
- `letter` / `side` (A1a / A1L): `a1a`, `a1l` open with no separator (fastest on a phone keyboard) → **our
  recommendation to SPWS: letters for the two places ("A1 L / A1 R" or "A1a / A1b")**.
Digits typed on a labelled round match labels only (the number is not a name there); names and `#codes` as today.

## Name plates with time boxes (part 2)

A plate = one entrant (a person in a solo championship, a pair/team line-up otherwise) for one **championship**: their
table (same all championship), name / pair or team name + members, flags, QR, and one box per challenge:
`Challenge 1 [ time ______ | missing __ ]`.

- No new kind: an option on the existing tents print (`competition_name_tags?layout=tents`, kinds `people`, `lineups`,
  `entries`): **"Time boxes for: [none ▾ | <standing> … | <category> rounds]"** → `?boxes=standing:<id>` /
  `?boxes=solo|duo|team` (the URL is the memory - a bookmark/print link keeps it), and `?missing=0` to leave out the
  "missing pieces" field (default on: the SPWS penalty process - judge writes time + missing pieces).
- The championship = a standing's `round_ids` (SPWS has one standing per championship; the demo too), else every round
  of the category, by schedule. The boxes: per round in order - a pick round with K ≥ 2 (a back-to-back block) gives
  **one box per puzzle slot** labelled by its puzzle label (`RoundPuzzleLabel`: the organiser's label, e.g.
  "Challenge 1"; default letters print as "<round name> · A"); any other round one box with the round name. **Labels
  only, never puzzle names** (plates print before the reveal; `RoundPuzzlesVisibleNow` is not needed because no name
  or picture is read).
- Which entrants and which table: unless `?round=` is given, the championship's **first round** is the printed round
  (its entrants, its table labels) - everybody does every challenge, so its entrants are the championship's.
- Layout: the tent's lower face (the side facing the judge's pen) gets a box strip at the bottom (one row, up to 6
  boxes, a second row beyond 6, ≤ 10); the QR shrinks from 60 to 48 mm on that face; the upper face (read by the other
  side) stays as today. `?flags=1` works as before. Print CSS: boxes with a 0.4 mm border, label 10 pt, a writing line
  of 12 mm height, "missing" 22 mm wide.
- Default page and every page without `?boxes=` byte-identical (`CompetitionNameTagsGoldenTest`).
- One statement more only with `?boxes=` (the championship's rounds + their puzzle labels, positions).

## Organiser UX summary

- Seating page of any round → "Table names" → generator or list → "Use for" rounds → Save. Undo = the toast's Undo
  (writes the previous lists back with `expected` = the new hashes).
- Wizard Extras: "Table names (hall plan)" (optional).
- Internal API (package C, scripts): `PUT /internal-api/rounds/{id}/table-labels`.
- Tags page: layout Tents → "Time boxes for" → Pairs championship → Print.

## Edge cases

- Labels set after seating: entries keep numbers, show new names - warned before saving; fewer labels than a seated
  number = refused.
- Table numbers switched off for a round: labels are kept but unused (like numbers).
- A seated number beyond the list can only exist from before this feature's validation (never through the writes) -
  shown as the number.
- Renumber / seat the rest / proposal past the list's end: refused client side ("Only 350 tables are named") and
  server side (`no_label`).
- Same tables copy from a labelled round to an unlabelled one (or with different lists): numbers are copied (identity);
  the confirm shows the target's labels. Recommended: give the rounds of a championship the same list (the editor's
  default "Use for").
- Two championships at once: separate lists per category, separate halls → different label sets; the `t` tag picks the
  round as today.
- Label with characters a QR or CSV dislikes: excluded by the character rule; CSV cell escaping as today.

## Test plan

- `TableLabelsTest` (validation, keys, labelOf/numberOf), `TableLabelGeneratorTest` (SPWS sets: 175 / 350, skip,
  orders, styles), parity `TableLabelsScriptTest` (fixture both ways) + `tests/table-labels-harness.mjs` (judge typing
  matrix over the SPWS sets: which keystrokes open which table).
- `SetRoundTableLabelsHandlerTest` (all or nothing, changed meanwhile, too few, null), `AssignTableNumbers`/
  `RecordRoundResults`/`CopyRoundTableNumbers` `no_label`, `SerializedByLockMessagesTest`.
- Controller: seating page renders the panel; label endpoint auth (organiser only, referee 403); internal API.
- Goldens unchanged; new JSON key only on a labelled round (`RoundResultsStateConditionalTest`-style test).
- Judge JS: `live-results-harness.mjs` (search/unique over labels), My tables label parsing; seating JS (`SeatingJsTest`).
- Plates: `CompetitionTagsPrintTest` (boxes per standing / category, pick round slots, labels not names for a secret
  puzzle, `?missing=0`), golden unchanged.
- Browser walk: seating (desktop + 375), desk, judge on 375 (typing a1l, a1 1), sheet, print preview of tents with
  boxes, competitor page, a display.

## Review amendments (binding - independent design review, 2026-11-24)

- **A1 Different lists in one event.** "Same tables as round X" (`SeatingProposer::sameTablesRounds()`) and "Use these
  tables in later rounds" (`SameTablesCopy`) only pair rounds whose lists are **equal** (both plain numbers, or the same
  labels) - a number means the same place only then. Other rounds are simply not offered (the copy confirm and the
  proposal's round select leave them out). The editor's default "Use for" = every round of the category, so a
  championship gets one list. Kind `t` tags (`/live/{cid}/t/{n}`, frozen): their print on an event with labelled rounds
  needs a round (`?round=`, default the first labelled round) and prints that round's labels; the page says the card
  serves every round with the same names. A `t` tag scanned during a round with another list lands on that round's
  number - documented; SPWS should use plates (person / line-up QR), which are unambiguous.
- **A2 Labels inside the statement.** Tag/print queries read the label per entry in SQL next to its number
  (`cr.table_labels ->> (table_number - 1)` in the same LATERAL / row) - the people tents' rounds differ per person
  without `?round=`. No statement more. Helper `TableLabelSql::label('cr', 'cpr.table_number')`.
- **A3 Translations, canaries.** Every new text in all 6 locales; `?boxes=` print + the label endpoint into
  `SecretPuzzleRoutesTest` (labels only - no name leaks) and the coverage/canary lists where they apply.
- **A4 More surfaces:** display live control + spotlight (`DisplayControlEntries`, `SetRoundSpotlightHandler`,
  `assets/displays/control_picker.js`, `render.js`), sheet People tab `team_table`, sheet paste by table
  (`round_paste.js` - labels parse), advance dialog "Seat them now" (cap at the target's label count), input modes
  (`pattern="[0-9]*"`, `inputmode="numeric"`, `type="number"`) become text on labelled rounds only.
- **A5 Plates for pick K of N with K < N:** K boxes ("<round> 1 … K"), never N puzzle labels.
- **A6 Plates' round set:** a standing's rounds (`standing:<id>` checked to be the event's), or a category's rounds
  **that no advancement step feeds** (no boxes for a final nobody has reached yet).
- **A7 No hash:** `expected` is the list itself (null = plain numbers), compared as PHP arrays - no PHP/JS hash twin.
- **A8 ASCII labels:** characters `[A-Za-z0-9 .\-/]`, start/end alphanumeric - no Unicode case-mapping twin risk.
- **A9 My tables on a labelled round:** the stored per-event text is resolved per round; tokens are labels or label
  prefixes (`A` = column A, `C3` = C3 and its places) and ranges `A1..A5` (also an en dash or ` to `; a plain `-` is
  part of a label); digits are labels too (`12` = label 12). Saving checks against the round on screen (`unknown`); in
  another round a token naming nothing there names nothing (never an error).
- **A10 Sheet version:** `table_labels` appended like divisions (NULL left out), every unlabelled event's hash unchanged.
- **A11 Duplicate** copies the labels explicitly; events.md + duplicate texts updated ("tables" there = the seating).
- **A12** `AdvanceQualified` writes no table numbers (doc corrected); `RecordRoundResultsHandler`'s table check gets the
  round.
- **A13 Plates physically:** the time strip goes on the face toward the judge; the big label shrinks with its length
  (≥ 5 characters → 90 pt, ≥ 8 → 64 pt); the print page warns "N entrants have another table in a later round of this
  championship" when tables differ.

## As built (PR 1, 2026-11-24)

Built as designed with the amendments. Deviations and details:
- **Where the names are set**: the seating page's "Table names" panel (`templates/seating/_table_labels.html.twig`,
  lazy `table_labels_editor_controller.js`; generator defaults A-H × 1-22, places "A1 L / A1 R"; after a save the page
  loads again) and the internal API. **Not yet in the creation wizard's Extras** - `docs/TODO.md`.
- **Judge typing** (node-pinned in `TableLabelsTest::testTheJudgeTypesATableOfTheHall`): labels match by folded key only
  when the typed text has a digit or names a label exactly (a name starting with "A" never lists the A tables); digits on
  a labelled round are labels. Over the SPWS hall: one per table - 159 of 175 open without Enter (A1/A2, B1/B2 … wait for
  the second digit like numbers today); `side`/`letter` places - all 350 open with no separator ("a1l"); `dash` - all 350
  need a space or "-" ("a1 1"). Hence the default style "A1 L / A1 R" and the recommendation to SPWS.
- **Displays / studio** read the label in the statement that reads the round; the studio's search over labels uses its
  own loose match (separators and case ignored) to stay within its bundle budget.
- **Same tables / copy**: rounds with different lists are simply not offered (A1); the seating page's copy button too.
- **Export**: "Table" = the name, "Table number" right after it - only for a labelled round.
- **Plates**: `CompetitionTagSheet::plates()`; the box strip sits on the lower face (`.tent-face.with-boxes`, QR 44 mm,
  boxes ≥ 7 → two rows); the select "Time boxes" appears on the tents layout of people / line-ups / entries (one more
  statement there, `GetTagPlateChallenges::options()`); a warning counts the people with another table in a later round.
- Live entry bundle budget 232.5 → 237.5 KB / 64 → 65.5 KB gzip (`LiveEntryBundleTest`).
- Browser walk (throwaway stack, Selenium, 1280 + 375): seating panel + save, renumber/seat-the-rest texts, judge
  "a1r" → Corner Pieces (A1 R) without Enter, desk editor "b3 r" / unknown "z9", sheet Pairs tab, plates print, competitor
  page (entry + table page title), display state.
