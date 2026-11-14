# Data import - research (2026-11-14)

The facts behind [README.md](README.md). Every production number was read on production in a read-only session on
2026-11-14; the three players' files were analysed locally and are not in the repository.

## Production numbers

| Fact | Number |
|---|---|
| Catalogue puzzles | 46,472 (45,980 approved, 5 without a picture, 1 hidden) |
| With a barcode / with a brand code | 28,239 (61 %) / 19,244 (41 %) |
| Distinct barcodes | 27,016, of them 900 on more than one puzzle (305 on three or more, the most 44) |
| Brands | 2,330 (1,999 approved) |
| Puzzles sharing name + pieces + brand with another puzzle | 1,476 (645 groups, the largest 7) |
| Puzzles sharing name + pieces | 5,691 (2,222 groups, the largest 25) |
| Puzzles sharing only the name | 12,510 (4,181 groups; "new york" 56, "london" 52, "disney princess" 50) |
| Piece counts in use | 477 distinct; 38,743 puzzles on a standard count |
| Results | 590,197 (21,026 without a time, 80,015 pair/team) |
| Players with results | 8,288; median 12, 90th percentile 203, 99th 787, the most 2,456; 239 with 500+, 44 with 1,000+ |
| Players with collection items | 2,425; median 8, 99th percentile 548, the most 1,754 |
| Back-dated entry, last 12 months (solve date > 30 days before the entry) | 1,857 players, 31,874 results of 348,337 added; 329 players 20+, 139 players 50+, 16 players 200+ |
| New trackers, last 12 months | 3,569; 667 back-filled in their first 14 days, 178 ten or more, 39 fifty or more, 11 a hundred or more |
| Feature requests about importing | none |
| Collection items | 113,230, of them 98,947 in the system collection; 46 player + puzzle pairs sit in it twice (53 extra rows) - the database has no uniqueness there |
| Results logged before their solve date (`tracked_at` day < `finished_at` day) | 1,369 of 590,225 - nothing may assume "logged after solved" |
| Players with a time zone | 1,797 of 12,388 |

## The first two files against the catalogue

4,764 distinct rows (brand, pieces, title) - three groups: file 1 piles (1,733), file 1 results (2,245), file 2 (786).

| | File 1 piles | File 1 results | File 2 | All |
|---|---|---|---|---|
| Name + pieces + brand, one puzzle | 653 | 734 | 359 | 1,746 (36.7 %) |
| Name + pieces + brand, several | 11 | 17 | 4 | 32 |
| Name + pieces (brand differs), one puzzle | 141 | 243 | 71 | 455 (9.6 %) |
| Name + pieces, several | 32 | 50 | 7 | 89 |
| Name + brand, other pieces | 17 | 31 | 12 | 60 |
| Name only | 59 | 116 | 34 | 209 |
| No exact name | 820 | 1,054 | 299 | 2,173 (45.6 %) |
| Brand text recognised (letters and digits compared) | 1,342 | 1,606 | 643 | 3,591 (75 %) |

Names were folded the way `SearchText::fold()` does (approximated) and also tried without a part in brackets; catalogue
names are the lines of `search_names` (main title and other names).

Rows without an exact name, a sample of 400, best catalogue puzzle of the same piece count by trigram similarity of the
name: ≥ 0.8 for 64 (16 %), 0.6-0.8 for 82 (20.5 %), 0.45-0.6 for 81 (20 %), none for 173 (43 %). With the same brand:
88 of the ≥ 0.6 ones.

Cost on production: the exact steps for all 4,764 rows in one statement 0.24 s; the similarity lookups 10.5 s for 400
(26 ms each). A first version of the exact statement with an `OR` in the join ran into the 45 s timeout - the import's
statements need `EXPLAIN` on production before they ship.

## The third file (a training workbook)

20 sheets, 4.7 MB, 1,927 dated results and a puzzle list of 1,338 puzzles; its owner is an active MySpeedPuzzling
player. Every sheet is an Excel table with a dashboard of formulas above the header.

| Fact | Number |
|---|---|
| Results sheets / results | 9 sheets (by piece count, pairs, competitions, two speed-run sheets, another person's) / 1,927 |
| Rows that are not results: only unticked checkboxes | 76 + 641 + 152 + 689 on four sheets |
| Template rows (puzzle prefilled, no date, no time) | 42 + 15 on the speed-run sheets |
| Results sheets without a brand column | 2 (502 results) - the brand is on the puzzle list |
| Results flagged "entered on MSP" / not / no such column | 1,101 / 360 / 466 |
| One puzzle solved most often | 153 times; up to 13 times on one day |
| Results equal in puzzle, day and time on one sheet | 13 pairs, all with different attempt numbers |
| The same result (puzzle, day, time) on two sheets | 144 |
| Attempt number 1 (first try) on the largest sheet | 550 of 916 |
| Dates in the future (typos) | 2 |

Against the production catalogue (1,381 distinct puzzles, 2026-11-14): name + pieces + brand on one puzzle 735
(53.2 %), several 16; name + pieces on one puzzle 205 (14.8 %), several 35; name + brand with other pieces 29; name
only 47; no exact name 314 (22.7 %). Brand text recognised for 1,101 (80 %). Of 300 rows without an exact name: a
similar name with the same pieces at ≥ 0.8 for 67, 0.6-0.8 for 87, 0.45-0.6 for 88, nothing for 58.

Against the results of the player whose results match the file best (counts only, nobody identified beyond that):

| Flag in the file | Rows | Puzzle found by exact name | Same day and time on the profile | Day off by one | Time on another date | Day with another time | Puzzle solved, nothing close | Puzzle not found on the profile |
|---|---|---|---|---|---|---|---|---|
| entered on MSP: yes | 1,101 | 914 | 837 | 4 | 16 | 6 | 2 | 236 |
| entered on MSP: no | 360 | 227 | 4 | 0 | 0 | 1 | 2 | 353 |
| no such column | 466 | 445 | 228 | 2 | 5 | 11 | 180 | 40 |

So "same puzzle, day and time" finds 97 % of the flagged results whose puzzle was found by name; the near cases are
3 %; and a fifth of the flagged results are invisible until the puzzle is paired, because the spreadsheet's name is
not the catalogue's. The flag in the file is nearly always right.

## What players track that MySpeedPuzzling does not

Read across the three files (and the public template) for what is worth having, not only worth importing.

**Personal facts with no home on MySpeedPuzzling today** - they become ready-made fields (README "Ready-made fields"):
private notes on a result (all three files; the site has only a public comment), what to work on next, the copy's
condition, missing pieces, purchase date / place / price, where the puzzle is kept, how many copies, personal tags
(seasons, themes).

**Facts about the puzzle the catalogue lacks:** artist (two files, over 1,300 rows), year of release, material
(cardboard / wood / plastic), piece style (XL pieces), shape. File 3 carries hand-made picture tags for 1,281 puzzles
(photo / illustration / collage, animals, landmark, busy, gradient, rectangle / circle) - with its owner's yes, a
labelled set to check the picture pipeline's tags against.

**Things the players calculate by hand that MySpeedPuzzling has the data for:**

| In the spreadsheet | What it is | On MySpeedPuzzling |
|---|---|---|
| "Placing if used at comp" + percentage, per result (file 3, 208 rows) | where my time would have placed at the event that used this puzzle | not shown; the events' results are there. The same idea as "race the championships at home" of the data opportunities (2026-11-12) - here a player does it by hand |
| "Diff between previous best attempt" (file 3, every repeat) | improvement against my own record | the attempts are on the result detail; the difference and a record history are not |
| "Current standing on my speed puzzling" + "in top percentage", typed by hand (file 3, 650 rows) | my rank on the puzzle, noted when I logged it | the live rank exists; the rank **at the time** is not kept |
| Time bands per piece count (under 36 min, 36-42, …) with counts, and the same for pace (file 3) | the distribution of my own times | charts by month exist; "my times in bands" does not |
| Monthly average, all runs against first tries only (file 3 "Stats") | am I getting faster on unseen puzzles | - |
| "Days to complete" = solved minus bought (file 1, 1,000 rows) | how long a puzzle waited on the shelf | needs "Bought on" - the ready-made field |
| Totals per brand and per piece count of the to-do pile (file 1) | what is my backlog made of | unsolved list exists, no breakdown |
| Goals (file 3 "Goals"), a target under each dashboard | personal targets | - |
| A sheet per puzzle solved over and over (file 3, 120 and 153 attempts) | a "project puzzle" and its curve | the result detail lists attempts; no page treats a puzzle as a project |
| "Waiting to arrive", "Got rid of" with where it went (file 3), a selling section (file 1) | the life of a copy: ordered → owned → passed on | wishlist, collection and sold/swapped exist as separate lists |

None of the second and third group is part of the import. They are candidates for their own work, written to
`docs/TODO.md` when the import's stage 1 lands.

## What the files look like

Covered in the README's table. Numbers worth keeping:

- File 1: 10 sheets, 5,206 puzzle rows, 62 rows that are not puzzles (summary blocks, notes). Time column of the year
  sheets: 60-70 % real duration or time cells, the rest text or empty; 12-28 text values per sheet that are not a clean
  time. Four number formats for dates in one column.
- File 2: 1 sheet, 792 rows, all formulas; pieces as text in 29 rows (`12*80`); "Completed?" in 230 rows, as `Y` or
  `Y - dd/mm/yyyy`.
- A public template (Jigsaw Puzzle Hobby tracker): 30 tabs, 17 MB (16 MB photos), time written as `8.10` for 8 h 10 min.

## The code we build on

| Finding | Where |
|---|---|
| `puzzle` is read by ~180 files, `puzzle_solving_time` by 152, `collection_item` by 17; secrecy has a shared fragment (`PuzzleSecrecy`) used by 28 files, other queries repeat the condition | visibility survey |
| An unapproved puzzle is public today (detail page, search); `approved` is a moderation state, not visibility | `GetPuzzleOverview`, `SearchPuzzle` |
| Coverage tests carry 37-153 listed files each (`TrackerlessTimeQueryCoverageTest` 153) | `tests/` |
| One typed result = 40-60 statements at once + XP and badge jobs; `tracked_at` is always now; followers are notified per result without an age check | `AddPuzzleSolvingTimeHandler`, `NotifyWhenPuzzleSolved` |
| The pace question lives in the controller, not the handler | `PuzzleAddController`, `SuspiciousTimeFormCheck` |
| Per-item outcomes exist (`SelectedPuzzlesOutcome`); the multiscan batches are all-or-nothing | `CopyPuzzlesToCollection`, `MultiscanBatchGuard` |
| Idempotency: client-chosen id + advisory lock; API derives a UUIDv5 from an idempotency key; receipts in the participants sheet | `IdLock`, `CreateSolvingTimeProcessor`, `ApplyParticipantSheetChangesHandler` |
| PhpSpreadsheet 5 is installed; the only reader is `ParticipantFileReader` (xlsx + csv, encodings, 5 MB, 5,000 rows, zip limits). It reads with `setReadDataOnly(true)`, so number formats - the only way to tell a date from a duration - are not available in that mode | `src/Services/ParticipantImport/` |
| The `async` queue carries `SendEmailMessage` (sign-in links and codes); one consumer in `compose.yml`; the digest has its own queue for that reason | `config/packages/messenger.php` |
| The duplicate cleaner removes "identical, same day, saved within 10 s" by itself; Night Owl and the hour-of-day chart read the hour of `tracked_at`; XP history is keyed on `tracked_at` | `DuplicateClassifier`, `AchievementSqlRules`, `XpFormulaCutoff` |
| `AddPuzzleToCollectionHandler` overwrites a passed comment, makes its own id, returns nothing; results without a time go through `AddPuzzleTrackingHandler` | `src/MessageHandler/` |
| The participants import is upload → mapping → preview → confirm with the file in a 24 h stash and the state in the URL | `participant-import-preview.md` |
| Google Sheets are read without credentials (`htmlview` for tabs, CSV export per gid), no size cap | `GoogleSheetReader` |
| No tolerant duration parser exists; the most tolerant is the event import's `h:mm:ss` / `mmm:ss` | `SarahDoesPuzzlesTabParser` |
| The participants sheet's grid, keys, TSV, toasts and preview dialog are generic; its model, save queue and undo are not. Phones get lists and a full-screen editor, not the grid | `assets/participants_sheet/` |
| The review banner's count is one statement of scalar subqueries, paid by the owner only | `GetPlayerReviewCounts` |
| A result's comment is public (result detail) | `templates/puzzle_result/_body.html.twig` |
| Global monthly statistics and the feeds read `tracked_at` | `GetStatistics`, `GetRecentActivity` |

## What others do

Importers:

- **Letterboxd** - an id column is an exact match, title + year a best guess; a review step to fix or drop rows; the
  same film and date updates the existing entry; "no undo after the confirmation step".
- **StoryGraph, Goodreads** - a repeated import is the main source of duplicates; the advice is to empty the account
  first; no way back.
- **Linear** - a re-import skips issues already imported; an import can be deleted for a limited time.
- **Shopify, Airtable, HubSpot, Mailchimp, Jira** - the user chooses create / update / both on a key; a blank cell
  erases in Shopify, is skipped in Airtable; nobody deletes rows that left the file.
- **HubSpot, Zoho, Pipedrive, Salesforce** - an import history; undo deletes created records (30 days / 48 hours),
  updates are not restored.
- **Flatfile, OneSchema** - column mapping with value mapping as a second step, uniqueness rules over several columns,
  an "only rows with problems" filter.
- Nobody found shows "new / changed / unchanged / missing" before applying; `terraform plan` is the model.

Identity without an id column:

- **YNAB** - `YNAB:[amount]:[date]:[occurrence]`: the counter lets two really identical rows live and still makes a
  re-import collide. The model for D6.
- **Firefly III, Lunch Money, BeanHub, Actual** - the failures: a hash of raw cells changes with whitespace, case or the
  mapping; file + line number breaks when rows shift; fuzzy matching after the id merged two real rows.
- **Stripe, Brandur, Morling** - the caller names the operation, the server keeps the first result; a key derived from
  the data needs no memory.

Recognising types - **DuckDB's CSV sniffer**: the header is the row that does not fit the types under it; a date column
starts with every format and loses the ones a value contradicts.

Reading files - PhpSpreadsheet costs about 1.6 KB a cell and had three XXE bypasses in 2024 (keep it current);
OpenSpout streams, returns durations as intervals, and needs its date option set explicitly; neither limits a zip bomb
by itself. Google: `export?format=xlsx` answers 307 to `*.googleusercontent.com`; a sheet that is not public answers
with HTML.

Sources are in the session notes of 2026-11-14; the links worth keeping:
[Letterboxd importing](https://letterboxd.com/about/importing-data/) ·
[Linear import](https://linear.app/docs/import-issues) ·
[HubSpot import history](https://knowledge.hubspot.com/import-and-export/view-and-analyze-previous-imports) ·
[Airtable CSV import](https://support.airtable.com/docs/csv-import-extension) ·
[YNAB import_id](https://cdn.jsdelivr.net/npm/ynab@4.1.0/dist/models/SaveTransaction.d.ts) ·
[BeanHub unique import id](https://beanhub-import-docs.beanhub.io/how-it-works/unique-import-id/) ·
[Brandur on idempotency keys](https://brandur.org/idempotency-keys) ·
[Morling on idempotency keys](https://www.morling.dev/blog/on-idempotency-keys/) ·
[DuckDB CSV sniffer](https://duckdb.org/2023/10/27/csv-sniffer.html) ·
[Smashing: designing a data importer](https://www.smashingmagazine.com/2020/12/designing-attractive-usable-data-importer-app/) ·
[PhpSpreadsheet memory](https://phpspreadsheet.readthedocs.io/en/latest/topics/memory_saving/) ·
[OWASP file upload](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html)
