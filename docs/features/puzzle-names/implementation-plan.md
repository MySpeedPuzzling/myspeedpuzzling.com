# Puzzle names - implementation plan

Design of record: [README.md](README.md). Every phase ships on its own and leaves the site working. Gates for every
PHP change: `composer run phpstan`, `composer run cs-fix`, `vendor/bin/paratest --testsuite "Project Test Suite"`
(as CI), `doctrine:schema:validate`, `cache:warmup`. Query changes get `EXPLAIN ANALYZE` on the dev copy of
production before they ship. New UI strings go to all 6 locales.

Releases follow expand → switch → contract because of blue-green deploys (old and new containers overlap, migrations
run on boot in one transaction).

## Phase 0 - fixes that cannot wait (independent, one small PR)

1. **Picker XSS.** `PuzzleByBrandAutocompleteController` builds option HTML from player-typed name, alternative name,
   brand code and EAN without escaping; Tom Select renders it (`options_as_html`). Render the option with a Twig
   partial (`templates/puzzle/_picker_option.html.twig`, auto-escaped). Unapproved puzzles stay listed (decision 10).
2. **Secret puzzles in the picker.** `SearchPuzzle::byBrandId()` has no `hide_until` filter ("Puzzle Month #1" is
   listed). Add `(puzzle.hide_until IS NULL OR puzzle.hide_until <= :now)`.
3. **Brand names in JS.** `time_form_autocomplete_controller.js` (~606, 631, 661) adds `{text: brand.name}` to an
   `options_as_html` select. Escape before adding.
4. **"1000" search bug.** `trim($search, '0')` (`SearchPuzzle.php:73, 249, 422`, same in `GetMarketplaceListings`)
   strips trailing zeros: "1000" becomes "1" and matches 19,479 puzzles by EAN. Strip leading zeros only; match codes
   as substrings only when the query has 5+ digits.
5. **Dead query.** `EditTimeController` loads `GetPuzzlesOverview::allApprovedOrAddedByPlayer()` (~40k rows) into a
   `puzzles` template variable nobody reads. Delete the call and the class (no other user).

Tests: a puzzle named `<img src=x onerror=…>` comes back escaped; a hidden puzzle is not listed; "1000" matches only by
name/pieces; an EAN ending in 0 still ranks as exact; edit-time page renders.

## Phase 1a - expand: storage and every writer (release 1)

1. **Value objects** (`src/Value/`): `PuzzleName`, `PuzzleNames`, `PuzzleNamesDiff`, `SearchText` (fold + `VERSION`),
   `SearchQuery`, `PuzzleSearchKeys` (builds both keys), `LanguageTag` (BCP 47, base language validated by Symfony
   Intl), `EanList` (exists, extend: canonical string, `e:` lines), `BrandCodeList`.
2. **Entity** `Puzzle`: `name` and `alternativeNames` private-write; new columns per README; constructor and
   `changeNames()` / `updateProductIdentifiers()` / `correctNewlyAdded()` rebuild the keys. **Dual write** until
   phase 1c: `changeNames()` also sets the old `alternativeName` to `legacyAlternativeName()`, so the old search and
   the old release keep working during the overlap.
3. **Migration** generated with `doctrine:migrations:diff`, plus hand-written parts: `SET LOCAL lock_timeout = '5s'`;
   backfill `alternative_names` from `alternative_name` in SQL (trim, collapse spaces, strip control characters, skip
   names equal to the main title after folding, `cs` when the name has Czech letters); the two custom GIN indexes.
   Last migration of its release, deployed outside the `*/15` cron minutes. No table rewrite (new columns without
   volatile defaults).
4. **Rebuild command** `myspeedpuzzling:rebuild-puzzle-search-keys`: dispatches `RebuildPuzzleSearchKeys(afterId, limit)`
   per batch; the handler loads the batch and calls `Puzzle::refreshSearchKeys()`. Run once after the release (and
   whenever `SearchText::VERSION` changes). Idempotent.
5. **All writers** move to the entity methods: `AddPuzzleHandler` (+ `AddPuzzle` message gets `alternativeNames`),
   `correctNewlyAdded()`, `AddPuzzleToCompetitionRoundHandler`, `ApprovePuzzleHandler`, `PuzzleRecordUpdater`
   (change-request approval + moderator edit), `ApprovePuzzleMergeRequestHandler` (union; merged main title becomes
   an other name without a language; survivor keeps its main title until phase 3 adds the editor), `LinkEanToPuzzleHandler`.
   Interim forms that edit one alternative name keep working by editing the first entry of the list.
6. **Audit and history:** `PuzzleMergeSnapshotBuilder` and the `PuzzleRecordUpdater` decision snapshot write
   `alternativeNames` (list); `GetPuzzleHistory` / `PuzzleHistoryChange` read both the old string and the new list.
7. **Reads:** the 30 query files that select `alternative_name` select `alternative_names` (+ `name_language`);
   result DTOs get `PuzzleNames $alternativeNames` via `PuzzleNames::fromJson()`. The ~19 that never used it drop it
   instead. `GROUP BY` users (`GetUnsolvedPuzzles`) need `jsonb` (equality works on jsonb, not json).
8. **API v1:** `alternative_name` = `legacyAlternativeName()` (unchanged meaning), new `alternative_names` list.
   Update `docs/features/api/README.md`.
9. **Caches:** bump `initial_puzzles_v3` (`PuzzleSearch`) and `difficulty_rankings_cache` keys (Redis is shared across
   blue-green).
10. **Tests infrastructure:** `tests/bootstrap.php` creates the two indexes; `tests/TestingDatabaseCaching.php` adds
    `tests/bootstrap.php` to the cache hash; `tests/ComparisonSeeding.php` raw `INSERT` updated; fixtures get names in
    several languages; a test that fixture keys equal `PuzzleSearchKeys` recomputed.
11. **Docs:** `docs/database-indexes.md`, `CLAUDE.md` (feature line + "names and codes change only through `Puzzle`
    methods; search keys are maintained by the entity"), `docs/ravensburger-puzzle-month.md` runbook `INSERT`.

Tests: `SearchTextTest` (Łódź, ß, Ø, full-width, ligatures, katakana), `PuzzleNamesTest` (dedupe rules, union, diff,
shownFor with `pt-BR`), entity keys after every write path, merge keeps both names, history reads old snapshots.

## Phase 1b - switch: search on the keys (release 2, after the rebuild ran and reported 0 empty keys)

1. `SearchQuery` + `PuzzleTextSearch` fragment (next to `HiddenPlayers`): condition, rank (6 tiers), params.
2. `SearchPuzzle::countByUserInput()` / `byUserInput()` / `allByEan()`, `GetMarketplaceListings`,
   `FindPuzzlesByExactEan`, `GetMultiscanCandidates` (exact only; update its tests and the API `?ean=` docs) use it.
   Count and page share the same condition (parity test). No text filter when the folded query is empty.
3. Ordering: `best-match` sort in `PuzzleSearchCriteria` (default when a term is typed), API `sort=best-match`
   additive. `GlobalSearch`: best 15 + "Show all N results" → `/puzzle?search=…`.
4. Client side: library-like lists render one `data-search` attribute (server-folded names + codes);
   `collection_filter_controller.js` folds only the typed query, pinned by a node parity test against `SearchText`
   (like `RelativeTimeParityTest`). Delete dead `puzzle_filter_controller.js` and the attributes on `_puzzle_item`.
   `PlayerSolvedPuzzles::matchesSearch()` uses `SearchText::fold()`. `event_offers_picker` search text includes all
   names, folded.
5. Picker endpoint: `search` = all names, `codes` = codes; Tom Select `searchField` weighted so long name lists do not
   push a puzzle down.
6. Duplicate finders (`GetPuzzleApprovals::possibleDuplicates`, `GetDuplicatePuzzleSignalCandidates`) compare name by
   name (`jsonb_array_elements`), never the joined key.

Tests: ranking order per tier, full-width wildcard stays literal, "1000", exact barcode vs a longer code, brand code
equal to an EAN, two-letter and Japanese queries, count/page parity, best-match default, "Show all" link.

## Phase 1c - contract (release 3)

Stop the dual write. Migration: copy anything the old release wrote into `alternative_name` during the overlap that
the list does not hold, then drop `alternative_name`, `custom_puzzle_alt_name_trgm`,
`custom_puzzle_alt_name_unaccent_trgm`, `custom_puzzle_name_unaccent_trgm`, `custom_puzzle_ean_trgm`,
`custom_puzzle_identification_number_trgm`. **Keep `custom_puzzle_name_trgm`** (approval-queue similarity). Guard
test (in the style of `BlocklistQueryCoverageTest`) fails on `alternative_name` anywhere in `src/` and `templates/`.

Shipped as two releases (blue-green: containers of the release before still map the column while the next one rolls
out): **1c-1** (`Version20261004235009`) unmaps and stops writing `alternative_name`, copies the overlap, drops the five
indexes and adds `LegacyAlternativeNameCoverageTest`; **1c-2** is only `ALTER TABLE puzzle DROP alternative_name`
(hand-written, with `lock_timeout`) plus removing the column from
`CustomIndexFilteringPostgreSQLSchemaManager::UNMAPPED_COLUMNS_AWAITING_DROP` and its guard-test entry.

## Phase 2 - showing names and SEO

1. `PuzzleNameLanguage` (page language; on English pages a signed-in player's country language) + Twig function and
   partial `puzzle/_name.html.twig` (second line with `lang`).
2. Second line on: `_puzzle_item`, `_puzzle_library_item`, `sell-swap/_item`, `GlobalSearch`, `MultiscanTray`, picker
   option, `added_tracking_recap`, puzzle page header. "Matched: …" line in search results.
3. Puzzle page: "Also known as" in the signed-in Details block and in the guests' "About this puzzle"; "Suggest another
   name" entry in `puzzle/_detail_actions_menu.html.twig` (links to phase 3; hidden until then).
4. SEO: title key with the local name in all 6 locales; no ` – MySpeedPuzzling` suffix on puzzle pages; meta
   description with the local name; Product JSON-LD `alternateName` + valid, padded GTINs; sitemap `lastmod` includes
   `names_changed_at` (`GetPuzzleIdsForSitemap`). Update `docs/features/seo/implementation-plan-2026-10.md`.
5. Translations in all 6 locales. Afterwards: Search Console index coverage per language for 6–8 weeks.

## Phase 3 - adding and moderating names

1. **Add form:** a quiet "+ name in another language" link on the Puzzle label line reveals name + language rows
   (`optional_rows_controller.js`); on English pages a new row has no default language. Form data → `AddPuzzle`.
2. **Names editor** (Twig partial + `names_editor_controller.js`): main-title slot, `name_language` choice ("no
   English title exists"), other names with language and "Make main title", warning when the main title does not look
   English, split helper for " / " (always an explicit choice). Used by: suggest a change
   (`ProposeChangesController`), admin change-request review, approval detail, moderator edit page, merge review.
3. **Change requests:** `PuzzleChangeRequest` gets `proposed_alternative_names`, `original_alternative_names`,
   `proposed_name_language`. Players can add a name in any language, re-tag, edit or remove names, or propose another
   main title; the moderator edits any of it in the review; approval applies the result as a diff.
   `PuzzleChangeRequestOverview` / pending summaries show name changes.
3b. **Merge requests:** optional "Its name is in: [language]" per reported puzzle in `ReportDuplicatePuzzleFormType`,
   in the approval queue's merge flow (`MergeUnapprovedPuzzleController`) and in the internal API submit
   (`SubmitPuzzleMergeRequestController`), stored as `PuzzleMergeRequest::$reportedNameLanguages` (jsonb, default
   `{}`). The merge review (`PuzzleMergeRequestDetailController` + template) gets the full names editor prefilled with
   the union and the reporter's languages; `ApprovePuzzleMergeRequest` gains `mergedNameLanguage` and
   `mergedAlternativeNames`; the internal API approve accepts them (optional, default = automatic union) and
   `ListPuzzleMergeRequestsController` returns every candidate's names and the reported languages. Tests: merge with
   edited names, merge with the reporter's language, merge without edits = automatic union.
4. **Stale forms:** `PuzzleRecordVersion` hidden field on every full-record form; handlers refuse with
   `PuzzleChangedMeanwhile` (422 + message). Messages changing a puzzle implement `SerializedByLock` (`puzzle-{id}`).
5. **"Suggest another name":** modal (Turbo frame, explicit `action`) → `SuggestPuzzleName` → names-only change
   request, or applied at once for moderators/admins with an approved audit row. Rate limit per player. Kill switch
   `PUZZLE_NAME_SUGGESTIONS_PUBLIC`, recorded in `docs/features/feature_flags.md`.
6. **Internal API:** submit/approve change requests accept `alternativeNames` (+ `FIELDS`), merge queue lists them;
   update `internal-api.md`, `internal-api.openapi.yaml`, the `puzzle-change-proposal` skill.
7. Moderation decision log snapshots carry the list. Translations in all 6 locales.

## Phase 4 - catalogue cleanup (data, through change proposals - never SQL)

Tag the names without a language; split jammed " / " names where they really are translations ("Polar Bear / Tiger"
is not); pairs sharing an EAN with different names become merge requests. Filed via the internal API, reviewed by
moderators.

## Phase 5 - codes in the forms

1. One input per code with "+ another" on the label line: add form, suggest a change, review, approval queue,
   moderator edit, competition-round form. No comma field anywhere.
2. All writers pass `EanList` / `BrandCodeList` to `updateProductIdentifiers()`; check digit per EAN as today (stored
   invalid codes are kept until a proposal fixes them).
3. Format-only canonicalisation command (batched, entity methods): separators, spaces, leading zeros. Anything that
   would change a value goes to a report → change proposals.
4. Puzzle page lists each code on its own line (UPC padded back to 12 digits); JSON-LD uses the same list.

Shipped as: `EanList` / `BrandCodeList` (canonical lists, `display()`, `union()`, `isFormatOnlyChangeOf()`),
`CodeListType` + `puzzle/_code_inputs.html.twig` (reusing `optional_rows_controller.js`, which now also loads a list for
the moderators' "Keep current" / "Undo my edit"), Twig `puzzle_eans()` / `puzzle_brand_codes()` / `puzzle_gtins()`,
`myspeedpuzzling:canonicalize-puzzle-codes` (`PuzzleCodesCleanup`, `CanonicalizePuzzleCodes` handler, report CSV).
Decisions and the production-copy numbers in [README.md](README.md) "Delivery decisions" (phase 5).
