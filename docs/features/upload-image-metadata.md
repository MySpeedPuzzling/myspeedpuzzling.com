# What an uploaded picture's file carried

Every uploaded picture loses its metadata before it is stored (`ImageMetadata::strip()`, called by
`ImageOptimizer::optimize()`): GPS position, camera, capture time, XMP, IPTC, comments. People photograph puzzles at
home, so nothing of it may be published. That stays as it is.

Some of that metadata is useful evidence for the image pipeline, and none of it identifies a person. Since 2026-10 a
short, privacy-safe **summary** is read from the file as uploaded, before anything is stripped or resized, and stored
with the picture.

## Who reads it

- **Catalogue pictures** feed the image pipeline (`GET /internal-api/puzzle-images`, item field `uploadMetadata`,
  [internal-api.md](internal-api.md) "Puzzle image analysis"). The summary is a prior for "photo from a camera" vs
  "digital artwork / shop render / screenshot". About 79 % of catalogue pictures are photos.
- **Finish photos** (`puzzle_solving_time.finished_puzzle_photo_upload_metadata`): the capture time next to the logged
  `finished_at` is supporting evidence for the time verification ([suspicious-time-review.md](suspicious-time-review.md))
  and for duplicate results ([duplicate-results.md](duplicate-results.md)). Nothing reads it yet.

Nothing shows it in the UI, and no public API returns it. The privacy policy does not list what is stored about
uploads, so it is unchanged.

## What is kept (`UploadedImageMetadata`, JSON)

| Key | What |
|---|---|
| `version` | `UploadedImageMetadata::VERSION`. Bump it when what is read, or how, changes. |
| `bytes` | The file's size as uploaded, before any resize or re-encode. |
| `format` | ImageMagick's format name: `JPEG`, `PNG`, `WEBP`, `HEIC`, `GIF`, ... |
| `width`, `height` | Stored pixels as uploaded, before the EXIF orientation is applied. |
| `exif` | Whether the file had an EXIF block at all. |
| `cameraExif` | Whether the EXIF holds what a camera writes when it takes a picture: make or model, a real capture time, exposure, aperture, ISO or focal length. |
| `capturedAt` | `DateTimeOriginal` as found, the camera's local wall-clock time (`2026-10-21T14:03:12`). No zone is guessed. `null` for a clock that was never set (`0000:00:00 ...`). |
| `capturedAtOffset` | `OffsetTimeOriginal` as found (`+02:00`), only when the camera wrote one. |
| `software` | The family of the `Software` tag: `camera` (camera firmware), `phone` (phone OS or camera app), `editor` (Photoshop, Lightroom, GIMP, Snapseed, ImageMagick, ...), `screenshot`, or `unknown`. `null` without a tag. |
| `make` | The camera brand, from a known list (`Apple`, `Samsung`, `Google`, `Canon`, `Nikon`, ...), else `Other`. Never the model. |
| `orientation` | EXIF orientation 1-8. |

## What is never kept, and why

- **GPS** (where the photo was taken): it is the player's home.
- **Serial numbers** (body, lens): they tie photos to one device, so to one person, across sites.
- **Owner, artist, copyright, user comment, image description**: free text that people fill in with their names.
- **Maker notes**: opaque vendor blobs that hold serials, faces and more.
- **The thumbnail**: a second copy of the picture that ImageMagick would not crop or rotate.
- **The camera model**: rare models narrow a player down. The brand is enough for "phone or camera".
- **The raw `Software` string**: editors and tools sometimes write a user name or a file path into it. Only the
  family is kept. The user comment is read only to tell an iOS/macOS screenshot (labelled "Screenshot") apart, and is
  dropped right after.

`UploadedImageSummariser` reads the whole EXIF block with PHP's exif extension, from ImageMagick's EXIF profile (JPEG,
WebP), a PNG's eXIf chunk, or the HEIC/TIFF file itself. It takes only the allow-list above. Everything else never
leaves that class. `UploadedImageSummariserTest` builds real EXIF blocks in both byte orders (`tests/ExifPhotos.php`,
with GPS, serial numbers, owner, user comment, maker note and a thumbnail) and checks that none of it reaches the
stored JSON.

## Where it is stored, and why there

One `jsonb` column next to the picture's path, on the row that owns the picture:

| Picture | Column |
|---|---|
| A puzzle's picture | `puzzle.image_upload_metadata` |
| A change request's proposed picture | `puzzle_change_request.proposed_image_upload_metadata` |
| A finish photo | `puzzle_solving_time.finished_puzzle_photo_upload_metadata` |

The alternative was one table keyed by the stored object path. It was not chosen because the codebase moves picture
*paths* between rows far more often than it deletes picture *objects*: a merge hands a puzzle's path to the survivor,
"keep this copy" takes a twin's photo path (`takeOverFrom()`), Undo restores a snapshot, and a deleted player's pair
time is handed over. Old puzzle pictures stay in storage for the history and for restoring. With a path-keyed table,
every one of those places would need a reference check and a cleanup, and a forgotten one leaves a summary behind.
On the owning row, the summary is deleted with its owner for free: player deletion, a deleted time, a merged puzzle.
The entity methods that change the path keep it in step:

- `Puzzle::useImage()`, `takeImageOf()` and `correctNewlyAdded()` set it with the image. `moveImageTo()` (a secret
  puzzle's picture renamed) keeps it, since it is the same picture. `image` is `#[Immutable(PRIVATE_WRITE_SCOPE)]` so
  that new writers go through these methods.
- `PuzzleImageStorage::useProposed()` moves the change request's summary to the puzzle, and `approve()` clears the
  request's copy. `reject()` clears it, because the proposal object is deleted. `useCopyOf()` (restoring an earlier
  picture) stores `null`: that picture's summary went when it was replaced.
- `PuzzleSolvingTime::modify()` takes the new summary when a photo was uploaded with the edit. Without an upload, it
  keeps the summary while the path stays the same, and drops it when the path changes. `takeOverFrom()` copies it with
  the photo, and `RemovedResultSnapshot` keeps it for Undo. `transferOwnership()` (a deleted player's pair or team
  time handed to another member) drops it: the photo stays for the others, but what its file said about the deleted
  player's device does not.

The migration adds three nullable columns, a metadata-only change in PostgreSQL. Old code ignores them, so it is
blue-green safe. Nothing is backfilled: the files stored before are already stripped, so every older picture stays
`null` (= not known).

## Where it is recorded

`ImageOptimizer::optimize()` returns the summary, read before it strips anything. These callers store it:

- **Puzzle pictures**: `AddPuzzleHandler` (the add form, multiscan quick-add, a resent form's `correctNewlyAdded()`),
  `AddPuzzleToCompetitionRoundHandler`, and `PuzzleImageStorage::storeUploaded()` (moderator edits, the approval
  queue, the merge review's upload).
- **Change-request pictures**: `SubmitPuzzleChangeRequestHandler` (the "Suggest a change" form and the internal API).
  Pictures the image pipeline proposes are its own output and are recorded like any upload; the pipeline knows them
  by the request's `source`.
- **Finish photos**: `AddPuzzleSolvingTimeHandler`, `AddPuzzleTrackingHandler`, `EditPuzzleSolvingTimeHandler`
  (direct-to-storage uploads come back through the same handlers as files from the PhotoStash).

These callers strip without a summary, on purpose: avatars, competition, series and organization logos, event page
section pictures, online round reference images (`toJpeg()`), and the result share image (`GetResultImage`, built from
an already stripped photo). Nobody reads metadata of those, and data nobody reads is not kept. Adding one later takes
one line: store the return value of `optimize()`.

**Never breaks an upload.** A file the summariser cannot read is logged at info and stores `null`. The upload goes on
exactly as before.

**Secret puzzles stay secret.** The summary sits on the puzzle row, so it is listed only where the picture is. The
work list leaves out hidden pictures (`hide_until`, `hide_image_until`), and a hidden picture's summary with them
(`PuzzleImageAnalysisInternalApiTest`).

## Known gap: the browser shrinks most phone photos first

The add/edit-time form (`submit_prevention_controller.js`, `compressImages`) and multiscan quick-add
(`compressedOrOriginal()`) shrink every photo over 500 KB in the browser before uploading it: a canvas re-encode to
2000 px JPEG 0.85 (`assets/image_compression.js`). A canvas re-encode drops all EXIF. So most phone photos on those
forms, which means most finish photos and most box photos added with a time, arrive with no EXIF at all. Their summary
says `exif: false`, with the shrunk file's size and pixels. Pictures that arrive as taken still get the full summary:
files under 500 KB, "Suggest a change", the moderator and approval forms, the merge review, and browsers that cannot
decode the photo (some HEIC outside Safari).

The fix is to read the same allow-list in the browser before shrinking, and to send it with the photo (`docs/TODO.md`).
EXIF is client-controlled either way, so a browser-sent summary is no weaker as evidence than the file's own.
