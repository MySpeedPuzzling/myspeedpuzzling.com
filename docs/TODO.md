# TODO

Open follow-ups, one place to come back to. Tick an item when it ships, delete a section when it is empty.
Feature-sized plans keep their own checklist in `docs/features/<feature>/` - this file is for the loose ends
that would otherwise be forgotten. Newest section on top.

## Shown prediction model switch v3 → v7 (`docs/features/puzzle-intelligence/README.md` "The shown model switch", 2026-10-31)

- [ ] Flip `PREDICTION_SHOWN_MODEL=v7` on lily.srv, then `backfill-solving-time-predictions --recompute-reconstructed`
  (prediction-history.md "Production run for the shown v7"); watch the calibration report (it reads candidate:7 from
  then on) and the next days' live slice
- [ ] After the rollback window (proposed: 30 days without a flip back) remove the switch and the v3 shown path:
  `ShownPredictionModel` (keep `statisticalVersionSql()` / `RepeatResiduals::storedPersonalSql()` while v7-labelled
  shown rows exist - the time verification and the repeats' residuals read the statistical side row through them),
  the v3 branches of `GetPlayerPrediction::forPuzzle()`, `GetPlayerPredictions`, `GetPersonalEstimates`,
  `GetPuzzlePickerSuggestions` (`StatisticalInputsSql::centreSql()` then only for the time verification? - it has no
  other reader), the `$shownModel` constructor arguments, `.env` / feature_flags.md, `ShownModelV7Test`'s v3 halves;
  `PREDICTION_CANDIDATE_ROWS` then too (the candidate's rows are always written while it is shown)
- [ ] Decide whether the time verification moves onto the candidate (a `SuspiciousTimeClassifier::VERSION` bump and a
  dry run of its own - today it keeps the statistical model's expectations on purpose, suspicious-time-review.md)
- [ ] Insights texts that describe the statistical model (methodology page, `puzzle_intelligence.methodology.*`) -
  check them against the candidate before INSIGHTS_PUBLIC

## Finished-puzzle photos as a second view (`docs/features/insights/finished-photos.md`, 2026-10-27)

- [ ] lily.srv cron `0 4 * * * … myspeedpuzzling:prune-finished-photo-analyses`
- [ ] First production `puzzle-vision finished-photos --profile m3-solo` run after the deploy (~34k photos, ~12-15 h,
  GPU - when `describe` is done); then the nightly derive records difficulty_prior 1.3.0 and the gates re-measure it
- [ ] Re-measure on the gate record: 500 box / assembled with a view, and the k = 1-4 blend with the new shrinkage target
- [ ] The seam-grid (cut) numbers for new brands without a brand × size offset, and as a taste-model input

## Sell/swap requests (`docs/features/marketplace/12-listing-requests.md`, 2026-10-27)

- [ ] Bulk *add* to the own sell/swap list (feature request 019d1fda, Mathew N's comment): an "Add to sell/swap list…" action in the collection pages' selection bar - one type and condition for all, a price per puzzle. Bulk remove shipped in 26e7f64bd
- [ ] "Mark all agreed as sold to {buyer}" on the request page, if sellers ask for it (today one button per listing)

## Profile results by placing (`docs/features/profile-results-placing.md`, 2026-10-27)
- [ ] Placing on the pairs and teams tabs: rank of the exact pair/team (`puzzling_team_id`) on the puzzle's pair/team board, then the same sorts, chips and median line as solo. Pair/team rows show no rank today, so the rank chip comes first; the solo LATERAL count in `GetRanking::rankings()` is the pattern (feature request #292 asked for solo only)

## Candidate prediction model v7 (`docs/features/puzzle-intelligence/README.md` "Candidate v7", 2026-10-27)

- [ ] After the deploy: the candidate fit once by hand (creates the v7 fit and `candidate_pace_offset`), then the
  backfill with `--candidate` for the v7 rows (prediction-history.md "Production run for v7")
- [ ] 1500+ pieces at k = 0: +2.6 % on the June split (37 rows), -2.4 % on April (54) - bias -2.7 → +2.2 %; the k = 0
  centre offset falls back to the size group's below 200 first tries. Check on the live rows; a pooled k = 0 offset for
  1500+ and odd sizes if it holds (odd sizes at k = 0: +1.5 / +1.0 % on 133 / 173 rows)
- [ ] The image estimates are trained on the shown (raw-pace) indices; the candidate's adjusted indices sit 0.035 log
  below them on average (the mean offset) (the centre offsets absorb it). When the labels move to the candidate's indices (review P6),
  train them on the adjusted ones

## Candidate prediction model v6 (`docs/features/puzzle-intelligence/README.md` "Candidate v6", 2026-10-27)

- [ ] After the deploy: the candidate fit once by hand (writes the v6 fit), then the backfill with `--candidate` for the
  v6 rows (prediction-history.md "Production run for v6")
- [ ] Unboxed first tries after April 2026 scatter more than the window's: the April split's unboxed 80 % window held
  75.9 % (80.1 % in the window) - fit the unboxed variance on a longer lookback (all unboxed first tries of the last 24
  months, their predictions as of each) once the live rows confirm the drift
- [ ] 1500+ pieces at k = 0: the image interval widened their windows on the June split (173 rows, 78 → 82 % in the 80 %
  window, interval score +1.4 %) - check on the live rows; a size-group reference for the interval if it holds

## Candidate prediction model v5 - the taste factor (`docs/features/puzzle-intelligence/README.md` "The taste factor (v5)", 2026-10-27)

- [ ] After the deploy: the candidate fit once by hand (writes the v5 fit and `candidate_player_taste`), then the
  backfill with `--candidate` for the v5 rows (prediction-history.md "Production run for the taste factor (v5)")
- [x] Unboxed first tries read their taste state from the served W (not cross-fitted): their 80 % window held 75.9 %
  on the April split (v4 78.3 %). Measured with v6: cross-fitted states pick the same window on both splits - not the
  cause (README "Candidate v6"); the rest moved to the v6 section
- [ ] A first try enters the player's taste state only with the next daily fit - fold new first tries in between (the
  15-minute refresh) if the live rows show the lag matters
- [ ] Launch (Jan): taste explanations ("you are quicker at photos") only for players with ≥ 30 first tries and a
  split-half stable sign (time-model review §4.5 step 4); the Picker's `centreSql()` twin needs the state + factors

## Candidate prediction model v4 (`docs/features/puzzle-intelligence/README.md` "The candidate model (v4)", 2026-10-27)

- [ ] After the deploy: the two cron rows (`fit-candidate-prediction-model` 04:41, `refresh-candidate-prediction-evidence`
  every 15 min), the candidate fit once, the backfill with `--candidate`, then `PREDICTION_CANDIDATE_ROWS=1`
  (prediction-history.md "Production run for the candidate")
- [x] The shown fit (`fit-prediction-model`) peaks at 434 MB resident in the 512 MB container: the candidate's levers
  (`CursorRows` for the qualifying first attempts, releasing what later steps do not read) would take it under 350 -
  a change to the shown path, so its own PR with the fit compared before / after. Done 2026-10-27 (branch
  `v3-fit-memory`): 348-386 → 265-268 MB VmHWM on the production copy, every output the same to the bit
  (README "Memory of the daily fit", `ShownModelParityTest`)
- [x] The 15-minute batch's peak is its derived metrics step, not the prediction model: ~420 MB resident on the
  production copy (`DerivedMetricsCalculator::preloadAllData()` keeps one array per solo solve, ~200 MB in PHP, and
  reads the statement buffered, ~100 MB more in the client library) - a cursor plus columns would take it to the
  ~230 MB of the difficulty and prediction model steps; check the `web` container's limit first (README "Memory of
  the daily fit"). Done 2026-10-27 (branch `batch-derived-metrics-memory`): improvement ratios, derived metrics,
  skills, ratings and baselines were at 300-429 MB too; the whole batch 428-429 → 228-229 MB VmHWM on the production
  copy (its peak is now the difficulty step), every table the same to the bit (README "Memory of the 15-minute
  batch", `InsightsBatchParityTest`)
- [x] Puzzles players disagree about most (dispersion ≥ 0.02): the weighted mean is 2.9 % worse than the shown median
  there - a robust index weight measured with v6 and left out (README "Candidate v6": −2.4 % there, ±0 overall, odd
  sizes worse, O(k²) per as-of moment)
- [x] k = 0 dispersion from the picture's look-alike numbers (F8) - built in v6 (the picture term)
- [ ] Launch (Jan): one `MODEL_VERSION` bump making v4 the shown model - shown difficulty / tiers stay the median until
  decided; the Picker's `centreSql()` twin then needs the candidate's evidence columns

## Background batch queries (`docs/database-indexes.md` `custom_pst_qualifying_first_attempt`, 2026-10-27)

Measured on production 2026-10-26 (`log_min_duration_statement = 500`) and a local copy; what was left as it is:

- [ ] `puzzle_solving_time` autovacuums every ~20 % of changed rows: last vacuum 2026-10-19, 73.5 % of the heap
  pages all-visible on 2026-10-26 - but the new index's scan fetched 307,796 of 465,662 rows (66 %) from the heap on
  production right after the deploy (the active players' pages are the ones changed), so it gained ~14 % there instead
  of ~35-50 % on a vacuumed copy; `custom_pst_solo_board` of the Times boards is in the same position. Now: a manual
  `VACUUM (ANALYZE) puzzle_solving_time` (no blocking, ~228 MB heap). Lasting: a per-table setting like notification's
  (`Version20261026235000`) - ~58k row updates a day, so e.g. 2 % - measure the boards and the batch before / after
- [ ] `recalculate-community-stats` (~3 s a run on production): the `community_player_stats` upsert (1.2-1.45 s) and
  the moments' running totals (1.4 s) aggregate every result of every (candidate) player, pairs and teams expanded from
  JSON - no index helps a full aggregate. Splitting the moments' windows at `:since` (results before it only counted)
  gives identical moments but only ~15 % locally, as reading and expanding the results is the cost. The real fix is
  incremental stats (only players whose results changed, plus the rolling 7 / 30 day and month windows) - a redesign,
  only if the run time ever matters
- [ ] `reconcile-round-results`' series pick statement (0.4-0.55 s): one lateral answer per pick over every edition
  (6,552 picks x 349 editions on 2026-10-26, ~0.06 ms each) - it grows with picks x editions. Fine for now; if it
  passes a few seconds, evaluate the rule per (series, puzzle, category, day) once instead of per pick

## Notifications (`docs/features/notifications.md`, 2026-10-26)

- [ ] lily.srv cron: `myspeedpuzzling:prune-read-notifications` daily. Run it once by hand after the deploy (~2.95M
  rows, 1–3 min), then `VACUUM (ANALYZE) notification` from the infra session
- [ ] Unread notifications of players not seen for a long time (488k rows, 1,097 players): prune them too?
- [ ] Index `(player_id, notified_at DESC)` if the heaviest readers' 40 ms (a top-N sort of their rows) still matters
  after the prune

## Wishlist availability and ask to borrow (`docs/features/wishlist-availability.md`, 2026-10-26)

- [x] lily.srv cron: `myspeedpuzzling:expire-borrow-requests` daily, 05:41 (lily.srv 2c1c5b5, installed 2026-10-26)
- [ ] Look at the numbers after a few weeks (requests sent, answered, lent) - widen "who can be asked" beyond favorites?

## Partner finder (`docs/features/partner-finder.md`, 2026-10-22)

- [ ] Look at the page with ~100 real listings on a phone once there are some (filter bar wrapping, details panel)

## Wasgij, multipacks and reverting merges (`docs/features/wasgij-and-multipacks.md`, 2026-10-22)

- [ ] Decide the two separate concepts (they can meet in one box): multipack (one box, several puzzles sharing name/EAN/brand code) and Wasgij (the image is
      not the puzzle, solution picture = spoiler) - open questions in the doc
- [ ] Duplicate checks (merge review, `duplicate_puzzle_signal`, approval queue) must not offer merging puzzles of one box
- [ ] **"Revert merge"** on an approved merge request - **admins only, never moderators** (own access rule above the
      moderators' `^/admin/puzzle(s/|-((change|merge)-requests…` rule): recreate the deleted puzzles with their ids,
      restore the survivor's fields, move back every migrated row still on the survivor (report the rest), delete the
      redirects, reject the request with a reason for the reporter, record a `merge_reverted` decision, recalculate
      statistics/karma/XP. First extend `puzzle_merge_audit` to snapshot every puzzle column (`approved_at`,
      `approved_by_id`, `names_changed_at`, `image_upload_metadata`, …) and the full rows dropped as duplicates (today
      only their ids - not recoverable). The 2026-10-19 Christmas 10 merge was reverted by hand (steps in the doc)
- [x] Christmas 10 + 16 labelled "… - box image" / "… - non box image" (4 proposals approved 2026-10-22)
- [ ] Reject Rozebeest's pending proposal `01a1607a-f950-7189-ab13-b68a0150f541` - it puts "- BOX IMAGE" on the
      Wasgij entry `0191be5d…` (now "non box image"); approving it would overwrite the correct name
- [ ] Second look at the six "Look out Below!" advent calendar merges of 2026-10-16 (day numbers differ: 1→19, 2→7,
      6→12, 6→10, 9→15, 3→9) - one puzzle per day, so possibly wrong merges
- [ ] Interim naming convention ("… - box image" / "… - non box image") into the moderators' guide; label existing pairs

## What an uploaded picture's file carried (`docs/features/upload-image-metadata.md`, 2026-10-21)

- [x] The browser shrinks photos over 500 KB before upload (add/edit-time form, multiscan) and the canvas re-encode
      drops all EXIF - read in the browser first (`assets/photo_metadata.js`), sent with the upload start / form,
      stored with `source: browser` when the server sees no EXIF itself (2026-10-21, "Read in the browser")
- [ ] Time verification / duplicate results: read `finished_puzzle_photo_upload_metadata.capturedAt` next to `finished_at`
- [ ] Image pipeline: use `uploadMetadata` of the work list as the photo-vs-artwork prior

## Puzzles needing improvement (`docs/features/puzzles-needing-improvement.md`, 2026-10-21)

- [ ] Add puzzles whose picture needs replacing (own badge, same row + "Suggest a change"), not by priority yet -
      promised to Laëtitia for the week of 2026-10-26 (mail #351, follow-up F164: tell her when it is live)

## MSP Rating history (`docs/features/msp-rating-history.md`, 2026-10-20)

- [x] After the deploy: `myspeedpuzzling:rerank-rating-snapshots` once on production, before the next weekly digest (run 2026-10-20; re-run 2026-10-21 wrote 0 places, cleared 0 - history matches the ladder)
- [ ] "Why did my place move" on the own rating card - store the portfolio entries per day (or the day's points per entry) first
- [ ] Ladder milestones (top 100, best place ever) as player moments on the Players page / future Hub feed
- [ ] Rating lines side by side on Compare's Charts tab
- [ ] Season 1: formula, rules (only times after the start), the season switch on the ladder - newsletter first

## Moderator invitations + guide (`docs/features/community-moderators.md`, 2026-10-20)

- [ ] Simona's day-to-day routine into the guide: the slot "How we work day to day" in `templates/moderators/guide.html.twig` (order of the queues, how she checks a box - which sites/searches, typical cases and their answers)
- [ ] Screenshots of the queues in the guide (slot under "Your tools") - once the routine is written, so they show what it describes

## Photo uploads on slow connections (`docs/features/photo-uploads/README.md`)

- [ ] **2026-10-25** (a week live, Claude does it): read the `photo_upload` channel in Loki - `event=save outcome=failed`
      per day (should be ~0), `event=upload outcome=failed` by `reason`, how often `photos_in_form > 0` (the plain path
      still used), p95 upload `duration_ms` - tune the backoff / stall numbers and the Loki alert thresholds below.
      Between reviews the Loki alerts (lily.srv `log-alerts.yml`, group `myspeedpuzzling-photo-uploads`, 2026-10-18)
      e-mail on: ≥ 3 failed saves in 6h, > 30 % of saves falling back to photos in the form, > 15 % of uploads failing.
- [x] lily.srv: deny `tmp-uploads/` in the images nginx like `online/` (kept and background-uploaded photos are private
      for 24 h; the tokens are unguessable, but nothing needs to serve them publicly).
- [x] lily.srv: Traefik `readTimeout` 180 s (applied 2026-10-18; already planned for the maintenance window) - the plain form path still
      exists as the fallback.
- [ ] The other photo forms (moderators' puzzle forms, "Suggest a change", a round's new puzzle, event / series logos)
      still send photos with the form - GitHub issue #266 (full context) - give them the background upload when one of them shows up in the telemetry
      (`submit_prevention_controller.js` takes the same `upload-url` values; FormPhotoStash already restores tokens).
- [ ] Nothing typed survives closing the tab or the phone killing the browser while the camera is open - a draft of the
      add form in sessionStorage (like multiscan's tray) would cover it; the photos are already server-side by token.

## Online competitions (`docs/features/online-competitions/`, round clock - stream D)

- [ ] Open the scheduled start ("Start in 10 s", "Start at a set time", "Cancel the start") to every organiser once
      the test cups are done: drop `can_schedule` in `manage_round_stopwatch.html.twig` and the `requiredAttribute`
      of `ScheduleRoundStartController` / `CancelRoundStartController` (feature_flags.md `ONLINE_ROUNDS_FOR_ORGANISERS`).
- [ ] The acting player of a scheduled start and of a cancel is carried by the messages but not stored anywhere yet
      (no column) - add it when the control room or the review needs to say who started a round.
- [x] A stopwatch page left open across the deploy that added the CSRF token fails its first action (Start, Stop,
      Reset: "the page was open too long") - a reload fixes it. Fine, but never deploy during a live event. Gone: that was the one deploy that introduced the token; the Test Cup #1 rehearsal (stream RH, 2026-10-14) restarted the app twice mid-round and pages opened before worked on their first action (End now, Stop).

## Online competitions - participant API and evidence storage (`docs/features/online-competitions/`, stream A)

- [x] lily.srv: a daily cron row for `myspeedpuzzling:prune-online-media` (periodic photos after 30 days, the rest - added 2026-10-13 (lily.srv a1f9fb6), the command verified on production
      after 365, then the orphan sweep of `online/`) - the orchestrator adds it with the deploy.
- [x] Before Test Cup #1, with Jan's OK: `myspeedpuzzling:online-media:cors --apply` on production (the bucket CORS
      rule for the phones' PUT + the review's GET from the site's origin; keeps the bucket's other rules - run it
      without `--apply` first to see the rule). Done 2026-10-14 (Jan's OK): applied; a preflight from the site's origin answers 200 with the headers, another origin 403.
- [x] The images proxy (`img.*/original/<key>` through imgproxy) can fetch ANY key of the bucket, `online/…` evidence
      included. The keys are unguessable (UUIDv7 in the path), but before any public use deny the `online/` prefix in
      the images nginx (lily.srv) - or move the evidence to a bucket of its own. Done 2026-10-14 (lily.srv `2b90668`): `online/` refused under `/original/`, `/puzzle/` and any `plain/` source, and only `plain/` sources accepted (the base64 form would bypass a path rule) - verified on production (404).
- [x] Spike S5 (`spikes.md`): does Hetzner Object Storage enforce a signed `Content-Length` / `Content-Type` in a
      presigned PUT (MinIO does: another length or type is 403 SignatureDoesNotMatch)? If not, VerifyOnlineMedia's
      size/type check is the only guard - it deletes what was not declared. **Yes** (production bucket, 2026-10-14): a presigned PUT signed for 100 B `image/jpeg` accepts exactly that (200); 200 B or `text/html` → 403 SignatureDoesNotMatch. VerifyOnlineMedia's check is the second guard, not the only one.
- [ ] Ops note for the test cups: `VerifyOnlineMedia` (a HEAD per uploaded object) runs on the shared `async` transport
      with e-mails, XP and badges. It no longer holds a phone up - the pending-upload cap counts only what the phone has
      not uploaded (stream P1F) - but a backlog delays the review's checked sizes and the deletion of objects that are
      not as declared. Expect ~3.3 checks/s per 100 participants (a periodic photo every 30 s), ~10/s in the 3 minutes
      before GO; watch the `async` queue depth during the cup and add a consumer (or a transport of its own) if it lags.
- [x] Traefik's access log (lily.srv) writes the full path, so the personal links' tokens of `/api/compete/<token>`
      and `/<locale>/compete/<token>` (`/soutezit/<token>`) land there - redact them in the access log config (the app
      redacts its own logs and Sentry: OnlineTokenRedactor). Done 2026-10-14 for Loki (lily.srv Alloy `stage.replace` - verified `/api/compete/[redacted]/state`); the raw access-log file on the box keeps paths (CrowdSec reads it) and is not rotated - lily.srv backlog.

## Online competitions - control room and review (`docs/features/online-competitions/`, stream C2)

- [ ] Referees can't review online rounds yet (evidence photos stay with organisers: the review needs
      ONLINE_ROUNDS_MANAGE besides COMPETITION_RESULTS_ENTRY) - decide before public use; letting them in is one
      condition in `OnlineOrganiserApi` and the review pages' voters.

## Online competitions - load and storage failover (`tools/online-load/README.md`, `spikes.md` S5, stream LT)

- [x] lily.srv (Jan's yes): route `/api/compete/*` and `/-/time` like `/api/v` - past the bot-blocker (90/min per IP +
      browser) and out of CrowdSec's captcha, before the first online event (a club on one Wi-Fi would be blocked). Done 2026-10-14 (lily.srv `2b90668`, router `myspeedpuzzling-compete-api`, `myspeedpuzzling-api-edge`, on the web service): 120 × `/-/time` in < 1 min from one IP + iPhone UA = 120 × 200.
- [x] A timeout on the phone's upload request (a hanging store took 41 s to switch to the second store - each phone
      waited for its connection timeout). Done (stream P1G): `media_outbox.js` aborts a PUT after 15 s + its body at
      32 KB/s and reports it like "no answer" with a fresh URL at once - a hanging store is left in ~17 s (harness).
- [x] Mark upload rows abandoned after a failure, so the review doesn't show them as pending. Done (stream P1G):
      `online_entry_media.abandoned_at` - set by the presign that names the given-up upload, else by the daily prune
      (nothing in the store 30 min after); the review says "never arrived". Run `myspeedpuzzling:prune-online-media`
      after an event's round if its review should say so before the night's run (safe at any time).
- [x] Probe the primary store before the circuit breaker closes again (now it closes after 60 s and one round of
      uploads fails before it reopens). Done (stream P1G): a HEAD of a key nobody writes, one request at a time; no
      answer = open another 60 s.
- [ ] The production load run at a quiet hour (Tue/Wed 05-07 Prague) with Jan's OK - plan in the README.
- [ ] At ~1,000 open phones a settings save makes every phone fetch its state within ~1 s (the `online.settings` push only carries the hash) - put the phone's settings into the push itself before a large event (Phase 4).

## Online competitions - live video on the participant page (stream VP)

- [x] Changing "Live video" / "Participants see each other" / the detection mode on the online timing page reaches a phone only at its next state fetch (start, return to the page, reload) - bump the round's state hash / publish on the stopwatch topic when the settings change. Detection also needs its parts on the page: a page rendered with detection `off` stays button-only until reloaded - tell the participant to reload (before GO) or render the detection parts for every online round. *Done (stream SP, 2026-10-14): a `settingsHash` in the state and the heartbeat + an `online.settings` push after every save - open phones apply video / see each other / the studio / the time's-up measure within a second (README §5.3); the detection mode is fixed from GO until a Reset, a page loaded with it `off` offers "reload to use it" before the start.*
- [ ] Real phones (`device-test.md` steps 25-37): the host's voice on iOS Safari waits for "Tap here once", the ducking around the beeps, the studio microphone added while the camera is open (earpiece vs speaker), mobile data + strict networks (TURN) once LiveKit runs on a server.

## Online competitions - hands-up detection on the participant page (stream P2I, README §5.7)

- [x] Serve the detector's files ourselves before the cup that turns detection on (Jan: open source **and self-hosted**) - stream MH, 2026-10-14: the production image carries `/online-detector/1.1.0-pose-lite-1-gesture-1/` (the npm tarball + the models, checksums verified at build, immutable, brotli), `ONLINE_DETECTOR_BASE_URL` switches (README §5.7 "Downloads").
- [x] Not online-specific (found by stream MH, 2026-10-14): the production image carries npm's download cache (`/root/.npm`, ~113 MB) from the asset build - `npm install` + `npm run build` then `npm cache clean --force` in the same `RUN` (or a cache mount) would drop it (`docs/ci-pipeline.md`, image tuning). Done 2026-10-14: `npm cache clean --force` in the install's `RUN`.
- [ ] The detector's Caddy rule is a site block for a path (`:8080/online-detector/*`), because the base image imports `Caddyfile.d` outside its `:8080` block - Caddy calls a path in a site address deprecated. Move the import inside the block in MySpeedPuzzling/Docker, then make the rule a `handle` (`OnlineDetectorCaddyRuleTest` catches a Caddy that drops it).
- [x] The reviewer's "G - the photo shows a gap" is not stored: keep it per candidate (a participant's premature raise vs a detector's false raise) - the accuracy report's "false raises" count both today, and the Phase 3.5 dataset needs the verdicts. Done 2026-10-14 (stream RG): `online_review_moment_verdict` per moment (raise or tap), "Gap" in the review's moments list, "Not a gap"; the accuracy report splits premature raises from false detections and counts premature taps (README §5.9).
- [ ] Older iPhones: the worker keeps both models (pose + gesture) loaded all session so "Test again" works - measure memory / reloads on the real-device pass (`device-test.md` 38-52); load only the chosen view's model if it hurts.
- [x] A phone that reloads for finish detection (the banner of stream SP) after "I'm ready" lands on the waiting screen and detects untested with the default `angled` view - offer the set-up test ("raise both hands") on the waiting screen while the round has not started. Done 2026-10-14 (stream RP): the waiting screen shows the checklist's "Finish detection" row with **Test** for a device that never passed (untested / failed - a skip stays the participant's choice) while the round has not started and the test fits before the countdown; never started by itself, never blocking; the countdown or a practice ends a test untested (`compete_detection.js` `waitingOffer()`).
- [ ] The set-up test keeps the first view whose hold completes - an incidental raise during the automatic test can pick `topDown` for an angled phone. Revisit with the footage.

## Online competitions - the completeness check (stream P3, README §5.8, `tools/online-cv/`)

- [x] Production: the CV worker on lily.srv (Jan's yes - a box change): the `online-cv` image built from `tools/online-cv/` (CI or the box), a service with **no published port**, `read_only`, `mem_limit` (~1 GB per `ONLINE_CV_WORKERS` + headroom - 1.5 GB for one; three 50 MP photos peak at 0.88 GB, `tools/online-cv/README.md` "Memory"), `cap_drop: [ALL]` and `no-new-privileges` (as compose runs it), on the app's network, `ONLINE_CV_ALLOWED_HOSTS` = the stores' hosts as the app names them (`myspeedpuzzling:online-cv:check-round` prints them - the Hetzner endpoint, and the Garage one once it exists), `ONLINE_CV_THREADS` / `ONLINE_CV_WORKERS` sized so an event's finishes never compete with the site, the service in `deploy.sh`'s list; then `ONLINE_CV_URL=http://online-cv:8000` in the app's env. Until then the check is off and the review is as before. Done 2026-10-14 (Jan's yes, lily.srv `2b90668`): `ghcr.io/myspeedpuzzling/online-cv:sha-7c03b5f` (this repo's `.github/workflows/online-cv.yml`), `ONLINE_CV_URL` on web / api / messenger-consumer - the check is ON in production; a new worker = bump the sha tag in lily.srv `apps/myspeedpuzzling/compose.yaml`.
- [x] An organiser-uploaded reference image per round (a clean motif - box photos carry borders, logos and text, and a HEIC catalogue image is not read by OpenCV at all: the check then runs without its grid half). Done (stream RF, 2026-10-14): the card on the round's online timing page, stored as a JPEG under `online/…/reference/`, used by `OnlineCvInputs` before the catalogue image, "Check the finish photos again" - README §5.8 "Built (stream RF)".
- [ ] WP 3.5 - the dataset: a consented export of the finish photos + bursts + the reviewer's verdicts (incl. the `G` per candidate - see the detection section) + the check's verdicts (`online_media_check`, every worker version), outside the repository, for training our own model.
- [ ] Tune the checks on real photos (spikes.md S1 footage, the first test cups): the thresholds of `tools/online-cv/completeness.py`; whether a grid check that cannot place a box-photo reference should leave the holes check to decide alone (today both must say complete); the expected shape of the puzzle (unplaced pieces kept out of the frame leave a smaller clean rectangle the holes check passes - the loose-pieces rule covers only pieces lying next to it); objects put on the mat after its empty photo make every check `unsure`; a catalogue piece count far too low scales the hole thresholds up (a single hole could fall below the doubt line) - check the count against the outline. The accuracy report could also count a finish's earlier photos (before the confirmed moment) as "incomplete" samples - more data on false completes. A changed rule bumps `completeness.VERSION` and `check-round --force` adds the new verdicts next to the old ones.
- [ ] `VerifyOnlineMediaHandler` asks the store (HEAD) inside its transaction - ~200 ms idle in transaction per uploaded photo (seen in P3F's end-to-end run); at event scale make it `HandledOutsideTransaction` like `CheckOnlineFinishPhoto` (read + HEAD outside, the write a message of its own).
- [ ] `online_entry_media.verdict` (jsonb, reserved by Phase 1 for this) stays unused - the verdicts are rows of `online_media_check` (one per worker version). Drop the column in a later migration.

## Plural texts without a form for 0 (found 2026-10-13 by the online translations' render check)

- [x] About 55 plural messages per locale outside the online pages are written `{1}…|]1,Inf[…` (or with too few
      standard forms) and have no form for 0: Symfony throws `InvalidArgumentException` (a 500) and the browser's
      `translation_choice.js` shows the bare number. English has them too (e.g. `live_results.sync.waiting`,
      `badges.reveals.invite`, `multiscan.recap.*`, `competition.participants.import.plan.blocker.*`). Most call sites
      never pass 0 (checked: `free_trial.status.days_left` rounds up while the trial runs) - go through each call site,
      add a `{0}` / `[0,Inf[` part where 0 can reach it, then widen `tests/OnlinePluralMessagesTest.php` (stream TX,
      online roots only) to every root with an allowlist of the keys whose callers never pass 0.
      Done 2026-10-14 (stream PL): a form for 0 in 382 messages (messages cs 55 / de 54 / en 56 / es 55 / fr 53 / ja 36,
      emails cs + en 12 / others 8, time cs 17), other counts unchanged; live only as races: `official_results.delete_round.intro`
      (own `{0}`) and `activity_digest.preheader.numbers`; guard `tests/PluralMessagesTest.php` (every domain and root).

## Mass mailing on Amazon SES (`docs/features/mass-mailing/README.md`, built switched off 2026-10-12)

- [x] Rollout done 2026-10-14: notifications, Listmonk and transactional on SES (failover to Seznam), test mode off.
- [ ] Delete the deactivated old access key of IAM user `ses-smtp-app` after a clean day (rotated 2026-10-14) - and
      once the app has run a week on the SES API (`ses-api-app`, 2026-10-19), the whole `ses-smtp-app` user (only
      the `MAILER_SES_SMTP_DSN` rollback key uses it).
- [x] Monday 2026-10-19: the first weekly digest - stopped at 468 / 1,915: SES dropped idle SMTP connections, the
      sticky `failover()` moved the digest to Seznam, Seznam refused the account (README "Reliability"). Fixed: SES
      API + `MailStreamRouting` retry/pause/fallback, Listmonk idle_timeout 5 s.
- [ ] Watch a week after the SES API switch (2026-10-19): `email_audit_log` failures per transport, the "Amazon SES
      failed twice in a row" warning in Sentry (should never appear), no Seznam rows at all (SES only since 2026-10-19).
- [x] Seznam blocked `notify@` ("bulk unsolicited mail") after the 2026-10-19 digest - Jan is asking them to unblock
      it. Production sends SES only; the Seznam rollback was removed 2026-10-19 (`MAILER_DSN_SEZNAM` deleted, Listmonk's
      Seznam server + POP mailbox removed, subdomain SPF `-all`). Inbound mail decided 2026-10-19: MX stays on Seznam Email Profi for the apex and all three
      subdomains - Seznam blocked only the one `notify@` mailbox, the domain catch-all ("Doménový koš" →
      jan@myspeedpuzzling.com) receives fine. Remove the `notify@notify` mailbox once its contents are not needed, so
      its mail falls into the catch-all too (a blocked mailbox does not). Fallback if Seznam ever blocks a whole
      domain: SES receiving in eu-north-1 (receipt rule per subdomain = catch-all, S3 + forwarding Lambda).
- [x] Privacy policy: AWS listed (0a01bd8d, 2026-10-14).
- [x] Google Postmaster Tools: the three domains were already there (Jan, 2026-10-14).
- [x] CloudWatch alarms bounce rate >= 4 % / complaint rate >= 0.08 % → e-mail to Jan (`aws-setup.sh alarms`,
      2026-10-14); hard bounces and complaints are Sentry warnings (30d1f880).
- [ ] Research first-party open/click tracking (links on our own domain, e.g. a redirect on myspeedpuzzling.com)
      - SES tracking was left off on purpose: `awstrack.me` links are a spam signal (Jan: no spam-score cost).
- [ ] Metrics per stream (sent / delivered / bounced / complained a day) - the alarms per stream exist since
      2026-10-19 (README "Alerts"), a dashboard/page with the numbers does not; a paused
      Listmonk campaign alert that does not go by e-mail.
- [ ] Un-suppress an address (admin action + removal from the SES suppression list) for "my mailbox works again".
- [x] Delete the deactivated old access key of IAM user `ses-api-app` (`…AC3MYBX7`, leaked in a transcript,
      rotated and deleted 2026-10-19; failure transport empty, no failed attempts after the swap).
- [x] Rotate/delete the Seznam `newsletter@news` mailbox password - the mailbox itself was deleted 2026-10-19
      (catch-all to jan@ instead). Was: rotate/delete the Seznam `newsletter@news` mailbox password (it was printed in a session transcript on
      2026-10-12) - Listmonk no longer uses it (SMTP disabled, POP scanner off 2026-10-14); keep the mailbox itself,
      it receives replies.

## Anti-farming (`docs/features/xp-levels/README.md` §Anti-farming, workstream G 2026-10-11)

- [ ] lily.srv: add the `verify-progression --repair` cron row (README §Cron) with the deploy; watch its first warnings
      (drift found = a write path that is not wired - name the check, fix the path).
- [ ] Karma loops that the rule itself pays for (path independent, but repeatable): a moderator may file trivial
      proposals and approve them (+1 each, no cap), and two colluding accounts can farm the merge reporter's +1 by
      adding duplicates of one puzzle and reporting them (the adder loses the copy's credit, the reporter keeps +1 per
      merge). Candidates: count a self-decided proposal only when another moderator could not have decided it, one
      reporter credit per (reporter, merged puzzle adder) pair a month - Jan to decide; the admins' accept/decline split
      above would show both.
- [ ] Merging a puzzle whose sell/swap listing has a conversation fails ("A new entity was found through the
      relationship Conversation#sellSwapListItem") - reproduced in the test fixtures (PUZZLE_500_01 into PUZZLE_500_02),
      not caused by the progression work; check `ApprovePuzzleMergeRequestHandler::migrateRecordsToSurvivor()`.
- [ ] The nightly check cannot tell a missing weekly boost on a solve whose base part is below 1 XP (the moving
      difficulty tier decides) - only too many boosts there. Fine for farming; a full rebuild of such a player fixes it.
- [ ] Two concurrent karma recalculations may write the older aggregate (set-based UPDATE from a snapshot taken
      before the other commit) - healed by the daily run; a per-player lock would close it if it ever shows.

## Progression pages (`docs/features/xp-levels/launch-2026-10.md` §3, workstream D)

- [x] Paging past the top 100 on the XP / AP leaderboard - replaced by the "where am I" window on every board (2026-10-11)
- [ ] XP leaderboard default "people you follow" (draft D6) - not decided, the board still opens on the world
- [ ] Karma deep page replaces the `karma_explainer` placeholder (workstream F) and the karma board joins `ProgressLeaderboardTab`
- [ ] Visual check of the hub, catalogue and XP history at 320/375 px on the integrated branch (not done in the workstream)
## Progression UX polish (`docs/features/xp-levels/ux-polish/design-spec.md`, 2026-10-11)

- [ ] Real-device pass of the bottom sheet and the holo medal (iPhone Safari, Android Chrome): swipe to close vs the content's
      scroll, the Android back gesture (`CloseWatcher`), tilt only with a mouse/pen, haptics, the 92dvh cap with the toolbar
- [ ] /ladder overview: each table now ends with "Full board, around you" next to the old "Top 10" badge and "Show more" link -
      decide which stays
- [ ] Sheet skeleton dropped (the spinner only shows after 150 ms and the sheet renders fast); revisit if phones show it often
- [ ] The P1 badge cabinet stays a possible later "trophy room" view on the same data (decided not now, §1)
- [ ] Site-wide contrast: the coral `#fe696a` on white is 2.8:1 - the leaderboard table header (white on coral, from #222),
      plain links and `.btn-outline-primary`. The new progression controls use the coral ink `#c7393b` (5.2:1); the site-wide
      fix is a brand decision (QA 2026-10-11)
- [ ] MSP Rating on the recap (asked 2026-10-11): only an estimate is possible (batch every 15 min, no rating history,
      the place depends on everyone's times) - cheapest honest version first: "This result doesn't count toward MSP
      Rating (pair/team · not 500 pieces · fewer than 20 solvers)" + "the ladder updates within 15 minutes"; a "+N"
      estimate needs MspRatingCalculator's puzzle rankings scoped to the player's puzzles. Waiting for Jan
- [ ] Real-device pass of the reveal sound: always on, no toggle (2026-10-11) - iPhone silent switch (ambient audio
      session), the first tap after a full page load, Android Chrome; the reveal page's first card opened from the modal's
      "Reveal my achievements" plays its sound without a tap of its own (the modal's AudioContext carried over the Turbo
      visit, `assets/last_piece_reveal/sfx.js`)
- [ ] Profile results after deploy: measure production (dev still spends ~250-300 ms of PHP over the whole history -
      filters/sorts/grouping of every result); the ranking now leaves 0-second solo times out (custom_pst_solo_board
      covers `> 0`; the web form stores 0 as "no time", the API's "00:00" can still store one - none exist)
- [ ] Rework the art of the launch share card (`XpShareCardController`, variant `launch`) - Jan 2026-10-12: "I do not
      like it". "Share my level" is hidden on the reveal page meanwhile (`xp/_launch_reveal_actions.html.twig`); the
      route and the `xp.reveal.share_*` texts stay, the button comes back with the new card
- [ ] Hub cards don't carry `?return=` (their pages go back to the hub anyway, and it would turn the hub's main links into
      nofollow ones) - only Leaderboards and Fair play do

## Recap progress card (`docs/features/xp-levels/README.md` §Surfaces, launch 2026-10)

- [ ] "Earned with this time" is a 10-minute window after `tracked_at`, cut at the player's next time
      (`GetBadges::earnedWithSolve()`), because a badge row does not point at the solve that earned it. If a cron run of the badge evaluation lands in that window, its tiers are credited to the time. A `badge.solving_time_id` written by `RecalculateBadgesForPlayer` (dispatched with the time id) would make it exact.
- [ ] Real-device pass of the recap reveal (88/144 px stages inside the card) and the milestone sheet on iPhone Safari + Android Chrome.

## Karma (`docs/features/karma.md`)

- [ ] **v2: events** - approved events, series and organizations could count once the events catalogue is part of
  "the database" (Jan, 2026-10-10: not in v1).
- [ ] **Trusted contributors** - high karma and >= 95 % accepted: small fixes applied at once, still listed for review
  afterwards. Moderator invitations suggested from karma (appointing stays a human decision).
- [ ] **Admins: each moderator's accept/decline split** (the guard against rubber-stamping now that decisions have no cap) -
  in the drafts, not built.
- [ ] Review queues sorted by the proposer's acceptance (drafts) - only shown, not sorted, in v1.
- [ ] If the filtered karma list (a kind or the last 12 months, ~110 ms on the dev copy) gets slow on production, store
  per-kind totals next to `player.karma` instead of deriving them per request.
- [ ] lily.srv: add the `recalculate-karma` cron row (in `docs/features/karma.md`).
## Progression on the profile header and navbar (`docs/features/player-header.md` "Progression", workstream C 2026-10-10)

- [ ] Karma chip in the header's chip row (teal `.player-chip-karma`, only when > 0) - the slot is marked in
  `templates/components/PlayerHeader.html.twig`, needs `player.karma` on the `byId()` row (workstream F)
- [ ] Point the tier chip, the ring's visitor link and the cards' "?" links to the "How it works" hub once it exists
  (today `methodology` / `xp_explainer`)
- [ ] Pinning achievements in the showcase (today: highest tier first, newest first within a tier)
- [ ] `PlayerCard::$mspRating` is no longer shown on the player card (Level + tier only) - drop it from
  `GetPlayerCard` or show it elsewhere
- [ ] Player profile at 320 px: the page is 5 px too wide - measured on a static render (no JS), the chart card's
  row, not the header; check on a real device
## Activity digest + "Your week" (`docs/features/content-digest/README.md`, progression launch WS-E)

- [ ] Karma: workstream F wires karma gained in a period into `GetContentDigestRecipients::activitySql()` (karma = activity) and `PlayerPeriodActivityReader::karmaGained()` (the thanks line, linking F's karma history)
- [x] Launch badge backfill vs the first digest: tiers earned before the launch switch went on are never counted (`PlayerPeriodActivityReader::achievementsEarned()` starts at `XpLaunchReadiness::readyAt()`), whenever the backfill runs
- [ ] "We miss you" waves by hand (README wave plan): Tue 27 Oct 28d-3m + visitors with an older result, Tue 3 Nov 3-9m; Tuesday cron row from 10 Nov
- [ ] Holdout comparison (README §Holdout SQL) once the first spells are 8 weeks old (late December)
- [ ] Link the page (`my_activity`) from the app: user menu / own profile / the recap ("See your week") - nothing links it yet besides the e-mail
- [ ] Visual check of the page and both e-mails at 375 / 320 px and in a long locale (not done in WS-E: no isolated browser run)
- [ ] Admin line with per-run counts (`content_digest_run`) on the e-mail audit page
- [x] Seznam Email Profi daily ceiling (README §17.1) - obsolete: every stream on SES since 2026-10-19 (50k/day)

## Event types (`docs/features/events-page/event-types.md`, 2026-10-19)

- [ ] Filter by kind on the events page (chips next to the scope: Competitions / Workshops / Casual puzzling / Swaps /
      Marathons), in the browser over the index like the scope - needs the kinds in the index entries (a new key,
      `EventsIndexFactory::DEFAULTS` + `expandEventsIndex()`) and the URL state in `events_page_controller.js`
- [ ] More kinds when organisers ask (Michelle of Virginia JPA asked in MSP #334) - a new `EventType` case at the end
      + `event_type.*` in all 6 locales

## High-frequency series (`docs/features/events-page/high-frequency-series.md`, PR #253)

- [ ] **The converted weekly online series (2026-10-09)**: tell its organiser about the change (Jan, by mail - drafts
  are Jan's), and ask for (a) the exact start time of each past contest - the backfill gave every round 19:00 Central
  European Time - and (b) the official number/name of contests whose official results were not published at conversion
  time (one contest is named by its date only). Fix both through the internal API (round `PATCH`, edition `PATCH`).
  Result of the conversion: 169 contests created from the organiser's published results (dates and categories
  checked against the logged results), 5,152 results → 5,026 matched by puzzle, 12 by date, **114 series-level** -
  110 of them are one team's weekly team-format runs (May-Aug) on the puzzles of a teams-marathon contest that was
  **not created** (its published sheet carries no puzzle list; creating it would pull all those runs onto it by
  puzzle). Decide with the organiser whether to add that contest. The pre-conversion snapshot (JSON) and the rollback
  notes are in `/root` on lily, dated 2026-10-09.
- [ ] **Importing upcoming contests** of a high-frequency series (e.g. from the organiser's shop listing or a feed):
  not built - editions are added by hand / through the internal API. Decide with the organiser whether they maintain
  them on MySpeedPuzzling themselves (organization team, "Add several dates").
- [ ] An organiser view of their series' **series-level results** ("N results not matched to a date yet") with a way to
  assign them to an edition.
- [ ] API v1: a way to **remove** a time's event link on `PUT` (today omitted/null keeps it - P17).
- [ ] Picker cards: `SeriesEditionDays` dates an edition without `date_from`/`date_to` by the UTC day of its first round
  (the matching rule uses the round's local day) - only the card label can differ by a day for far-zone evening rounds.
- [ ] Picker: a series card whose only current edition is live shows the live badge and "1 date" but no date
  (one-time live events show theirs) - show the live edition's day (`SeriesEditionDays` would need it).
- [ ] Puzzle page "Used at": round lines are capped at 10 + "and N more", tag lines are not (harmless today).
- [ ] Performance: a flush touching rounds of N editions of one series runs N series reconciles
  (`CompetitionRoundsChanged` deduplicates per competition). Nothing does this today; if a bulk path appears, key the
  event by the series. If one series' reconcile ever exceeds ~1 s, scope it to picks near the changed edition.
- [ ] Other undated one-time "umbrella" events holding results of many separate contests: candidates for
  `convert-to-series` with `keepAsEdition: false` + an edition backfill (survey production first).
- [ ] Post-launch (~2026-11-09): how often players use S1 (typing an edition), the open short list and "change";
  how many new series-level times stay unmatched per series.
- [ ] **"Date finished" is prefilled with the server's (UTC) day, not the player's**: `PuzzleAddFormData` and
  `EditPuzzleSolvingTimeFormData` set `finishedAt = new DateTimeImmutable()` and PHP runs in UTC, so a solve logged in
  the Americas in the evening (after ~19:00-20:00 local = midnight UTC) is saved as the next day unless the player
  changes the date. Found on Canadian Speed Puzzlers (2026-10-13, MSP #270): their evening events' results land a day
  late (`finished_at` = next day, `tracked_at` 00-05 UTC), so the ±1-day date rule picks the wrong edition or none when
  editions are on consecutive days (BYOP Tue/Wed), and calendars, streaks and "today" stats shift too. Fix: prefill
  from the browser's local date (or the player's time zone), never the server day - check every entry point (add form,
  stopwatch finish, multiscan quick-add, API v1 default); dates already saved stay as they are.

## Organizations and drafts (`docs/features/organizations/README.md`, PR #252)

Replaces "Format chips / an 'Organisation' level above series" - the level is built (one series per format).

- [ ] Co-hosts: several organizations on one event (today one organization per series or one-time event).
- [ ] Notifications for followed organizations (a new date, registration opens) - with the followed-series ones.
- [ ] A casual/competitive flag on series and events (an event without rounds already shows no results).
- [ ] Season rankings - only on demand (the motivating organiser does not want them).
- [ ] Outreach to organisers matching the README's patterns ("Organiser patterns seen in production" - patterns, no
      names here): one series holding several formats, two series of one brand, a duplicate series, events of one
      organiser not grouped, annual championships entered as separate one-time events.
- [ ] Merging duplicate series of other organisers (a merge tool, or moves + delete through the internal API).
- [ ] Moving participants (round entries, pairs/teams) with a round - `MoveRoundToCompetition` refuses a round with
      entries today.
- [ ] Moving a one-time event into a series (P23) - its URL, follows and approval change shape; needs its own rules.
- [ ] Page sections on organization pages (and an organization's own event calendar export).
- [ ] The restructuring pages (`move_edition`, `move_competition_round`) load every series / event
      (`GetRestructureChoices`) and narrow them in PHP to what the actor may edit (admins: all) - fine at today's
      size; page it or make it an autocomplete once it grows.
- [ ] The events page's own month calendar still starts on Monday (the date pickers follow the visitor's locale since
      2026-10-08, `assets/datepicker_locale.js`).
- [ ] The profile results date filter still shows dd.mm.yyyy.
- [ ] The site's flash colours fail WCAG AA contrast (success and warning about 2.2:1 on their backgrounds) - older
      than this feature, but its key messages use them ("Saved as a draft", "cannot go back to draft").
- [ ] Header action buttons and social icons of the event family are 40 px (`ev-action-button`), not the 44 px target.
- [ ] Browser review leftovers (2026-10-09, all minor): the series page's facts strip (".ev-facts-strip": "2 editions
      · since … · next …") still starts a wrapped line with "·" at 360 px (older than this feature - the byline, the
      organization facts and the cards were fixed); "Save as draft" stays enabled on "Add several dates" while no date is
      checked (the server refuses); on "Move to another series" the address prefix sits above the label and the
      taken-address error still says "type another one" while a free suggestion is filled in; the "by <organization>"
      link on series directory lines is 14 px tall; required-field markers read "* Required" in English on de/ja forms
      (older than this feature).
- [ ] The `competitionId` branch of `AddPuzzleSolvingTimeHandler` does not re-check visibility (the forms validate the
      picker's set and API v1 checks rounds, but the handler itself would link a time to a draft).
- [ ] Anonymous pages are shared-cached for 60 s, so an unpublished (or newly hidden) item can linger that long on
      guests' pages.

## Series, edition and event pages (`docs/features/events-page/detail-pages.md`)

- [ ] An organiser-only hint on long spans without rounds ("add a round per night"), so a month-long online event
      stops reading as "Ongoing" with no dates.
- [ ] BreadcrumbList JSON-LD for the crumbs of the three pages (the crumbs are plain links today).
- [ ] A live leaderboard link for spectators while a round runs (Live entry / round results), next to the "Live" round.
- [ ] `EXPLAIN ANALYZE` of `GetEventOccurrences::all()` and `forSeries()` on a copy of production data with the per-round
      `has_results` join (`OccurrenceRounds::sqlJoinWithResults()`) - before/after numbers into the PR; if the events page
      statement grows by more than 5 ms, keep the flag on the series page only.

## Events page (`docs/features/events-page/README.md`, shipped 2026-10-08)

- [ ] Post-launch measurement ~2026-11-05: `?view=calendar` loads (Tempo) and the GA events `events_scope` / `events_view` / `events_search` / `events_calendar_day` / `events_calendar_month` vs the README baseline (7 days before launch: 1,004 views from 404 IPs, filters from 115, calendar from 32)
- [ ] Phase 2 ideas: "N sellers bringing puzzles" tag from Marketplace at events (`docs/features/marketplace/11-events.md`), "Live results" tag on events happening now
- [ ] Notifications for followed series (new edition announced, registration opens); "N of your favourite puzzlers are going" (then add the page to the blocklist/private-profile canaries)
- [ ] Data clean-up (via the internal API / organisers): series without editions or duplicated, undated editions duplicating dated ones, online one-time events with no dates that are really series (`ConvertCompetitionToSeriesController`), annual championships entered as separate one-time events
- [ ] An index endpoint instead of the embedded index once the page passes ~1,000 occurrences
- [ ] `CountryRegion` could replace the region grouping copies in `MarketplaceListing` / `ManageCompetitionParticipants`
- [ ] Admin approve/reject routes have no CSRF check (admin-only POST, unchanged) - separate hardening

## Puzzle picker - feedback from a puzzler (`docs/features/puzzle-picker/README.md`, e-mail 2026-09-20)

A second puzzler asked for much the same, so it is not a one-off wish.

- [ ] The whole result list, not just 5 more: the page shows the total of matches, but "Show more" only reveals the
      over-fetched rows (README "Show 5 more"). Wanted most when the results are sorted (e.g. by the gap to the
      prediction). The README's way out: chained Turbo Frames `?seed=…&offset=6&limit=5`, the seeded order already
      makes paging stable (`GetPuzzlePickerSuggestions` takes `LIMIT :limit OFFSET :offset`).
- [ ] A short explanation of what each preset ("Surprise me", "Quick one", "Rating grind", …) and each filter does,
      next to it - for accessibility, not only for newcomers.
- [ ] Pick from the puzzles people bring to a meetup. Today it needs a temporary custom collection (members only,
      one owner). Idea: a shared collection where each attendee adds the puzzles they bring, which the picker then
      uses as its source like any collection. Check overlap with "Marketplace at events" (`11-events.md`, sellers
      mark listings they are *bringing*) before designing a second "bringing" concept.
- [ ] "Similar puzzles" (more like a puzzle I liked / want to improve on): needs image/category classification
      (small models, embeddings) - not before the more important backlog items. Same person offered help with
      embedding models (no training, existing models only).

## Outdated puzzle requests (`docs/features/puzzle-approvals.md`, "Outdated requests", PR #247)

- [ ] `GetPendingPuzzleProposals` (the puzzle page's "pending proposal" badge, `blocksNewProposal()`, the pending list)
      and `GetPuzzleMergeRequests::sqlNoSecretPuzzle()` still match a merge request by its reported ids only. A pending
      request naming a puzzle merged into this one since (resolved through `puzzle_redirect` everywhere else -
      `MergeRequestPuzzles`) is not shown on this puzzle's page and does not block a new suggestion there.
- [ ] The history line of an outdated merge request names a puzzle deleted by a merge as "Puzzle 018d…" -
      `GetPuzzleHistory::knownNames()` reads `puzzle_name` only, not a merge's `details.mergedPuzzleNames`.

## Inbox promises (simona@ backlog, 2026-10-07)

What a reply to a player promised to build. Each has an MSP Mailer follow-up: tell the player when it ships.

- [x] First tries (and unboxed) filter for pairs and teams, on the profile and the puzzle leaderboard, kept across
      tabs (2026-10-08, `docs/features/pairs-and-teams/README.md` "Filters"). With it Allison's other report: the
      "with…" pair/team filter no longer hides the Solo tab. Promised to Gav (MSP #90, follow-up F45) and Allison
      (MSP #107, F46) - tell them once it is deployed.
- [x] Move several puzzles at once on a collection page (select, then "Move to collection"), not only one by one or
      through multiscan. Promised to Allison (MSP #107, follow-up F50). Shipped 2026-10-08 with Copy and Remove,
      members only (`docs/features/collections/bulk-actions.md`).
- [ ] Rename the default ("system") collection - Allison asked in the same mail (MSP #107), answered "noted", not promised.
- [x] Select several puzzles on the other lists too (wishlist, sell/swap, unsolved, lend/borrow) - shipped 2026-10-08,
      members only (`docs/features/collections/bulk-actions.md` "Other lists").
- [x] Profile results sort 'Slowest' should order puzzles by the player's best (fastest) time per puzzle, not by their
      slowest attempt (usually the first try). Promised to Šárka (MSP #194, follow-up F60). Shipped 2026-10-27 (also
      pairs/teams and "Slowest PPM"; under first tries / unboxed the row's attempt decides), told in MSP #360.
- [ ] A player setting to hide other puzzlers' skill tiers / rating shields across the site (ladder, leaderboards,
      profiles) - today ranking opt-out hides only your own. Promised to Martine (MSP #125, follow-up F66).
- [ ] Brand doughnut chart on player statistics: with 7+ brands the legend overflows and the last line is cut off
      (Android Chromium) - give the chart container a height that fits the legend
      (PlayerStatistics::getManufacturersChart, _player_statistics_category.html.twig). Promised to Fredrik (MSP #216,
      follow-up F77).
- [ ] Add-time/edit-time event picker: make it clear that picking a series (e.g. Euro Jigsaw Jam) is enough - one entry
      covers every edition and both LIVE and FLEX runs, and MySpeedPuzzling matches the right edition by puzzle/date
      automatically, also when the edition is added later (a player saved a FLEX jam time without any event because the
      edition looked like the LIVE jam only). Promised to Carsten (MSP #257, follow-up F104).
- [ ] Public API v1: endpoints to create and edit competitions/events (POST/PATCH, today /api/v1/competitions is GET
      only), so an organiser like Puzzle Rush can sync events from their own site instead of setting them up twice in
      MSP. Promised to Rob (MSP #261, follow-up F107).
- [ ] Image search: find a puzzle from a photo of its box (titles on the box often differ from the listed/translated
      names, and some brands print no barcode) - possibly building on the image work of Puzzle Cousins
      (jacobpilawa.github.io/puzzlecousins). Promised to Jessica (MSP #267, follow-up F112).
- [ ] MSP Rating progress on the own profile (PlayerRatingProfile): 'Total solves' really counts different 500-piece
      puzzles solved solo with a time in the last 2 years (MspRatingCalculator::getProgress) - rename it (e.g.
      'Different puzzles') and explain what counts (repeats, other piece counts and pair/team results don't), all 6
      locales. Promised to Destanie (MSP #268, follow-up F113).
- [ ] Canadian Speed Puzzlers follow-ups: (1) MoveEditionToSeries must carry the edition's automatic results along
      (times with a series pick on the old series linked to the moved edition get the new series as their pick - today
      the reconciler drops them to series-level of the old series); (2) after editing an edition, redirect back to its
      series page instead of the events page (faster to rename a list of dates added with Add several dates); (3) event
      picker while typing: keep the server's date order (live, recent past, upcoming) instead of TomSelect's text score,
      and fix the option row that breaks when the highlighted name wraps. Promised to Allison (MSP #270, follow-up
      F115).
- [ ] Never refuse a new puzzle whose EAN is already in the database (multiscan quick-add, add form, collection mode):
      show the existing puzzle(s) and ask 'Is it one of these?', and create the new puzzle with the same EAN once the
      player confirms it is a different one. Promised to Lior (MSP #282, follow-up F124).
- [x] Saving the add/edit time form with photos survives slow and dropping mobile connections (background photo upload
      with progress and retry; the photo-upload hardening PR, docs/features/photo-uploads/). Promised to Gal (MSP #300,
      follow-up F129).
- [ ] Organization collections: an organization page can have its own puzzle collection, managed (incl. lending) by
      members of the organization's team; then move the Bucharest Jigsaw Puzzle Club's collection from the member's
      account under their organization. Promised to Alex Roman (Bucharest Jigsaw Puzzle Club) (MSP #307, follow-up
      F134).
- [ ] Puzzle page: make Puzzle Insights easier to reach - the side-panel dropdown needs scrolling to see all data;
      consider opening the insights in a full-screen popup/modal. Promised to HighMansWith_An_S (MSP #312, follow-up
      F139).
- [ ] Results stay secret while their round puzzle is secret: a solving time linked to a round whose puzzle has not
      been revealed yet is shown only to its people (tracker + registered pair/team members), the event's team and
      admins - nowhere else (leaderboards, feeds, profiles, puzzle page, stats, API) until the reveal moment
      (`RoundPuzzleReveal`), so an organiser running the same puzzles at several venues on different days can keep them
      secret until e.g. Friday. Idea: stored `puzzle_solving_time.hidden_until` kept in sync by the add/edit handlers,
      round/series reconcilers and the reveal handlers, one read fragment like `HiddenPlayers` + a coverage test like
      `SuspiciousTimeQueryCoverageTest` + a canary. **Needs a proper plan first** (Jan, 2026-10-19): who exactly sees it,
      times not linked to the event, official results, notifications/e-mails, achievements/XP and stats recomputation.
      Promised to Dakota (MSP #331, follow-up F149).
- [x] Add/edit event form: when 'recurring series' is ticked, make Location optional for the series and explain in the
      help text that every edition has its own location, dates, rounds, participants and results (an organiser read
      'series' as 'same place every time') - CompetitionFormData, add_competition.html.twig, all 6 locales. Promised to
      Dakota (MSP #331, follow-up F150).
- [ ] Unify event tags with rounds: when an organiser sets an event's (or edition's) round puzzles, create the event's
      tag if missing and keep its puzzles in sync with the rounds (today only the internal API's SetCompetitionPuzzles
      creates/fills tags), so the puzzle badge and the puzzle database's 'Competition / event' filter work without
      asking us. Promised to Stefan (MSP #333, follow-up F152).
- [ ] Let a player edit their own pending puzzle change suggestion or add to it (e.g. an EAN found after suggesting a
      new picture) - today a pending picture/EAN/brand/pieces/code suggestion blocks any new one on that puzzle until a
      moderator decides it (GetPendingPuzzleProposals::blocksNewProposal()). Promised to Patrick (MSP #340, follow-up
      F160).
- [ ] Report / suggest a change on event, edition and series pages (e.g. 'this is a duplicate of …', wrong date or
      details) so players can flag event listings without the feedback form - today there is no way to report or propose
      edits to an event they don't manage. Promised to SickHippie (MSP #342, follow-up F161).
- [x] Profile results: with the 'first tries only' filter on, the rank chips (#X of Y, top %), placing sorts, placing
      chips and the median placing line should come from the first-try leaderboard (everyone's first attempts), not from
      the best-time board (GetRanking always ranks best times). Promised to Šárka (MSP #360, follow-up F169).
- [ ] Practice tracking for partial puzzles: let a player log timed practice sessions on part of a puzzle (e.g. a
      section of trees) and see their progress over time in one place, instead of 'relax time' notes scattered over the
      activity calendar. Promised to MarieR (MSP #366, follow-up F172).

## Participants spreadsheet (`docs/features/competitions-management/participants-spreadsheet.md` §13)

- [ ] Pilot: the Wisconsin organiser tries the sheet on their next event (D14) - e-mail drafted in MSP Mailer for Jan.
- [ ] Manual pass the automated checks could not do: Safari macOS + iOS (clipboard through the hidden textarea, Cmd+D,
      the sticky table), a real Android phone, Windows + Excel, VoiceOver and NVDA on the grid (selection, checkbox
      names, the hidden textarea briefly focused), a Japanese IME's first keystroke on a cell (Enter/F2 first is the
      documented path). Clipboard samples from real Excel (Mac/Windows), Numbers and Google Sheets: paste them into the
      spike's `capture.html` (kept in the delivery notes) and add them to `tests/participants-sheet-core/tsv_fixtures.mjs`.
- [ ] Export / print of the current tab (pairs with members, tables and results) - today the participants export and the
      results desk export per round.
- [ ] A change log with restore (D11): `participant_sheet_change_receipt` already keeps who sent what (90 days) - a
      "what changed today, by whom" panel when organisers ask.
- [ ] Highlight rows another organiser changed (they update live today, with an announcement only).
- [ ] People tab: "Make a pair/team ▾ → existing", "Split → Move to a new pair/team" (§5), hiding single round columns
      (15 rounds at WJPC); phone chips: "Replace with…" after a no-show.
- [ ] Registration actions are sent at once, not kept offline like the sheet's edits; and merging a self-joined row into
      an imported row of the same person.
- [ ] The import confirm, the check-in page and self join/leave do not publish the sheet's live topic - open sheets
      catch up within 30 s (version check); publish from them if organisers notice.

## Managed registration (`docs/features/competitions-management/registration.md`)

- [x] Registration status / paid / checked-in as optional columns of the participants spreadsheet's People tab
      (participants-spreadsheet.md D10) - the import reads nothing from the export's registration columns today.
- [ ] Notify maintainers about a new registration, a cancelled *paid* registration (refund talk) and a listed name
      picked on a managed event ("connected by …" in the participants sheet - review 2 A-F10, documented trade-off);
      optional daily digest.
- [ ] Payment deadline / automatic release of unpaid spots; a response deadline for a spot offered from the waitlist.
- [ ] A verified e-mail before registering (throwaway accounts can fill a capacity).
- [ ] Check-in tolerant of venue Wi-Fi (client-side search, optimistic taps) - it is a Live component today.
- [ ] Event JSON-LD `offers` pointing at the event page with availability (sold out / waitlist) while managed.
- [ ] Series edition cards: an internal "Register" button for a managed edition (the external link is hidden).
- [ ] API v1 (`CompetitionDetailResponseProvider`, `CompetitionListResponseProvider`) returns `registrationLink: null`
      for a managed event - clients cannot tell registration happens on MSP. Additive field, e.g. `registrationManaged`
      plus the event page URL (no BC break); left unchanged in the port on purpose.

## Event page content sections (`docs/features/competitions-management/public-page.md`)

- [ ] Prune unreferenced `competition-pages/<owner>/` objects: uploads of a section form that was never saved, and the
      pictures of a deleted competition/series (its sections cascade, the files stay). `GetStoredFileReferences` already
      knows section pictures; a daily cron like `myspeedpuzzling:prune-photo-stash`, with an age threshold - needs a cron
      row on lily. Until then the per-player upload limit (60/h) and the 40-picture cap per section bound the leftovers.
- [ ] A gallery photo also embedded by URL in a rich-text `<img>` is not seen by `GetStoredFileReferences`: removing it
      from the gallery deletes the file and the rich text image breaks (edge case, review 2).
- [ ] Contact section publishes the e-mail address in clear text - obfuscate it or offer "message the organiser" via MSP
      chat instead.
- [ ] If organisers ask: order/hide the page's own parts (puzzles, participants, ...) - deliberately not built
      (the PR's `page_layout` was dropped in the port).
- [ ] The section form's other repeatable rows (photo caption, sponsor name/URL, link label/URL) have an `aria-label`
      and a placeholder but no visible label - the FAQ rows got real labels after the browser verification; the same
      pattern there if organisers find the rows unclear once something is typed.

## Official round results (`docs/features/competitions-management/official-results.md`)

- [ ] Unfinished (pieces placed) and did-not-start results onto players' profiles - after phase 1b of
      `unfinished-results-plan.md` (today only finished results are offered as "Add to my profile").
- [ ] Rounds with several puzzles: one total result per entry today - a result per puzzle when organisers ask.
- [ ] Derive table numbers from the table layout tool (`table_spot`) instead of typing them.
- [ ] Results from timing devices without the round stopwatch (import of a device's export).
- [x] Cron row on lily.srv for `myspeedpuzzling:prune-round-result-change-receipts` - daily at 03:23 (lily.srv 3cf56d0, 2026-10-07).
- [ ] Live entry quick add: the event's people come with the page (`GetLiveResultsEventPeople`) - somebody added to
      the event by another device during the session is not offered until a reload (rare; typing the name in creates a
      second person, as before).
- [x] The participants spreadsheet (`participants-spreadsheet.md`, its own PR): its result / table / qualified cells
      send `RecordRoundResults` changes (the live entry's and the desk's write path), not a separate changeset type.
- [ ] Advance with the country rule: entries sharing the K-th place of a country across groups are ordered by the
      advancement seed (relative result, round order, name) - highlight such ties for the organiser like the desk's
      per-round helper does, if organisers ask.
- [ ] "Take out of this round" exists on the results desk only - the live entry (a referee quick-added the wrong
      person) could offer it too.
- [ ] The live entry's seating recommendation follows the one rule (`tablesReadiness`) but keeps its own wording
      (`live_results.tables.*`) - switch it to `seating.readiness.*` with the live page's next change.
- [ ] An event that never numbers tables: "none of this event's rounds use table numbers" in one click (today per round
      on the seating page; past rounds never show the step any more).
- [ ] An event-wide results export (every round of the event in one file) - today the results desk exports one round.
- [ ] A "disqualified" result (with a reason) - today the organiser clears the result or marks "did not start".
- [ ] Check-in → "did not start": when a round starts, offer to mark its entrants who were not checked in (managed
      registration) as did not start - the organiser decides, nothing automatic.
- [ ] `result_entered_at` is `TIMESTAMP(0)` and the results desk's late-answer guard compares with a strict `<`: two
      saves of one entry within the same second can briefly show the older one (the 1.5 s own-write resync puts it
      right) - harmless; compare with `<=` plus the result value, or store milliseconds, if it ever confuses anybody.

## Referees (`docs/features/competitions-management/live-results.md` "Referees")

- [ ] "My events" does not list the events a player referees (its cards are organiser cards with edit/delete) - a
      referee opens the link they were given. A "Referee at" list with a Live entry button when referees ask for it.
- [ ] A newly added referee is not told (no notification/e-mail) - the organiser hands them the link.
- [ ] Referees on the results desk (read-only ranking) if events want referees to check what others entered.
- [ ] A referee's live updates withhold every private player (the update has no viewer); a referee on a private
      player's allow list keeps her code from the page's own state (`keepWithheldPlayers()`), but an entry they first
      see through an update (a quick add on another device) shows her code only after the next state fetch (≤ 60 s).

## Seating (`docs/features/competitions-management/seating.md`)

- [ ] Two rounds running at once in one hall (WJPC semifinals): table numbers are unique per round only - the
      organiser gives the second round "First table number 101"; a shared check across simultaneous rounds if asked.
- [ ] Pairs/teams seated by MySpeedPuzzling times: a pair's own pair time beats the mean of its members' solo times, so
      pairs that puzzled together come first - calibrate (e.g. scale members' solo times by the typical pair speed-up)
      if organisers notice.
- [ ] Seat-by-drag on phones works through Move up / Move down only (SortableJS touch drag is there, but small screens
      make it awkward) - a "Move to table…" action if organisers seat on phones.

## Time verification (`docs/features/suspicious-time-review.md`)

- [ ] Result detail of a private player's queued time: the queue card links no result detail for a private player
      (the detail 404s for anybody the player hides from). Settled decision 1 allows a moderator to see that one time
      while it is queued - open the detail for `REVIEW_SUSPICIOUS_TIMES` + a case in the queue, or show what is needed
      on the card.
- [ ] Admin funnel: `/admin/duplicate-results` ("Your results" e-mails in numbers, `GetResultReviewContactsOverview`)
      counts every contact, but nothing tells the e-mails with verification content apart and "all resolved" knows
      duplicate cases only - count the e-mails that carried notices (`suspicious_notice_ids`) and the reactions to
      them (fixed / says correct / left it), or link the queue's Numbers tab.
- [ ] Undo of an automatic duplicate removal (`result_auto_removal`, docs/features/duplicate-results.md) restores a
      flagged time with the same id but without its `suspicious_time_case` / notices (they cascaded with the deleted
      copy): the next scan's flag reconciliation gives it a new manual case and the notice run tells its players again,
      although they were told about the mark before. Rare (a marked time that was also an automatically removed copy);
      restore the case and notices from the removal snapshot, or record the restored notices as already told.
- [ ] After a few weeks of decisions: read Numbers (precision per reason, what players did) and decide the thresholds of
      `SuspiciousTimeClassifier::VERSION` 2 (`--dry-run` first); also whether pair/team fast times and "Needs
      verification" from the result detail (origin `moderator`) are worth a version ("Not in v1").

## Round reveal delay (`docs/features/competitions-management/README.md` "Automatic reveal delay")

- [ ] Deploy it by the checklist in README "Deploying and rolling back": nothing pending in the next 24 hours (the
      `+ interval '10 minutes'` form of the query), every web/api/consumer container on the new image, then the delay
      query - re-sync each round it lists with `PATCH /internal-api/rounds/{id}` and `{}`.
- [ ] Once deployed, tell the organiser who asked for it (she added 5 minutes to her round starts to get 15 minutes
      before the reveal). She can put each start back to the real one and set the delay to 15. An earlier start with
      a longer delay that keeps the moment asks for no confirmation.
- [ ] The internal API's `"confirmReveal": true` is a blanket yes (as since PR #240): a list of revealed puzzles that
      grew between the 409 and the resend is applied unseen. Bind it to the list like the web form does: the 409
      answers the list's `SecretRevealPreview::hash()`, the resend sends it back (`confirmedRevealHash`).
- [ ] API v1 competition detail does not expose a round's reveal delay or its puzzles' reveal moments - add them
      (additive) if an API client ever needs to show when a secret puzzle comes out.

## Participant import (`docs/features/competitions-management/participant-import-preview.md`)

- [ ] `.xls` / `.ods` uploads; localized header aliases ("Jméno", "Nom", …); a header row chosen by hand; remembering
      the mapping per event.
- [ ] A team renamed in the file (all its members under a new name) is planned as move + delete, not as a rename.
- [ ] Clearing field values (country, external id) from empty cells in full sync - empty cells never clear today (D14).
- [ ] A connected player the file wants to change for a participant who has one is refused with a row message (never
      taken over) - offer a way to change it on purpose (the participants sheet's profile column, or an explicit mapping). External ids are
      updated unless another participant of the event has the file's one.
- [ ] Code page guess: names that defeat the neighbour rule (Norwegian "Øystein", French "Anaïs" next to vowels) can tip
      a small Western file to Windows-1250 - the Encoding select fixes it; watch for reports.

## API usage statistics (`docs/features/api/usage-statistics.md`)

- [ ] API-wide rate limiting: one Redis Lua script (GCRA / sliding window) on `kernel.request` in `redis-state`, keyed by `ApiCaller`, limits chosen from `api_caller_day.peak_requests_per_minute` once there are a few weeks of data; fold `api_puzzle_search` into it (design in the doc, "Later: rate limiting").
- [ ] Look at the busiest-minute numbers on `/admin/api-usage` around mid-November 2026 and decide the limits.

## Participant management after the edit-form bug (`docs/features/competitions-management/participants.md`)

- [ ] Wisconsin 2026 participant data repair: pending organiser's answer, script kept privately by Jan.
- [x] Import reads `round_names` (what the template/export write) and still `round_name`; rows of the same person add
      up; unknown rounds and columns are reported; the export carries `participant_id` and one `team_name: <round>`
      column per pair/team round, so an export imported back changes nothing; same-named people are never merged.
- [x] Import accepts `.xlsx` only - CSV/TSV/TXT, a sheet chooser, column mapping, a preview and full sync shipped
      (`participant-import-preview.md`).
- [x] Bulk editor (spreadsheet-like grid of all participants: name, country, external id, player, one checkbox per
      round, team per round) - proposal in PR #241.
- [x] `RoundTableManager` Live actions check `CompetitionEditVoter` on every request and only touch rows, tables
      and spots of their own round.
- [ ] Re-import behaviours that predate the rework (review of PR #242):
      - [x] a matched soft-deleted participant is restored unless its row says `status = deleted` - now shown on the
        preview as "Restore (removed on …)" before anything is written;
      - [ ] a matched self-joined participant becomes `imported` (`markAsImported()`), so leaving the event later only
        disconnects them - re-importing an export does this to every self-joined participant on it;
      - [x] a player who left (their self-joined row is soft-deleted and never matched) was signed up again by an old
        file - such a row is now skipped with a message.

## Organiser fixes 2026-10-07 (Ou La La Puzzles report, `docs/features/competitions-management/README.md`)

- [ ] Prod: "Ou La La SPC No. 17" exists twice - the duplicate (slug `ou-la-la-spc-no-17`, no date, no rounds) was
      invisible on the series page; it is now listed as "Date not set". Jan asks the organiser which one to keep - no
      data was changed.
- [ ] Two saves taking the same slug at the same moment: the handler re-check catches a save that finished in between,
      a truly simultaneous one fails at flush with a unique violation (series, editions) = 500. Standalone events have
      no DB constraint at all (`series_id` NULL) - consider a partial unique index `custom_competition_standalone_slug`
      on `competition (slug) WHERE series_id IS NULL` after checking prod for duplicates.
- [ ] `AddEditionHandler` still generates its own slug unique only within the series (could equal a standalone event's
      - harmless since `/en/events/{slug}` prefers the standalone event, but `CompetitionSlugGenerator` should do it).
- [ ] Missing translation keys seen on the way (not competition pages): `collections.no_move_target_member`
      (`collections/move.html.twig`), `wish_list.remove.title` and `wish_list.already_in_wishlist`
      (`wishlist/add_item.html.twig`).

## Round time zones and secret-puzzle reveal (`docs/features/competitions-management/README.md`)

- [ ] With the deploy, right after it: `myspeedpuzzling:backfill-round-puzzle-reveals` (dry run), read the list, then
      `--write` - until then old rows have `hides_everywhere = false` and a round move moves only the event page.
- [ ] Wisconsin State Jigsaw Puzzle Championship 2026: confirm the 4 round times with the organiser, set the
      rounds' `timezone` to `America/Chicago`, re-sync the 2 Team Relay secret puzzles (SQL in the PR description).
- [ ] Rename the random (hex) image names of secret puzzles to SEO names once revealed or approved.
- [ ] Existence signals of secret puzzles still open (nothing of the puzzle itself, but they tell that something is
      there): `MergeUnapprovedPuzzleController`
      `puzzleExists`, results counts and the edition's `puzzle_count`, the image aspect ratio while hidden, `/me`
      predicted time answering 200, the add-time EAN lookup no longer finding an image-only secret puzzle (a player may
      add a duplicate).
- [ ] Prague-formatted dates still in `events.html.twig:68` and the edition/event detail meta descriptions.
- [ ] Deleting a whole event or series does not ask before revealing secret puzzles other events hold (round deletion
      and puzzle removal do - `SecretRevealPreview`).
- [ ] `GetCompetitionEditions` is dead code - remove.
- [ ] `SecretPuzzleHides::isStoredFor()` runs one query per row - fine at today's sizes.
- [ ] The images cache (nginx in front of imgproxy, 365 days) still serves thumbnails requested under a secret
      puzzle's old guessable image name after the backfill moved it - there is no purge endpoint; delete the cache
      files of those keys on the box if they were requested.
- [ ] A puzzle removed from its round while on a manual reveal stays hidden with no end - its adder finds it in the round
      picker again, otherwise an admin clears `puzzle.hide_until` / `hide_image_until` on request.
- [ ] Nothing new is recorded on a secret puzzle before its reveal (`SecretPuzzleAccess::assertWritableBy()`), but
      records made before it became secret stay - a puzzle taken over by "Keep it hidden everywhere" or by the backfill
      may already have times, collection items or listings, and feeds, profiles and the marketplace show its name with
      them. Hide those records (or the puzzle in them) while it is secret, if one ever turns up.
- [ ] The add-time form and the stopwatch tell an organiser up front that a secret puzzle takes no time yet; the
      collection / wishlist / sell-swap / lend buttons on its page still say it only on submit (flash or modal).
- [ ] With the deploy: purge the old guessable image names the backfill prints from the images-cache and Cloudflare
      (commands in the PR's production plan). Later renames (a puzzle becoming secret) are rare - same commands by hand.
- [x] "Something went wrong" in multiscan for a row whose puzzle became secret meanwhile - say "no longer available".
      Shipped 2026-10-08: taken out of the tray with "no longer available"; a puzzle merged away meanwhile becomes the
      survivor, a deleted one an unknown code (`docs/features/multiscan/README.md` §5).
- [ ] Multiscan answers a code only a hidden puzzle carries with its generic "could not be added / linking failed" (since
      2026-10, no more "already assigned" - that told a secret box has the code). A player may retry in vain; once the
      puzzle is revealed it resolves normally.
- [ ] A guessable picture name a change request's snapshot (`puzzle_change_request.original_image` / `proposed_image`)
      still references is kept when its puzzle becomes secret (warning "Old guessable picture of a secret puzzle kept").
      Resolve by hand: point the snapshot at the puzzle's new image (`UPDATE puzzle_change_request SET original_image =
      <new> WHERE original_image = <old>`), then delete the old object and purge it from the caches (PR #240 step 3b).
- [ ] "Shown by another surface" for turning a non-secret round puzzle secret is read as "another round shows it now"
      (`RoundPuzzleOwnership::sqlShownByAnotherRound()`) - a public catalogue puzzle may still be hidden on the event
      page before its round starts, as when adding it. Revisit if that should be refused too.
- [ ] Not in the internal API: changing a reveal, Reveal now, "Keep it hidden everywhere", attaching a secret puzzle
      (refused - its reveal is chosen on the round's page).
- [ ] The coordinator saw America/Chicago twice at the end of the zone select; the server renders it once and TomSelect
      moves the selected option to the end of the native select - not reproduced in Chrome 2026-10-06, re-check on a phone.

## Large photo uploads (Sentry WEB-D4, 2026-10-06)

A 54 MB phone photo on the add form went over PHP's `post_max_size` (50M): the whole request was dropped and the
player lost the form. Shipped: limit 128M (`web-base-php85/php.ini`), photos up to 60 MB accepted and shrunk by
`ImageOptimizer` (`PhotoUploadLimits`), and `submit_prevention_controller` takes out a photo still above the limit
(with a note) instead of sending a request PHP would drop.

- [ ] Find out why the browser did not shrink the photo (Samsung Internet, Chrome 143 base): `compressImage()` decodes
      the full image into an `<img>` first - a 200 MP shot may be too big to decode on a phone, a HEIC cannot be
      decoded outside Safari. Try `createImageBitmap(file, {resizeWidth, resizeHeight})`, and a beacon when
      compression fails, to see how often it happens.
- [x] Traefik's default `readTimeout` is 60 s for the whole request, body included (lily's `traefik.yml` sets none):
      a 60 MB photo that the browser could not shrink needs ~8 Mbit/s upload to make it. Raise it on `websecure`
      (shared edge, all apps) or accept that the fallback only works on a fast connection.
- [ ] The other photo forms (Suggest a change, puzzle record, avatar, competition logo) have no submit-time
      compression or guard yet (part of GitHub issue #266) - they only take small files, but the same body-limit loss applies above 128M.

## Events through the internal API (`docs/features/internal-api.md` §Competitions and events)

- [ ] Shared tags: a competition whose tag other competitions/series carry too answers `PUT …/puzzles` with 409. If
      that happens on production (e.g. one "WJPC" tag for several editions), decide: give each competition its own tag
      (and keep the old one for the badge), or let the endpoint fork the tag.
- [ ] Not in the API yet: the competition logo (upload), series and editions (`AddCompetitionSeries`, `AddEdition`),
      rejecting a competition, a round puzzle's "hide until the round starts" setting (new ones are attached
      unhidden), maintainers by player code instead of id.
- [ ] Puzzle search leaves out secret competition puzzles (`hide_until` in the future), like the site - an admin
      entering a future round with a secret puzzle has to know its id.
- [ ] `AddCompetitionHandler` still calls `flush()` itself (older than the flush rule) - drop it once nothing depends
      on the competition being flushed before the e-mail.

## Barcode scanner (`docs/features/barcode-scanner/README.md`)

- [x] Step 0 (2026-10-06): the browser's own detector is back on Android. zbar alone had cost about half of the Android
      scans (add-form lookups 169 → 92 a day, iOS flat). The plan with Steps 1-4 is in the README.
- [x] Vanja's test (2026-10-18, Galaxy S25 + Samsung Internet, Christmas Town `4005555017585`, MSP #293 / F128):
      both readers read it; Google's still saw the parity misread ×6 and `4005555017479` ×1, zbar nothing wrong
      (README §5.7). Led to both readers side by side.
- [x] 2026-10-13: camera fixes in one release (README §5.6): 1080p, main lens on Android, 1.5× default zoom, focus
      kicks, torch, cropped zbar decoding, `?scan-debug=1`; the zoom pill no longer hides behind the video.
- [x] Stephanie (Galaxy S24, Chrome, multiscan): both readers read her Ravensburger `4005556173501` after the fix,
      the right puzzle every time we could reconstruct (Tempo body sizes), nothing misread stored. Her screenshot lost
      the panel's end under the pinned action bar → read lines first + a Copy button (2026-10-18).
- [x] Tempo recount 2026-10-18 (`/puzzle-by-ean-search/` per UTC day, requests / IPs): Android 79/52 on 10-05 →
      150-210/75-86 after Step 0 (back above the old 169), 160-230/84-95 after the camera fixes (no regression, no clear
      second jump; 10-17's 422 includes one IP with 159). iOS flat at 113-226. Tempo cannot tell what was read in
      multiscan (the code is in the Live POST body) - the beacon (Step 1) is still the only way to measure reads.
- [x] Step 1 (2026-10-18): misread correction in the scanner lookups + `/-/scan-session` reports (README §5.1).
- [x] 2026-10-19: Google's reader and zbar side by side on Android (README §5.7, `assets/barcode_acceptance.js`);
      the same night zbar became a helper with a time budget after a Galaxy A33 slowed from 83 to 418 ms a frame.
- [ ] In the reports: phones where `zbar.max_backoff` reaches 16-32 (zbar practically off - they read like before,
      without the misread guard) - candidates for zbar in a Web Worker (README §5.3), which takes it off the main thread.
- [ ] After ~5 days of `/-/scan-session` reports: read rate, time to first read and the models that never read, per
      platform (Android Chrome, Samsung Internet, iOS) - Loki, log channel `barcode_scanner` (on lily also
      `docker logs` of the web/api containers). The reports of 2026-10-18 evening are Google-only on Android, from
      2026-10-19 both readers.
- [ ] ~2026-11-02, from two weeks of side-by-side reports (README §5.7 "Decision"): in how many Android sessions that
      read did zbar see the accepted code (`zbar.first_seen_ms` not null), how much later than Google's reader, which
      phone models only Google's reader read, and how often Google's reader saw another code (`seen`). Then decide:
      Android drops Google's reader (one path everywhere), or both stay until Step 4.
      Also compare before/after a58edf419 (2026-10-19 ~17:30 UTC, zbar's budget allows milliseconds on top of the
      ratio): `zbar.max_backoff` per phone and the time from `native.first_seen_ms` to `first_read_ms` - before it,
      0.8 s at backoff ≤ 2 vs 2.1 s at 16-32x (261 sessions). Fast phones should now sit at ≤ 2 while Google's reader
      keeps its `decode_ms`; a mid-range phone whose `native.decode_ms` grew against its `baseline_ms` means too loose.
- [x] 2026-10-19: zbar from our own server, jsdelivr as the fallback (README §5.9). Check `zbar_source` in the
      reports: practically all `self`; `zbar_failures` = `self` would mean our copy fails somewhere.
- [x] Scanner heat (Allison, iPhone Pro, multiscan, 2026-10-18 - MSP #321 / F143): every session decoded every frame
      nonstop; 2026-10-19 the scanner rests at ≤ 10 decodes/s while no code is in view (README §5.8).
- [ ] Check the rest mode in the reports (`frames` / `duration_ms` of long iOS sessions, ~10/s idle) and ask Allison
      whether her phone stays cooler - if it still gets hot, the 1080p camera itself is next (a lower resolution while
      resting would need a camera restart - measure first).
- [ ] When no zbar source loads (8 s each since README §5.9), iOS shows a camera that never reads - say so in the
      scanner and point to "Type a code" (texts in 6 locales).
- [ ] A camera that fails to open (permission denied, no camera) sends no `/-/scan-session` report - count them too
      (outcome `camera-error` + the error name).
- [x] 2026-10-19: zbar is no longer held up by its own one-off misreads (a competitor must be read twice in 2 s) -
      Allison's Anatolian Route 66 was read from 0.8 s but accepted after 3.6-11.6 s, up to a minute before.
- [ ] Angled barcodes on iPhone (Allison, 2026-10-20: "3-9 s from the red box to the puzzle, instant when lined up";
      her 8 multiscan sessions: 35 boxes, 0 wrong codes, first sight -> acceptance 0.1-7.4 s). The red box is drawn
      for every zbar read, but only reads with quality >= 3 (scanlines) count, and a slanted code crosses few
      scanlines. Ideas, each to measure first: report how many reads had quality < 3 (now `seen` keeps only the last
      quality); a "hold it straight" hint when a code keeps being seen at quality < 3; zbar on a rotated copy of the
      band now and then. Not the threshold itself - it is what keeps zbar's one-off misreads out.
- [ ] The debug panel's "Copy" text arrives URL-encoded from Chrome on iOS (Allison's paste, 2026-10-19: `engine:%20zbar…`)
      - check `navigator.clipboard.writeText` there; readable after decoding, so low priority.
- [x] Global search scanner layout on iPhone (Jan, 2026-10-19 screenshot): the video is narrower than the box and
      centred while the guide corners sit at the box's edges - make the video fill the width (`object-fit: cover` on
      a full-width video, as in multiscan) so picture, guide and decoded band line up.
- [x] The scanner HUD's zoom − / + buttons are not round on iPhone (`_barcode_scanner_hud.html.twig`) - 32×32 now. Every scanner shares `_barcode_scanner_viewfinder.html.twig` (multiscan `compact`), global search with `data-live-ignore` (2026-10-19).
- [ ] Labels without a quiet zone: Anatolian boxes (white label tight around the bars on a black box, e.g.
      `8698543136342`, `8698543135796`) barely read or not at all - zbar needs the white margin to find the start.
      A test case for Step 4's decoder (it fits geometry, needs no quiet zone); her photo is in MSP inbox:8800.
- [ ] Step 4's shadow mode can reuse the side-by-side frame loop (`scanLoop()` runs any number of readers).
- [ ] *The World of Trolls* (`01947b9c-24c6-7359-972a-54f6a6d86402`) stores `045570100330`, most likely a misread of
      Ravensburger `4005556100330`. Check a box or a shop listing, then file a change proposal.

## Codes in the forms (`docs/features/puzzle-names/README.md`, phase 5)

- [x] `myspeedpuzzling:canonicalize-puzzle-codes` on production (2026-10-05): 1,175 EAN + 157 brand-code fields
      written, search keys unchanged, undo file `/root/codes-20261005T084536Z/codes-undo-20261005T084536Z.csv` on the
      box (`\N` = NULL); 304 of the report's 316 puzzles with a fix filed as change proposals (one per puzzle, both
      lists). Jan's call: the technical, certain ones approved by Claude right away (94, + 4 a moderator approved
      first); the other 165 (plain numbers, Amazon codes - Jan: moderators decide -, unknown formats) wait in the
      queue.
- [ ] File the other 12 once their pending merge / change request is decided (the API answered 409):
      `.claude/worktrees/pn-delivery-state/codes-cleanup/file/refile-409.json` (local to Jan's Mac) - re-read each
      puzzle first, the proposal replaces both whole lists.
- [ ] The 126 puzzles the report has no fix for wait for a person with the box (`codes-cleanup/file/nofix.json` next
      to it): 65 barcode lengths with a wrong check digit, 60 brand codes with words in them, 1 comma between digits.
- [x] One release after phase 5: `CodeListType::acceptLegacyFields()` and its five calls removed (2026-10-05).

## Codes help and check-digit aliases (`docs/features/puzzle-names/codes-help-and-check-digit.md`)

- [x] Release runbook on production (2026-10-05): dry run 8,944 keys gain an alias (only added `c:` lines, report
      `/root/search-key-aliases-20261005.csv` on lily), the run 5.5 s, a second dry run 0; "Finding Concentration"
      found by `120020288`, `12 002 028 8` and `12002028`, the barcode lookup still exact. Nightly
      `rebuild-puzzle-search-keys --alert-on-drift` at 05:16 (lily.srv bc00ef5).
- [ ] The "What is it?" help also on "add puzzle to a round" and "Suggest a change" (`puzzle/_code_inputs.html.twig`).
- [ ] A puzzle without a valid EAN gets no alias: `120020288` does not find a stored `12002028` there (an unchecked
      fallback would hit near-miss codes).
- [ ] Global search highlights nothing when an alias matched (`components/GlobalSearch.html.twig`, the shown code
      differs by a digit).

## Puzzle names - catalogue cleanup (`docs/features/puzzle-names/README.md`, phase 4)

- [x] All filed on 2026-10-05 (Jan: everything at once, approve right away what is certain): wave 1 (45 merges,
      54 splits, 3 swaps - in the queue), 401 high-confidence language tags (397 read by hand and approved, 4 spelled
      alike in another language left to moderators), 128 medium/low-confidence ones for moderators (19 had no
      language to propose). Tool, research and logs local to Jan's Mac: `.claude/worktrees/pn-delivery-state/phase4/`.

## Puzzle names on pages and in search (`docs/features/puzzle-names/README.md`, phase 2)

- [ ] 6-8 weeks after phase 2 shipped (2026-10-05, so 2026-11-16 to 2026-11-30): Search Console index coverage per
      language (`/puzzle/…` cs, `/de/puzzle/…`, `/fr/…`, `/es/…`, `/ja/…`) - do the locale copies of puzzles with a
      name in that language leave "crawled - currently not indexed" more than the others? Compare with the numbers
      before the deploy (`docs/features/seo/research-2026-09.md` §4.1, §9).

## EAN codes in the catalogue (2026-10-04)

- [x] "Suggest a change" refuses codes that are not EAN/UPC codes, each one of a comma-separated list
      (`Value\EanList`); codes the puzzle already carries pass, so legacy junk never blocks another change.
      Ravensburger `4005555…`/`4005556…` typed without its two zeros (`45555…`) is refused with the full code suggested.
- [x] Same check on the add-puzzle form, for a new puzzle only, with a Clear button; the photo stays (2026-10-04).
- [x] 7 of the 11 stored `45555…` codes: change proposals filed via the internal API (`puzzle-change-proposal` skill,
      `01a106fe-…`, 2026-10-04) - waiting for review.
- [ ] The other 4 need a person: *Library at St. Florian* (Barb's proposal `019e9cfd-de9e-7176-a416-f46f467ad92a` pending
      since June - fix the EAN while deciding it); *Star wars the man* (pending merge into The Mandalorian and Grogu
      `019ea35e-e36f-7026-be51-c6ce7b24739e` - drop `45555018124` from the merged EAN); *Have dog, will travel #1* (merge
      `01a08232-606a-7187-884c-36dc111b9382` misses a third record `019fc37a-0a6c-722b-b07c-344caf3a7d72` holding the
      full code); *Extinct Giants* (`045556130443` - its full code is on *By the River* `01934f99-de98-71e9-91e4-232499596903`).
- [x] The 12 pending change requests that changed only the EAN, to an invalid code, rejected via the internal API
      ("The proposed EAN is not valid EAN barcode", 2026-10-04).
- [ ] 4 pending ones carry an invalid code but change other fields too - manual review
      (`019df4f1-d64d-7179-b8ef-0e5ab1f77b56`, `019e4530-f7ca-72a3-b365-c2df422784e1`, `019e98f1-dc2a-7225-9842-257ab9cd377e`,
      `019ea2a3-e359-724c-9327-76f86c03c125`).
- [x] Where the `45555…` form comes from: Android's built-in barcode detector misreads `4005555…` as `0045555…`
      (a valid code, stored without leading zeros). Vanja reproduced it on her box, 8 of the 9 players scan on Android.
      The scanner used our zbar on every platform from 2026-10-04 (`docs/features/multiscan/README.md` "Decoder"). It
      went back to the browser's detector on Android on 2026-10-06, see the Barcode scanner section.
      Matilda's Aurore `778649925052` is the same misread of `3770039925052` - not a real code.
- [x] Ask Vanja or Matilda to scan one of those boxes again after the deploy (Android) to confirm the fix. Vanja
      (2026-10-05): with zbar her phone read nothing ("the camera couldn't focus properly").

## Players page (`docs/features/players-page/README.md`)

- [x] Cron row for `myspeedpuzzling:recalculate-community-stats` in `~/www/lily.srv` (`14-59/15`, lily.srv b3ea25c);
      first run by hand right after the deploy: 3.37 s, 11,493 players, 85 scopes, 1,334 moments (2026-10-03).
- [x] Translations of `players.*` into cs, de, es, fr, ja; the dead keys of the old page removed (2026-10-03).
- [ ] Leaderboard "Where you stand": best-time distribution per country with your marker (line for every signed-in
      player, chart for members, like the puzzle page).
- [ ] Hub rework (Jan, 2026-10-03: catch-up + trending + recent activity of all sorts, digests of other sections,
      likes/comments later): "See all" from Hub people widgets into Players, `player_moment` rows as feed items.
- [ ] Player card on leaderboards, the feed and puzzle pages (avatar/name taps; a puzzle leaderboard row tap stays the
      result detail).
- [ ] Measure after a few weeks: Players visitors per day against the 53 baseline (Tempo), Favorite/Compare started
      from the card, the share of new sign-ups with a country.
- [ ] Remove the "New" badges from the menu and footer Players links once the page is not new any more.

## Marketplace at events (`docs/features/marketplace/11-events.md`)

- [ ] Phase 2 (#228): "Going to events" panel on sell/swap lists (own: edit per event; visitors: read-only, links to the
      filtered marketplace) and a bag icon next to sellers in an event's participant list.
- [ ] Phase 3 (#229): notify a buyer when a puzzle from their wishlist is coming to an event they go to; tell a seller how many
      people going have one of their listed puzzles on the wishlist (count only); e-mail 3 days before the event.
- [ ] Links are kept after the event (decision) - nothing reads them yet ("Brought to WJPC 2026" history is an idea).
- [ ] Measure after the spring 2027 season: sellers marking per event vs sellers going, "Ask to bring it" conversations,
      listings sold to a buyer who went to the same event (`sold_swapped_item` + participants).
- [x] Event pages still format dates with `_event_date_range.html.twig` ("10.-11.10."); the new labels use the
      locale-aware `event_dates()` - unify the event pages one day. (Done by the detail pages redesign: the event,
      edition and series pages use `events_date` / `events_date_range()`; only the manage pages keep the partial.)
- [ ] Marketplace filter panel: the Sort select is squeezed to ~90 px ("Ne…" for "Newest") on desktop - pre-existing,
      seen during the review (also on `main`).
- [ ] German pre-filled buyer message uses the site's formal "Sie" - between hobbyists "du" may read warmer; Jan to decide.

## Compare line-ups (`docs/features/player-comparison.md`)

- [ ] Guests can't open a shared comparison link (sign-in first). Consider a public read-only view if members share
      links outside the site.
- [x] Caps are not race-proof: two simultaneous adds can exceed a cap by one - `AddComparisonSubject` is
      `SerializedByLock` per owner now.
- [ ] Scatter chart scale follows the longest time; one marathon solve squeezes the rest - consider clipping at p95.
- [ ] "Puzzles they solved that you haven't" (show = all) could link to the wishlist - idea from the review.
- [ ] Measure usage after a few weeks (line-up sizes per kind, views, similar-speed rolls, share links opened).
- [ ] Charts tab at 320 px: the head-to-head grid card pushes the page ~60 px sideways with 7 subjects (measured
      2026-10-03; `.cmp-chart` / `.cmp-h2h-wrap`). Also `.cmp-h2h` is two things: the head-to-head card
      (`_comparison.scss`) and the charts' grid table (`_comparison-charts.scss`) - the card's padding lands on the table.
- [ ] Site topbar at 320 px: the language/feedback row overflows by 15-35 px in de/fr/es/ja on every page (not
      compare-specific; measured on the homepage 2026-10-03).
- [x] Panther suite red (found 2026-10-03, fixed the same day, 62/62 green). Only one test was about the launcher,
      and it was test-only: WebDriver scrolls an element just to the window's edge, under the pill (or the sticky
      header at the top) - people scroll it clear, every page has room for the pill under the footer. Cards are now
      opened through `AbstractPantherTestCase::openCardMenu()` (card in the middle of the window, real click). The
      SystemCollection failure was a **real bug**: "Borrow from player" on a collection page answered 500 after
      saving the borrow (stream rendered without the card) - fixed, `BorrowPuzzleControllerTest`. PuzzlesTest raced
      the debounced Live search (`searchPuzzles()` waits for the whole query's render).

## Player header (#224)

Shipped: [`features/player-header.md`](features/player-header.md).

- [ ] **API vs web (Jan's call):** `/api/v1/me/followers` (`GetPlayerConnections`) still lists private followers the
      viewer may not see, masked but by `#CODE`; the favorites page only counts them (`GetFavoritePlayers::followersOf`).
      Align the API (count only) or accept the difference
- [ ] The favorite toggle is still a GET link (`ToggleFavoritePlayerController`, `rel="nofollow"`, no CSRF) - a POST
      button would be the honest form now that it is a primary action on 12 pages
- [x] Found while screenshotting, not caused by #224: `onboarding.checklist.progress` was overwritten by the free trial
      (`cf819693`) with `%logged%`/`%required%` in en, cs, de - restored, guarded by `HubControllerTest`
- [ ] Found while screenshotting, not caused by #224: the site topbar (`.topbar-text.text-nowrap`) is wider than
      320 px in German and for admins - the page scrolls sideways there

## Transactional e-mails

Shipped: [`features/transactional-emails.md`](features/transactional-emails.md).

- [ ] **Reply-To (Jan's decision):** the footer invites questions, but replies to `robot@mail.` / `notify@notify.`
      go nowhere anybody reads - pick the mailbox (e.g. simona@ or jan@) and set `Reply-To` on the transactional and
      notification e-mails (the newsletter already sets `Reply-To: jan@myspeedpuzzling.com` per campaign)
- [ ] Inky `<spacer size-sm>` never shows anywhere (its `hide-for-large` class inlines `display:none` and the mobile
      rule that would show it is not in `email_document`); spacing relies on paddings - replace them with `size` or a
      plain spacer table when touching a template

## Duplicate results (#221)

Plan: [`features/duplicate-results.md`](features/duplicate-results.md).

- [ ] "Possibly saved twice" marker in the player's results list (`PlayerSolvedPuzzles`) for results with an open
      case - left out of P4 while those templates are being reworked
- [ ] "Your results" backlog (491 planned 2026-10-02, sending since ~16:00, one e-mail a minute around the clock,
      crons live in lily.srv) - after the first day
      watch bounces/complaints, the reaction rate and the folder at Gmail / iCloud / Seznam (admin "Contacts"); lower
      `RESULT_REVIEW_EMAILS_PER_RUN` or raise `RESULT_REVIEW_EMAIL_SPACING_SECONDS` on the box if it goes badly
- [ ] Admin overview: prevented saves (re-sends caught, "saved anyway" after the warning) next to the monthly trend -
      the doc's success measure; no query reads `result_duplicate_prevention` yet (left out of the 2026-10-08 redesign,
      which was presentation only)

## Live activity feed (Hub + Recent activity)

Shipped: [`features/live-activity-feed.md`](features/live-activity-feed.md).

- [ ] A week after deploy: compare `RecentActivity` re-renders in Tempo with the baseline in §1.2 (7,281 polls
      Thu 12–24 UTC, 91 % from tabs open 3+ hours)
- [ ] Report upstream (symfony/ux): a re-render whose `fetch` rejects leaves `Component::backendRequest` set, so the
      component ignores every later render on that page; drop the workaround in `live_refresh_controller.js` once fixed
- [ ] `mercure_hub_controller.js` (unread counts, chat) has no recovery: on iOS 18 the stream can stay "open" but dead
      after resume, a non-200 answer closes it for good, and the open stream keeps signed-in pages out of Chrome's
      back/forward cache - close on `pagehide`, reopen on `pageshow` / visible, reconnect with backoff (§10 of the doc)
- [ ] Before a competition with thousands watching: push rows rendered once per locale through Mercure instead of
      per-viewer refreshes, after a load test through Traefik (§6.1 of the doc)

## Result detail modal + unified ranking rows (#222)

Shipped: [`features/puzzle-result-detail.md`](features/puzzle-result-detail.md),
[`features/puzzle-leaderboard-chart.md`](features/puzzle-leaderboard-chart.md) §"Row layout".

- [ ] Translate the new `puzzle_result.*` and `puzzle_times.leaderboard.*` keys (English only so far) + the `loading`
      spinner label (English only from before)
- [ ] Real-device check after deploy: the phone sheet, iOS swipe-back and the Android back button closing the modal
- [ ] Click through the signed-in edit-time and feedback modals after the `dynamic_modal_controller.js` rewrite
- [ ] Editing a time from the result detail opened on the profile lands on the puzzle page afterwards, not back on the
      profile (edit context `puzzle-detail`)
- [ ] The modal's rank is always the unfiltered leaderboard rank - with a country / first-try filter on the puzzle
      page it can differ from the row's rank
- [ ] After a link out of the modal, the page shows up twice in history (Back from the next page, then once more on
      the same page) - accepted trade-off of pausing Turbo's history, see the hotwire guide
- [ ] Profile pair/team tabs count distinct puzzles while their rows are per puzzle *and* pair - "Pair (1)" can have 2
      rows; decide whether the tab should count rows
- [ ] Older, found on the way: `templates/puzzle/brand_hub.html.twig` uses `column-gap-*`, which Bootstrap 5.2 does
      not have (no-op)

## Leaderboard chart switch (distribution / rankings)

Shipped: [`features/puzzle-leaderboard-chart.md`](features/puzzle-leaderboard-chart.md) §"Chart switch".

- [ ] Click the switch as a member in a real browser (dev is not signed in as one): both ways on London Postcard, zoom +
      reset in the rankings, tooltips in the distribution, no console errors. Both views and a short board were checked
      on screenshots of rendered pages (375 px + 1,280 px, 2026-10-02); the Live switch itself was not clicked yet
- [ ] Rankings on big boards ship 118 KB of chart JSON with every re-render (London Postcard); if members use it a lot,
      send a `firstAttempt` flag array and map colours in JS (~ -40 KB)

## First-try integrity (#217)

Shipped: [`features/first-try-integrity.md`](features/first-try-integrity.md).

- [x] Hub banner for players with first-try conflicts - part of the "Review your results" banner since duplicate
      results P4
- [ ] Remind about unresolved first-try conflicts in the digest e-mail
- [ ] A few weeks after the deploy: how many of the 3,170 duplicate pairs (2026-09-30) got resolved - decide whether
      the rest needs another nudge
- [ ] Object-storage lifecycle rule for `tmp-uploads/` (24 h) as a second safety net next to the
      `myspeedpuzzling:prune-photo-stash` cron, if Hetzner Object Storage supports one

## FrankenPHP worker restarts (found 2026-09-30, session research)

- [ ] The `max_requests 500` guard against memory leaks probably never fires: FrankenPHP 1.12.7 resets its request
      counter every time the Symfony worker script restarts, and that script restarts every 500 requests too (locally,
      both limits at 10: 100 requests → 0 thread restarts; script limit 1000 → 9). If the guard matters, set
      `FRANKENPHP_LOOP_MAX` above `max_requests`. Never pair it with a persistent PDO connection: FrankenPHP does not
      close those on a thread restart (reproduced: 2 threads, 9 restarts, 11 connections left) - the session handler
      deliberately uses the worker's Doctrine connection instead.

## Insights page (`docs/features/insights/page.md`, Phase 6)

- [ ] "Expected for you / Prediction as shown" switch from the prototype (6a built only the calibrated view)
- [ ] Median histogram: median and "your usual" marker lines (6a colours the bars faster / slower instead)
- [ ] Sticky filter bar - collides with the player header's compact bar today; needs one shared sticky stack
- [ ] `_insights.scss` (~1,100 lines) lives in the global `app.scss`; move it to its own Encore entry via
      `page_stylesheets` once the page is public (needs `docker compose restart js-watch` on dev)
- [ ] `recalculate-puzzle-intelligence` on production is 25.4-25.8 s (2026-10-19, after 3a + 4a + 3b; was 16-18 s
      before Insights, plan budget ~+8 s): profile the steps and trim before more is added to the batch
- [ ] Personal estimates (`docs/features/insights/estimates.md`, 6c): estimated tiers on the other thumbnail corners
      (profile results, recent activity, ladders, most solved, round results, result detail, calendar) - only the
      marketplace cards carry them; `ResolveDifficultyTiers` needs its own estimated-tier read
- [ ] Personal estimates: no line on the solved, sell/swap and lend/borrow lists (not in the plan's list) - decide
- [ ] Personal estimates: a list row whose puzzle has a tier but no time (no pace at that size) shows no line and so no
      estimated tier
- [ ] Personal estimates at the launch (§8): remove the "while hidden" branches (Insights panel prediction block,
      Picker card prediction row, collections "+ predictions" pill, the `GetPlayerPrediction(s)` calls they need -
      `feature_flags.md`), and decide whether the Picker's budget / gap filters read picture estimates at k = 0
      (measure the Picker then)

## Insights recalculation writes only what changed

Shipped 2026-09-30: [`features/puzzle-intelligence/README.md`](features/puzzle-intelligence/README.md) §"Writes: only what changed".

- [ ] After the deploy: WAL per day on lily and `n_tup_upd` of the eight insights tables, against the 2026-09-30
      research (~13 GB/day, 43 % of all WAL, ~111k row updates per run)
- [ ] `player_rating_snapshot` has no reader, grows by 17.5k rows a day (575 MB on the 2026-09-25 copy) and is now most
      of the recalculation's WAL (2.2 GB on a replayed day): the first run of every day inserts 17.5k rows into four
      indexes of a 2.7M-row table (82-151 MB), and every run updates the snapshots of the players whose rating or skill
      moved. Decide: keep as is, one row per day written once, room for HOT updates (fillfactor), or drop the table
- [ ] `ImprovementRatioCalculator` orders a player's solves of one puzzle by `COALESCE(finished_at, tracked_at),
      tracked_at` only, so equal timestamps come back in plan order and a few "4+" ratios differ between any two runs
      (now: rewritten, before: invisible). `pst.id` as the last tie-breaker (also `buildTransitionsCte()` and
      `PredictionReconstructor`) makes it deterministic, changing those few ratios once
- [ ] `myspeedpuzzling:recalculate-puzzle-intelligence --player=UUID` removes every other player's baselines, skills,
      ratings and improvement ratios (and so most difficulty scores) until the next full run - its cleanups delete what
      the run did not produce, as they did before 2026-09-30. Scope the cleanups to that player, or drop the option

## Database indexes (2026-09-30 review)

Registry and how the numbers were taken: [`database-indexes.md`](database-indexes.md). Shipped: `custom_notification_unread`,
`custom_player_search_trgm`, two unused `puzzle_solving_time` indexes dropped. What an index could not fix radically:

- [ ] After the deploy: `pg_stat_user_indexes.idx_scan` of `custom_notification_unread` and `custom_player_search_trgm`
      is growing, and the `pg_stat_statements` mean of the unread count and the player search dropped
- [ ] `GetRanking::allForPlayer()` (profile ranking) is the web statement with the most total time: 114-128 ms mean, ~13k
      calls a day, 0.3-0.7 s for players with 100+ puzzles on production. A covering `(puzzle_id, player_id,
      seconds_to_solve)` index only gets 1.7-1.9x - the aggregate and the two window functions over every player's
      best time on those puzzles are the cost. Rewrite to "players with a better best time + 1" per puzzle, or cache
- [ ] `MostActiveSoloPlayers` this/last month: 64-80 ms, ~2.4k calls a day. A `finished_at` index only gets 1.6x
      (the joins to player and puzzle and the aggregate remain) - cache it for a few minutes instead
- [ ] Notifications page (`GetNotifications::forPlayer()`): 112 ms mean, 58k buffers a call for players with thousands
      of notifications - every branch reads all of them before `LIMIT 200`; limit inside the branches first
- [ ] `SearchPuzzle::byUserInput()`: 53 ms mean, ~13.5k calls a day. A term of 3+ characters is index-served (0.5-12 ms
      locally); the mean comes from calls without a term (library pages, filters, API: ~110 ms locally - `ILIKE '%%'`
      and the `match_score` CASE with the non-inlinable `immutable_unaccent()` run on all 41k puzzles) and 1-2
      characters (~70 ms). Skip the search conditions when the term is empty; plain `unaccent()` for short terms
      (as `SearchPlayers` does)
- [ ] Two hand-made indexes exist only on production (`custom_puzzlers_gin`, `custom_seconds_to_solve_order_asc`, both
      used): put them into a migration + `tests/bootstrap.php` with the query they serve, or drop them

## Speed check of the autumn SEO round

Measured 2026-09-30: [`features/seo/performance-2026-10.md`](features/seo/performance-2026-10.md).

- [ ] Decide on JIT for web requests: `PGOPTIONS='-c jit=off'` on the web + api containers (lily.srv), or
      `ALTER DATABASE speedpuzzling SET jit = off` - it cost 34 ms of the 83 ms London Postcard pairs query, and a query
      estimated above 500k pays another 150-400 ms (numbers in the doc)
- [ ] After the deploy: Sentry p95 of `GET puzzle_detail` for the biggest boards (London / New York Postcard),
      `GET ladder`, `GET ladder_solo_500_pieces`, `GET sitemap_players`, and the brand hubs for signed-in players
- [ ] Tracker page: take `GetStatistics::globally()` (a sum over all solving times on every request) from the
      homepage's 60 s snapshot (`HomepageStatistics`)

## Prediction history

Design: [`features/puzzle-intelligence/prediction-history.md`](features/puzzle-intelligence/prediction-history.md) (2026-09-30).
First delivery = store + backfill; the UI comes after it.

- [x] Build it: columns + live recording on add/edit + backfill command (2026-09-30)
- [x] Recap page and API `POST /api/v1/me/solving-times` read the stored prediction (fallback to computing it only
      while a back-dated time is still pending)
- [x] Backfill on production 2026-09-30: 393,074 times of 6,977 players in 27 min (first attempt OOM-killed by Sentry
      console tracing - command excluded since 7581129d)
- [x] Cron `34 1,7,13,19 * * *` Prague in lily.srv (c32e756), Sentry monitor `backfill-solving-time-predictions`
- [x] Calibration check (first numbers after the backfill): personal predictions 74 % inside their range, median
      -0.3 % (well centred); statistical only 32 % inside the p25-p75 range and players a median 9.8 % faster than
      predicted - fixed by prediction model v3 (Insights 3b, 2026-10): on a time split first tries 50.8 % inside the
      50 % window, bias -0.2 % (README "Step 8", Accuracy)
- [x] `--recompute-reconstructed` on the backfill command (Insights 3b): selects the shown predictions reconstructed by
      an older model version (no bulk reset to NULL); `live` rows stay. Production run: prediction-history.md "Production
      run for v3"
- [ ] Model v3 rollout on lily.srv (after the deploy): run `myspeedpuzzling:fit-prediction-model` once, then the
      detached `backfill-solving-time-predictions --recompute-reconstructed` (~1 h, resumable, prediction-history.md
      "Production run for v3"); cron rows `11 4 * * * myspeedpuzzling:fit-prediction-model` and
      `51 4 * * * myspeedpuzzling:report-prediction-calibration` (Prague; the report after the fit)
- [ ] Time verification on shrunk and 500-scaled predictions (model v3): the scan and the form check ignore first-try
      predictions of puzzles with 1-4 indices and those resting on the 500-piece pace scaled to a thin size
      (`pace_source = 'scaled_500'`) - they keep the expectations they had; using them would cover more first tries -
      decide, and bump `SuspiciousTimeClassifier::VERSION` with it
- [ ] Stale leave-one-out prior after a piece-count fix or a brand merge, until the next daily fit: the puzzle's own
      share (`difficulty_prior_puzzle`) is subtracted from a group it was never part of (or no longer is) - bounded
      by a day; refit on such catalogue changes or keep the share per group (review of Insights 3b)
- [ ] The fit's centre uses the pace-history row's baseline (as of the attempt), the live prediction today's
      `player_baseline` - equal for a direct baseline, an interpolated one can differ slightly; measure whether it
      matters for the window calibration (review of Insights 3b)
- [ ] `RepeatHistory` (the repeat window's fit and evaluation) does not leave out repeats of the same moment the way
      `RepeatResiduals::beforeEverySolve()` does for live/reconstructed windows (review of Insights 3b)
- [ ] Live predictions before the first fit (the minutes between the deploy and `fit-prediction-model`) still write a
      v3 side row from the defaults (only the backfill waits for the fit) - a live row is history and stays; run the
      fit right after the deploy
- [ ] Prior-only predictions (k = 0) are stored side by side only (`SolvingTimePrediction::forDisplay()`); showing them is
      Insights Phase 6 (estimates, marked as estimated)
- [ ] Live drift / spread lag the batch by up to 15 minutes (only the batch writes pace-history rows): a live prediction
      right after another solve of the same piece count misses that solve's residual; the reconstruction later has it
- [ ] `solving_time_prediction` keeps one row per solo time per predictor and model version (~460k per version): prune
      the rows of versions nobody compares any more (after Phase 5's evaluation)
- [ ] Calibration buckets of repeats and of odd / 1500+ sizes are thin: revisit `PredictionModelFitter::MINIMUM_BUCKET_SAMPLES`
      and the fallbacks after a few months of live rows (`report-prediction-calibration`)
- [ ] Per-time outcome in the player's history (puzzle page "my times", profile): range, "12 % faster than predicted", inside/outside the range, members-only, hidden for players who opted out of predictions (stored anyway), decide for unboxed/suspicious times
- [ ] Mark `reconstructed` predictions in the UI ("reconstructed from your earlier solves") vs `live`
- [ ] Player-level accuracy: how often inside the range, average beat, trend over time (Insights section)
- [ ] Ideas for later: "beat the prediction" streaks/badges, API fields on result rows (additive: `prediction`, `faster_than_predicted_percent`)

## Pair/team predictions (`features/puzzle-intelligence/README.md` "Pair and team predictions")

- [ ] After the deploy: cron row `56 4 * * *` for `myspeedpuzzling:fit-group-prediction-model`, first fill with
      `--rows`, then `PREDICTION_GROUP_ROWS=1` (prediction-history.md "Production run for the pair/team rows")
- [ ] Once a few thousand live rows exist: `evaluate-prediction-candidate --candidate=group:1 --reference=group_baseline:1`
      and read the live slice
- [ ] Windows still too narrow out of period for picture-only difficulty (44-45 / 74-76 %, bias +2 %) and at events
      (45 / 74-75 %); puzzles with no difficulty at all are now scaled like picture-only ones - re-measure on a split
- [ ] The difficulty is the shown rule as of the solve; try the candidate v4 posterior when v4 is shown (expected small:
      a few thousandths against a pair's/team's 0.11-0.18 error)
- [ ] Whether pair/team predictions are ever shown (add-time recap, pair/team page) - Jan's decision; nothing reads them now

## Image estimates in the predictions (Insights 5d, `features/insights/image-estimate-gates.md`)

- [ ] lily.srv: a daily cron row for `myspeedpuzzling:evaluate-image-estimates` (after the 4×-daily prediction backfill,
      e.g. `5 8 * * *`) once Phase 6c shows estimates - until then run it by hand
- [ ] Before the launch decision: final derive → `backfill-solving-time-predictions --recompute-image` (detached, ~1 h)
      → `evaluate-image-estimates` (prediction-history.md "Production run for the image rows")
- [ ] The image predictor's window = the estimate's interval as the puzzle term with the statistical k = 0 quantiles; if
      the window gate fails on the real backfill, fit a calibration table of the image rows' own (like
      `PredictionModelFitter`'s first-try buckets) rather than loosening the gate
- [ ] Gate v2 (2026-10-27): honesty is the binding rule on production (500 flat 50 % window 0.478 out of period, 500 box
      80 % 0.837) - the picture-only window's hit rate drifts ± 5 points between periods. Levers, side by side first:
      a recency-weighted image scale fit, heteroscedastic estimate intervals (image review P4)
- [ ] Jan (plan B1): show the tier as a range? The 80 % interval's tier range holds the measured tier 93 % but spans
      2.55 tiers on average - a coverage rule joins the gate only if the range is what members see
- [ ] Gate a candidate time model with `myspeedpuzzling:evaluate-prediction-candidate --candidate=statistical:4
      --reference=statistical:3` once its side rows exist (the launch `MODEL_VERSION` bump's proof)
- [ ] No estimate history: a reconstruction uses the out-of-fold estimate current when it runs (the derive re-pushes
      nightly). Store estimate history only if a gate decision ever needs "the estimate as of the solve"
- [ ] `puzzle_difficulty.blend_prior_log` / `difficulty_prior_puzzle.blend_prior_log` have no reader yet - the Picker and
      bulk readers of Phase 6c; and `ShelfPicks::estimates()` (computation.md §4c.7) is 6c's seam for picture-only shelf picks
- [ ] Whether the shown statistical prediction should shrink toward the image prior at k = 1-4 once the gates pass
      (research: 0.133 → 0.124 at k = 1-2) - a model change: bump `TimePredictionCalculator::MODEL_VERSION`, never
      switch it by the gate record
- [ ] Root cause of stale side rows: a duplicate merge (`migrateToPuzzle()` keeps the prediction), `moveToPuzzle()` and a
      solve back-dated in front of others never re-evaluate the later times of the same puzzle - their stored statistical
      row still says "first try" (or a stale personal prediction). The image work list now closes such times with
      "not predictable" rows (143 on the dev copy); re-evaluating the affected later times on merge / move / back-dated
      add would fix the statistical rows too
- [ ] The backfill work list's image condition costs ~1.2 s over 523k times with an estimate for every puzzle (the
      "nothing to do" cron ~0.7 → ~2 s); watch the Sentry monitor of `backfill-solving-time-predictions`

## SEO site-wide links (WS-F2)

Shipped with WS-F2 of [`features/seo/implementation-plan-2026-10.md`](features/seo/implementation-plan-2026-10.md).

- [ ] The footer "Popular searches" are hand-picked, hardcoded links (5 brand × pieces pages, 4 brand hubs, the WJPC
      2022-2026 event slugs, the hardest 1000-piece list) - all checked live on the production copy 2026-09-30.
      Renaming one of those brand or event slugs breaks a link on every guest page; revisit the picks yearly (new WJPC)
- [ ] The "how long" guides are English-only: their links on the pieces hubs, brand × pieces pages, `/puzzle` and in
      the footer say "(in English)" in the other five languages - drop that when the guides get localised (plan, open decisions)

## Sign-in / sign-up UX redesign

Phase 1 shipped 2026-09-29: [`features/auth-ux-redesign.md`](features/auth-ux-redesign.md) §8. Phase 2 (6-digit code) shipped 2026-09-29: §9.

- [x] Phase 2: 6-digit code in the sign-in e-mail + code input on `/login-link/sent`, attempt cap, audit, e-mail subject with the code (§9)
- [ ] Real-device check of the code: iOS Mail / Gmail "one-time-code" autofill from the e-mail subject, paste from the notification, Instagram in-app browser end to end (request -> mail app -> back -> code)
- [ ] After a month: `auth_audit_log` `sign_in_code_used` vs `sign_in_link_used`, and `sign_in_code_failed` by `metadata.code` (lots of `locked_out`/`throttled` from one IP = somebody guessing - then add a `warning` log for clustered lock-outs, spec §6.4)
- [ ] Test the redesigned forms with 1Password, Bitwarden, iCloud Keychain and Chrome on Android (save on register/reset, fill on login) - §6.4
- [ ] Real-device check of the in-app notice: Instagram + Facebook on iOS and Android (UA tokens drift; "Open in Chrome" intent, copy link)
- [ ] Watch `auth_audit_log` sign-in-link use rate before demoting "Email me a sign-in link instead" to a text link (D7)
- [ ] 16px inputs site-wide, then drop `maximum-scale=1` - [#216](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/216)
- [ ] `/welcome?return=` (spec §5.4) - not built; registration still ends on the welcome page

## Difficulty on puzzle lists and thumbnails

Shipped with #214, thumbnails + profile/marketplace filters 2026-10-03 (d8b3183d, b39d8e30). Design: [`features/list-difficulty-and-my-list-filter.md`](features/list-difficulty-and-my-list-filter.md).

- [ ] Turbo-stream re-renders of list items (reserve, move, lend, ...) drop the difficulty icon, solve count and `data-difficulty-tier` until reload - pass `puzzle_insights` for the one puzzle in the stream callers if it ever matters
- [ ] Same for the marketplace card re-rendered by the reserve / unreserve streams (`sell-swap/_mark_reserved_stream`, `_remove_reservation_stream`): no corner until reload - resolve the one puzzle's tier with `ResolveDifficultyTiers` there
- [ ] Round results show no tier for a puzzle without any picture either (the gate is "picture is out", the cheap proxy for "revealed") - expose the hide state from `GetEditionRounds` if that ever matters
- [ ] Sort by difficulty on the profile results and the marketplace (the comparison has it) - not asked for yet
- [ ] Costs were measured on the dev copy only - check the Hub, `/ladder` and the marketplace in Tempo a week after the deploy
- [x] `templates/_most_solved_puzzle.html.twig` deleted - unused since the Hub's Most solved became a component (efc8b2b4)

## Social login hardening

Shipped 2026-09-29; Google + Apple public 2026-09-29, Facebook 2026-09-30 (Meta app published, flag removed). Design: [`features/auth-hardening/README.md`](features/auth-hardening/README.md) §Hardening 2026-09-29.

- [ ] Stripe mails to Apple relay addresses: Stripe customers carry the account e-mail, so receipts to `@privaterelay.appleid.com` come from Stripe's domain and are likely dropped by the relay - Stripe custom e-mail domain + register it with Apple, or accept ([`setup-apple.md`](features/auth-hardening/setup-apple.md) §Open questions)
- [x] Translate the interstitial strings `auth.social.confirm.sign_in_and_connect` / `duplicate_accounts_help` and re-translate `auth.social.confirm.have_account` in cs/de/es/fr/ja (2026-09-29)
- [ ] Deliberately NOT done: Gmail dot/plus normalisation of provider emails when matching accounts (rule 2/3 compare the canonicalised address as-is)
- [ ] True account merge (two accounts, two players) stays a manual admin operation - write the runbook when the first request comes in
- [x] "Continue with Microsoft" (personal accounts, ~13 % of players have a Microsoft mailbox) - code shipped dark 2026-09-30 ([`microsoft-plan.md`](features/auth-hardening/microsoft-plan.md))
- [ ] Microsoft go-live: Jan's console work per [`setup-microsoft.md`](features/auth-hardening/setup-microsoft.md) (prod + `MySpeedPuzzling Local` registrations) → local test with the local values in `.env.local` → prod values to Infisical (`MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_CLIENT_SECRET_EXPIRES_AT`) + deploy = public → verify the publisher domain (`/.well-known/microsoft-identity-association.json`) → smoke test
- [ ] Box: `docker compose pull bot-blocker && docker compose up -d bot-blocker` so bot-blocker `7effa7c` (Microsoft's verifier always passes on `/.well-known/microsoft-identity-association.json`) is live before the publisher-domain verification
- [ ] Microsoft client secret rotation due: _fill in when the prod secret is created (creation + 23 months)_ - runbook in [`setup-microsoft.md`](features/auth-hardening/setup-microsoft.md) §Secret rotation; Sentry warns daily from 30 days before `MICROSOFT_CLIENT_SECRET_EXPIRES_AT`
- [ ] Upgrade path at the first rotation: certificate credential (`private_key_jwt`) instead of a client secret ([`microsoft-plan.md`](features/auth-hardening/microsoft-plan.md) §6)

## Puzzle approvals

Shipped 2026-09-25. Design: [`features/puzzle-approvals.md`](features/puzzle-approvals.md).

- [ ] Tell the adder when their puzzle is approved or merged (notification type + `GetNotifications` branch)
- [ ] Count approvals in `GetModerators` (reads `reviewed_by_id` today; `puzzle_moderation_decision` has everything)
- [ ] A read-only history page over `puzzle_moderation_decision` (who decided what, filter by player)
- [ ] Unapproved brands on production: 57 after the 2026-10-03 cleanup (was 213), 10 of them without a pending puzzle, so the queue never shows them - see [Duplicate brands](#duplicate-brands)

## Duplicate brands

Design + survey: [`features/brand-duplicates.md`](features/brand-duplicates.md). Merge API shipped 2026-10-03.

- [x] Cleanup 2026-10-03 (report: `~/Downloads/msp-brand-duplicates-2026-10-03/cleanup-report-2026-10-03.md`): 2,277 → 2,057 brands, 217 merged, 3 empty deleted, 35 approved; no two brands share a name ignoring case/spacing any more
- [ ] Community review of the 137 puzzle merge requests filed by the cleanup (`/admin/puzzle-merge-requests`)
- [ ] Jan's calls from the report: D-Toys/Roovi display name, Big Ben, generic "Jigsaw puzzle" names, Miniwan, Valo, Zdeko, Canadian Art Prints, Whitman Guild, Boynton, Britto/Scenic; 4 Renoir puzzles to re-brand to MarMa
- [ ] Approve or merge the 10 unapproved brands that have no pending puzzle
- [x] Hardening: one brand resolver for `AddPuzzleHandler` (incl. multiscan quick-add) / `AddPuzzleToCompetitionRoundHandler` - `ManufacturerResolver`, case + spacing only (2026-10-03)
- [ ] Hardening: unique index on the normalised brand name (race-safe insert) - possible now: zero collisions since the 2026-10-03 cleanup
- [x] Hardening: every brand picker lists unapproved brands too (2026-10-03)
- [ ] Hardening: approval queue suggests unapproved twins and refuses "approve" for a same-key approved brand
- [x] Hardening: brand picker's client-side match compares the plain name (exact, else a single prefix match), not `includes()` on the option HTML (2026-10-03)
- [ ] Hardening: no brand may sit unapproved without anyone being asked (queue shows brands without a pending puzzle)
- [x] Internal API: delete an empty brand (`POST /internal-api/manufacturers/{id}/delete`, 2026-10-03)

## Image storage (bucket audit 2026-09-22)

Full scan of the bucket against every DB image column; fixes shipped in lily.srv (imgproxy source limit 30 → 60 MP,
images-cache re-resolves imgproxy on Docker DNS). Lists in `~/Downloads/msp-image-audit-2026-09-22/` on Jan's Mac,
raw scan on the box in `/root/bucket-scan.csv` + `/root/db-image-refs.tsv`.

- [x] New result share images live on local disk for 48 hours, never in the bucket (2026-10-18, `ResultImageCache`):
      lily.srv volume `result-images` shared by web/api/cron, hourly `myspeedpuzzling:prune-result-images`, ~1.3 GB in
      use; a deleted player's cards go with `DeletePlayerStoredFilesHandler`. Replaces steps 2-3 of the plan below
- [x] Deleted the cards still in the bucket (2026-10-18): 490k `players/<id>/results/<id>.png` ≈ 420 GB, with the
      ~23k of them that still carried EXIF/GPS; nothing referenced them (checked every DB image column). rclone on
      lily, log in `/root/prune-bucket-result-images.log`; a second pass found nothing left
- [ ] Handlers never delete the previous object when a puzzle image / finished photo / logo is replaced or the time is
      deleted - 6,862 orphans ≈ 16 GB today (`3-orphaned-objects-not-referenced.csv`); delete the old key in the handler
- [ ] One-off: delete the existing orphans after a spot check (avatars: `myspeedpuzzling:storage:delete-orphaned-avatars`,
      #213 - see [`features/account-deletion.md`](features/account-deletion.md); a deleted account's files now go automatically)
- [ ] Optional: downscale the 84 pre-`ImageOptimizer` originals above 30 MP to 2,000 px like today's uploads
      (`2-oversized-originals-over-30mp.csv`); not needed for serving since the limit covers them
- [x] A large imgproxy preset - shipped 2026-09-30: `puzzle_large=rs:fit:1200:1200/f:jpg` (lily.srv
      `IMGPROXY_PRESETS` + local `compose.yml`; JPEG pinned so every link previewer takes it and the bytes never
      depend on `Accept`; its `eth:0` went with `IMGPROXY_ENFORCE_THUMBNAIL` on 2026-10-19). Used by the image
      sitemap, the puzzle `og:image` + Product JSON-LD, the event/edition/series JSON-LD `image` and the photo
      links (puzzle page, own finished photos) - no public page links a raw original (`/original/…`) any more;
      admin review pages still do on purpose
- [x] Stored originals with EXIF/GPS - stripped 2026-09-30: `/original/<key>` serves the raw file to anyone who
      knows the key (it is in every thumbnail URL), and 21,658 of 112,548 originals carried a GPS position. Lossless
      strip job (scripts removed afterwards, in git history at `ad0ddbf2`, `tools/image-metadata-strip/`): 66,710 objects stripped
      under the same keys + 308 already clean, every one verified (stored checksum, pixel compare of backup vs
      object, rendering of 3,002 keys through imgproxy). Work dir `/root/msp-exif-2026-09-30/` on the box
- [x] Backups deleted 2026-09-30 after the full verification plus an independent spot check (Jan: "check all photos
      are ok, then delete the backup") - no rollback any more; the per-object audit CSVs went too (camera/date details)
- [x] ~~Cloudflare prefix purge of `img.myspeedpuzzling.com/original/` + `img.myspeedpuzzling.com/puzzle/`~~ - skipped
      (Jan, 2026-09-30): old cached copies expire on their own (1-year TTL, rarely requested files are evicted much
      sooner, and no public page links an original any more); `cdn.*` and every imgproxy render are clean
- [x] `puzzle_medium` and every crop/resize of a HEIC source were drawn from its embedded ~240×320 preview
      (`IMGPROXY_ENFORCE_THUMBNAIL=true`) - setting removed 2026-10-19 (lily.srv `616bfc3`, journal `2be5d17`,
      681 cached HEIC/AVIF renders deleted). AVIF was never affected (no embedded preview)

## Multiscan

Shipped 2026-09-22. Design and plan: [`features/multiscan/README.md`](features/multiscan/README.md).

- [ ] Aggregated lending notification ("Anna lent you 6 puzzles") instead of one per puzzle
- [x] Tray persistence across a reload (`sessionStorage` mirror + `restore()` action) - shipped 2026-09-28 with the required quick-add photo
- [ ] Native apps: a multi-mode scanner in the iOS/Android shells (today the single-shot native scanner is re-opened per code)
- [ ] More actions: mark solved without a time (shared date), list for swap/free, remove from library
- [ ] "Undo last batch" for lend/return
- [ ] Watch the numbers after a few weeks: not-found rate, links created (`puzzle_change_request` rows with `proposed_ean` only), quick-adds

## Getting started / newcomer onboarding

Shipped 2026-09-20 (`1f59d530`). Design, research and rules: [`features/getting-started-guide.md`](features/getting-started-guide.md).
Mirrored in [#212](https://github.com/MySpeedPuzzling/myspeedpuzzling.com/issues/212).

**Not built yet**

- [ ] First-time celebration: after the very first saved time, a small "Nice!" moment (reuse the picker's confetti) with
      links to *My statistics*, *Leaderboard* and *Finish my profile* - the best moment to introduce those features
- [ ] Empty states with one sentence + one button on the owner's own empty profile, collections and wishlist
      (only empty statistics has one today); link to the guide's anchors `#track`, `#library`
- [ ] Welcome e-mail with the same first steps + the guide link (today only the verification mail goes out)
- [ ] Edit profile: basic form (name, photo, country…) to the top, developer cards (tokens, applications) folded at the bottom
- [ ] `membership.full_description` is out of date: no Insights / picker filters, "coming soon" items that shipped

**Verify / decide**

- [ ] One real registration on production end to end: name → welcome screen → Hub card at "1 of 5" → guide, click
      *My statistics* and check the step gets its tick (the `mark-seen` beacon was never clicked in a real browser)
- [ ] Native-speaker read of the es / fr / de / ja texts (`onboarding.*`), Japanese first
- [ ] Should Statistics / Leaderboard ticks also count visits through the menu, not only a click from the guide?
      (costs a check on every view of those pages)
- [ ] Remove the NEW badges on "Getting started" and "Pairs & Teams" when they stop being new (no expiry built in)

**Measure**

- [ ] Compare activation (share of registrations with a solving time within 7 days) before vs after 2026-09-20 -
      SQL in the feature doc. If people stall at the fifth checklist step, drop "Add a favorite puzzler"

## Badge UI fine-tuning (`docs/features/badges-ui-handoff.md`, art: `tools/badge-art/README.md`)

- [ ] Real-device pass of the badge reveal page + XP launch reveal (iPhone Safari, Android Chrome): haptics, sound
      toggle, tap targets, hover space above the stage, locked look on OLED
- [ ] Holo tilt + medal back in the achievement modal (prototype P3; precompute the bitmap analysis in tools/badge-art;
      own code, not the GPL pokemon-cards-css); needs rarity + serial numbers
- [ ] Self-animating T4/T5 SVG shine as an extra tools/badge-art output (prototype P5; loop only in modal/reveal)
- [ ] Badge cabinet as a jigsaw board on the profile (prototype P1) - decide yearly reset / picture / 40 types on phones
- [ ] Event passport for one-off event badges (prototype P6), spinning medal for the reveal (prototype P4) - optional
- [ ] Check the 48 px e-mail PNGs in Gmail/Outlook dark mode
- [ ] `EligibilityScheduleTest::testTheEventPageAndTheEventsPageRows` fails on origin/main too (2026-10-10): its two
      events 22 and 50 days out both fall in November and merge into one events-page row - make it date-proof

## XP launch - after the switch is on (`docs/features/xp-levels/launch-2026-10.md`, `feature_flags.md` `xp-launch`)

- [ ] Remove `XpFeatureGate`, the `{% if xp_system_visible() %}` wrappers and the readiness check in `XpLaunchRevealRule`
      once the launch is settled; `XpLaunchReadiness` + `xp_settings.launch_ready_at` go with them
- [ ] Add `xp_leaderboard` (both tabs) and `achievement_detail` to `BlocklistCanaryTest` + `PrivateProfileCanaryTest`
      (needs fixture players with XP / achievements as positive controls)
- [ ] Query budgets of signed-in pages carry +1 for the header XP ring (`GetXpProfile`) since the gate opened for
      everybody - gone once the level rides on the viewer's profile row
- [ ] Re-run `myspeedpuzzling:xp-distribution` after the launch backfill: without achievement XP a local copy gave 112
      players at Level 50 and median level 10 (the curve was calibrated to a median of 13-14 with achievement XP) -
      decide whether the curve needs a touch
