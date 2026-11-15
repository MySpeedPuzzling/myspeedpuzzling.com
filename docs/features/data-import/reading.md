# Data import: reading the file with live progress (stage 3b)

What stage 3b built of [the design](README.md): an uploaded spreadsheet ([upload.md](upload.md)) is read by the
`imports` worker into the import's tables, its page shows the progress live, and when reading ends it lists the
sheets the file holds - or says in plain words why the file was not read. The reader itself is [reader.md](reader.md).
**Nothing is recognised, parsed, paired or added yet** - that is stage 4 and later; no page of the site links here.

## States

`uploading` → `uploaded` → `reading` → `setting_up` (read) or `read_failed`; `discarded` from anywhere
(`DataImportStatus`).

| State | Means | The page |
|---|---|---|
| `uploaded` | the file is in object storage and waits for the worker | "Waiting to be read…", live |
| `reading` | the worker has it; sheets and rows are being written | "Reading the file…", sheets read of the total, rows so far, live |
| `setting_up` | read: `data_import_sheet` and `data_import_row` hold it | the sheets list: name, rows, columns, a "Hidden" mark; "adding arrives soon" |
| `read_failed` | reading ended without the rows; `read_error` says why | the reason in plain words, "Try again", "Upload another file", Discard |

`read_failed` counts as an unfinished import (it holds its file and a place of the three).

## The read, step by step

`ReadImportFile(importId)` travels on the Messenger transport `imports` (no retries). It is sent when an import
becomes `uploaded` - by `ConfirmImportUploadedHandler`, which both the direct upload's confirmation and the fallback
form go through - inside that handler's transaction and on the same database connection as the queue, so the message
exists exactly when the state does.

`ReadImportFileHandler` runs **outside a transaction** (`HandledOutsideTransaction`): downloading and parsing take
seconds to minutes and must hold no lock. Everything it finds is written by small messages of their own - not routed,
so handled at once, each in its own short transaction - and **each answers whether reading goes on**:

| # | Message | What |
|---|---|---|
| 1 | `BeginImportReading` | `uploaded` → `reading`, `read_attempt` + 1, under the import's lock, committed **before the file is touched**. Answers the job's plain values, or nothing when there is nothing to read - a second copy of the message, a second worker |
| 2 | - | the object is streamed from storage into a temporary file (never into memory), its sha256 worked out on the way; another size than the confirmed one is a fault of ours. **Bounded in time**: the object-storage client gives a request 3 s without bytes and 10 s in all (`ObjectStorageHttpClientFactory`), and `ImportFileStorage::downloadTo()` itself gives up a read that gets nothing for 20 s and the whole download after 120 s (`ImportDownloadTimedOut`) - a fault of ours: `failed`, logged with the exception, the player can try again |
| 3 | `RecordImportFileHash` | `file_sha256`; "the same file again" is answered here (below) |
| 4 | `RecordImportSheets` | the file is opened **once**; its sheets before any row: position, title, hidden, and the hints the file gives (below). `sheets_total` |
| 5 | `RecordImportRows` | **every sheet in one streaming pass**, never opened again to resume; rows in batches of 500 (or 4 MiB, whichever comes first), each batch one transaction together with the progress. A fresh entity manager per batch. The sheet's last batch - also an empty one - marks the sheet read |
| 6 | `RecordImportRead` | `reading` → `setting_up` |
| - | `RecordImportReadFailed` | instead of 6: what was written is removed, `read_failed` + the reason |

**Memory is flat**: a row lives until its batch is written; nothing of a sheet is kept in an array. The temporary
file (`<system temp>/msp-import-files/<random>`) and the reader's own folder are removed in a `finally`; what a killed
worker left is pruned by the next read once it is a day old.

**Every write of a read is refused once the import is not being read by that very read** (`DataImport::isBeingReadBy()`:
state `reading` and the read's own `read_attempt`), under the import's advisory lock - which a discard, "Try again"
and the rescue take too - and **on fresh state**: every `Record*` message is a `RequiresFreshEntityManagerState`
one, because the worker's entity manager still holds the import as it was when the read began, while a discard (a
web request) or a second worker after "Try again" changed it in the database. So a discard while a file is
downloaded, opened or read stops the read at its next write - no hash, sheet or row is written behind it and a file
that then proves unreadable does not turn `discarded` into `read_failed`; a read that was given up as stalled can not
write into the next attempt.

The plan's "one job per sheet and per 2,000 rows" is **dropped**: a streaming reader can only read a sheet from its
start, so slicing would re-read (and pay the budget for) the same bytes again and again. One job reads the file; its
transactions are the short things.

### What a sheet keeps

`data_import_sheet`: `position`, `title` (`''` for a text file - shown as "The whole file"), `hidden`, `rows_total`
(rows with a value), `columns_total` (the right-most column a value stands in), and `hints` - what the file says
about the sheet, for the step that finds headers and tables without opening the file again (`ImportSheetHints`):
`tables` (`[{name, range, headerRow, totalsRows}]`), `frozenPane` (`{row, column}`), `usedRange`. Everything else of
the README's table (`kind`, `region`, `mapping` …) is the later stages'.

Not stored: **merged cells** (they need a second pass over every sheet; `docs/TODO.md`).

### What a row keeps

`data_import_row`: `row_number` (the spreadsheet's own - gaps stay gaps), `first_seen_version` = `last_seen_version`
= the import's version, `state` `waiting`, and `cells` - per column (its number as the key, `"1"` = A) what
`ImportRowCells` makes of the reader's cell:

| Key | |
|---|---|
| `k` | the kind: `text`, `number`, `date`, `duration`, `time_of_day`, `boolean`, `error` |
| `v` | the typed value: a text or error code as it is, a number, a date as `Y-m-d`, seconds, `true` / `false` |
| `s` | the text the player saw - left out when it says the same as `v` |
| `f` | `true`: a formula's saved result |
| `p`, `y`, `t` | dates: the precision when it is not a day (`month`, `year`), `false` when the format hides the year, the seconds of a time shown next to the date |
| `z` | the digits a number's format pads to with zeros (a barcode keeps its leading zeros in `s`) |

Empty cells are not there. Nothing is trimmed, guessed or parsed here.

## Limits

On top of the reader's own caps (reader.md "Limits and reasons"), per import (README Q8, `DataImportLimits`):

| | |
|---|---|
| File | 25 MB (checked before the upload) |
| Sheets | 100 |
| Rows | 50,000 (all sheets together) |
| Cells | 1,000,000 |
| Text kept | 300 MiB of cells altogether (the reader bounds a cell and a row, not their sum) → `too_large` |

## Budget

- **60 s of CPU time** for the reader on one file, **300 s on the wall clock** as the last resort
  (`SpreadsheetLimits::$maxReadSeconds` / `$maxWallSeconds`; reader.md "Why 60 s of CPU and 300 s on the wall"). The
  time the job spends writing rows is not the reader's. Over either: `too_slow`.
- **PHP's own time limit, 420 s**, around the read (`set_time_limit`, put back afterwards): the stop from outside
  the reader - a parser that hangs dies with the worker (the container starts it again), and the import is rescued
  by the safety net.
- The consumer's memory limit stays 512 MB; the job needs a fraction of it ("Measured").

## When reading fails

`read_error` holds the reason; the page has a sentence for each (`data_import.read_failed.<reason>`, 6 locales).

| Reason | The page says |
|---|---|
| `too_many_sheets`, `too_many_rows`, `too_many_cells`, `too_many_columns`, `too_large`, `cell_too_large` | which limit, and what to do (remove sheets or columns, split the file) |
| `password_protected` | save a copy without the password |
| `damaged` | open it in your spreadsheet app and save it again |
| `not_a_spreadsheet`, `unsupported_format` | save it as `.xlsx` or CSV |
| `too_slow` | "Reading the file took too long this time. Please try again." - never "your file is broken" |
| `failed`, `stalled` (ours) | "Something went wrong on our side … Your file is still here - please try again." |

What a file is or holds (`SpreadsheetUnreadable`) is logged at `info`. Everything else - object storage, the disk,
`SpreadsheetReaderFailed`, a bug - is logged at `error` with the exception and stored as `failed`. Nothing is thrown
on: the queue has no retries, a failed read is recorded on the import. The import and its file stay; the player can
**Try again** (`POST /{_locale}/import/{importId}/read-again` → `ReadImportAgain`: `read_failed` → `uploaded`,
queued again), upload another file, or discard.

## The same file again

Once the worker knows the sha256 (`RecordImportFileHashHandler`, under the player's lock): when the player already
has an import of the very same bytes that is being read, read, in review or done, **the new one is closed in favour
of that one** - discarded, its file deleted, `same_file_as_import_id` pointing there. Its page (and its progress
endpoint) send the player to the existing import with the notice "You already imported this very file - here it is."
An earlier import whose reading failed does not count (the new upload is the next try), nor does a discarded one, nor
another player's.

**Versions are left out of this stage.** The README's "reading again raises `data_import.version`" needs rows matched
by `source_key`, which stage 4 computes; until then a file is read once (`version` stays 1), "Try again" starts the
same version over, and a changed file is a new import.

## Progress

`ImportProgress` is the one place:

- `store()` - on the import (`sheets_total`, `sheets_read`, `rows_read`, `last_activity_at`), inside the step's
  transaction: the progress is always in the database;
- `publish()` - **after the commit**, what the database now says goes to the player's own private Mercure topic
  `/imports/<playerId>` as JSON `{type: "import_progress", importId, status, moving, sheetsTotal, sheetsRead,
  rowsRead}` - states and numbers, nothing of the file. One topic per player, added to
  `MercureTopicCollector::getAllTopicsForPlayer()` (so every signed-in page's subscribe cookie covers it; a topic per
  import would be dropped whenever another request rewrites the cookie). Row batches are published at most once a
  second, state changes always. A hub that does not answer never fails a step (one warning per import).

The page (`data_import_progress_controller.js`) listens to the `mercure:message` events `mercure_hub_controller.js`
already hands out, and **falls back to asking** `GET /{_locale}/import/{importId}/progress` (`data_import_progress`,
the same JSON plus `url`; another player's import = 404; `no-store`) every 4 seconds whenever no event arrived in the
last 4. When nothing moves any more it goes where the endpoint says: the page again - now with the sheets, or the
reason - or the import the player already has. An endpoint that does not answer (offline, a deploy, a 5xx) is asked
after 4, 8, 16 and then every 30 seconds, and after ten minutes without one answer not at all: the line "We could
not check on the reading for a while. Reload the page to see where it stands." A 401 or 403 - or the sign-in page
instead of JSON - reloads the page at once. Without JavaScript the page has a "Refresh the page" link.

No e-mail and no bell notification in this stage (README: no "file is ready" before the import can add anything; a
notification type is its own piece of work - `docs/TODO.md`).

## The safety net

`php bin/console myspeedpuzzling:read-waiting-imports` (**cron: every 5 minutes**; the command dispatches
`ReadWaitingImports`, the handler has the logic, outside a transaction):

1. imports waiting in `uploaded` whose file is still there get `ReadImportFile` again (`RequestImportRead`):
   never asked for - uploaded before this stage shipped - and resting for 2 minutes, or **asked for more than 15
   minutes ago** (`data_import.read_requested_at`, stamped by the upload's confirmation, by "Try again" and by this
   very request) - their message was lost. So the same waiting import is queued once per quarter of an hour, not on
   every run; a second message for an import somebody reads meanwhile finds nothing to do;
2. imports in `reading` **without any progress for 15 minutes** (`last_activity_at`; every batch moves it) - the
   worker died or was killed - are failed as `stalled` (a warning in the log), their page is told, and the player
   can try again. The reading state always ends.

## Routes, messages, commands

| | |
|---|---|
| Routes | `data_import_progress` (GET JSON), `data_import_read_again` (POST, CSRF `data_import`); `data_import_detail` shows the progress, the sheets, the failure |
| Messages | `ReadImportFile` (→ `imports`), `BeginImportReading`, `RecordImportFileHash`, `RecordImportSheets`, `RecordImportRows`, `RecordImportRead`, `RecordImportReadFailed`, `ReadImportAgain`, `ReadWaitingImports`, `RequestImportRead` |
| Command | `myspeedpuzzling:read-waiting-imports` |
| Migrations (two, generated) | `data_import`: `read_requested_at`, `reading_started_at`, `read_finished_at`, `read_attempt`, `sheets_total`, `sheets_read`, `rows_read`, `same_file_as_import_id`; `data_import_sheet`: `hidden`, `columns_total`, `hints` |

## What the consumer needs

The `imports` consumer container, beyond what it has for `async`: object storage (it downloads the file), the
**Mercure publisher settings** (`MERCURE_URL` reachable from the container, `MERCURE_PUBLIC_URL`,
`MERCURE_JWT_SECRET` - no consumer published to Mercure before), a writable temporary folder with room for the file
(25 MB) and the reader's shared strings (up to 128 MiB). Nothing else: the job sets and restores PHP's time limit
itself, and needs no extension the web container does not have.

## Measured

2026-11-15, PHP 8.5.12, the project's base image in a container with `--memory=512m` (PHP `memory_limit` 480M) on
the development machine, the whole job - download from the suite's object store, sha256, one streaming pass, 101
transactions of 500 rows into the test database (PostgreSQL 16 in another container), progress stored and published
to the test hub. One run each; `tests/MessageHandler/ReadImportFileLargeTest.php` (opt-in) prints them.

| File | Wall time | CPU time of the process | Peak memory growth | Stored |
|---|---|---|---|---|
| The reader tests' generator, 50,000 rows × 20 columns (1,000,000 cells, 100,050 texts), 2.1 MiB | 11.4 s | 6.1 s | +13.6 MiB (peak of the process 82 MiB) | 50,000 rows, 58 MiB of `cells` |
| The same plus 22 MiB of pictures: 24.1 MiB, the largest file allowed | 11.3 s | 6.2 s | +12.5 MiB (peak 105 MiB, of which the test's in-memory object store holds the file) | the same |

The reader's share is about 2.4 s (reader.md); the rest is building and inserting 50,000 rows. The CPU time counted
here is the whole job's - the reader's own budget (60 s) counts only its part. Memory does not grow with the rows:
a batch is about 5.6 MiB as a message and is gone when written.

Seen while measuring: **the test container's message bus keeps every message it handled** (`TraceableMessageBus`,
for the profiler) - a hundred batches added up to the 480 MB limit. Production's bus does not; the opt-in test
forgets them after each dispatch, and says so. The ordinary tests read small files. The same holds for the **local
development** consumer (the profiler is on in `dev`): a file near the limits can run it out of memory there - not a
fault of the file.

## Tests

`tests/Controller/DataImport/ImportReadingTest.php` (the worker is played by calling the handler; storage and Mercure
are the suite's doubles; every file is made up): a workbook of two sheets, one hidden - sheets, rows, cells, hints,
progress stored and published to the owner's private topic, nothing left on the disk, the page's live line and
sheets list, the JSON endpoint; a CSV with semicolons; a file over each limit (sheets, rows, cells), a
password-protected one, one cut short, a picture - each with its sentence on the page; a fault of ours and "Try
again"; the same file twice (and another player's same file untouched); another player's import = 404 on the
endpoint, the page and "Try again"; the stalled-read rescue, the re-queue of an unread import, and a dead read's late
rows refused; nothing written into an import discarded while it was read; nothing written by a read whose import
another process changed behind the worker's entity manager (discarded, failed as stalled, taken by a second
worker); a download that takes too long; a hub that is down.
`tests/MessageHandler/ReadImportFileLargeTest.php` - opt-in, the measurements above.
Reader: `ReadBudgetTest`, and in `XlsxTimeBoundTest` the blank-run attack and the pretty-printed sheet.

## Not built here

Columns and mapping, kind guessing, parsing values, pairing, adding anything to a profile, versions (reading the
same source again), merged cells, Google Sheets, e-mail, the bell notification, admin pages, entry points from other
pages.
