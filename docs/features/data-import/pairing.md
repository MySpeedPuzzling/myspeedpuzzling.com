# Data import: pairing a file's puzzles with the catalogue (stage 5a)

The data, the matcher and the decisions. The screens (lists per group, the chooser, the search sheet, "To fix", the
row sheet) are stage 5b - here the import page only says where the pairing stands. Design: [README](README.md)
"Pairing with the catalogue", "3. Puzzles". Nothing of this stage touches a result, a collection or the catalogue.

- [The flow](#the-flow)
- [Tables](#tables)
- [Who may be a candidate](#who-may-be-a-candidate)
- [Tiers](#tiers) - the exact rule of each, as built
- [Already yours](#already-yours)
- [Remembered decisions](#remembered-decisions)
- [Decisions](#decisions) - `DecideImportPuzzles`
- [Where the rules end](#where-the-rules-end)
- [The summary](#the-summary)
- [A puzzle merge](#a-puzzle-merge)
- [When a run fails](#when-a-run-fails)
- [The statements](#the-statements) - measured on production; the exact one once more before the merge
- [What 5b's screens can call](#what-5bs-screens-can-call)
- [Where the README and the code differ](#where-the-readme-and-the-code-differ)

## The flow

```
ParseImportSheetHandler ends a sheet's parse
  → BuildImportPuzzles(importId)                      imports queue, one transaction, the import's lock
       1. distinct puzzle keys of the rows  → data_import_puzzle (upsert), orphans deleted, rows linked
       2. ImportPuzzleMatcher::matchExact() → rows of earlier imports marked, remembered decisions applied,
                                              one lookup of names / barcodes / brand codes / own ids,
                                              one lookup of the player's own results, one write
       3. counters; MatchImportPuzzlesFuzzy(importId, run) queued
  → MatchImportPuzzlesFuzzy(importId, run)             imports queue, 100 puzzles a job, queues itself again
       similar names for puzzles nothing exact was found for → "choose" or "none"
       nothing waits any more → data_import.paired_at, counters
```

- **Idempotent, and run after every parse.** A known key keeps its row, what the rules said and its decision; it
  takes what the file says now (name, brand, pieces, codes, row counts). Every run decides again about every puzzle
  the player has not decided - so a catalogue that grew, a brand that was merged or a changed mapping are picked up.
- **`data_import.pairing_run`** is raised by every build; a similar-name job of an older run finds another number
  and stops. A puzzle the search already looked at keeps its result while the exact rules still find nothing (its key
  is its name, pieces and brand - the search would ask the same question).
- **The import rests in `setting_up`** with `paired_at` set. A mapping can still change (the "Sheets and columns"
  screen works only in that status), and then the pairing runs again. `reviewing` begins with a later stage.
- Building needs the rows' content: an import whose cells were wiped, or that is not being set up, is left alone.
- Every step is **one statement for the whole import** (or for a chunk of the search) - `ImportPuzzleMatcherTest`
  pins that building and pairing 60 puzzles costs the statements of 2.

## Tables

`data_import_puzzle` - a distinct puzzle of an import, the unit of pairing. Unique `(import_id, puzzle_key)`, index
`(import_id, tier, choice)`.

| Column | |
|---|---|
| `puzzle_key` | `ImportPuzzleKey::of()` - every row with it is this puzzle |
| `name`, `brand_text`, `pieces_count`, `own_puzzle_id` | as the first of its rows writes them (first sheet, then row); `own_puzzle_id` = the puzzle id of our own export |
| `eans`, `brand_codes` | jsonb lists: every code of every row, each once |
| `manufacturer_id` | the catalogue brand the text stands for (`ImportBrandResolver`), `ON DELETE SET NULL` - worked out again by every run |
| `rows_results`, `rows_owned`, `rows_wish_list` | rows by kind (a `both` row counts in two); rows that are no puzzle or left out do not count |
| `tier` | `found` / `probably` / `choose` / `none` - **the rules' word; a decision never changes it**. Null: the similar-name search has not come to it |
| `candidates` | jsonb, at most 5, the best first: `[{puzzleId, how, score, piecesDiffer}]`. `how`: `own_id`, `earlier_import`, `barcode`, `name`, `own_result`, `brand_code`, `own_result_loose`, `similar_name`. `score` only for `similar_name`. Ids are not foreign keys: follow `puzzle_redirect` and check `sqlCandidate()` when one is used |
| `searched_at` | when the similar-name search ran for it |
| `choice` | `undecided` / `catalogue` / `unlisted` / `left_out` |
| `puzzle_id` | the catalogue puzzle of a `catalogue` choice. Foreign key **without** `ON DELETE`: a merge moves it; any other path that deletes a paired puzzle fails instead of leaving a decision without its puzzle |
| `decided_at` | when the decision last changed - taking it back counts |
| `decision_version` | raised by every change of the decision (the player's, a remembered one arriving, the rules' own) - **the three-way check's stamp** (`decided_at` keeps whole seconds) |
| `decided_how` | `player` / `remembered` / `automatic`. Only `player` is never replaced by a later run |

`import_puzzle_decision` - a player's remembered answer. Unique `(player_id, puzzle_key)` (a surrogate `id` is the
primary key - the README has the pair as the key). `choice` (`catalogue` / `unlisted` / `left_out` /
`not_this_puzzle` - a pairing the player took back, with the puzzle it was), `puzzle_id` (`ON DELETE CASCADE`),
`decided_at`. Goes with the player.

`data_import_row`: `import_puzzle_id` is filled (index added); new `already_yours_how` (`profile` /
`earlier_import`) and `already_yours_solving_time_id`.

`data_import`: `pairing_run`, `paired_at`, `pairing_counters` (jsonb), `pairing_requeues`.

Not built here: `picture_url`, `unlisted_puzzle_id` (stage 7), the player's own brand aliases.

## Who may be a candidate

One rule, `GetImportPuzzleCandidates::sqlCandidate()`, in every lookup, in the remembered decisions and in
`DecideImportPuzzles`:

- not hidden: `hide_until` **and** `hide_image_until` are empty or past (a competition's secret puzzle, a placeholder
  that hides its picture and codes),
- approved - or added by the importing player themselves.

So a secret puzzle is not found by its name, its barcode, the file's own puzzle id, a similar name, the player's own
result on it, nor accepted when the player sends its id.

## Tiers

`ImportPuzzleTierRules` (pure, `ImportPuzzleTierRulesTest` is its table). Every catalogue puzzle a lookup found is
first given a strength. "The count agrees" = the file's piece count is the puzzle's, or one apart (a box says 501).

| Found by (`how`) | What the lookup is | Strong | Weak |
|---|---|---|---|
| `own_id` | the file's own puzzle id, following `puzzle_redirect` | always | |
| `earlier_import` | a row with the same `source_key` in another import of the player made a result that exists - on that result's puzzle | always | |
| `barcode` | a barcode of the rows **equals** an `e:` line of `search_codes` (digits without leading zeros on both sides) | the count agrees | the file has no piece count |
| `name` | the name folded by `SearchText::fold()` - as written, and without a `(…)` part - **equals** a line of `search_names` (any name of the puzzle), **and** `pieces_count` is the file's when the file has one | the brand resolved from the file is the puzzle's brand | the brand differs or is missing; or the file has no piece count |
| `name_without_brackets` | the same lookup with the file's name without its `(…)` part - "Ocean (500)" may be "Ocean", "Paris (Panorama)" is not "Paris" | | always |
| `name_short` | the `name` lookup for a folded name of fewer than 4 characters or without a letter ("Cat", "2024", "20 26") | | always |
| `own_result` | one result of the player meets one row, one to one: same day, same seconds, same solo/pair/team | the time has real seconds (not a multiple of 60), the file has a piece count, and no other lookup points at another puzzle | otherwise |
| `own_result_loose` | a result meets a row less exactly (the day before or after, another kind, several rows or results) | | always |
| `brand_code` | a brand code of the rows (letters and digits) **equals** a `c:` line of `search_codes` - with or without the EAN's check digit - **and** the file's brand is the puzzle's | | always |

**A lookup that was cut is never "found".** Each kind of lookup brings at most 20 puzzles per import puzzle - after
the candidate rule, the file's brand first. When it found more (`capped`), one strong puzzle among those it brought
is "choose": it may be one of several (25 puzzles called "Sunset", one of the brand).

**A hit whose piece count does not agree is neither strong nor weak**: it is a candidate only (`piecesDiffer`), and
alone it makes the puzzle "choose".

| Tier | Exact rule | Decided for the player |
|---|---|---|
| **found** | exactly one strong puzzle, no lookup cut | yes - `catalogue`, `automatic`, changeable - unless the player took exactly this pairing back before ("not this puzzle"): then it stays undecided with its candidates |
| **choose** | several strong puzzles; or one strong puzzle of a cut lookup; or no strong one and several weak ones; or only candidates with another piece count; or (after the search) names with trigram similarity ≥ 0.6 | never |
| **probably** | no strong puzzle, exactly one weak one | never |
| **none** | nothing exact, and no similar name | never |

- Among candidates of one strength, a puzzle the player solved or owns comes first.
- A similar name is **always "choose"** - even a name that is the very same text on a puzzle of another piece count.
  Similar names are looked for only when nothing exact was found; the same piece count first, then the same brand,
  then the most similar, 5 at most; under three folded characters nothing is compared.
- The threshold is `ImportPuzzleTierRules::SIMILAR_NAME_THRESHOLD` = 0.6 - **not yet checked** on 200 labelled rows
  (implementation plan, "Threshold check").

## Already yours

A hint of the pairing on the row, worked out again by every run while the row is `waiting` - **the row's `state` is
not changed** (a row that is `already_yours` is never parsed again; whether a row is added is settled when it is
added, README "Never twice").

- `earlier_import` - a row with the same `source_key` in **another import of the same player** has a
  `solving_time_id` whose result still exists (`DataImportRowRepository::markRowsOfEarlierImports()`).
- `profile` - a result **tracked by the player** has the row's seconds on the row's day and the row's
  solo/pair/team, on a candidate puzzle whose piece count agrees with the row's puzzle, and they meet one to one
  (no other row for the result, no other result for the row). Only rows with an exact day and a time.

`already_yours_solving_time_id` names the result. **It is a hint until the row's puzzle is decided**: 5b / 6b must
compare the result's puzzle with the decided one before calling the row "already on your profile".

Not looked at here: results the player is only a member of (the team snapshot would put an `OR` into the join),
months and years, rows without a time, owned and wish-list rows - all of that is "Never twice" in 6b.

## Remembered decisions

Written by `DecideImportPuzzles`, one per player and puzzle key. **A pairing taken back is remembered as "not this
puzzle"** (`not_this_puzzle` + the catalogue puzzle): the import's puzzle is a new row whenever its rows leave the
file and come back (a sheet left out and taken in again), and in the player's next file - and the rules would make
the same wrong pairing again. With it they still say "found" and decide nothing. Taking back anything that names no
puzzle (unlisted, left out) removes the remembered decision.
Every run applies them first (`DataImportPuzzleRepository::applyRememberedDecisions()`), to every puzzle the player
has not decided in this import: the same key → the same choice, `decided_how = remembered`, with the moment the
decision was made. A remembered catalogue puzzle that may not be a candidate any more is not applied, and a
`remembered` decision whose source went is taken off again. A puzzle that leaves the file is deleted, decided or
not - the decision is remembered, so the key arrives decided when it comes back.

## Decisions

`DecideImportPuzzles(importId, playerId, list<ImportPuzzleDecisionChange>)`, each change
`(importPuzzleId, choice, puzzleId, seenVersion)`. Under the import's lock; **all or nothing, everything checked
before anything is written**:

| Refused with | When |
|---|---|
| `DataImportNotFound` (404) | not the player's import, or it is not being set up |
| `ImportPuzzleDecidedMeanwhile` (409, `importPuzzleIds`) | a puzzle's `decision_version` is not what the page saw (`seenVersion`, 0 = never decided) |
| `ImportPuzzleChoiceRefused` (422) `unknown_puzzle` | no such puzzle of this import |
| … `invalid` | `catalogue` without a puzzle, another choice with one, or `not_this_puzzle` (nothing a page sends) |
| … `not_choosable` | the catalogue puzzle does not exist or may not be a candidate for this player |
| … `other_piece_count_in_group` | a puzzle whose piece count is not the file's (more than one apart) in a message of several decisions - it can only be chosen alone |

`Undecided` takes a decision back: `decided_how = player` (so no run ticks it again), and what is remembered is
"not this puzzle" (above). Any other choice is stored as the player's and remembered. An automatic "found" is undone the same way.

## Where the rules end

`data_import.pairing_counters`, numbers only - counted by `GetImportPairing::counters()` at the end of every run and
moved by each decision (`DecideImportPuzzlesHandler::counted()` is the same rule for one puzzle). The shape, with
invented numbers:

```json
{"puzzles": 1338,
 "tiers": {"found": 610, "probably": 120, "choose": 240, "none": 368, "open": 0},
 "remembered": 0,
 "player": {"decided": 31, "foundOverruled": 2, "noneOfThese": 9, "pickedAnother": 4, "foundByHand": 6}}
```

A candidate is kept by its id at the time, so `GetImportPairing::counters()` reads candidates through
`puzzle_redirect` (a puzzle merged since is the puzzle it was merged into); the handler's own count between two runs
does not, and the next run corrects it.

`tiers` = what the rules left (never changed by a decision; `open` = the search has not come to it). `foundOverruled`
= a "found" the player unticked or replaced; `noneOfThese` = "probably" / "choose" answered with unlisted or left
out; `pickedAnother` = "probably" / "choose" answered with a puzzle that was not offered; `foundByHand` = "none"
answered with a catalogue puzzle.

## The summary

`GetImportPairing::summary()` → `ImportPairingSummary`, one statement, shown on the import's page
(`data_import/_pairing_summary.html.twig`, texts `data_import.pairing.*` in 6 locales) once the import has puzzles:
"Found in the database: N · Probably: M · To choose: K · Not found: J". It counts **what is left to do**: `found` =
decided to be a catalogue puzzle (by the rules, remembered or by the player), `probably` / `choose` = undecided in
that tier (a "found" the player unticked is "to choose"), `notFound` = undecided "none" or kept as unlisted;
`searching` = the search has not come to it.

## A puzzle merge

`ApprovePuzzleMergeRequestHandler::migrateRecordsToSurvivor()` moves `data_import_puzzle.puzzle_id` and
`import_puzzle_decision.puzzle_id` onto the survivor as objects (`puzzleMergedInto()`), ids in the merge audit's
inventory (`importPuzzles`, `importPuzzleDecisions`). Both are listed in `PuzzleMergeForeignKeyCoverageTest`;
`ImportPuzzleMergeTest`.

## When a run fails

The `imports` queue has no retries. A run that fails - a puzzle merge deleting a puzzle the run is just pairing with
(the foreign key refuses one of the two), a fault of ours - is rolled back whole and leaves `paired_at` empty. The
safety net (`ReadWaitingImportsHandler`, every 5 minutes) queues `BuildImportPuzzles` again for an import that is
being set up, has a parsed sheet, is not paired and wrote nothing for a quarter of an hour
(`RequestImportPairing`) - at most `DataImportLimits::MAX_SET_UP_ATTEMPTS` (3) times (`data_import.pairing_requeues`,
back to zero when a run ends). It also pairs imports parsed before this stage.

## The statements

Bulk and native on purpose (the reason is on `DataImportPuzzleRepository`): an import holds up to 50,000 rows and
thousands of puzzles. Per run of `BuildImportPuzzles`, whatever the size:

| # | Where | What |
|---|---|---|
| 1 | `DataImportPuzzleRepository::distinctPuzzlesOfRows()` | `GROUP BY puzzle_key` over the import's rows |
| 2 | `::store()` | `INSERT … SELECT FROM jsonb_to_recordset ON CONFLICT (import_id, puzzle_key) DO UPDATE` (ids are UUIDv7 made in PHP) |
| 3 | `::deleteWithoutRows()` | puzzles no row says any more |
| 4 | `::linkRows()` | `data_import_row.import_puzzle_id` |
| 5 | `DataImportRowRepository::markRowsOfEarlierImports()` | the hint starts over; rows of earlier imports marked |
| 6, 7 | `DataImportPuzzleRepository::applyRememberedDecisions()` | stale `remembered` taken off; remembered applied |
| 8 | `::forTheRules()` | the puzzles nobody decided |
| 9 | `ImportBrandResolver::resolve()` | every brand, once |
| **10** | **`GetImportPuzzleCandidates::exactHits()`** | **all names, barcodes, brand codes and own ids** |
| 11 | `GetImportRowsOnProfile::hits()` | the player's own results |
| 12 | `DataImportPuzzleRepository::storeRulesResults()` | tier, candidates, brand, automatic decisions |
| 13 | `DataImportRowRepository::storeProfileHints()` | `profile` hints |
| 14 | `GetImportPairing::counters()` | |

and per chunk of `MatchImportPuzzlesFuzzy`: the waiting puzzles, the threshold, **`GetImportPuzzleCandidates::similarNames()`**, one write.

What a name, a barcode and a brand code match is `PuzzleTextSearch`'s: the exact statement joins by equality with
the lines of the stored keys (`sqlNameLines()` / `sqlCodeLines()` split a key in SQL; `nameLine()` / `barcodeLine()`
/ `brandCodeLine()` write the other side in PHP), the similar-name statement uses `batchSimilarNameCondition()` /
`nameSimilarity()`. The line format lives there and in `PuzzleSearchKeys` only - `PuzzleTextSearchLinesTest` pins
that a key is split into exactly the lines it is built of (other names, several EANs, check-digit aliases). No
statement elsewhere matches puzzle text.

### Measured on production (2026-11-15, read only, 46,427 puzzles)

- **Similar names**, a chunk of 100: 840 ms - a `Bitmap Index Scan on custom_puzzle_search_names_trgm` per puzzle
  (80 index rows → 2 after the filter). Within the budget.
- **The exact statement, first shape - `LIKE` through the trigram indexes - was far over budget**: 100 wanted puzzles
  1,072 ms, 3,000 ran into the 20 s statement timeout (a whole-name `LIKE` ~8.5 ms, a barcode `LIKE` ~3.7 ms each on
  production; the dev copy's 0.25 ms was not representative). 5,000 puzzles would have been 45-60 s inside the
  build's transaction and lock.
- **The shape built now - equality hash joins against the catalogue's lines, split once**: 143 ms for 5,000 wanted
  puzzles (names + barcodes; a sequential scan of `puzzle` ~9 ms per key, 48,182 name lines, 28,897 barcode lines,
  two hash joins ~70 ms each). One shape for every size of import - a small import is not sent through the index.

The statement as committed adds to the measured one: brand codes (`c:` lines, in the same join as the barcodes, by
tag), a join to `puzzle` inside each lookup so that **who may be a candidate and whether the brand agrees is settled
before the cap**, the cap of 20 hits per wanted puzzle and kind (`row_number()`, the brand's own puzzles first,
`capped` when more were found), the own-id branch and `is_players`. On the dev
copy (41,282 puzzles) the whole of it took ~0.6 s for 5,000 wanted puzzles with hash joins throughout - **worth one
more `EXPLAIN (ANALYZE, BUFFERS)` on production before the merge** (the planner's row estimates for the joins are
far too high, so look at what JIT costs).

**1. The exact statement** (`GetImportPuzzleCandidates::exactHits()`). Budget: under 1 s for 5,000 puzzles (README).

```sql
-- :wanted = [{"id": "<uuid>", "pieces": 500, "manufacturerId": "<uuid or null>", "ownId": null,
--             "names": [{"line": "zq circle of kites (gift)", "how": "name"},          -- PuzzleTextSearch::nameLine() = SearchText::fold()
--                       {"line": "zq circle of kites", "how": "name_without_brackets"}],
--             "eans":  ["4005556174812"],          -- ::barcodeLine(): digits without leading zeros
--             "codes": ["17481"]}, …]              -- ::brandCodeLine(): letters and digits (SearchText::code())
WITH wanted AS MATERIALIZED (
    SELECT *
    FROM jsonb_to_recordset(CAST(:wanted AS JSONB)) AS wanted(id UUID, pieces INT, "manufacturerId" UUID, "ownId" UUID, names JSONB, eans JSONB, codes JSONB)
),
wanted_names AS MATERIALIZED (
    SELECT DISTINCT wanted.id, wanted.pieces, wanted."manufacturerId" AS manufacturer_id, name.line, name.how
    FROM wanted
    CROSS JOIN LATERAL jsonb_to_recordset(wanted.names) AS name(line TEXT, how TEXT)
),
wanted_codes AS MATERIALIZED (
    SELECT DISTINCT wanted.id, wanted."manufacturerId" AS manufacturer_id, 'e' AS tag, barcode.code
    FROM wanted
    CROSS JOIN LATERAL jsonb_array_elements_text(wanted.eans) AS barcode(code)

    UNION ALL

    SELECT DISTINCT wanted.id, wanted."manufacturerId", 'c', brand_code.code
    FROM wanted
    CROSS JOIN LATERAL jsonb_array_elements_text(wanted.codes) AS brand_code(code)
),
catalogue_names AS MATERIALIZED (
    -- PuzzleTextSearch::sqlNameLines()
    SELECT pts_puzzle.id, pts_puzzle.pieces_count, pts_line AS line
    FROM puzzle pts_puzzle
    CROSS JOIN LATERAL unnest(string_to_array(btrim(pts_puzzle.search_names, E'\n'), E'\n')) AS pts_line
    WHERE pts_line <> ''
),
catalogue_codes AS MATERIALIZED (
    -- PuzzleTextSearch::sqlCodeLines()
    SELECT pts_puzzle.id, substring(pts_line from 1 for 1) AS tag, substring(pts_line from 3) AS code
    FROM puzzle pts_puzzle
    CROSS JOIN LATERAL unnest(string_to_array(btrim(pts_puzzle.search_codes, E'\n'), E'\n')) AS pts_line
    WHERE pts_line LIKE 'e:%' OR pts_line LIKE 'c:%'
),
hits AS (
    -- Who may be a candidate, and whether the brand agrees, is settled BEFORE the cap: the brand's own puzzles come
    -- first, and a kind of lookup that found more than it may bring says so (`capped`) - never "found" then
    SELECT by_name.id, by_name.puzzle_id, by_name.how, by_name.total > 20 AS capped
    FROM (
        SELECT
            wanted_names.id,
            wanted_names.how,
            puzzle.id AS puzzle_id,
            row_number() OVER name_hits AS position,
            COUNT(*) OVER (PARTITION BY wanted_names.id, wanted_names.how) AS total
        FROM wanted_names
        INNER JOIN catalogue_names
            ON catalogue_names.line = wanted_names.line
            AND (wanted_names.pieces IS NULL OR catalogue_names.pieces_count = wanted_names.pieces)
        INNER JOIN puzzle ON puzzle.id = catalogue_names.id
        WHERE ((puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
            AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now::timestamp)
            AND (puzzle.approved OR puzzle.added_by_user_id = :playerId))
        WINDOW name_hits AS (
            PARTITION BY wanted_names.id, wanted_names.how
            ORDER BY (wanted_names.manufacturer_id IS NOT NULL AND puzzle.manufacturer_id = wanted_names.manufacturer_id) DESC, puzzle.id
        )
    ) by_name
    WHERE by_name.position <= 20

    UNION ALL

    SELECT by_code.id, by_code.puzzle_id, CASE WHEN by_code.tag = 'e' THEN 'barcode' ELSE 'brand_code' END, by_code.total > 20
    FROM (
        SELECT
            wanted_codes.id,
            wanted_codes.tag,
            puzzle.id AS puzzle_id,
            row_number() OVER code_hits AS position,
            COUNT(*) OVER (PARTITION BY wanted_codes.id, wanted_codes.tag) AS total
        FROM wanted_codes
        INNER JOIN catalogue_codes
            ON catalogue_codes.code = wanted_codes.code
            AND catalogue_codes.tag = wanted_codes.tag
        INNER JOIN puzzle ON puzzle.id = catalogue_codes.id
        WHERE ((puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
            AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now::timestamp)
            AND (puzzle.approved OR puzzle.added_by_user_id = :playerId))
        WINDOW code_hits AS (
            PARTITION BY wanted_codes.id, wanted_codes.tag
            ORDER BY (wanted_codes.manufacturer_id IS NOT NULL AND puzzle.manufacturer_id = wanted_codes.manufacturer_id) DESC, puzzle.id
        )
    ) by_code
    WHERE by_code.position <= 20

    UNION ALL

    SELECT wanted.id, COALESCE(puzzle_redirect.survivor_puzzle_id, wanted."ownId"), 'own_id', FALSE
    FROM wanted
    LEFT JOIN puzzle_redirect ON puzzle_redirect.old_puzzle_id = wanted."ownId"
    WHERE wanted."ownId" IS NOT NULL
)
SELECT
    hits.id AS import_puzzle_id,
    hits.puzzle_id,
    hits.how,
    BOOL_OR(hits.capped) AS capped,
    puzzle.pieces_count,
    puzzle.manufacturer_id,
    (
        EXISTS (SELECT 1 FROM puzzle_solving_time WHERE puzzle_solving_time.player_id = :playerId AND puzzle_solving_time.puzzle_id = puzzle.id)
        OR EXISTS (SELECT 1 FROM collection_item WHERE collection_item.player_id = :playerId AND collection_item.puzzle_id = puzzle.id)
    ) AS is_players
FROM hits
INNER JOIN puzzle ON puzzle.id = hits.puzzle_id
WHERE ((puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
            AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now::timestamp)
            AND (puzzle.approved OR puzzle.added_by_user_id = :playerId))
GROUP BY hits.id, hits.puzzle_id, hits.how, puzzle.id
ORDER BY hits.id, hits.puzzle_id, hits.how
```

A barcode is compared as the key stores it - digits without leading zeros, the whole number; the scanner's
misread tolerance (`FindPuzzlesByScannedEan`) is deliberately not part of the exact tier.

**2. The similar-name statement** (`GetImportPuzzleCandidates::similarNames()`), a chunk of 100. Budget: ~2 s a
chunk - measured 840 ms (above). Run both lines in one transaction - the first sets the index threshold (0.1 under
the similarity asked for: the index finds a stretch of the names key, the measure compares whole names). Watch for:
`Bitmap Index Scan on custom_puzzle_search_names_trgm` with `search_names %> …` per wanted puzzle, and how many rows
the heap filter throws away for short or common names.

```sql
SELECT set_config('pg_trgm.word_similarity_threshold', '0.50', true);

-- :wanted = [{"id": "<uuid>", "name": "zq circle of colors ocean", "pieces": 500, "manufacturerId": null}, …]
--            name = SearchText::fold() of the file's name;  :similarity = 0.6
WITH wanted AS (
    SELECT *
    FROM jsonb_to_recordset(CAST(:wanted AS JSONB)) AS wanted(id UUID, name TEXT, pieces INT, "manufacturerId" UUID)
)
SELECT wanted.id AS import_puzzle_id, found.puzzle_id, found.pieces_count, found.manufacturer_id, found.score
FROM wanted
CROSS JOIN LATERAL (
    SELECT scored.puzzle_id, scored.pieces_count, scored.manufacturer_id, scored.score
    FROM (
        SELECT puzzle.id AS puzzle_id, puzzle.pieces_count, puzzle.manufacturer_id,
            (SELECT MAX(similarity(pts_name_line, wanted.name))
             FROM unnest(string_to_array(btrim(puzzle.search_names, E'\n'), E'\n')) AS pts_name_line) AS score
        FROM puzzle
        WHERE wanted.name <% puzzle.search_names
            AND ((puzzle.hide_until IS NULL OR puzzle.hide_until <= :now::timestamp)
                AND (puzzle.hide_image_until IS NULL OR puzzle.hide_image_until <= :now::timestamp)
                AND (puzzle.approved OR puzzle.added_by_user_id = :playerId))
    ) scored
    WHERE scored.score >= :similarity
    ORDER BY
        (wanted.pieces IS NOT NULL AND scored.pieces_count = wanted.pieces) DESC,
        (wanted."manufacturerId" IS NOT NULL AND scored.manufacturer_id = wanted."manufacturerId") DESC,
        scored.score DESC,
        scored.puzzle_id
    LIMIT 5
) found
ORDER BY wanted.id
```

Worth a look on production as well, with a player who has thousands of results and an import of thousands of rows:
`GetImportRowsOnProfile::hits()` (a hash join of the import's dated rows with the player's results on the seconds)
and `DataImportRowRepository::markRowsOfEarlierImports()` (reads every row of the player's other imports that made a
result - `data_import_row` has no index by player and key; the README's `(player_id, source_key)` index needs a
`player_id` column the row does not have).

## What 5b's screens can call

- `GetImportPairing::summary(importId)` / `::counters(importId)`.
- Lists per group: `data_import_puzzle` by `(import_id, tier, choice)` (the index is there); a candidate's picture
  and name come from `puzzle` by the ids in `candidates` - check `GetImportPuzzleCandidates::sqlCandidate()` and
  follow `puzzle_redirect`.
- `DecideImportPuzzles` for one tap, "Tick all I see here" (a list of changes, each with the `decision_version` the
  page showed), "None of these" (`Unlisted` / `LeftOut`), untick (`Undecided`). A 409 names the stale puzzles.
- The search sheet: whatever the player picks goes through `DecideImportPuzzles`, which checks it like a candidate.
- Rows of a puzzle: `data_import_row.import_puzzle_id` (indexed); `already_yours_how` for the folded group.
- A decision changes no row: "a row is ready when its puzzle is decided" is derived (`choice` of its puzzle).

## Where the README and the code differ

| README | Built | Why |
|---|---|---|
| Similar names: trigram on `puzzle.name` (`custom_puzzle_name_trgm`), best three | every name of the puzzle through `search_names` (`custom_puzzle_search_names_trgm`, word similarity as the index filter, whole-name similarity as the measure), best five | the catalogue holds other names; the brief asked for five stored candidates |
| A barcode on a puzzle with another piece count is "Probably" | "choose" | the stage's guard: another piece count is never more than a choice |
| Brand code + the same brand | the same; a file without a brand gets nothing from a brand code | short codes repeat across brands |
| Unapproved puzzles | only the player's own are candidates | the catalogue search shows everybody's unapproved puzzles; pairing a result with a puzzle that may still be merged or corrected is the player's own risk only for their own |
| `data_import_row.state = already_yours` set by the pairing | a hint in two columns, the state untouched | a row in that state is never parsed again, and "already yours" is settled when rows are added |
| `import_puzzle_decision` primary key `(player_id, puzzle_key)` | a surrogate id + unique | every entity has a UUIDv7 id |
| `decided_how (player \| remembered)` | + `automatic` | the rules' own "found" must be told apart: it is replaced by later runs |
| Remembered brand answers ("MB = Milton Bradley") | not built - `ImportBrandResolver` knows the catalogue's rule and letters-and-digits | no storage for a player's aliases yet |
| "Run when a layout is confirmed" | after every parse of a sheet | the parse is what changes rows |
| Name + pieces + brand is "Found", "also without a `(…)` part" | without the brackets, or for a name under 4 characters or without a letter, only "probably" | the review: "Paris (Panorama)" is not "Paris" |
| Three-way on `decided_at` | on `decision_version` | the column keeps whole seconds |
| Taking a decision back clears the remembered decision | a pairing taken back is remembered as "not this puzzle" | the import's puzzle is rebuilt, and the rules would pair it again |

## Loose ends

- Every run writes every undecided puzzle again (tier, candidates) even when nothing changed - row churn on a large
  import parsed sheet by sheet.
- The similar-name search is never repeated for a puzzle it looked at, while the exact rules find nothing - a puzzle
  added to the catalogue later under a similar name is not offered until the key is new.
- The 0.6 threshold is unchecked; results the player is only a member of are not "already yours"; no index for
  `markRowsOfEarlierImports()`.
