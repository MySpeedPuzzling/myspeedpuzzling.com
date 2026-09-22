# Multiscan — implementation plan

Design: [`README.md`](README.md). Planned as two PRs, **shipped as one on 2026-09-22** (both stages
below are live). Deviations from the plan while building are marked *as built*.

House rules (same as every recent feature): state changes only through Messenger handlers,
repositories never flush, `ClockInterface`, `Uuid::uuid7()`, single-action controllers, raw-SQL
query classes + `Results` DTOs, translations through `messages.*.yml`, Stimulus strings via
`data-*-value`, full check suite before every commit (`phpstan`, `cs-fix`, `phpunit --testsuite
"Project Test Suite"`, `doctrine:schema:validate`, `cache:warmup`), `js-watch` builds assets,
template changes need `docker compose restart web`.

---

## PR 1 — Page, continuous scanner, tray, five batch actions

### Value objects
- `src/Value/Ean.php` — `tryFrom(string $input): ?self` (strip non-digits; accept 8 / 12 / 13
  digits; UPC-A → prefix `0`; GS1 mod-10 checksum), `public string $digits` (full code),
  `normalized(): string` = `ltrim($digits, '0')` (the storage convention in `AddPuzzleHandler:94`),
  `equals()`. Tests `tests/Value/EanTest.php` (valid/invalid checksum, UPC-A, zeros, garbage).
- `src/Value/MultiscanAction.php` enum: `AddToLibrary`, `AddToWishlist`, `Lend`, `Borrow`, `Return`
  (`value` = URL slug for `?action=`).
- *As built:* no `MultiscanRowState` enum - the row state is the plain string `resolved` / `ambiguous`
  / `unknown` inside the `rows` LiveProp; `Results\MultiscanRow` narrows it for the template.

### Queries
- `src/Query/GetPuzzleOverview.php::byIds(list<string> $ids): array<string, PuzzleOverview>` — one
  statement, same SELECT as `byId()` incl. `hide_image_until` masking; missing ids simply absent.
- `src/Query/GetMultiscanCandidates.php` — thin wrapper: `forEan(Ean $ean): list<PuzzleOverview>`
  = `SearchPuzzle::allByEan($ean->digits)` re-ordered so **exact list-member matches come first**
  (`ean = :n OR ean LIKE :n || ',%' OR ean LIKE '%, ' || :n …` computed in PHP over the small
  result), substring matches after. Keeps the tolerant behaviour of the single scanner but makes the
  auto-pick deterministic.

### Eligibility (shared by component and handlers)
- `src/Services/MultiscanEligibility.php` — `check(MultiscanAction $action, list<string> $puzzleIds,
  UserPuzzleStatuses $statuses, ?string $collectionId = null): MultiscanEligibilityReport`
  (`src/Results/MultiscanEligibilityReport.php`: `eligible: list<string>`, `skipped: array<puzzleId,
  reasonKey>`, `lentPuzzleIds: array<puzzleId, lentPuzzleId>` for Return). Rules from README §4:
  - AddToLibrary: skip when `statuses->puzzleCollections[puzzleId]` already contains the target
    collection (`__system_collection__` for null).
  - AddToWishlist: skip when in `wishlist` or in `collection`.
  - Lend: skip when in `lent` (reason `already_lent`, name from `lentToNames`).
  - Borrow: skip when in `borrowed` (`already_borrowed`, name from `borrowedFromNames`).
  - Return: eligible when in `lentPuzzleIds` **or** `borrowedPuzzleIds`; else `not_lent`.
  Tests `tests/Services/MultiscanEligibilityTest.php` (pure, statuses built by hand).

### Messages + handlers (`src/Message`, `src/MessageHandler`)
Each handler: load player (`PlayerNotFound`), compute the report via `MultiscanEligibility` on a fresh
`GetUserPuzzleStatuses::byPlayerId()`, **throw `MultiscanBatchRejected(puzzleId, reasonKey)`
before any write** if a puzzle in the message is not eligible, then loop calling the existing
single handler's `__invoke()` directly (they are services; nested bus dispatch would add a savepoint
pair per puzzle). Handlers do **not** check membership (never do in this codebase).

| Message | Args | Delegates to |
|---|---|---|
| `AddPuzzlesToCollection` | `playerId, list<string> puzzleIds, ?collectionId, ?comment` | `AddPuzzleToCollectionHandler` |
| `AddPuzzlesToWishList` | `playerId, puzzleIds` | `AddPuzzleToWishListHandler` |
| `LendPuzzlesToPlayer` | `ownerPlayerId, puzzleIds, ?borrowerPlayerId, ?borrowerName, ?notes` | `LendPuzzleToPlayerHandler` |
| `BorrowPuzzlesFromPlayer` | `borrowerPlayerId, puzzleIds, ?ownerPlayerId, ?ownerName, ?notes` | `BorrowPuzzleFromPlayerHandler` |
| `ReturnLentPuzzles` | `actingPlayerId, puzzleIds` (resolved to lent ids inside via the report) | `ReturnLentPuzzleHandler` |

Extra guards: empty `puzzleIds` → `MultiscanBatchRejected('empty')`; duplicates de-duplicated;
lend/borrow to self → `MultiscanBatchRejected('self')`; `AddPuzzlesToCollection` verifies the
collection belongs to the player (the single handler does, keep it) and that a **named** collection
is only used by a member — no: membership is the component's job, but the handler must still refuse
a collection that is not the player's (`CollectionNotFound`).

Exception `src/Exceptions/MultiscanBatchRejected.php` (`puzzleId`, `reasonKey`; not an HTTP
exception — the component catches it).

Tests `tests/MessageHandler/{AddPuzzlesToCollection,AddPuzzlesToWishList,LendPuzzlesToPlayer,
BorrowPuzzlesFromPlayer,ReturnLentPuzzles}HandlerTest.php`: happy path with 3 puzzles, all-or-nothing
(one ineligible → nothing written), idempotent re-run, self-lend refused, return works for owner and
holder, mixed borrowers in one batch. Fixtures: `.claude/fixtures.md` LENT_01…08 already cover
lent/borrowed by registered and free-text people.

### Person input
- `src/Services/LendBorrowParticipantParser.php` — the `#code` / free-text convention of the lend and
  borrow forms as a service: `parse(string $input, string $actingPlayerId): LendBorrowParticipant`
  throwing `PlayerNotFound` / `CannotLendToSelf`. *As built:* used by the tray only; the two existing
  controllers keep their inline copy (deliberately untouched).

### Live Component `src/Component/MultiscanTray.php` + `templates/components/MultiscanTray.html.twig`
- Props: `#[LiveProp] list<array{ean: string, puzzleId: ?string, state: string, candidateIds:
  list<string>}> $rows = []`; `#[LiveProp] ?string $presetAction`, `#[LiveProp] ?string
  $presetCollectionId` (from the URL, validated); `#[LiveProp(writable: true)] string $action`
  (current tab of the action bar); writable form props `person`, `notes`, `collectionId`,
  `comment`; `#[LiveProp] ?array $notice` (`{type, ean, puzzleId}` — rendered as
  `data-multiscan-notice="duplicate|found|unknown|ambiguous"` for the bridge, cleared on the next
  action); `#[LiveProp] ?array $recap`; `#[LiveProp] ?string $error` (translation key + params).
- Actions:
  - `scan(#[LiveArg] string $ean)` — `Ean::tryFrom` (invalid → notice `invalid`); duplicate by
    normalised EAN → notice `duplicate`; `GetMultiscanCandidates::forEan()`; 0 → row `unknown`
    (PR 2 opens the sheet here); 1 → resolved (but duplicate by puzzleId → notice `duplicate`);
    >1 → auto-pick the single candidate present in `statuses->collection ∪ lent ∪ borrowed`, else
    row `ambiguous` with `candidateIds`.
  - `choose(ean, puzzleId)`, `remove(ean)`, `clear()`.
  - `apply()` — refuse when `!profile->activeMembership` (`error = 'multiscan.members_only'`);
    build the report; dispatch the batch message; on success `rows` = unresolved rows only,
    `recap = {action, applied: list<puzzleId>, skipped, counterpartyName}`; catch
    `MultiscanBatchRejected | PlayerNotFound | CannotLendToSelf | CollectionNotFound` → `error`.
- `getRows()` hydrates once per render: `GetPuzzleOverview::byIds()` + `GetUserPuzzleStatuses` →
  `list<MultiscanRow>` (`src/Results/MultiscanRow.php`: overview, state, chip key + params,
  eligibility per action, candidates). `getActionBar()` returns counts per action from
  `MultiscanEligibility`.
- Template: rows list (`id="multiscan-row-{ean}"`, cover, name, pieces, brand, chip, per-row
  reason under the active action, chooser for ambiguous), **Unresolved** section, sticky bottom
  action bar (segmented action switch → shared-input fields → "Apply to N" button), recap card,
  error alert. Only `<video>`-free markup; the scanner viewfinder is a sibling.
- Tests `tests/Component/MultiscanTrayTest.php` (LiveComponent test helpers as in
  `ReferralCodeInputTest`): scan resolves; duplicate EAN and duplicate puzzle both refused; ambiguous
  with auto-pick vs chooser; unknown row excluded from counts; `apply()` refused for non-member;
  `apply()` keeps unresolved rows and builds the recap; every render ≤ N queries
  (`QueryCountAssertions`).

### Page
- `src/Controller/MultiscanController.php` — GET, `#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]`,
  route `multiscan`, paths `cs /multiscan`, `en /en/multiscan`, `es /es/multiscan`, `ja /ja/multiscan`,
  `fr /fr/multiscan`, `de /de/multiscan`. Non-member → render the same page in **teaser mode**
  (locked viewfinder + `#membersExclusiveModal` button) instead of a redirect, so the link from the
  library still explains the feature. Query params: `action` (enum slug), `collection` (uuid or
  `__system_collection__`, must be the player's).
- `templates/multiscan/index.html.twig` — header, `_scanner.html.twig` (viewfinder, zoom, typed-EAN
  input with `capture`-less `inputmode="numeric"`, mute toggle), `<twig:MultiscanTray
  :presetAction :presetCollectionId />`, both wrapped by `data-controller="multiscan"`.
- Entry points: `templates/puzzle_library.html.twig` header button (member: link, non-member:
  locked button — same pattern as *Create collection*); `templates/lend-borrow/detail.html.twig`
  "Scan returned puzzles" (`?action=return`); `templates/collections/detail.html.twig` "Scan puzzles
  into this collection" (`?action=add_to_library&collection=…`), own profile only.
- Tests `tests/Controller/MultiscanControllerTest.php`: guest → login redirect; non-member → 200
  teaser, no tray; member → 200 with tray; invalid `collection` ignored. Add the route to the
  `BlocklistCanaryTest` / `PrivateProfileCanaryTest` allowlists (reason: viewer's own data only).

### JavaScript
- `assets/controllers/barcode_scanner_controller.js` — add `static values = { continuous: Boolean,
  cooldownMs: { type: Number, default: 2500 }, feedback: Boolean }`. In continuous mode: after an
  accepted read do **not** `stopScanning()`; keep `acceptedAt[code]` and ignore a code inside the
  cooldown; `pause()` / `resume()` (keep the stream, stop the loop) as Stimulus actions and on
  `barcode-scan:pause` / `barcode-scan:resume` window events; `flash(kind)` draws a green (found) /
  amber (duplicate) / red (unknown) frame for 300 ms; `feedback(kind)` = `navigator.vibrate`
  pattern + a Web Audio beep (oscillator, no asset; muted by the toggle, remembered in
  `localStorage`). Native bridge: in continuous mode `handleNativeScanResult` emits the event and
  re-opens the native scanner once the bridge calls `resume()`.
- `assets/controllers/multiscan_controller.js` — `getComponent(trayTarget)`; on
  `barcode-scanner:scanned` and on Enter in the typed input → `component.action('scan', {ean})`,
  with a client-side in-flight guard + retry with backoff on `response:error` (keeps the EAN, shows
  the "Couldn't check, retrying" toast — never "not found"); on `render:finished` read
  `data-multiscan-notice` → `barcode-scanner` feedback + mini-toast (`toast:show`, the unused
  `toast_controller.js` API), and `data-multiscan-sheet-open` → pause/resume. Strings via
  `data-multiscan-*-value` from translations.

### Translations
`multiscan.*` keys (title, meta, instructions, chips, reasons, actions, recap, errors, toasts,
teaser) in **all six locales** (121 keys each).

### Measured cost (2026-09-22)
- Read side on production data: EAN lookup ~1 ms (trigram index), exact-code write guard ~1 ms,
  hydration of 20 rows 0.2 ms, statuses of the heaviest library (1,722 items) ~7 ms, brand list for
  quick-add 15 ms (only while the quick-add form is open). One scan = 6 queries regardless of tray size
  (guarded by `testScanAndApplyStayWithinAQueryBudgetWhateverTheTraySize`).
- Write side, 20-puzzle batches on the local stack (PHP included): lend 200–330 ms (10–16 queries per
  puzzle, the notification event is the bulk), return 70–100 ms, borrow ~230 ms, add to library
  ~200 ms (7 queries per puzzle), wishlist ~20 ms. At 50 puzzles: lend to a registered player 0.9 s
  (799 queries, SQL only 93 ms - the rest is PHP in the per-puzzle notification handler), borrow
  0.9 s, lend to a name 0.34 s, return 0.15–0.2 s, add to library 0.3 s, wishlist 0.02 s. Cost is
  linear in the batch, so the tray is capped at `MultiscanTray::MAX_ROWS = 50` (worst case under
  1 s); a pile larger than that is applied in two rounds. The aggregated-notification follow-up is
  also the main speed-up for lend/borrow.

### Gotchas met while building
- **Batch return needs a flush + clear per puzzle.** A return inserts a `lent_puzzle_transfer` that
  points at the `lent_puzzle` row it deletes; the `LendingTransferCompleted` event dispatched inside
  the single handler flushes in a savepoint, after which the transfer still references the removed
  entity and the next flush reports it as "new". `ReturnLentPuzzlesHandler` flushes and clears the
  entity manager after every puzzle (see the comment there). Lend and borrow batches are unaffected.
- **Fixture EANs had wrong check digits.** `Ean` validates GS1 mod-10, so the multiscan tests use new
  constants on `PuzzleFixture` (`EAN_PUZZLE_*`, `EAN_SHARED_4000_5000`, `EAN_UNKNOWN`) and the
  fixture manufacturers got `eanPrefix` values (Ravensburger `4005556`, Trefl `5900511`).
- **A 14-digit code with a zero indicator digit (GTIN-14) is accepted as the EAN-13 inside it**;
  a scanner reading `0` + EAN-13 must not be reported as invalid.
- **Dev browser check needs the asset caches purged** (`/-/reset`) before a new Stimulus controller
  loads - the dev service worker pins `/build/app.js` (see memory `project_dev_asset_caching_gotcha`).
- `CollectionNotFound` (an HTTP exception) arrives **unwrapped** from the bus
  (`UnwrapHttpExceptionMiddleware`); `MultiscanBatchRejected` arrives wrapped in `HandlerFailedException`.

---

## PR 2 — Not-found resolution: link, quick add, recheck

### Query
- `src/Query/FindPuzzlesByExactEan.php::ids(Ean $ean): list<string>` — exact **list-member** match
  over the comma list (`:n = ANY(string_to_array(replace(ean, ' ', ''), ','))`), **including hidden
  puzzles** (write-side guard only; never used for display).

### Message + handler
- `src/Message/LinkEanToPuzzle.php` — `puzzleId, playerId, string $ean`.
- `src/MessageHandler/LinkEanToPuzzleHandler.php`:
  1. `Ean::tryFrom` or throw `InvalidEan`.
  2. `FindPuzzlesByExactEan::ids()` contains `puzzleId` → return (idempotent). Contains another id
     (hidden or not) → throw `EanAlreadyAssigned` (rendered generically).
  3. Puzzle `ean` empty → `updateProductIdentifiers(ean: normalized, …)` and persist a
     `PuzzleChangeRequest` with `originalEan = null`, `proposedEan = normalized`, `originalName`/
     `originalPiecesCount`/`originalManufacturerId` filled, then `->approve($player, $now)` (self-
     approved audit row; visible under `GetPuzzleChangeRequests::allApproved()`).
  4. Puzzle has a different `ean` → persist a **pending** `PuzzleChangeRequest` with `proposedEan =
     existing . ', ' . normalized`; do not touch the puzzle. A pending request with the same
     `proposedEan` for this puzzle already existing → return (idempotent).
  Tests `tests/MessageHandler/LinkEanToPuzzleHandlerTest.php` for each branch + hidden puzzle.
- `PuzzleAddController`: accept `?ean=` and prefill `PuzzleAddFormData::$puzzleEan` (used by the
  "Open the full form" link; the JS already reveals the new-puzzle fields when EAN is set — verify).

### Component additions
- Props: `#[LiveProp] ?string $resolvingEan` (sheet open), `#[LiveProp(writable: true)] string
  $resolveQuery = ''` (`data-model="debounce(250)|resolveQuery"`), `#[LiveProp] ?string
  $resolveBrandId` (from `GetManufacturers::allByEanPrefix`), writable quick-add props `newName`,
  `newPiecesCount`, `newBrand` (id or free text, prefilled), `#[LiveProp] ?string $resolveError`.
- `scan()` on 0 candidates → row `unknown` **and** `resolvingEan = ean` (sheet opens, camera paused
  by the bridge). Sheet header: EAN, detected brand, the "try the other barcode" hint when no brand
  matched and the code is not EAN-13.
- `getResolveResults()` → `SearchPuzzle::byUserInput(brandId: $resolveBrandId, search:
  $resolveQuery, pieces: PiecesRange::any(), tag: null, limit: 8)` only when `resolveQuery` ≥ 2
  chars; rendered as buttons with cover image.
- Actions: `link(ean, puzzleId)` → dispatch `LinkEanToPuzzle`, row → resolved (also when the
  handler only queued a pending request), notice `linked`; `createPuzzle(Request $request)` (button
  `data-live-action-param="files(photo)|createPuzzle"`, `<input type="file" name="photo"
  accept="image/*" capture="environment">` optional) → validate name/pieces/brand, dispatch
  `AddPuzzle(Uuid::uuid7(), userId, name, brand, pieces, photo, ean->digits, null)`, row → resolved
  with the new id, notice `created`; `skipResolve()` → row stays `unknown` (Unresolved section),
  sheet closes; `openResolve(ean)` from an unresolved row; `recheckUnknown()` (bridge calls it on
  `visibilitychange` → visible) re-runs the lookup for every unknown row — covers "added it in the
  full form in another tab".
- Idempotency: `createPuzzle` first calls `FindPuzzlesByExactEan::ids()`; a hit resolves the row to
  that puzzle instead of creating (the hidden case throws the same generic error as link).
- Tests: `MultiscanTrayTest` gains sheet-open on unknown, search results, link, quick add with and
  without photo, skip → unresolved, recheck resolves after an external add.

### Translations
`multiscan.resolve.*` - shipped in all six locales.

---

## Follow-ups (after PR 2, tick when shipped)

- [ ] Aggregated lending notification ("Anna lent you 6 puzzles") — new `NotificationType`, emitted
      by `LendPuzzlesToPlayerHandler` instead of N single ones
- [ ] Tray persistence across a reload (bridge mirrors `rows` to `sessionStorage`, `restore()` action)
- [ ] Native apps: multi-mode scanner in the iOS/Android shells (today: re-open per code)
- [ ] More actions: mark solved without a time (shared date), list for swap/free, remove from library
- [ ] "Undo last batch" for lend/return (a reverse batch)
- [ ] Funnel numbers: scans per session, not-found rate before/after, links created (Loki or a
      `multiscan_event` table — decide when there is traffic)

## Decisions taken

1. All six locales (Jan, 2026-09-22).
2. Beep on by default, mute toggle remembered per device in `localStorage` (`msp-scan-sound`).
3. Lending a puzzle that is **not** in your library is allowed from the tray (as from the puzzle
   detail), with the informational chip *Not in your library*.
