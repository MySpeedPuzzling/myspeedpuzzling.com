# Photo uploads on slow and flaky connections

Design of record for how the add/edit-time form (`templates/_solving_time_form.html.twig`) and multiscan quick-add
upload photos. Jan, 2026-10-18: *"this is the most critical part of the whole application and the user experience
must be smooth here."*

## 1. The problem

**Incident, 2026-10-14 23:36 – 10-15 06:00 UTC.** A player (Pixel 7, Firefox Android, Australia) tried 8 times to save
a new puzzle with a box photo and a finished photo. Each `POST /en/puzzle-add` declared ~0.95 MB (the browser's
compression worked), but only 0.4–0.75 MB arrived: her upload ran at ~10 KB/s that day (the same phone and provider
uploaded ~1 MB at 250–490 KB/s on Oct 1 and 6). The lily.srv Traefik edge cut every request at exactly 60 s
(`entryPoints.websecure` `respondingTimeouts.readTimeout`, default 60 s) and answered a plain-text 502/504. Turbo renders
a failed answer only when it is HTML, so she saw the progress bar fill and nothing happen; the puzzle was never
created. Oct 6–18: ~13 of ~4,300 uploads above 300 KB hit the 60 s cut - each one a player losing a filled-in form.

**What made it possible:**

1. The photos travelled inside the form's own request, so the whole save had to fit in one slow request. Anything
   that cut it (the edge's timeout, a dropped connection, a phone switching networks) lost the entire save.
2. **A slow upload holds a PHP worker for its whole duration.** Measured 2026-10-18 with FrankenPHP (`dunglas/frankenphp:php8.5`,
   worker mode, one worker): a 60 KB multipart POST sent at 3 KB/s, and a plain `GET` sent 3 s later - the `GET`
   waited **16 s**, until the upload had finished. FrankenPHP hands the request to a PHP thread as soon as the headers
   arrive and PHP reads the body (`$_FILES`) through the SAPI while the thread is busy. In production a few slow
   uploads at once take workers away from everybody (`max_wait_time 30s` → 503 for others).
3. A failed save said nothing (fixed already on main: "Add/edit time form: say so when saving does not finish").
4. A form sent again after its save had gone through, but whose answer was lost, could come back as "the photo could
   not be kept" (422) instead of "this result was already saved": the save deleted the kept photos, so the resend's
   token found nothing (§4.4).

Infra, outside this repo: Traefik's `readTimeout` goes to 180 s in the next maintenance window. That helps the plain
form path a little; it does nothing for 2.

## 2. Decisions

Jan's, not to revisit: catalogue photos stay **2000 px, JPEG 0.85** (they feed the image-analysis pipeline) - never
smaller to make uploads faster. **Open source + self-hosted only**, no third-party upload service.

### 2.1 Every photo uploads in the background, straight to object storage (chosen)

The moment a photo is picked (or edited in the image editor), the browser shrinks it (2000 px / 0.85, as before - only
earlier: at pick time instead of on Save) and uploads it **straight to object storage** through a presigned `PUT`,
while the player is still filling in the form. The form's Save then carries only a 32-character token per photo.

```
pick photo ─► shrink ─► POST /{_locale}/photo-upload ─► {token, url, headers}     (PhotoUploadStartController)
                         └─ PUT url (Hetzner, not through Traefik/PHP) ─► 200
Save ─► POST /en/puzzle-add  … photo_stash[puzzlePhoto]=<token>   (a few KB)
         └─ FormPhotoStash::restore() reads the photo back from the bucket → the form validates it as an upload
```

Why this one:

- **No byte goes through PHP or Traefik** - the slow part never meets the 60/180 s edge timeout and never holds a
  FrankenPHP worker. Hetzner's endpoint is public and takes the upload directly.
- **It is the PhotoStash.** The photo lands where a kept photo of a refused form lives (`tmp-uploads/<player>/<token>`,
  `PhotoStash::objectKey()`), and the form already knew how to take a photo as a token (`photo_stash[<field>]`,
  `FormPhotoStash::restore()` before `handleRequest()`). The server-side change is small: reserve a place, sign a URL.
  The form's own constraints (`PuzzleBoxPhoto`, `FinishedPuzzlePhoto`) still decide - a non-image or oversized file
  is refused by the form exactly as an upload would be.
- **It is proven here.** The online competitions' evidence photos do exactly this (`OnlineMediaStorage`,
  `S3QueryPresigner`, docs/features/online-competitions/README.md §5.11); the bucket's CORS rule for the site's origin
  is live on production and Hetzner was verified to enforce a signed `Content-Type` + `Content-Length` (TODO.md,
  online competitions stream A, 2026-10-14).
- The waiting happens while the player types the time, picks the date, writes a comment - usually the photo is up
  before they reach Save.

### 2.2 Rejected

- **A resumable / chunked upload to our own endpoint (tus-style).** Survives drops at chunk granularity, but every byte
  still goes through Traefik and a PHP worker: chunks keep the 60 s cut away, not the thread holding (2.: the worker is
  held for as long as bytes flow). Needs a protocol, server state and its pruning. Our photos are ~0.3–1 MB: a whole
  retry costs little, so resumability buys little.
- **S3 multipart uploads.** Parts must be ≥ 5 MB (except the last) - useless for a 1 MB photo; kept in mind for video
  (online competitions §5.11).
- **Only showing progress for the plain form.** Honest, but every slow save still dies at the edge and holds a worker.
  The plain form's progress is now the button label while it waits for photos.
- **An automatic retry of a plain form carrying photos.** Re-sending 1 MB on the connection that just failed is the
  same failure again, and a long one. Automatic retry is only for a save **without** photo bytes (§3, "Save").
- **Lowering resolution / quality.** Jan's decision.

## 3. How it works

**Server**

- `PhotoUploadStartController` - `POST /{_locale}/photo-upload`, JSON + `X-CSRF-Token` (stateless id `photo_upload`),
  signed in. Body `{bytes, type, name, token?, replaces?, metadata?}` → `{token, url, headers, expiresAtMs}`.
  `metadata` = what the browser read from the photo's EXIF before shrinking it, kept in the reservation's description
  (an invalid one is left out, never refused - docs/features/upload-image-metadata.md "Read in the browser"). Refusals are JSON
  `{error}` the browser does not show - it then sends the photo with the form. Rate limiter `photo_upload`
  (2000 new places per player and hour - an abuse ceiling only, a player catching up on photos for old results must
  never meet it; a re-sign of the same token is free). Kill switch `DIRECT_PHOTO_UPLOADS`
  (docs/features/feature_flags.md): 404 `disabled`, and the pages stop asking.
- `DirectPhotoUpload::start()` - refuses what the form would refuse (empty, > `PhotoUploadLimits::MAX_PHOTO_BYTES`,
  a type not in `MIME_TYPES`), reserves a place (`PhotoStash::reserve()`: the `.json` description now, the photo
  later) or signs an existing one again (`token` of this player with the same size - a retry never leaves a second
  photo behind), lets go of the field's previous photo (`replaces`), and signs a `PUT` for the primary store's public
  endpoint (`OnlineMediaStores::publicLocation(Primary)`) with `Content-Type` + exact `Content-Length`, valid
  `PUT_LIFETIME_SECONDS` (15 min).
- `FormPhotoStash::keep()` gives a directly uploaded photo its preview (`PhotoStash::addPreview()`) when a refused form
  shows it again.
- **Photos are no longer deleted when the add/edit-time form saves** (`PuzzleAddController`, `EditTimeController`): a
  resend of the same form (the answer got lost, the automatic retry) restores them, passes validation and is answered
  as the saved result (`SolvingTimeAlreadySaved` → "This result was already saved"). They expire after
  `PhotoStash::KEEP_HOURS` (24 h) like any kept photo; `PhotoStash::MAX_PER_PLAYER` went from 6 to 20 so used photos
  never push out the current form's.
- `MultiscanTray::createPuzzle(photoToken)` - quick-add takes the background-uploaded box photo by token when no file
  comes with the action.
- `PhotoUploadReportController` - `POST /-/photo-upload-report`, telemetry (§5).
- No Messenger message: a reservation writes only the PhotoStash's temporary storage (no database row), exactly like
  `PhotoStash::keep()` always has from the controllers. What the photo becomes is decided by the form's own save, which
  goes through its handlers as before.

**Browser** - `assets/photo_upload.js` (`PhotoUpload`), used by `submit_prevention_controller.js` (add/edit-time form)
and `multiscan_controller.js` (quick-add):

- Before shrinking, the photo's EXIF allow-list is read (`assets/photo_metadata.js` - the canvas drops it): sent with
  the start (`metadata`), and as `photo_metadata[<field>]` with a photo that goes with the form; multiscan sends it as
  the `createPuzzle` action's `photoMetadata`. Stored only when the photo that arrives has no EXIF of its own
  (docs/features/upload-image-metadata.md).
- Sign, then `PUT` with `XMLHttpRequest` (upload progress events; `fetch()` has none).
- **Stall watchdog:** no progress for 30 s → abort and try again (a dead connection that never says so).
- **Retries:** network error / stall / store 5xx → backoff 1, 3, 8, 15, 30 s (60 s while `navigator.onLine` is false),
  woken at once by the `online` event; 8 attempts, then `failed` (retryable). A `403` from the store = the URL
  expired → signed again once; twice = the store refusing.
- **`failed` = goes with the form.** The photo stays in its file input and is sent as a file, exactly as before this
  existed: the server refused to start (too large, type, limit, signed out, storage unavailable 503), the store
  refused the signed upload (4xx), or the `PUT` failed instantly twice without a byte sent although the page had just
  reached our server (a store this browser cannot reach - CORS, a blocked host). The plain form is the floor; nothing
  is ever worse than before.
- **Save** (`submit_prevention_controller.js`): photos still uploading → Save waits, the button shows "Uploading
  photos… 62 %"; an upload that gave up on the connection is resumed for 3 more attempts first. Then each uploaded photo
  goes as its token (`photo_stash[<field>]`, its file input disabled for that request), every other photo as a file.
- **Automatic retry of a lost save:** when the request carried no photo bytes and the answer never came (network error,
  or a non-HTML 0/502/503/504 - a deploy's moment, the edge), it is sent once more after 2.5 s ("Saving… trying once
  more"). Safe: `time_id` / `new_puzzle_id` make a resend answer the saved result (docs/features/duplicate-results.md),
  and the photos are still there (§3, Server). A save with photo bytes is not retried by itself.
- **Still saving:** no answer after 20 s → an info note "Saving takes longer than usual on this connection. Please keep
  this page open…".
- The image editor's Apply dispatches `change` → the edited photo uploads, the previous upload is aborted and its place
  let go (`replaces`).

## 4. Failure modes - what the player sees

| Situation | What happens | What the player sees |
| --- | --- | --- |
| Normal connection | Photo up in 1–3 s after picking | Under the photo: "Uploading the photo… 40 %" → "Photo uploaded." Save is instant |
| Slow connection (the incident: 10 KB/s) | Upload goes on in the background, no edge timeout | "Slow connection - uploading the photo… 35 %. You can keep filling in the form." If Save comes first: button "Uploading photos… 62 %", then "Saving…" - the save itself is a few KB |
| Connection drops mid-upload | Retried with backoff / on `online`; resumed if it gave up when Save is tapped | "The connection dropped - trying again in a moment…" / "No connection right now - the photo will upload as soon as it is back." |
| Upload cannot work at all (CORS, store refusing, server refused, kill switch) | Photo goes with the form as a file - today's path | "The photo will be sent together with the form when you save." |
| Object storage down (Hetzner outage) | `PUT` fails → retries → goes with the form → `FailoverS3Adapter` spools it locally as before | As above, the save works |
| Save's answer lost (dropped, edge 502/504, deploy) - no photo bytes in it | One automatic resend after 2.5 s; a resend of a saved form = the saved result | "Saving… trying once more", then the result page (or "This result was already saved – here it is.") |
| Save lost twice, or a save with photo bytes lost | No more automatic tries | Red alert above Save: saving didn't finish, everything filled in is still here, try again / another connection. Tapping Save again re-uses the uploaded photos |
| Save slow (no answer in 20 s) | Waits | Info note: saving takes longer than usual, keep the page open |
| Refused form (422: first try, missing field, …) | Photos come back as tokens with their preview | The form with the error; photos shown in their drop areas, "Your photo is still here" |
| Photo too large even after shrinking (a 200 MP shot the browser cannot decode) | Not uploaded; Save takes it out | "This photo is too large to upload (N MB)." next to the field (unchanged) |
| HEIC the browser cannot shrink | Uploaded as it is (≤ 60 MB, type guessed from the extension when the browser gives none); `ImageOptimizer` shrinks it on the server as before | As a normal upload, slower |
| Player picks another photo / edits it | Old upload aborted, its place let go, new one starts | The status line restarts |
| Multiscan quick-add | Same uploader; "Add puzzle" waits for the upload and sends the token; a token the server cannot use → the next tap sends the file | The line under the photo; button disabled while waiting |

### 4.1 What can still lose a form

- Closing the tab or navigating away loses everything typed (nothing is stored in the browser) - docs/TODO.md.
- A phone OS killing the backgrounded browser while the camera app is open (multiscan restores its tray from
  sessionStorage; the add form does not) - docs/TODO.md.

## 5. Telemetry

`POST /-/photo-upload-report` (`sendBeacon`, `PhotoUploadReportController`) → log channel **`photo_upload`**, its own
prod handler (info written as it comes, like `service_worker` / `barcode_scanner`), excluded from `main` so it is
countable in Loki without fingers_crossed:

- `event=upload` - every background upload's end: `outcome` (`done` / `failed`), `reason` (`network`, `stalled`,
  `store_error`, `store_refused`, `refused`, `unreachable`, `sign_failed`, `expired`), `status`, `bytes`, `sent_bytes`,
  `duration_ms`, `attempts`, `field`, `effective_type`, `downlink`, `online`.
- `event=save` - every lost save, and every save that had photos: `outcome` (`saved` / `refused` / `failed`),
  `status`, `duration_ms`, `waited_ms` (Save waiting for uploads), `photos_direct`, `photos_in_form`, `retried`.
- **Warning (→ Sentry) only for `reason=store_refused`**: object storage answered a signed upload with an HTTP error
  while the player's connection worked - ours to look at. Everything else is a player's network: info.

Loki queries to start with: share of `event=upload outcome=failed` by `reason`; `event=save outcome=failed` per day
(the incident's number - should go to ~0); p95 `duration_ms` of `done` uploads; `photos_in_form > 0` (how often the
plain path is still used).

## 6. Security

- A token is 128 random bits under the player's own prefix; a token only restores for the player it was made for, a
  reservation of another player is neither signed again nor let go.
- The presigned URL allows one `PUT` to one key, with the declared type and exact length (Hetzner enforces both), for
  15 minutes. The bucket stays private; CORS allows `PUT`/`GET` from the site's origin only (`OnlineMediaBucketCors`).
- What arrives is validated by the form like an upload (`Image` constraint: real image type, size).
- The images proxy (imgproxy with credentials) could fetch a `tmp-uploads/` key if somebody knew the token - the same
  as for kept photos since PhotoStash exists; tokens are unguessable and expire in 24 h. Denying `tmp-uploads/` in the
  images nginx like `online/` is a lily.srv follow-up (docs/TODO.md).

## 7. Testing

- `tests/Services/PhotoStash/DirectPhotoUploadTest.php` - signing (key, signed headers, lifetime), re-sign, replace,
  refusals, other players.
- `tests/Services/PhotoStash/PhotoStashTest.php` - reserve → upload → restore → preview.
- `tests/Controller/PhotoUploads/DirectPhotoUploadTest.php` - the endpoint (auth, CSRF, refusals, kill switch), a new
  puzzle saved with a background-uploaded photo, the resend after a save answered as the saved result, a refused form
  keeping the token with a preview, another player's token refused, the telemetry levels.
- `tests/Component/MultiscanTrayTest.php` - quick-add with a token.
- What the browser read before shrinking: `tests/PhotoMetadataParityTest.php` (the parser under node),
  `tests/Services/BrowserImageSummaryTest.php`, and in `tests/Controller/PhotoUploads/DirectPhotoUploadTest.php` the
  summary with a background upload, through a refused form, and the photo's own EXIF winning.
- Real browser (2026-10-18): Selenium Chromium on the compose network against a worktree build, MinIO as the store,
  with the network throttled and uploads made to fail - see the PR description.
