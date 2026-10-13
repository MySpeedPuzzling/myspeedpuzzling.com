# Phase 1 contract - remote stopwatch

Fixed before the parallel streams start (implementation-plan.md §3, §7). **Names, shapes and file ownership below are binding.** A stream that needs to change any of them stops and tells the orchestrator; it never edits another stream's files.

Design of record: [README.md](../README.md) · plan: [implementation-plan.md](../implementation-plan.md) · progress: [STATUS.md](../STATUS.md).

---

## 1. Streams and file ownership

| Stream | Scope | Owns (creates / edits) |
|---|---|---|
| **F - foundation** (first, alone) - **merged** | the shared model and services everything else builds on | every file named in §2-§5 of this contract, the migration, `OnlineRoundsFixture`, the translation roots (§8), `docs/features/feature_flags.md` section |
| **A - participant backend** | `/-/time`, the participant JSON API, presence in Redis, presigned uploads, events, connect, media pruning | `src/Controller/Compete/Api*.php`, `src/Controller/Compete/CompeteConnectController.php`, `src/Controller/OnlineTimeController.php`, handlers of `RecordOnlineEntryEvents`, `RegisterOnlineMedia`, `VerifyOnlineMedia`, `PruneOnlineMedia`, `src/Services/OnlineRounds/RedisOnlinePresence.php`, `S3OnlineMediaStorage.php`, `OnlineStoreBreaker.php`, `OnlineConnectRules.php`, `src/ConsoleCommands/PruneOnlineMediaCommand.php`; edits `config/packages/security.php` (firewall), `rate_limiter.php`, `messenger.php` (routing), `config/services.php` (bindings) |
| **B - participant page** | the page phones open | `assets/online/*.js`, `assets/controllers/compete_controller.js`, `templates/compete/**`, `src/Controller/Compete/CompetePageController.php`, `tests/online-*-harness.mjs` + their PHPUnit wrappers, `assets/styles/compete.scss` |
| **C1 - organiser set-up** | online timing settings, personal links, the sheet column, the event-page button, menu entries | handlers of `EnableOnlineRound`, `UpdateOnlineRoundSettings`, `GenerateOnlineTokens`, `RegenerateOnlineToken`; `src/Controller/OnlineRounds/{OnlineRoundSettings,OnlineLinks,OnlineLinksCsv,OnlineLinksQrSheet,RegenerateOnlineLink}Controller.php`, `src/FormType/OnlineRoundSettingsFormType.php` + form data, `templates/online_rounds/settings.html.twig`, `links*.html.twig`; edits `GetParticipantsSheetState::people()` + `ParticipantsSheetPerson` + `assets/participants_sheet/views/people_view.js` (personal link column), `GetEventAttendance` + `templates/event_parts/_taking_part.html.twig` (button), `templates/events/_manage_items.html.twig` + `templates/official_results/_round_tool_links.html.twig` (links) |
| **D - round clock** | countdown / scheduled start, cancel, `scheduled` status, publish after commit, ms on the wire, every stopwatch reader | `CompetitionRound` stopwatch methods, handlers of `ScheduleRoundStart` / `CancelRoundStart` + the three existing stopwatch handlers, `src/Services/RoundStopwatchLiveUpdates.php`, the stopwatch controllers (+ new `ScheduleRoundStartController`, `CancelRoundStartController`), `RoundStopwatchStateController`, every reader listed in implementation-plan.md WP 1.1 (incl. the `started` flag in `GetParticipantsSheetState::rounds()` - **not** `people()`) |
| **C2 - control room + review** (wave 2, after A + D merge) | the organiser's live page and the review → official results | `src/Controller/OnlineRounds/{OnlineControlRoom,OnlineLive,OnlineReview,OnlineReviewEntry,ConfirmOnlineResult,OnlineAccuracy}Controller.php`, handler of `ConfirmOnlineResult`, `templates/online_rounds/{control,review,accuracy}*.html.twig`, `assets/controllers/online_control_controller.js`, `online_review_controller.js`, `assets/online/review_*.js`, `assets/styles/online_rounds.scss` (shared with C1, which comes first) |

Translations: every stream adds keys **only inside its own sub-root** (§8), English only; the other 5 locales are filled at the end of the phase.

**Page stylesheets** (orchestrator, at the foundation merge): the online pages never add to the site-wide `app.scss`. Two Encore style entries exist - `compete` (`assets/styles/compete.scss`, the participant page) and `online_rounds` (`assets/styles/online_rounds.scss`, the organiser pages). A page links its entry by overriding the base layout's block `{% block page_stylesheets %}{{ encore_entry_link_tags('compete') }}{% endblock %}`.

**`SerializedByLockMessagesTest`** is shared: it flags handlers that name participant classes (also through `OnlineAccess` / `OnlineRoundState`). Each stream adds its own `NOT_LOCKED` entries (with a reason) for its handlers, in alphabetical position. The orchestrator resolves the merge conflicts in that file.

---

## 2. Model (stream F)

Enums in `src/Value/` (string-backed):

| Enum | Cases (value) |
|---|---|
| `OnlineDetectionMode` | `Off` (off), `Shadow` (shadow), `On` (on) |
| `OnlineUnfinishedMeasure` | `PiecesLeft` (left), `PiecesPlaced` (placed) |
| `OnlineMissingPieceRule` | `CompleteRequired` (complete_required), `PenaltyFiveSeconds` (penalty_5s) |
| `OnlineEventType` | `ConsentGiven` (consent.given), `DeviceReady` (device.ready), `CountdownSeen` (countdown.seen), `StartSeen` (start.seen), `FinishCandidate` (finish.candidate), `FinishButton` (finish.button), `FinishButtonUndone` (finish.button.undone), `CameraLost` (camera.lost), `CameraBack` (camera.back), `PageHidden` (page.hidden), `PageVisible` (page.visible), `NetworkOffline` (network.offline), `NetworkOnline` (network.online), `TimeUpPieces` (timeup.pieces), `MediaUploaded` (media.uploaded) |
| `OnlineMediaKind` | `Periodic` (periodic), `EmptyMat` (empty_mat), `Start` (start), `Candidate` (candidate), `Button` (button), `Burst` (burst), `End` (end) |
| `OnlineMediaStore` | `Primary` (primary), `Secondary` (secondary) |
| `OnlineConfirmedSource` | `Detection` (detection), `Button` (button), `Reviewer` (reviewer) |
| `OnlineReviewStatus` | `Pending` (pending), `Confirmed` (confirmed), `Flagged` (flagged) |

Entities (conventions: implementation-plan.md §3.4):

**`OnlineRound`** (`online_round`) - one row per round that uses online timing
- `id` uuid; `round` `CompetitionRound` (one-to-one, unique, `onDelete: CASCADE`)
- `enabledAt` datetime; `enabledBy` `Player` (nullable, `onDelete: SET NULL`)
- `detectionMode` `OnlineDetectionMode` (default `Off` in Phase 1); `videoEnabled` bool (false); `participantsSeeEachOther` bool (false); `studioEnabled` bool (false); `recordingEnabled` bool (false)
- `unfinishedMeasure` `OnlineUnfinishedMeasure` (`PiecesLeft`); `missingPieceRule` `OnlineMissingPieceRule` (`CompleteRequired`)
- method `changeSettings(...)` (named arguments for every setting)

**`CompetitionParticipant`** gains `onlineToken` (string(32), nullable, unique) + `onlineTokenCreatedAt` (datetime, nullable); methods `assignOnlineToken(string $token, DateTimeImmutable $at)` (also used to regenerate).

**`OnlineEntry`** (`online_entry`) - a participant's online state in one round, created on first contact
- `id` uuid; `entry` `CompetitionParticipantRound` (one-to-one, unique, `CASCADE`)
- `activeDeviceId` string(64) nullable; `firstSeenAt` datetime; `readyAt` datetime nullable
- `clockOffsetMs` float nullable; `clockRttMs` float nullable
- `confirmedMomentMs` bigint nullable; `confirmedSource` `OnlineConfirmedSource` nullable
- `reportedMeasure` `OnlineUnfinishedMeasure` nullable; `reportedPieces` int nullable
- `reviewStatus` `OnlineReviewStatus` (`Pending`); `reviewedBy` `Player` nullable (`SET NULL`); `reviewedAt` datetime nullable
- `flags` jsonb list of strings (default `[]`)

**`OnlineEntryEvent`** (`online_entry_event`) - append-only
- `id` uuid **= the client's UUIDv7** (never generated server-side); `onlineEntry` (many-to-one, `CASCADE`)
- `deviceId` string(64); `type` `OnlineEventType`; `clientMs` bigint; `perfMs` float nullable; `offsetMs` float nullable; `rttMs` float nullable; `receivedAtMs` bigint; `payload` jsonb (default `{}`)
- index (`online_entry_id`, `client_ms`)

**`OnlineEntryMedia`** (`online_entry_media`)
- `id` uuid (generated by the controller before dispatch); `onlineEntry` (many-to-one, `CASCADE`)
- `kind` `OnlineMediaKind`; `takenMs` bigint; `store` `OnlineMediaStore`; `objectKey` string(255); `contentType` string(64); `declaredBytes` int
- `uploadedAtMs` bigint nullable; `verifiedBytes` int nullable; `manifest` jsonb nullable (burst frames `[{offset, length, captureMs}]`); `relatedEventId` uuid nullable; `verdict` jsonb nullable (Phase 3); `createdAt` datetime
- index (`online_entry_id`, `kind`, `taken_ms`)

Repositories (`get()` / `save()` / `delete()` + the finders named here): `OnlineRoundRepository::ofRound(string $roundId): ?OnlineRound`, `OnlineEntryRepository::ofEntry(string $entryId): ?OnlineEntry`, `OnlineEntryEventRepository::existingIds(list<string>): list<string>`, `OnlineEntryMediaRepository`, `CompetitionParticipantRepository::getByOnlineToken(string $token)` (throws `OnlineLinkNotFound`). Exceptions (`src/Exceptions/`, extend `NotFoundHttpException`): `OnlineRoundNotFound`, `OnlineLinkNotFound`, `OnlineEntryMediaNotFound`.

**Round stopwatch status** stays a string column, values `null | 'scheduled' | 'running' | 'stopped'` (stream D implements `scheduled`).

---

## 3. Shared services and values (stream F)

| Piece | Contract |
|---|---|
| `src/Value/RoundClock.php` | `RoundClock::fromRound(CompetitionRound $round, DateTimeImmutable $now): self`; `status`: `waiting` (no start) · `scheduled` (start in the future) · `running` · `ended` (stopped, or `now ≥ startedAt + minutesLimit`); `startedAtMs`, `stoppedAtMs`, `endsAtMs`, `minutesLimit`; `stateHash()` (short, stable for the same state); `toArray()` |
| `src/Value/OnlineToken.php` | `OnlineToken::generate(): string` - 128 random bits, base58, 22 characters; `OnlineToken::looksValid(string): bool` |
| `src/Services/OnlineRounds/OnlineRoundsFeature.php` | `isAvailableTo(?string $playerId): bool` - admins always; organisers when env `ONLINE_ROUNDS_FOR_ORGANISERS=1` (default `0`, defined in `.env`) |
| `src/Security/OnlineRoundsVoter.php` | attribute `ONLINE_ROUNDS_MANAGE`, subject = competition id: `COMPETITION_EDIT` **and** the feature available to the user |
| `src/Services/OnlineRounds/OnlineAccess.php` | `forToken(string $token): OnlineAccessContext` (throws `OnlineLinkNotFound` for unknown / removed participants) - context: participant, competition, current round (running or scheduled clock first, then the next round by `startsAt`) or null, its `OnlineRound` or null, the participant's `CompetitionParticipantRound` or null |
| `src/Services/OnlineRounds/OnlineRoundState.php` | `forParticipant(OnlineAccessContext $context): array` - exactly the JSON of implementation-plan.md §3.1 `state` (`serverNowMs`, `asOfMs` taken when the rows are read, `participant`, `competition`, `round` incl. `RoundClock` fields + `settings`, `entry`, `mercure`) |
| `src/Services/OnlineRounds/OnlinePresence.php` (interface) | `beat(string $roundId, string $participantId, array $data, int $nowMs): void`; `forRound(string $roundId): array<string, array>` (participant id → last beat incl. `lastSeenMs`). F ships `InMemoryOnlinePresence` (test double, bound in `services_test.php`); A ships the Redis implementation |
| `src/Services/OnlineRounds/OnlineMediaStorage.php` (interface) | `presignPut(OnlineEntryMedia $media): PresignedUpload` (`url`, `headers`, `expiresAtMs`, `store`); `presignGet(OnlineEntryMedia $media, int $ttlSeconds): string`; `head(OnlineEntryMedia $media): ?int` (bytes, null = missing); `delete(OnlineEntryMedia $media): void`. F ships `FakeOnlineMediaStorage` (tests); A ships the S3 implementation |
| `OfficialResultsSubscription::forOnlineParticipant(string $roundId): ?array` | public, the round's stopwatch topic only (F adds the method) |

---

## 4. Messages (stream F creates the classes; handlers by the owning stream)

| Message | Fields | Handler by | Notes |
|---|---|---|---|
| `ScheduleRoundStart` | `roundId`, `startsAt` (whole second, ≥ now), `actingPlayerId` | D | status `scheduled` |
| `CancelRoundStart` | `roundId`, `actingPlayerId` | D | only while `scheduled` and not started |
| `EnableOnlineRound` | `roundId`, `actingPlayerId` | C1 | also generates missing tokens |
| `UpdateOnlineRoundSettings` | `roundId`, every setting of `OnlineRound` | C1 | |
| `GenerateOnlineTokens` | `competitionId` | C1 | for participants without a token |
| `RegenerateOnlineToken` | `participantId` | C1 | the old link dies |
| `RecordOnlineEntryEvents` | `participantId`, `roundId`, `deviceId`, `events` (list of arrays) | A | returns `{acks, refused}` (`HandledStamp`); creates `OnlineEntry` on first contact |
| `RegisterOnlineMedia` | `mediaId`, `participantId`, `roundId`, `deviceId`, `kind`, `takenMs`, `bytes`, `contentType`, `relatedEventId` | A | the controller then presigns |
| `VerifyOnlineMedia` | `mediaId` | A | **async** (HEAD → `verifiedBytes`) |
| `PruneOnlineMedia` | `olderThanDays` | A | cron |
| `ConfirmOnlineResult` | `competitionId`, `roundId`, `participantId`, `momentMs` (nullable), `source`, `pieces` (nullable), `actingPlayerId` | C2 | `SerializedByLock` (`CompetitionParticipantsLock`), one transaction with the official result |

---

## 5. Routes (names binding)

| Name | Method + path (en) | Stream |
|---|---|---|
| `online_time` | GET `/-/time` | A |
| `compete_page` | GET `/en/compete/{token}` (locale map) | B |
| `compete_connect` | POST `/en/compete/{token}/connect` | A |
| `compete_api_state` | GET `/api/compete/{token}/state` | A |
| `compete_api_heartbeat` | POST `/api/compete/{token}/heartbeat` | A |
| `compete_api_events` | POST `/api/compete/{token}/events` | A |
| `compete_api_media` | POST `/api/compete/{token}/media` | A |
| `schedule_round_start` | POST `/en/round-stopwatch/{roundId}/schedule` (`countdown=10` or `at=<ISO>`) | D |
| `cancel_round_start` | POST `/en/round-stopwatch/{roundId}/cancel` | D |
| `online_round_settings` | GET+POST `/en/online-rounds/{roundId}/settings` | C1 |
| `online_links` | GET `/en/online-competitions/{competitionId}/links` | C1 |
| `online_links_csv` | GET `/en/online-competitions/{competitionId}/links.csv` | C1 |
| `online_links_qr_sheet` | GET `/en/online-competitions/{competitionId}/links/qr-sheet` | C1 |
| `online_link_regenerate` | POST `/en/online-competitions/{competitionId}/links/{participantId}/regenerate` | C1 |
| `online_control_room` | GET `/en/online-rounds/{roundId}/control` | C2 |
| `online_live` | GET `/en/online-rounds/{roundId}/live.json` | C2 |
| `online_review` | GET `/en/online-rounds/{roundId}/review` | C2 |
| `online_review_entry` | GET `/en/online-rounds/{roundId}/review/{participantId}` | C2 |
| `online_review_confirm` | POST `/en/online-rounds/{roundId}/review/{participantId}/confirm` | C2 |
| `online_accuracy` | GET `/en/online-rounds/{roundId}/accuracy` | C2 |

Organiser routes are locale maps like `manage_round_stopwatch`; ids use `FirstTryConflictsController::ID_REQUIREMENT`.

---

## 6. Participant API (stream A, consumed by B)

Shapes: implementation-plan.md §3.1, plus:

- **Errors:** `{"error": "<code>"}`, `private, no-store`. Codes: `unknown_link` 404, `invalid_json` 400, `round_not_online` 409, `rate_limited` 429 (+ `Retry-After`), `media_too_large` 422 (> 15 MB), `media_kind_unknown` 422. A refused event is not an HTTP error: it appears in `refused` with `reason` (`unknown_type`, `invalid_fields`, `wrong_round`).
- **Idempotency:** an event id seen before is acked again, never stored twice.
- **Late arrival:** stored as received; the review computes "arrived N s after its moment while heartbeats kept coming".
- **Heartbeat** never touches Postgres.
- **Heartbeat lookup cache:** the heartbeat reads a link from the shared pool `cache.app` under `OnlineAccessLight::CACHE_KEY_PREFIX` (`online_access_light.<token>`, `OnlineAccessLight::cacheKey()`, TTL 60 s) - the one definition of the key. Whoever kills a link drops its entry through `OnlineAccessLight::forget()` **after** the change is committed (dropped inside the transaction, a heartbeat in between caches the old link again): "New link" (`online_link_regenerate` - the token `RegenerateOnlineToken` answers it replaced, and the one the request read).
- **Every answer** carries `serverNowMs` (the clock sync's free sample).

---

## 7. Client modules (stream B; C2 reuses `clock_sync.js` + `round_state.js`)

Exactly implementation-plan.md §3.2 (`clock_sync`, `round_state`, `event_outbox`, `media_outbox`, `frame_buffer`, `camera`; Phase 1 has no `finish_machine` / `detector.worker` / `livekit_session`). Every module: plain ES module, no Stimulus, no DOM at import time, all I/O injected, node harness tests.

---

## 8. Translations (English during the build)

| Root | Sub-root | Stream |
|---|---|---|
| `compete` | `page`, `checklist`, `screen`, `finish`, `timeup`, `connect` | B |
| `compete` | `api` | A |
| `online_rounds` | `settings`, `links`, `menu` | C1 |
| `online_rounds` | `control`, `review`, `accuracy` | C2 |
| `competition.stopwatch` (existing) | new keys only | D |

Stream F creates the roots with one placeholder key per sub-root, so every stream only ever inserts inside its own block (conflict-free merges).

---

## 9. How every stream works

- **Worktree:** the Agent tool's own worktree; never `cd` into the main checkout, never touch another worktree.
- **Commands:** run everything through `/Users/janmikes/www/speedpuzzling.cz/.claude/worktrees/online-delivery/run.sh <name> '<command>'` from your worktree root. Your test database is `speedpuzzling_<name>_test`. Wrap test suites, PHPStan and migrations in `heavy.sh` - it holds one of 3 shared slots.
- **Gates for your stream:**
  - `APP_ENV=dev … 'php bin/console cache:warmup'`, then `vendor/bin/phpstan --memory-limit=2G analyse`;
  - `vendor/bin/phpcs` on your files;
  - `php vendor/bin/phpunit <your test files and the existing tests you touched>`;
  - the node harnesses through their PHPUnit wrappers.
  - The orchestrator runs the full suite.
- **After an entity change:** `rm tests/.database.cache` in your worktree.
- **Migrations** (stream F only):
  1. Build `speedpuzzling_<name>_migcheck` from the committed migrations (`DB_SUFFIX=migcheck APP_ENV=dev … 'php bin/console doctrine:database:create && php bin/console doctrine:migrations:migrate -n && php bin/console doctrine:migrations:diff'`).
  2. Read the generated file line by line - it must only add.
  3. Drop that database in a separate call, with its name written literally.
- **Never:**
  - drop or migrate the dev database `speedpuzzling`;
  - run `docker rm` without `-v`, or prune anything;
  - `git push`, `git stash`, or any history rewrite;
  - edit files outside your ownership.

  Keep `docker run --rm alpine:3 df -h /` under 90 %.
- **Commits:** small commits on your worktree branch with clear messages; the orchestrator merges. End with a short report: what you built, files, how you tested, open questions, deviations from this contract.
