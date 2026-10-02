# TODO

Open follow-ups, one place to come back to. Tick an item when it ships, delete a section when it is empty.
Feature-sized plans keep their own checklist in `docs/features/<feature>/` - this file is for the loose ends
that would otherwise be forgotten. Newest section on top.

## Player header (#224)

Shipped: [`features/player-header.md`](features/player-header.md).

- [ ] **API vs web (Jan's call):** `/api/v1/me/followers` (`GetPlayerConnections`) still lists private followers the
      viewer may not see, masked but by `#CODE`; the favorites page only counts them (`GetFavoritePlayers::followersOf`).
      Align the API (count only) or accept the difference
- [ ] The favorite toggle is still a GET link (`ToggleFavoritePlayerController`, `rel="nofollow"`, no CSRF) - a POST
      button would be the honest form now that it is a primary action on 12 pages
- [ ] Found while screenshotting, not caused by #224: `onboarding.checklist.progress` was overwritten by the free trial
      (`cf819693`) with `%logged%`/`%required%`, but `onboarding/_getting_started_card.html.twig` passes
      `%done%`/`%total%` - the card shows "%logged% of %required% so far" (en, cs, de at least)
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
- [ ] Calibration check (first numbers after the backfill): personal predictions 74 % inside their range, median
      -0.3 % (well centred); statistical only 32 % inside the p25-p75 range (a calibrated IQR holds ~50 %) and players
      are a median 9.8 % faster than predicted - look into it before the UI shows "faster than expected" on first solves
- [ ] `--recompute-reconstructed` on the backfill command, built together with the calibration fix: after a
      `TimePredictionCalculator::MODEL_VERSION` bump it resets the `reconstructed` rows to NULL (a genuine bulk
      `UPDATE`, commented as such) so the next run rebuilds them with the new model - `live` rows stay, they are
      history. Without it the ~445k reconstructed rows keep model version 1 and only new times get the new model
- [ ] Per-time outcome in the player's history (puzzle page "my times", profile): range, "12 % faster than predicted", inside/outside the range, members-only, hidden for players who opted out of predictions (stored anyway), decide for unboxed/suspicious times
- [ ] Mark `reconstructed` predictions in the UI ("reconstructed from your earlier solves") vs `live`
- [ ] Player-level accuracy: how often inside the range, average beat, trend over time (Insights section)
- [ ] Ideas for later: "beat the prediction" streaks/badges, API fields on result rows (additive: `prediction`, `faster_than_predicted_percent`)

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

## Difficulty on puzzle lists

Shipped with #214. Design: [`features/list-difficulty-and-my-list-filter.md`](features/list-difficulty-and-my-list-filter.md).

- [ ] Turbo-stream re-renders of list items (reserve, move, lend, ...) drop the difficulty icon, solve count and `data-difficulty-tier` until reload - pass `puzzle_insights` for the one puzzle in the stream callers if it ever matters

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
- [ ] 196 unapproved brands on production, 158 of them held as merge candidates (`/root/brand-merge-proposal-2026-09-17.json` on the box) - the queue now handles them one puzzle at a time

## Image storage (bucket audit 2026-09-22)

Full scan of the bucket against every DB image column; fixes shipped in lily.srv (imgproxy source limit 30 → 60 MP,
images-cache re-resolves imgproxy on Docker DNS). Lists in `~/Downloads/msp-image-audit-2026-09-22/` on Jan's Mac,
raw scan on the box in `/root/bucket-scan.csv` + `/root/db-image-refs.tsv`.

- [ ] Result share images (`players/<id>/results/<id>.png`, 800×800): 490k objects ≈ 420 GB, one per solving time,
      **never deleted** - `GetResultImage` only redraws a card older than a month *when it is requested*, a card nobody
      opens again stays forever. ~5 % of them (≈ 23k, 141 in a 3,000 sample) still carry the finished photo's EXIF/GPS
      from before 2026-09-30 (new cards are clean), reachable via `/original/<key>` - the key is built from public ids.
      Agreed plan (Jan, 2026-09-30: later): 1) delete all existing cards (bulk DeleteObjects, minutes; each one is
      redrawn cleanly on its next request); 2) store new cards under their own prefix `results/<playerId>/<timeId>.png`
      (+ `-hidden`); 3) bucket lifecycle rule: expire `results/` after 35 days (Hetzner supports it - the API answers
      `NoSuchLifecycleConfiguration`, i.e. none set yet; a prefix filter cannot match the middle segment of today's
      `players/<id>/results/`, hence step 2). No cron needed afterwards.
- [ ] Handlers never delete the previous object when a puzzle image / finished photo / logo is replaced or the time is
      deleted - 6,862 orphans ≈ 16 GB today (`3-orphaned-objects-not-referenced.csv`); delete the old key in the handler
- [ ] One-off: delete the existing orphans after a spot check (avatars: `myspeedpuzzling:storage:delete-orphaned-avatars`,
      #213 - see [`features/account-deletion.md`](features/account-deletion.md); a deleted account's files now go automatically)
- [ ] Optional: downscale the 84 pre-`ImageOptimizer` originals above 30 MP to 2,000 px like today's uploads
      (`2-oversized-originals-over-30mp.csv`); not needed for serving since the limit covers them
- [x] A large imgproxy preset - shipped 2026-09-30: `puzzle_large=rs:fit:1200:1200/eth:0/f:jpg` (lily.srv
      `IMGPROXY_PRESETS` + local `compose.yml`; JPEG pinned so every link previewer takes it and the bytes never
      depend on `Accept`, `eth:0` = the full HEIC instead of its embedded ~320 px thumbnail). Used by the image
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
- [ ] `puzzle_small`/`puzzle_medium` of a HEIC source are drawn from its embedded ~320 px thumbnail
      (`IMGPROXY_ENFORCE_THUMBNAIL=true`) - `puzzle_medium` (`el:1`) upscales it to 400 px; `eth:0` there too?

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
