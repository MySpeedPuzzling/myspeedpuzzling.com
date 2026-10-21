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
| `source` | `server` (read from the file that arrived) or `browser` (read by the page from the photo as picked, before it shrank it - see "Read in the browser"). A summary stored without one (the first hours, 2026-10-21) is the server's. |
| `bytes` | The file's size as uploaded, before any resize or re-encode. For `browser`: the photo as picked. |
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

The add form's new puzzle without a box photo takes the finish photo as its box photo. It gets a copy of the file
(`PuzzleAddController::copyOf()`): `AddPuzzleHandler` strips its photo in place, and the finish photo's summary is read
after it. Before 2026-10-21 both shared one file, so such a finish photo was always summarised as "no EXIF".

These callers strip without a summary, on purpose: avatars, competition, series and organization logos, event page
section pictures, online round reference images (`toJpeg()`), and the result share image (`GetResultImage`, built from
an already stripped photo). Nobody reads metadata of those, and data nobody reads is not kept. Adding one later takes
one line: store the return value of `optimize()`.

**Never breaks an upload.** A file the summariser cannot read is logged at info and stores `null`. The upload goes on
exactly as before.

**Secret puzzles stay secret.** The summary sits on the puzzle row, so it is listed only where the picture is. The
work list leaves out hidden pictures (`hide_until`, `hide_image_until`), and a hidden picture's summary with them
(`PuzzleImageAnalysisInternalApiTest`).

## Read in the browser (the add/edit-time form and multiscan)

The add/edit-time form (`submit_prevention_controller.js`) and multiscan quick-add (`multiscan_controller.js`) shrink
every photo over 500 KB in the browser before uploading it: a canvas re-encode to 2000 px JPEG 0.85
(`assets/image_compression.js`, docs/features/photo-uploads/README.md). A canvas re-encode drops all EXIF, so most phone
photos on those forms - most finish photos, and most box photos added with a time - would arrive without a capture
time. The image editor's crop/turn is a canvas re-encode too.

So the browser reads the same allow-list first, from the photo as picked, and sends it with the photo:

- **What it reads** (`assets/photo_metadata.js`, dependency-free): JPEG (the `Exif` APP1 segment, the frame
  header for the pixels) and the HEIF family - HEIC and AVIF - through the container's `Exif` item (`meta` → `iinf`,
  `iloc`, `ipma`/`ispe`, the `irot` turn applied like the server's libheif does). Desktop Safari shrinks a HEIC itself,
  so HEIC is read too. Other formats (PNG, WebP, GIF) give nothing - the server's own reading of the shrunk file stands.
  Within the TIFF block it looks tags up by number: IFD0 Make, Model (only whether there is one), Orientation, Software
  and the Exif IFD pointer; in the Exif IFD DateTimeOriginal, OffsetTimeOriginal, exposure, aperture, ISO, focal length
  (only whether they are there) and the user comment (only whether it labels a screenshot). The GPS pointer is never
  followed; serials, owner, artist, copyright, maker notes and the thumbnail are skipped without being decoded.
- **What it sends** (JSON): `bytes`, `format` (`JPEG`/`HEIC`/`AVIF`), `width`, `height` (the photo as picked), `exif`,
  `cameraExif`, and only with EXIF: `dateTimeOriginal`, `offsetTimeOriginal`, `software`, `make` (each the raw tag,
  ≤ 128 characters, control characters removed), `orientation`, `screenshot: true`. Only for a photo the browser changed
  (shrunk, or edited - the editor's result carries the summary of the photo it was made from): a photo sent as picked
  has its own EXIF, and the server reads that.
- **Never in the way:** any failure (another format, a broken block, a file slower than 3 s to read) gives nothing, and
  the upload goes on exactly as before.
- **How it travels:** with the background upload's start (`POST /{_locale}/photo-upload`, `metadata`), kept in the
  PhotoStash description next to the photo (`PhotoStash::reserve()`). With a photo that goes with the form as a file,
  as `photo_metadata[<field>]`; a refused form keeps it with the kept photo (`FormPhotoStash::keep()`), so the token
  brings it back. Multiscan sends it as the `createPuzzle` action's `photoMetadata` (else the token's). The controllers
  hand it to the messages (`AddPuzzle::$puzzlePhotoBrowserMetadata`, `…::$finishedPuzzlesPhotoBrowserMetadata`).
- **Checked strictly** (`BrowserImageSummary`): only the keys above are read, anything else is dropped; each must have
  its type, length and range (`bytes` ≤ 500 MB, sides ≤ 100,000 px, an orientation 1-8, trimmed printable strings, no
  EXIF fields when `exif` is false). One value off the rules rejects the whole summary - the page never sends one. The
  raw tags are reduced exactly like the server's own reading (`UploadedImageSummariser::make()`, `softwareFamily()`,
  `captureTime()`), so the Software and Make strings never go further than that class.
- **Stored only when the server sees no EXIF itself** (`UploadedImageMetadata::preferring()`): EXIF in the file that
  arrived always wins. A browser summary is stored with `source: browser`. It is self-reported - so is EXIF, but EXIF
  is at least in the file - and readers should weigh it so.

`PhotoMetadataParityTest` runs the real module under node on the synthetic photos of `tests/ExifPhotos.php` (GPS, serial
numbers, owner, user comment, model, maker note and a thumbnail in every phone photo, both byte orders, a HEIC with the
Exif item in `mdat` and in `idat`, an `irot` turn): what the browser sends, once accepted, equals the server's own
reading of the same file, and nothing outside the allow-list is ever sent. Checked once by hand against a HEIC encoded by
macOS (`sips`): the same values as the server's libheif.
