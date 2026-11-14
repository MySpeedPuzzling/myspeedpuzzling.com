# Data import: upload and the imports pages (stage 3a)

What stage 3a built of [the design](README.md): a signed-in player can bring a spreadsheet, see their imports and
discard one. **Nothing reads the file yet** - `uploaded` is where an import rests until the reader arrives (stage 3b).
No page of the site links here yet; entry points are a later stage.

## Pages

All signed-in only, `noindex`, in 6 locales, another player's import = 404 (`GetDataImports` takes the signed-in
player's id for every read, `DataImportRepository::getOwnedBy()` for every write).

| Route | Path | What |
|---|---|---|
| `data_imports` | `GET /{_locale}/import` | Imports home: the player's imports, newest first, state of each, "Import a spreadsheet". Uploads still on their way and discarded imports are not listed |
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
   25 MB) and the player's unfinished imports (at most 3 with a file that arrived - `DataImportStatus::unfinished()`),
   under a lock per player, and creates the import in the state `uploading`. The answer: `importId`, the presigned
   `url`, the `headers` it was signed with, `expiresAtMs` (30 minutes), `confirmUrl`, `importUrl`.
   - The **key is the server's**: `tmp-imports/player-<playerId>/<importId>/file.<kind>` - nothing of the file's name.
   - The **content type is the server's** (browsers send none or a wrong one for these files) and the **exact length
     is signed**, so the store refuses any other upload.
   - `importId` in the body signs a still uploading import of the same player and size again (the URL ran out) - no
     new import, no rate-limit hit. Anybody else's id simply makes a new import.
   - Refusals are `{"error": <reason>, "message": <text>}`: 422 `type` / `empty` / `too_large` / `too_many`, 429
     `rate_limited`, 403 `invalid_csrf_token`, 400 `invalid_request`.
2. **PUT** by the browser (`assets/controllers/data_import_upload_controller.js`): progress line, three attempts, a
   403 from the store signs once more.
3. **Confirm** (`ConfirmImportUploadController` → message `ConfirmImportUploaded`): the handler checks that the object
   exists with exactly the signed size and moves the import to `uploaded`. 409 `missing` / `size` / `state`, 503
   `storage` when object storage does not answer (nothing changed - the browser asks again), 404 for another
   player's import. Confirming twice changes nothing. **No read message is dispatched** - that arrives with the
   reader.

**The fallback** is the page's plain form (`BringImportFileController`, `multipart/form-data`): used without
JavaScript, and by the script whenever the direct way can not work (the start unreachable, the store refusing or
unreachable from this browser). `ImportUpload::receive()` sends the same `StartImport`, stores the file under the same
key and sends the same `ConfirmImportUploaded` - the same limits by the same code. Success redirects to the import's
page, a refusal re-renders with 422 and the reason.

**Rate limit** `data_import_upload`: 10 new uploads per player per hour (sliding window; the design's number),
counted for direct starts and fallback posts alike.

## States

`DataImportStatus`: `uploading` → `uploaded` → (later stages: `reading`, `setting_up`, `reviewing`, `done`), and
`discarded`. The README's data model lists the later ones; `uploading` and `uploaded` are this stage's.

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
`discarded` (gone from every page at once - a 404). A delete object storage refuses is retried by the storage layer;
should it throw, the import keeps its key and the prune tries again. Nothing an import could have added exists yet, so nothing else is kept; a later stage
keeps the rows' keys and outcomes of an import that added something (README "Never twice").

## Prune

`php bin/console myspeedpuzzling:prune-data-imports` (daily; the command dispatches `PruneDataImports`, the handler
has the logic):

1. uploads never confirmed after 24 hours: whatever arrived is deleted, the import with it;
2. files of imports created more than 30 days ago: the object is deleted, the import stays with `file_removed_at`;
3. discarded imports: the file if it is still there, then the import whole (its sheets and rows by foreign key).

At most 500 of each kind per run. A delete object storage refuses is queued and retried by the storage layer itself
(`FailoverS3Adapter`); should a delete ever throw, the import keeps its key and the next run tries again.

## Account deletion

`data_import.player_id` cascades; `DeletePlayerHandler` hands the keys of the player's import files to
`DeletePlayerStoredFiles`, like the avatar and the photos.

## Not built here

Reading the file, sheets and rows, the progress line of reading, Google Sheets, "the same file again" (needs the
worker's `file_sha256`), entry points from other pages, notifications, e-mail, admin pages. No feature flag: the
pages are linked from nowhere.
