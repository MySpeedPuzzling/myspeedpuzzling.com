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
  `{error}` the browser does not show - it then sends the photo with the form (all but `empty`: §4.2). Rate limiter `photo_upload`
  (2000 new places per player and hour - an abuse ceiling only, a player catching up on photos for old results must
  never meet it; a re-sign of the same token is free). Kill switch `DIRECT_PHOTO_UPLOADS`
  (docs/features/feature_flags.md): 404 `disabled`, and the pages stop asking.
- `DirectPhotoUpload::start()` - refuses what the form would refuse (empty, > `PhotoUploadLimits::MAX_PHOTO_BYTES`,
  a type not in `MIME_TYPES`), reserves a place (`PhotoStash::reserve()`: the description now, the photo
  later) or signs an existing one again (`token` of this player with the same size - a retry never leaves a second
  photo behind), lets go of the field's previous photo (`replaces`, `PhotoStash::release()`), and signs a `PUT` for the primary store's public
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
- **Where the stash keeps what** (since 2026-11): the photo and its preview JPEG are objects in object storage
  (`tmp-uploads/<player>/<token>`, `….jpg`); the *description* that makes a token work (name, type, preview yes/no,
  reserved bytes, the browser's summary, `stored_at` = the expiry clock, `touched_at` = recency for the cap of
  `MAX_PER_PLAYER`) is a `photo_stash_entry` row of that player, written through messages (`RecordPhotoStashEntry` -
  also evicts beyond the cap, `MarkStashedPhotoPreview`, `ForgetStashedPhoto`; sync, each in its own transaction).
  **Starting an upload makes no object-storage call**: a new place = one INSERT (+ eviction), a re-sign = one indexed
  SELECT, `replaces` = one DELETE, the signature is pure computation. Before, the description was a `.json` object and
  the start made 1-9 sequential object-storage round trips (LIST for the cap, DELETEs of the oldest, PUT) - p99 12 s on
  production (Sentry, 2026-11-09). Objects of photos that made way or were replaced are no longer deleted on the spot:
  their token is dead at once, the daily prune removes the objects. An explicit `discard()` (other forms after a save,
  feedback screenshots) still deletes them right away. The daily prune removes expired rows too (`PrunePhotoStashHandler`).
  Postgres rather than redis-state: the save already needs Postgres, so the flow gains no new dependency.
- What the photo becomes is decided by the form's own save, which goes through its handlers as before.

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
| The phone hands over a photo of 0 bytes, or one the browser cannot read (§4.2) | Taken out of the form when picked - never uploaded, never sent | Next to the field: "This photo could not be read from your device, so it is not attached. You can save without it, or pick it again…" Save works |
| A save dies at once with a photo inside the request (§4.2) | It may be the connection or the photo - both are said | Red alert: "It may be the connection, or the photo could not be sent from your device" + "Try again" and "Save without the photo"; the photo is named when it happens again at once |
| Multiscan quick-add | Same uploader; "Add puzzle" waits for the upload and sends the token; a token the server cannot use → the next tap sends the file | The line under the photo; button disabled while waiting |

### 4.1 What can still lose a form

- Closing the tab or navigating away loses everything typed (nothing is stored in the browser) - docs/TODO.md.
- A phone OS killing the backgrounded browser while the camera app is open (multiscan restores its tray from
  sessionStorage; the add form does not) - docs/TODO.md.

### 4.2 A photo the phone cannot read

**Incident, 2026-11-14.** A phone (Edge on Android) handed the add-result form a photo of **0 bytes**. The upload's
start refused it (`empty`), the page said the photo would go with the form, and every Save then died in the browser
after 30-80 ms with status 0 - eleven taps, each answered by "the connection was too slow… try Wi-Fi or mobile data".
The result was saved only after the form was opened again without the photo; the same had happened a week before.
(Why the browser aborts a request carrying such a file is not known - it never reached the server whole. A reviewer's
hypothesis, **not verified**: Chromium's `ERR_UPLOAD_FILE_CHANGED` - Android's content provider reports the file with
size 0 when it is picked, the real file differs when the request is built, and Chromium refuses to upload a file that
changed. The `error` texts of the telemetry below will tell.)

**The rule: a photo the browser cannot read never blocks a save.** It is taken out of the form, the player is told
next to the field, and the save goes on without it. Four places, each one wrapped so that a failure of the check
itself leaves the form doing what it did before (`assets/photo_readability.js` holds the decisions, without the DOM):

1. **When it is picked** (`photoChosen` - `photoChecked` on a form without background uploads, the kill switch -
   and multiscan `photoPicked`): `size === 0` → out at once. Otherwise one byte is
   read (`file.slice(0, 1).arrayBuffer()`): a refused read (`NotReadableError`…) or one that comes back without the
   byte → out. A read that does not finish in 1.5 s (a photo still coming from the cloud), a browser without the API
   or a check that throws → **readable**, nothing changes. A photo the browser managed to shrink was read - not asked.
2. **When the upload's start says `empty`** (`PhotoUpload.refusal`) → out, instead of "goes with the form". Every
   other refusal keeps the fallback exactly as it was.
3. **Right before the request** (Save): a photo of 0 bytes still in the form is taken out without waiting; photos
   that would travel inside the request as files are asked for one byte first (the same detour Save takes for
   shrinking and for waiting on uploads - none when every photo went up in the background, the usual case).
4. **The server's floor** (`EmptyPhotoParts`, called by `PuzzleAddController` / `EditTimeController` before
   `FormPhotoStash::restore()`): a photo part that still arrives without a byte counts as no photo. Before, the form
   answered 422 "An empty file is not allowed". Now an existing puzzle's result is saved, a new puzzle gets its usual
   "photo required", and a flash says a photo was left out. Request handling only - no message or handler knows.

**A photo taken out while saving is said on the next page.** The note next to the field is gone with the form in the
same tap (the redirect, the modal closing), and under the kill switch nothing of the photo reaches the server. So a
photo taken out right before the request, by "Save without the photo", or while Save waited for its upload goes with
the form as `photo_dropped[<field>]=<reason>` (`empty` / `unreadable` / `not_sent`; removed again when another photo
is picked for the field), and `EmptyPhotoParts` turns it into the same flash - on the recap after an add, after an edit
(page and modal), or on the refused form. Read strictly (a photo field of this form, a known reason, no photo for the
field in the request after all) and added once: not while the same flash still waits to be shown (the automatic
resend, a second tap). A photo taken out when it was picked leaves no marker - the player read the note.

A new puzzle needs its box photo: there the note says "please pick it again" and promises no save without it
(field `puzzlePhoto`, multiscan quick-add). Multiscan keeps the photo taken before, if there was one.

**The message of a lost save** (`saveFailureKind()`). Status 0 in under a second, online, with a photo inside the
request is what a photo the browser cannot send looks like - and also a connection that broke without saying so (the
first request after the camera, a network change, DNS on dead Wi-Fi), and photos travel in the form exactly when the
connection is bad (always under the kill switch). So:

- the **first** such failure → `either`: "It may be the connection, or the photo could not be sent from your device"
  with **Try again** (the form once more, through Save's own path - `time_id` makes a resend safe) and **Save without
  the photo**. The same for a failure after 1-10 s.
- the **second in a row** (`isInstantPhotoFailure()` counts them; anything else in between starts anew) → `photo`:
  the photo is named, **Save without the photo**.
- no photo in the request, offline, the edge's 502/504, a wait over 10 s, an answer Turbo dropped → `network`: the
  connection message as it always was, no photo button.

**"Save without the photo" never promises what cannot work** (`photosToSaveWithout()`): it takes out the photos that
would travel as files - but a NEW puzzle (its section on show) keeps its box photo, and its finished photo too when
that is the only photo it has. When nothing can go, the button is not offered and the message says a new puzzle needs
its photo ("Try again" stays for the first failure).

**The pace check's dialog** (`ppm_validator_controller.js`) on the same form: with a photo travelling in the request,
Save detours for the one-byte read before the dialog and again after "Yes, time is correct". The first submit of each
pair is stopped (`stopImmediatePropagation`) before the pace check sees it, so its `confirmed` flag is still set for
the one that is sent - once (`testConfirmingThePaceCheckSavesExactlyOnce`).

Not covered on purpose: the feedback form's screenshots (own controller, same uploader - an `empty` refusal there
still sends the picture with the form) and the other photo forms, which send photos with the form only; the server's
floor is the add/edit-time form's only.

## 5. Telemetry

`POST /-/photo-upload-report` (`sendBeacon`, `PhotoUploadReportController`) → log channel **`photo_upload`**, its own
prod handler (info written as it comes, like `service_worker` / `barcode_scanner`), excluded from `main` so it is
countable in Loki without fingers_crossed:

- `event=upload` - every background upload's end: `outcome` (`done` / `failed`), `reason` (`network`, `stalled`,
  `store_error`, `store_refused`, `refused`, `unreachable`, `sign_failed`, `expired`), `status`, `bytes`, `sent_bytes`,
  `duration_ms`, `attempts`, `field`, `effective_type`, `downlink`, `online`.
- `event=save` - every lost save, and every save that had photos (or had one taken out as unreadable): `outcome`
  (`saved` / `refused` / `failed`), `status`, `duration_ms`, `waited_ms` (Save waiting for uploads), `photos_direct`,
  `photos_in_form`, `retried`; of a lost save also `error` (the browser's own words, e.g. `TypeError: Failed to fetch`)
  and `failure` (the message shown: `either` / `photo` = the second instant failure in a row / `network`); `photos_dropped` + `dropped_on` when photos were
  taken out of this form before (§4.2). A save that goes through without its unreadable photo is `saved`, never `failed`.
- `event=photo_dropped` - a photo taken out because the device could not read it (§4.2): `reason` (`empty` /
  `unreadable` / `not_sent` = the player's "Save without the photo"), `detected_on` (`pick` / `start` = the upload's
  start called it empty / `save` / `save_without` / `server` = `EmptyPhotoParts`), `field`, `bytes`, `error`. Info.
- `error` (uploads, saves, dropped photos): name and message of the error the browser caught, at most 200 characters;
  file names, paths and addresses are replaced in the browser (`describeError()`) and again on the server.
  `reason=discarded` (outcome `failed`, status 0, never resent by itself): a full-page submit answered 200 without a
  redirect - Turbo drops it and leaves no result, so before 2026-11-09 it was reported as `refused` and the player saw
  nothing happen (the form had posted to the page before - it now always carries its own URL as `action`).
- `Background photo upload refused` (info, server side, `PhotoUploadStartController`): why a start was refused
  (`empty` / `too_large` / `type`, with `bytes` + `type`) - the browser's report only says `refused`.
- **Warning (→ Sentry) only for `reason=store_refused`**: object storage answered a signed upload with an HTTP error
  while the player's connection worked - ours to look at. Everything else is a player's network or phone: info.

Loki queries to start with: share of `event=upload outcome=failed` by `reason`; `event=save outcome=failed` per day
(the incident's number - should go to ~0); p95 `duration_ms` of `done` uploads; `photos_in_form > 0` (how often the
plain path is still used); `event=photo_dropped` by `detected_on`. The alert on failed saves counts events, not
clients - one player tapping Save many times fires it (docs/TODO.md).

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
- `tests/Services/PhotoStash/PhotoStashTest.php` - reserve → upload → restore → preview, the cap (kept photos and
  reservations together, a preview making a photo recent again), expiry not moved by a preview, every read refusing
  another player's token, browser summaries.
- `tests/Services/PhotoStash/DirectPhotoUploadTest.php::testStartingTouchesNoObjectStorage` - new place, re-sign,
  replace and eviction against object storage that throws on every call (`ExplodingFilesystemAdapter`), zero calls.
- `tests/MessageHandler/PrunePhotoStashHandlerTest.php` and the
  player deletion cascade in `DeletePlayerHandlerTest.php`.
- `tests/Controller/PhotoUploads/DirectPhotoUploadTest.php` - the endpoint (auth, CSRF, refusals, kill switch), a new
  puzzle saved with a background-uploaded photo, the resend after a save answered as the saved result, a refused form
  keeping the token with a preview, another player's token refused, the telemetry levels.
- `tests/Component/MultiscanTrayTest.php` - quick-add with a token.
- What the browser read before shrinking: `tests/PhotoMetadataParityTest.php` (the parser under node),
  `tests/Services/BrowserImageSummaryTest.php`, and in `tests/Controller/PhotoUploads/DirectPhotoUploadTest.php` the
  summary with a background upload, through a refused form, and the photo's own EXIF winning.
- A photo the phone cannot read (§4.2): `tests/PhotoReadabilityScriptTest.php` runs `assets/photo_readability.js` under
  node (0 bytes, a refused read, a read that hangs or throws, which message a lost save gets, what "Save without the
  photo" may take out, error texts without file names); `tests/UnreadablePhotoFormScriptTest.php` runs the real
  `submit_prevention_controller.js` (real Stimulus, `photo_upload.js`, the pace check's confirm) in jsdom - pick, the
  start's `empty`, Save with an unreadable photo, the messages and their buttons, a new puzzle's photo, the
  `photo_dropped` marker; `tests/Controller/PhotoUploads/UnreadablePhotoTest.php` - the start's `empty` answer, an
  empty photo part saved without (add, edit) and "photo required" for a new puzzle, the marker's flash (once; page and
  modal; garbage ignored), the new report fields cut and cleaned at info. Multiscan has no script test.
- Real browser (2026-10-18): Selenium Chromium on the compose network against a worktree build, MinIO as the store,
  with the network throttled and uploads made to fail - see the PR description.
