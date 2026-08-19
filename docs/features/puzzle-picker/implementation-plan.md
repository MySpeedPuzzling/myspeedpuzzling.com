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

Everything free unless said otherwise; the criteria strip what a guest / non-member may not use.

- **Solve-count range** replaces the three-way `solved` enum inside the criteria: the state is one range
  `[solvedMin, solvedMax]` — `solved=never` = [0, 0], `solved=before` = [1, ∞[, `solved_min` /
  `solved_max` (0–999) refine or override it bound by bound (`solved=before&solved_max=5` → [1, 5];
  a collision such as `solved=never&solved_min=2` keeps the explicit bound). The public `solved`
  property is derived (Never / Before / Any — what the radio shows) and the canonical URL keeps
  `solved=never|before` for the two named shapes, `solved_min` / `solved_max` otherwise. SQL:
  `COALESCE(s.solve_count_any, 0) + COALESCE(ts.solve_count, 0) >= :solvedMin AND (:solvedMax::int IS NULL
  OR … <= :solvedMax)`. One chip for the whole constraint (never / before / exact / between / at least /
  at most), its × clears shape and bounds together.
- **Not solved since**: `since=<1–999>&since_unit=d|w|m` (days default), `since_require_solved=1`. The
  query turns it into a timestamp with the clock (`now->modify("-6 months")`) and compares it with my last
  solve incl. team participation: `(:notSolvedSince::timestamp IS NULL OR GREATEST(s.last_solved_at,
  ts.last_solved_at) IS NULL OR GREATEST(…) < :notSolvedSince::timestamp) AND (:sinceRequireSolved = 0 OR
  GREATEST(…) IS NOT NULL)` — never-solved puzzles are included by default (§8 decision 6). Enum
  `PuzzlePickerSinceUnit`. Chips `puzzle_picker.chips.since.{d,w,m}` / `since_solved.{d,w,m}` (pluralised).
- **My time**: `my_time=fastest|latest|first`, `my_time_op=lt|gt` (`lt` default), `my_time_minutes`
  (1–1440; the criteria expose `myTimeSeconds()`). Half a filter is no filter. SQL
  `AND s.<fastest|latest|first>_seconds <|> :myTimeSeconds` — column and operator come from the enums
  `PuzzlePickerMyTime` / `PuzzlePickerMyTimeOperator`, never from the request; puzzles without a solo time
  never match (NULL comparison).
- **Community results**: `community=few|rated|popular` (`PuzzlePickerCommunity`: solo solves ≤ 5 / ≥ 20 /
  ≥ 50, `puzzle_statistics.solved_times_solo_count`, `COALESCE(…, 0)` for "few" so puzzles without a
  statistics row count as few). `puzzle_statistics` is joined before the LIMIT once when either this
  filter or the community time budget needs it. Free for guests too (puzzle attribute).
- **Specific collections (members)**: `collections[]` = collection uuid and/or `Collection::SYSTEM_ID`
  (`__system_collection__`), deduplicated, max 20, stripped for non-members and guests; when set the
  source is implied `mine`. The `my_items` CTE aggregates `array_remove(array_agg(ci.collection_id),
  NULL) AS collection_ids, bool_or(ci.collection_id IS NULL) AS has_system`; predicate
  `((:includeSystemCollection = 1 AND mi.has_system) OR mi.collection_ids && :collectionIds::uuid[])`
  (custom ids as a Postgres array literal). Borrowed puzzles are on the shelf but in no collection, so
  they drop out; a foreign collection id matches nothing (only my own items are aggregated). Chips
  `collection:<id>` replace the shelf chip; the filters modal shows a checkbox list (system first) built
  by the controller from `GetPlayerCollectionsWithCounts` for members, the locked pattern (lock + members
  modal button, no button for guests) otherwise. `collections/detail.html.twig` got "Pick from this
  collection" (`.puzzle-picker-collection-link`) on the viewer's own collections: members deep-link with
  `collections[]=<id|sentinel>`, non-members get `?source=mine`.
- **Remembered filters (session)**: `PuzzlePickerController::SESSION_KEY = 'puzzle_picker.filters'`.
  Signed-in players only — guests never touch the session (`$request->getSession()` is only called when
  the profile is not null; a test pins "no cookie, session not started" for guest requests). Any request
  with a query string (chips, form, presets, spin, even a seed-only URL) stores
  `criteria->withSeed(null)->toQueryParams()`, an empty result removes the key. A bare visit with a stored
  value builds the criteria from it (`PuzzlePickerCriteria::fromQuery()`), renders on the bare URL without
  a redirect, shows a "Your last filters" marker (`.puzzle-picker-remembered`) and the "Reset" link
  (`.puzzle-picker-reset`) points to `?reset=1`, which removes the key and renders the defaults (no
  redirect). The modal footer / empty-state reset links use the same `reset_url` (bare URL for guests).
  The empty state's "Pick from all puzzles" now keeps the other filters and only widens the source
  (collections dropped, since they imply the shelf).
- **Presets** (`PuzzlePickerPreset` enum, rendered from `presets` + `active_preset` passed by the
  controller): Surprise me (`source=mine`), Something new (`source=mine&solved=never`), Quick one
  (`predicted_max=60`), Dust off the shelf (`since=6&since_unit=m&since_require_solved=1`), Rating grind
  (`pieces[]=500&solved=never&community=rated`), Beat my record (`solved=before&gap=slower&order=gap_slower`,
  predictions only). `PuzzlePickerCriteria::activePreset()` compares the normalised query params
  (seed aside), skipping presets the player is not eligible for; the bare default highlights "Surprise me".
  Every chip is `rel="nofollow"`, `data-preset="<value>"`.
- **Share**: `_share_button.html.twig` in `_results.html.twig` with `url('puzzle_picker',
  criteria.toQueryParams(seed))` — the seeded URL of the current draw.
- **Not shipped (roadmap)**: the free "longest not solved first" / "fewest solves first" pick orders from
  README §3, hh:mm inputs for the my-time threshold (a minutes input is enough for now).
- Tests: `tests/Value/PuzzlePickerCriteriaTest.php` (range grammar, since, my time, community, collections
  gating, chips, presets), `tests/Query/GetPuzzlePickerSuggestionsTest.php` (every predicate against the
  fixtures — PLAYER_REGULAR's 11 solved puzzles, PLAYER_PRIVATE's team participation, PLAYER_WITH_STRIPE's
  collections incl. the system sentinel and borrowed / lent-out puzzles, community thresholds by editing
  `puzzle_statistics` inside the test transaction), `tests/Controller/PuzzlePickerControllerTest.php`
  (chips + form state, presets, share URL, session memory / reset / seed-only forgetting, guest
  session-free, member collections list, locked list) and `tests/Controller/CollectionDetailControllerTest.php`
  ("Pick from this collection" for member / non-member / other's collection / guest + the deep link).

## PR 4 — My times (+ predictions) in collections

Shipped as designed in README §5, with one deliberate change: the persisted mode is **not** on
`PlayerProfile` — that DTO is loaded on every signed-in page, and putting the new column there would
have made the whole site depend on the migration; a tiny read query serves the two pages that need it.

- **Preference**: `CollectionDisplayMode` string enum (`off` | `times` | `times_predictions`, helpers
  `showsTimes()` / `showsPredictions()`), `Player::$collectionDisplayMode` (`enumType` column, default
  `off`, `changeCollectionDisplayMode()`), generated migration `Version20260819010848` (one `ALTER TABLE
  player ADD collection_display_mode VARCHAR(255) DEFAULT 'off' NOT NULL`). Read model
  `GetCollectionDisplayMode::forPlayer()` (Off for a missing row). Message `ChangeCollectionDisplayMode`
  + handler (loads the Player, sets, no flush).
- **POST** `ChangeCollectionDisplayModeController` — route `collection_display_mode` (six locale paths,
  e.g. `/en/collection-display-mode`), `IS_AUTHENTICATED_FULLY`, session-backed CSRF token
  `collection_display_mode` (the form only ever renders for signed-in viewers, who already have a
  session), body field `mode`; unknown mode → 400, bad token → redirect without change; a non-member or a
  predictions-opted-out member asking for `times_predictions` is downgraded to `times`; redirects to a
  `ReturnUrl::tryFrom()`-validated `?return=` (the collection page puts its own path there) or to the
  viewer's puzzle library.
- **Read models**: `GetPlayerPuzzleTimes::forPuzzles(playerId, puzzleIds)` → `PlayerPuzzleTimes` per
  puzzle (`solveCountAny`, `solveCountSolo`, `fastestSeconds`, `latestSeconds`, `lastSolvedAt`,
  `latestDiffersFromFastest()`), one query mirroring the picker's `my_solves` / `my_team_solves` CTEs
  restricted to the listed puzzle ids; never-solved puzzles are absent. Predictions reuse
  `GetPlayerPredictions::forPuzzles()` (PR 2).
- **`ResolveCollectionDisplay::forViewer(profile, puzzleIds)`** (service shared by
  `CollectionDetailController` and `SystemCollectionDetailController`) → `CollectionDisplay` (effective
  mode + the two maps). Eligibility for predictions = `activeMembership && !timePredictionsOptedOut`
  (`ResolveCollectionDisplay::predictionsAllowed()`, also used by the POST). Guests get Off without a
  query. `CollectionDisplay::templateParameters()` passes `display_mode` always and `my_times` /
  `predictions` **only for the active mode** — the item partial keys on `my_times` being defined.
- **UI**: `collections/_display_mode.html.twig` — "Display ▾" (`btn-outline-secondary btn-sm`,
  `.collection-display`) in the header actions of `collections/detail.html.twig` for every signed-in
  viewer (own or other's public collection; the toggle shows the active mode). The menu is one POST form
  with submit buttons Off / My times / My times + predictions; the third is the locked pattern
  (`.collection-display-locked`, `ci-locked` + `#membersExclusiveModal`) for non-members and a disabled
  item + opt-out notice with the settings link (`.collection-display-opted-out`) for opted-out members.
  `_puzzle_library_item.html.twig` (page_context `collection`, `my_times` defined) renders
  `.collection-my-times` under the manufacturer line: "Not solved yet" or "Solved N× · fastest 00:28:20 ⭐
  [· latest 00:31:40 only when it differs] · last time 10 days ago" ("No solo time yet – solved in a
  team" for team-only solves), plus the `.collection-prediction` pill `⏱ ~31min 30s` (title = "Your
  prediction: range · personal/statistical explanation", `bi-person-check` vs `bi-graph-up` glyph) when a
  prediction exists; `data-my-fastest-seconds` / `data-predicted-seconds` on the item column (empty when
  unknown) for future client-side sorting. Turbo-stream re-renders of a single item (`_library_item_stream`)
  do not carry `my_times`, so a replaced card shows the block again on the next full render.
- **Translations**: `collections.display.{label,hint,mode.off,mode.times,mode.times_predictions,
  not_solved_yet,solved_times,fastest,latest}` in all six locales; the rest reuses
  `puzzle_picker.card.*`, `puzzle_times.my_attempts.*`, `puzzle_intelligence.prediction.*`,
  `membership.members_exclusive_short`, `statistics.change_in_settings`.
- **Tests**: `tests/MessageHandler/ChangeCollectionDisplayModeHandlerTest.php`,
  `tests/Query/GetPlayerPuzzleTimesTest.php` (PLAYER_REGULAR × PUZZLE_500_02 = 3 / 1700 / 1700, slower
  latest on PUZZLE_1000_02, team participation, absent never-solved),
  `tests/Controller/ChangeCollectionDisplayModeControllerTest.php` (auth, member modes, non-member and
  opted-out downgrade, CSRF, bad mode, hostile `return`), `tests/Controller/CollectionDetailControllerTest.php`
  (control for signed-in viewers only incl. locked / opted-out variants, my-times block on own, other's
  and system collections, predictions pill for an eligible member, non-member served "times" only).

## PR 5+ — Reach & polish (roadmap)
Hub mini-picker, stopwatch prediction line, search listing pill, unlimited "more" via Turbo Frames,
wishlist source, tag filter, "why this puzzle", MSP expected-gain estimate, API, materialised
`player_puzzle_prediction` escape hatch.
