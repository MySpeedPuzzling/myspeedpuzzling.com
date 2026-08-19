# Puzzle Picker — staged implementation plan

Design: [`README.md`](README.md). Every stage is one PR, stacked on the previous one
(`puzzle-picker/1-foundation` → `puzzle-picker/2-insights` → `puzzle-picker/3-filters` →
`puzzle-picker/4-collections`), independently deployable and useful on its own; later stages only add
filters/places, they never rewrite earlier ones.

House rules that apply to every stage:
- read model = raw-SQL query class (`readonly final`, DBAL `Connection`, heredoc SQL, null-tolerant
  optional filters like `SearchPuzzle`) + `Results` DTO with `fromDatabaseRow()`; no entities in the
  read path; state changes only through Messenger handlers; `ClockInterface` for time;
  `Uuid::uuid7()`; single-action controllers.
- **All six locales** for every string (`translations/messages.{cs,de,en,es,fr,ja}.yml`, same key
  tree, natural translations — not machine-literal) and for every route path.
- **Tests for everything**: query (`tests/Query`, `KernelTestCase`, fixture constants), value
  objects (`tests/Value`), controllers (`tests/Controller`, `WebTestCase` + `TestingLogin::asPlayer`),
  handlers (`tests/MessageHandler`).
- Full check suite before every commit: `docker compose exec web composer run phpstan`,
  `docker compose exec web composer run cs-fix`, `docker compose exec web vendor/bin/phpunit
  --testsuite "Project Test Suite"`, `docker compose exec web php bin/console
  doctrine:schema:validate`, `docker compose exec web php bin/console cache:warmup`.
- Do not rebuild JS assets by hand (`js-watch` does it); template changes need
  `docker compose restart web` to show up (FrankenPHP worker mode).

---

## PR 1 — Foundation: page (tool + guest landing), card, seeded random pick, free filters, entry points

**Ships:** a working fortune wheel for signed-in players, an indexable SEO landing page with a live
demo for guests, three entry points. No members-only bits yet.

### Route & controller
- `src/Controller/PuzzlePickerController.php` — GET, **no `IsGranted`**; route `puzzle_picker`, paths
  `cs /co-skladat-dal`, `en /en/what-to-solve-next`, `es /es/que-puzzle-armar`,
  `fr /fr/quel-puzzle-faire`, `de /de/welches-puzzle-als-naechstes`, `ja /ja/次のパズル`.
- `RetrieveLoggedUserProfile` → `PlayerProfile|null`. Criteria via
  `PuzzlePickerCriteria::fromRequest($request, isAuthenticated: $profile !== null)`; a missing/invalid
  `seed` is generated server-side (`bin2hex(random_bytes(4))`), the generated seed is **not**
  redirected into the URL (the bare URL stays canonical), it is only used for the "Pick another"/share links.
- Fetch `PICK_SIZE = 6` suggestions; render `puzzle_picker/index.html.twig` (signed-in) or
  `puzzle_picker/landing.html.twig` (guest). Both include `_card.html.twig`, `_filters_modal.html.twig`,
  `_results.html.twig` (card + hidden 5 + buttons + "1 of N") and `_empty_state.html.twig`.
- Guest: source forced to `any`, solved forced to `any`; personal filter groups render disabled with a
  sign-in note; the landing adds marketing sections (what/how/filters/members teaser, sign-in +
  register CTAs). SEO: `{% block robots %}` = `noindex, follow` when `app.request.query.count > 0`,
  else `index, follow`; canonical/hreflang come from `base.html.twig` automatically; add
  `puzzle_picker` to `SitemapStaticController::STATIC_ROUTES`; every filter/spin link `rel="nofollow"`.

### Value object — `src/Value/PuzzlePickerCriteria.php` (+ enums `PuzzlePickerSource`, `PuzzlePickerSolved`)
- Fields: `source` (`mine`|`not_mine`|`any`; default `mine` for signed-in, `any` for guests),
  `solved` (`any`|`never`|`before`), `pieces` = `list<array{int|null,int|null}>` parsed from
  `pieces[]` values `N` (exact), `A-B`, `A-` (at least), `-B` (at most) — invalid entries dropped,
  max 12; `brandIds` = `list<string>` from `brand[]` (uuid-validated, max 20); `includeLentOut`
  (`lent=1`, default false); `seed` (`[a-z0-9]{4,16}`, else null); constants `PICK_SIZE = 6`,
  `PIECES_CHIPS = [54, 100, 150, 200, 300, 500, 750, 1000, 1500, '2000-']`.
- Methods: `fromRequest(Request, bool $isAuthenticated)`, `toQueryParams(?string $seedOverride = null)`
  (only non-default values, so bare defaults produce an empty array), `withSeed()`, `isDefault()`,
  `activeFilters(): list<PuzzlePickerActiveFilter>` (`key`, translation key + params, and the query
  params *without* that filter, for the × chips), `hasPersonalFilters()`.

### Query — `src/Query/GetPuzzlePickerSuggestions.php`
- `pick(PuzzlePickerCriteria $criteria, null|string $playerId, int $limit, int $offset = 0): PuzzlePickerPick`
  (`src/Results/PuzzlePickerPick.php`: `list<PuzzlePickerSuggestion> $suggestions`, `int $totalMatching`).
- SQL exactly as README §6.2: CTEs `my_solves` (all types counted; solo-only for times; `suspicious =
  false`, `seconds_to_solve IS NOT NULL`; `COALESCE(finished_at, tracked_at)` as solve date), `my_team_solves`
  (rows where I am a non-owner participant: `pst.player_id <> :playerId AND (pst.team->'puzzlers')::jsonb @>
  jsonb_build_array(jsonb_build_object('player_id', :playerId::text))` → adds to `solve_count_any` only),
  `my_items`, `lent_out`, `borrowed`; `picked` = filter + `count(*) OVER()` + `ORDER BY md5(:seed ||
  p.id::text) LIMIT/OFFSET`; hydration joins (`manufacturer`, `puzzle_statistics`) *after* the limit.
  `approved = true AND (hide_until IS NULL OR hide_until < :now)`; image via `CASE WHEN hide_image_until >
  :now THEN NULL ELSE image END`. Null player → all personal CTEs are empty (`WHERE :playerId::uuid IS NOT
  NULL AND …`), source `any`.
- Pieces ranges are expanded into `(p.pieces_count BETWEEN :pmin0 AND :pmax0 OR …)`; brands via
  `p.manufacturer_id = ANY(:brandIds)` (`ArrayParameterType::STRING`).

### Result — `src/Results/PuzzlePickerSuggestion.php`
`puzzleId, puzzleName, puzzleAlternativeName, puzzleIdentificationNumber, puzzleEan, manufacturerId,
manufacturerName, piecesCount, puzzleImage (null when hidden), puzzleImageRatio, communitySolvedCountSolo,
communityAverageTimeSolo (?int), mySolveCountAny, mySolveCountSolo, myFastestSeconds, myFirstSeconds,
myLatestSeconds, myLastSolvedAt (?DateTimeImmutable), inMyCollection, isBorrowed, isLentOut` +
`fromDatabaseRow()`. Later stages add nullable fields (additive).

### Templates & assets
- `templates/puzzle_picker/index.html.twig`, `landing.html.twig`, `_results.html.twig`, `_card.html.twig`,
  `_filters_modal.html.twig`, `_empty_state.html.twig`. Card per README §4 (compact; reuse
  `puzzle_image`, `puzzlingTime`/`compactTime`, `puzzle_times.my_attempts.*` wording, CTAs
  `stopwatch_puzzle`, `puzzle_add`, `puzzle_detail`). Filters modal: `<form method="get">`, Bootstrap
  modal `modal-fullscreen-sm-down`, brand `<select multiple>` via the existing `tomselect-sync`
  controller + `puzzle_search_filter_options`, pieces chips as checkbox pills + min/max inputs, source and
  solved as radio pills, "include lent out" checkbox, Apply/Reset. Active-filter chips with × links.
- `assets/controllers/puzzle_picker_reveal_controller.js` — reveal the 5 hidden cards, hide the button.
- Entry points: `templates/puzzles.html.twig` (small outline button next to the H1),
  `templates/base.html.twig` profile dropdown item, `templates/puzzle_library.html.twig` header actions
  (own profile). Icon: `bi-shuffle`.
- Translations `puzzle_picker.*` in all six files (page, landing sections, filters, card, chips,
  empty states, menu label, meta title/description).

### Tests
- `tests/Query/GetPuzzlePickerSuggestionsTest.php`: seed determinism + offset paging without repeats;
  source `mine` / `not_mine` / `any`; borrowed puzzle appears in `mine`, lent-out puzzle excluded
  unless `includeLentOut`; solved semantics incl. the fixture team solve (team-001: PLAYER_REGULAR +
  PLAYER_PRIVATE on PUZZLE_1000_01 → counts as solved for both, no solo times); my stats for
  PLAYER_REGULAR on PUZZLE_500_02 (3 attempts 36:40 → 31:40 → 28:20: fastest 28:20, first 36:40,
  latest 28:20, count 3); unapproved puzzle never returned; `totalMatching`; guest (`null` player)
  works with `any` and returns no personal data.
- `tests/Value/PuzzlePickerCriteriaTest.php`: parsing/defaults, guest forcing, `toQueryParams`
  round-trip, chip-removal params, pieces grammar, invalid input dropped.
- `tests/Controller/PuzzlePickerControllerTest.php`: guest 200 + `noindex` only with query params +
  canonical present; signed-in 200 with card; entry-point buttons present on `/en/puzzle` and the
  library; sitemap-static contains the route.

---

## PR 2 — Insights layer for members: prediction on the card + insights filters

Gated by `activeMembership && !timePredictionsOptedOut` (difficulty tier only by `activeMembership`).
The controller computes `insightsAllowed` / `predictionsAllowed` once and hands them to
`PuzzlePickerCriteria::fromRequest()`, which strips every non-eligible value — the gating lives in one place.

- `src/Services/PuzzleIntelligence/TimePredictionCalculator.php`: the pure math (personal: ratio ×
  Holt blend, 70 % floor, MAD range; statistical: baseline × difficulty, p25/p75 range) extracted out of
  `GetPlayerPrediction`, which now only fetches rows and delegates. `src/Query/GetPlayerPredictions.php`
  (bulk): `forPuzzles(playerId, puzzleIds[])`, `forAllSolvedPuzzles(playerId)` — attempts in one query,
  the two ratio tables in one each, statistical inputs for the asked ids in one; per-request memo
  (`ResetInterface`); `tests/Query/GetPlayerPredictionsTest.php` pins parity with `forPuzzle()` for
  every fixture player × puzzle.
- Criteria: `difficulty[]` (tiers 1–6), `gap` (`slower`|`faster`) + `gap_min` (minutes, default 1),
  `predicted_max` (minutes; the "I have ~N minutes" control — my prediction for members with
  predictions, community `average_time_solo` otherwise, `usesPersonalPrediction()`), `order`
  (`random`|`gap_slower`|`gap_faster`); non-eligible → dropped server-side.
- Query: only when an insights filter is active are `puzzle_difficulty` / `player_baseline` /
  `puzzle_statistics` joined *before* the LIMIT; personal predictions injected via
  `LEFT JOIN unnest(:ids::uuid[], :seconds::int[])`; `predicted = COALESCE(personal, round(baseline ×
  difficulty))` and `gap = fastest − predicted` computed in `CROSS JOIN LATERAL` so they can be filtered
  and ordered on; gap orders = `gap DESC/ASC NULLS LAST, md5(seed || id)`. Result gains
  `difficultyTier`, `difficultyConfidence` (always hydrated post-LIMIT), `predictedSeconds`, `gapSeconds`
  (only when computed pre-LIMIT). The card renders the full `TimePredictionResult` from
  `GetPlayerPredictions::forPuzzles()` for the ≤ 6 picked puzzles (same numbers as the puzzle detail page).
  Measured on a prod-like copy: "any puzzle" pool, 1.7k injected predictions, gap filter + gap order ≈ 27 ms.
- Card: difficulty tier (icon + name) for members, prediction row (wording of
  `_difficulty_section.html.twig:65-98`) + "could beat your record by N / N slower than your best / right at
  your best"; one locked row for non-members; opt-out notice for opted-out members; guests nothing.
  Filters modal: one "Insights (members)" group (tier pills + "only puzzles with enough data", compared to
  my prediction + "by at least N min", pick order), locked for non-members, prediction controls disabled
  for opted-out members; time budget in its own free group with the engine helper text; preset
  "Beat my record" (link / muted / 🔒).
- Tests: parity; gap both directions; gap orders (deterministic incl. seed tie-break, paging windows);
  personal vs. community budget; tier filter; criteria stripping for non-member / opted-out / guest;
  controller: member, non-member, guest, opted-out member markup.

## PR 3 — Precision filters, collection targeting, remembered filters, presets, share

- Filters: solve-count range, "last solved more than N days/weeks/months ago" (never-solved included,
  checkbox to require solved-before), my fastest/latest/first `<`/`>` hh:mm, community results
  (few/popular), custom pieces min–max already in PR 1.
- Specific collections (members): `collections[]` → `mi.collection_ids && :ids` (system collection =
  `Collection::SYSTEM_ID` sentinel → `NULL`); "Pick from this collection" on `collections/detail.html.twig`.
- Session-remembered filters (`puzzle_picker.filters`), auto-applied on bare visit, "Reset" chip.
- Presets: "Surprise me", "Something new", "Quick one", "Dust off the shelf", "Rating grind" (free);
  share button with the seeded URL.
- Tests for each predicate (fixtures have lent/borrowed puzzles), collection multi-select incl. system
  collection, session restore.

## PR 4 — My times (+ predictions) in collections

- `CollectionDisplayMode` enum (`off`|`times`|`times_predictions`), `player.collection_display_mode`
  column (generated migration), `PlayerProfile` field, message `ChangeCollectionDisplayMode` + handler,
  POST controller (`IS_AUTHENTICATED_FULLY`, CSRF, redirect back).
- `src/Query/GetPlayerPuzzleTimes.php`: `forPuzzles(playerId, puzzleIds[])` → per puzzle
  `solveCount, fastestSeconds, latestSeconds, lastSolvedAt` (solo, valid).
- "Display ▾" control on `collections/detail.html.twig` (both system & custom, own or others' public):
  Off / My times / My times + predictions (locked for non-members). Item partial shows the my-times
  block (not solved yet / solved N× · fastest ⭐ · latest unless identical) and the prediction pill.
- Tests: handler, controller (member modes, non-member downgrade), query, template smoke.

## PR 5+ — Reach & polish (roadmap)
Hub mini-picker, stopwatch prediction line, search listing pill, unlimited "more" via Turbo Frames,
wishlist source, tag filter, "why this puzzle", MSP expected-gain estimate, API, materialised
`player_puzzle_prediction` escape hatch.
