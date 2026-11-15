# Data import: upload and the imports pages (stage 3a)

What stage 3a built of [the design](README.md): a signed-in player can bring a spreadsheet, see their imports and
discard one. Since stage 3b an import that becomes `uploaded` is read at once - [reading.md](reading.md).
No page of the site links here yet; entry points are a later stage.

## Pages

All signed-in only, `noindex`, in 6 locales, another player's import = 404 (`GetDataImports` takes the signed-in
player's id for every read, `DataImportRepository::getOwnedBy()` for every write).

| Route | Path | What |
|---|---|---|
| `data_imports` | `GET /{_locale}/import` | Imports home: the player's imports, newest first, state of each, "Import a spreadsheet". Discarded imports are not listed; an upload on its way is listed once it is a minute old, as "Finishing the upload" with the button below |
| `data_import_upload_finish` | `POST /{_locale}/import/{importId}/finish` | "Finish the upload": the confirmation as a plain form (CSRF, then the import's page) - for an import that stayed `uploading` |
| `data_import_new` | `GET\|POST /{_locale}/import/new` | Bring the file: file picker with a progress line; the `POST` is the fallback form |
| `data_import_detail` | `GET /{_locale}/import/{importId}` | One import: file name, size, state, "reading and adding arrive soon", until when the file is kept, Discard |
| `data_import_discard` | `POST /{_locale}/import/{importId}/discard` | Discard (CSRF, then back to Imports home) |
| `data_import_upload_start` | `POST /{_locale}/import/upload` | JSON: starts a direct upload |
| `data_import_upload_confirm` | `POST /{_locale}/import/{importId}/uploaded` | JSON: "the file is up" |

CSRF token id `data_import` (session-backed - every page is a signed-in one): a hidden `_token` in the forms, the
`X-CSRF-Token` header on the two JSON endpoints.

## The upload

The file goes **straight to object storage** by a presigned `PUT` - the photo uploads' presigner (`S3QueryPresigner`),
store configuration (`OnlineMediaStores`) and bucket CORS rule (`OnlineMediaBucketCors`: `PUT` with `content-type`)
are reused; the rest is the import's own (`ImportFileStorage`, `ImportUpload`).

1. **Start** (`StartImportUploadController` → message `StartImport`): the browser sends `{name, bytes}`. The handler
   checks the kind (by the name's extension: `.xlsx`, `.csv`, `.tsv` - `DataImportFileKind`), the size (1 byte to
   25 MB) and the player's imports holding a place (at most 3: the unfinished ones **and the uploads on their way** -
   `DataImportStatus::holdingAPlace()`; else uploads started side by side would all pass), under a lock per player,
   and creates the import in the state `uploading`. The answer: `importId`, the presigned
   `url`, the `headers` it was signed with, `expiresAtMs` (30 minutes), `confirmUrl`, `importUrl`.
   - The **key is the server's**: `tmp-imports/player-<playerId>/<importId>/file.<kind>` - nothing of the file's name.
   - The **content type is the server's** (browsers send none or a wrong one for these files) and the **exact length
     is signed**, so the store refuses any other upload.
   - `importId` in the body signs a still uploading import of the same player and size again (the URL ran out) - no
     new import, no rate-limit hit. Anybody else's id simply makes a new import, and so does an upload started more
     than 23 hours ago (see "A file must not come back").
   - Refusals are `{"error": <reason>, "message": <text>}`: 422 `type` / `empty` / `too_large` / `too_many`, 429
     `rate_limited`, 403 `invalid_csrf_token`, 400 `invalid_request`.
2. **PUT** by the browser (`assets/controllers/data_import_upload_controller.js`): progress line, three attempts, a
   403 from the store signs once more. When the file is up but the confirmation can not be reached after its
   retries, the page says the file arrived and links to the import's page - "Finish the upload" there (and on
   Imports home) checks again; the form is not sent then (the file would travel twice).
3. **Confirm** (`ConfirmImportUploadController` → message `ConfirmImportUploaded`): the handler checks that the object
   exists with exactly the signed size and moves the import to `uploaded` (under the player's lock, the limit of 3
   unfinished imports checked once more). 409 `missing` / `size` / `state` / `too_many`, 503
   `storage` when object storage does not answer (nothing changed - the browser asks again), 404 for another
   player's import. Confirming twice changes nothing. The first confirmation queues `ReadImportFile`
   ([reading.md](reading.md)).

**The fallback** is the page's plain form (`BringImportFileController`, `multipart/form-data`): used without
JavaScript, and by the script whenever the direct way can not work (the start unreachable, the store refusing or
unreachable from this browser). `ImportUpload::receive()` sends the same `StartImport`, stores the file under the same
key and sends the same `ConfirmImportUploaded` - the same limits by the same code. Success redirects to the import's
page, a refusal re-renders with 422 and the reason. When the script had started an upload before it fell back, the
form carries that import's id (`import_id`) and **goes on with it** (`ImportUpload::continues()`: the player's own,
still uploading, same size and kind): one file is one import and one upload of the rate limit.

**Rate limit** `data_import_upload`: 10 new uploads per player per hour (sliding window; the design's number),
counted for direct starts and fallback posts alike.

## States

`DataImportStatus`: `uploading` → `uploaded` → `reading` → `setting_up` or `read_failed` ([reading.md](reading.md))
→ (later stages: `reviewing`, `done`), and `discarded`. `uploading` and `uploaded` are this stage's.

## Tables

One generated migration creates `data_import`, `data_import_sheet`, `data_import_row` as the README's data model
describes them. Only `data_import` is written so far; the other two have their entities and repositories.

Where the README left room, `data_import` got:

- `file_kind` (`xlsx` / `csv` / `tsv`) and `file_size` (the signed size) next to `file_name`;
- `uploaded_at`, `discarded_at`;
- `file_removed_at` - set when the object left storage (30 days, or a discard); `file_key` is then NULL. The import
  stays and its page says the file was removed;
- `status` also takes `uploading` and `uploaded`.

`data_import_row.import_puzzle_id` and `batch_id` are plain nullable ids until `data_import_puzzle` and
`data_change_batch` exist. No custom index is needed yet (indexes: `data_import (player_id, created_at)`,
`data_import_sheet (import_id, position)`, `data_import_row (sheet_id, row_number)` and `(import_id, state)`).

## Discard

`DiscardImport` (the player's own import only): rows and sheets deleted, the object deleted, the import marked
`discarded` (gone from every page at once - a 404). Nothing an import could have added exists yet, so nothing else is
kept; a later stage keeps the rows' keys and outcomes of an import that added something (README "Never twice").

## A file must not come back

A presigned upload URL stays valid for 30 minutes after it was signed, whatever happens to the import meanwhile: a
browser still sending - or anybody holding the URL - can create the object again after it was deleted. So **a row is
removed only when no signature for its key can still be valid, and only after its object was deleted once more**:

- a discarded import **keeps `file_key`**; the discard deletes the object at once, the prune deletes it again and
  removes the row once the discard is more than 1 hour old (`DataImportLimits::SIGNATURE_GRACE_HOURS`);
- an upload never confirmed is removed 24 hours after it started, and nothing is signed for it after its 23rd hour
  (`ImportUpload::isRetry()`).

## Prune

`php bin/console myspeedpuzzling:prune-data-imports` (daily; the command dispatches `PruneDataImports`, the handler
has the logic):

1. uploads never confirmed after 24 hours: whatever arrived is deleted, the import with it;
2. files of imports created more than 30 days ago: the object is deleted, the import stays with `file_removed_at`;
3. imports discarded more than 1 hour ago: the object once more, then the import whole (its sheets and rows by
   foreign key).

At most 500 of each kind per run. `PruneDataImports` is handled **outside a transaction**
(`HandledOutsideTransaction`): the handler reads what is due and deletes the objects; the rows are written by
`RecordDataImportsPruned`, 50 imports per message and transaction, which reads each row again (an upload confirmed in
the meantime stays, without its file). A delete object storage refuses is queued and retried by the storage layer
itself (`FailoverS3Adapter`); should a delete ever throw, the import keeps its row and the next run tries again.

## Account deletion

`data_import.player_id` cascades; `DeletePlayerHandler` hands the keys of the player's import files to
`DeletePlayerStoredFiles`, like the avatar and the photos.

## Not built here

Reading the file, sheets and rows, the progress line of reading, Google Sheets, "the same file again" (needs the
worker's `file_sha256`), entry points from other pages, notifications, e-mail, admin pages. No feature flag: the
pages are linked from nowhere.
