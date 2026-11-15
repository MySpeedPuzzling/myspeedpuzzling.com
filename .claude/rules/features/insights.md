---
paths:
  - "src/**/*PuzzleIntelligence*"
  - "src/**/*Insights*"
  - "src/**/*Prediction*"
  - "src/**/*Predictor*"
  - "src/**/*Difficulty*"
  - "src/**/*PlayerSkill*"
  - "src/**/*PlayerBaseline*"
  - "src/**/*PlayerElo*"
  - "src/**/*PaceHistory*"
  - "src/**/*PaceTimeline*"
  - "src/**/*MspRating*"
  - "src/**/*RatingLadder*"
  - "src/**/*RatingRanking*"
  - "src/**/*RatingSnapshot*"
  - "src/**/*PuzzlePicker*"
  - "src/**/*PuzzleListInsights*"
  - "src/**/*DifficultyTier*"
  - "src/Services/PuzzleIntelligence/**"
  - "src/Services/Insights/**"
  - "src/Services/MspRating/**"
  - "templates/puzzle_intelligence/**"
  - "templates/insights/**"
  - "templates/msp_rating*/**"
  - "templates/rating/**"
  - "templates/puzzle_picker/**"
  - "templates/methodology/**"
  - "assets/**/*picker*"
  - "assets/**/*insight*"
  - "docs/features/puzzle-intelligence/**"
  - "docs/features/insights/**"
  - "docs/features/msp-rating-history.md"
  - "docs/features/puzzle-picker/**"
  - "docs/features/list-difficulty-and-my-list-filter.md"
  - "src/**/*DisplayMode*"
  - "src/**/*PuzzleSearchList*"
  - "templates/**/*difficulty*"
  - "templates/**/*insight*"
  - "templates/**/*rating*"
  - "assets/**/*rating*"
  - "templates/**/*picker*"
  - "src/**/Insights/**"
  - "src/**/Rating/**"
  - "src/**/*Pace*"
  - "src/**/*SpeedPercentile*"
  - "src/**/*SolveTimeDistribution*"
  - "src/**/*DerivedMetrics*"
  - "src/**/*PersonalEstimate*"
  - "src/**/*CloseCalls*"
  - "src/**/*Estimate*"
---

# Puzzle Insights, predictions and ratings

Loaded automatically when you read or edit files of this area (difficulty, skill, predictions, the MSP rating, the Puzzle Picker and difficulty on lists). Read on purpose when planning work here before touching code. Each entry is the as-built summary; its doc under `docs/features/` is the design of record - read it before changing behaviour, and update both when behaviour changes.

- **Puzzle Insights**: `docs/features/puzzle-intelligence/` — Puzzle difficulty, player skill tiers, MSP rating, derived metrics
- **Prediction history**: `docs/features/puzzle-intelligence/prediction-history.md` — every solo time stores what was predicted for it at that moment (`puzzle_solving_time.predictable` = true/false, NULL = not evaluated: group/no-seconds forever, solo pending until evaluated; range, method, attempt, source `live`/`reconstructed`, model version). **Live** only for adds solved today/yesterday (`SolvingTimePredictor::predictAddedTime` before `persist()` — the insights tables do not know the solve yet; in a savepoint, failure never loses the time); back-dated adds + invalidating edits queue `BackfillSolvingTimePredictions` async. `PredictionReconstructor` = today's model fed only with data before the solve (tuple `(COALESCE(finished_at, tracked_at), tracked_at)`), sharing the pure calculator methods; guard tests pin "as of now == live". `recordPrediction()` records **no domain event** on purpose. Outcome is derived on read, never stored. Recap + API POST read the stored value. Backfill/daily cron: `myspeedpuzzling:backfill-solving-time-predictions` (also writes missing side-by-side rows - none before the model's first fit; `--recompute-reconstructed` replaces every reconstruction, shown and side by side, with the current fit, never live ones). Bump `TimePredictionCalculator::MODEL_VERSION` when predictions change. **Model v3** (Insights 3b): drift + spread on `player_pace_history`, shrunk difficulty for 1-4 indices toward a `DifficultyPrior` chain (`BrandPiecesPrior`, secret puzzles none), the 500-scaled pace for thin sizes, calibrated 50 % / 80 % windows (`predicted_range80_*`), fitted by the daily `myspeedpuzzling:fit-prediction-model` into `prediction_model_fit` (read as a scalar subquery - no query of its own; the 15-min batch only rewrites drift/spread); every predictor's prediction per model version in `solving_time_prediction` (columns on the time = the shown one; prior-only predictions side by side only, `SolvingTimePrediction::forDisplay()`; `pace_source` = own / interpolated / scaled_500 - the time verification leaves shrunk and 500-scaled first-try predictions out); `StatisticalInputsSql` = the one SQL of the inputs (the Picker mirrors the centre); `evaluate-predictions --fit-before=` (time split) and `report-prediction-calibration` (daily, warning on > 3 points drift). README "Step 8"
- **MSP Rating history**: `docs/features/msp-rating-history.md` — ladder rows show the move against the last stored day ≥ 7 days ago, never across a formula change (`MspRatingTimeline::moveReferenceDay()`; **a new rating formula adds a line to `MspRatingTimeline::FORMULA_CHANGES`**), ranked at read time from the stored ratings with the ladder's own pool + filters (`GetPlayerRatingRanking::window(now:)`, `sqlPlaceOnStoredDayOf()` for the header chip); stored `elo_rank` = the ladder's rule without anybody's blocks (`sqlOnStoredLadder()`, `myspeedpuzzling:rerank-rating-snapshots`); "This week on the ladder" cached 15 min (`RatingLadderThisWeek`); rating card `msp_rating_card` in the puzzler card's frame (global ranking: private players never) + lazy profile block; lost places quiet grey; index `custom_rating_snapshot_day`; "Season 0 · Season 1 starts on …" sneak peek (`NEXT_SEASON_STARTS_ON`)
- **Puzzle Picker ("What should I solve next?")**: `docs/features/puzzle-picker/README.md` + `implementation-plan.md` — route `puzzle_picker` (`/en/what-to-solve-next`), one public route: signed-in tool + indexable guest landing with a live demo (`noindex, follow` on any query string, canonical/hreflang from base, in `sitemap-static.xml`). Seeded random read model `GetPuzzlePickerSuggestions` (filter → `ORDER BY md5(seed||id)` LIMIT → hydrate; ≤ 75 ms worst case on prod), criteria = URL (`PuzzlePickerCriteria`, members-only filters stripped server-side), free filters (collection/solved/pieces/brand/since/my time/community) + Insights filters for members (difficulty, gap vs prediction, personal time budget). Predictions at list scale = bulk `GetPlayerPredictions` sharing `TimePredictionCalculator` with `GetPlayerPrediction` (never materialised). Collections: "Display" mode `player.collection_display_mode` (off / my times / + predictions, members) via `ChangeCollectionDisplayMode`. Shipped as stacked PRs #189 → #193 → #191 → #192 (2026-08-19) + same-day follow-ups on `main` (intro state with "Surprise me", 5 s drumroll reveal + confetti, seed redirect for signed-in draws, switch-based filter sheet, brand logos, footer link; remembered filters and share button removed) — see `implementation-plan.md` "Follow-ups"
- **Difficulty on puzzle lists + "My list" filter**: `docs/features/list-difficulty-and-my-list-filter.md` — list pages (collections, wishlist, unsolved, solved, sell/swap, lend/borrow) show the difficulty icon + "Completed N×" and members get client-side difficulty chips; **one query per page** via `ResolvePuzzleListInsights` (tiers only for members, viewer's membership decides). `/puzzle?list=` narrows the database to one of the viewer's lists (`PuzzleSearchList`; wishlist/unsolved/solved/system collection for signed-in, custom collections/borrowed/lent/sell-swap for members, gated in `PuzzleSearchCriteria`) as one semi-join in `SearchPuzzle::listFilter()`; a list is never "default", so the shared first-page cache stays viewer-independent. Difficulty on thumbnails (profile results, recent activity, ladders, most solved, marketplace cards, round results, result detail, calendar day): members see the tier on the image corner (`_difficulty_corner`), tiers only through `ResolveDifficultyTiers::forViewer()` (null = not a member, nothing queried); members-only difficulty filter on the profile results and the marketplace (`DifficultyFilter` + `_difficulty_filter_live`, `"0"` = not rated yet); members-only "Easiest first / Hardest first" sort on the profile results, the collection-like pages (client-side, `data-difficulty-score`) and the marketplace - by `difficulty_score`, not rated yet last both ways, no extra query, no index needed (measured in the doc)

## Puzzle Insights System
- **Batch computation**: All insights metrics (difficulty, skill, rating) are computed every 15 minutes via `myspeedpuzzling:recalculate-puzzle-intelligence` console command, NOT event-driven
- **Services**: All calculation logic is in `src/Services/PuzzleIntelligence/` — `PlayerBaselineCalculator`, `PuzzleDifficultyCalculator`, `PlayerSkillCalculator`, `DerivedMetricsCalculator`, `MspRatingCalculator`, `PuzzleIntelligenceRecalculator` (orchestrator)
- **Entities**: `PlayerBaseline`, `PuzzleDifficulty`, `PlayerSkill`, `PlayerSkillHistory`, `PlayerElo`, `PlayerPaceHistory`
- **As-of difficulty**: every difficulty index = time ÷ the solver's `player_pace_history` row valid just before the solve (`PaceTimeline::paceAt()`, shared by the batch, the incremental handler and `PredictionReconstructor`); the pace history is kept current by the batch (checksum per row = the watermark, only changed pairs rewritten), `myspeedpuzzling:backfill-pace-history` fills it (idempotent; run it right after the deploy - until the first complete sync `PaceHistoryReadiness` keeps every stored difficulty). Qualifying first attempts come from one rule, `PlayerBaselineCalculator::qualifyingFirstAttemptsQuery()` (`docs/features/puzzle-intelligence/README.md` "Pace history")
- **Queries**: `GetPuzzleDifficulty`, `GetPlayerSkill`, `GetPlayerSkillHistory`, `GetPlayerRatingRanking`, `GetPlayerPrediction`
- **Visibility**: All insights data is members-only except raw median, MSP Rating ladder, and methodology page
- **Design doc**: Full specification at `docs/features/puzzle-intelligence/README.md`
- **Cron**: `*/15 * * * * docker compose exec web php bin/console myspeedpuzzling:recalculate-puzzle-intelligence`
- **First-time setup**: After migration, run `php bin/console myspeedpuzzling:recalculate-puzzle-intelligence`
