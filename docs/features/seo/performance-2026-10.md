# Speed check of the autumn SEO round

Status: done 2026-09-30. Scope: every page and query the SEO round of 2026-09-29/30 added or changed
([`implementation-plan-2026-10.md`](implementation-plan-2026-10.md) WS-A to WS-I and F2, and the leaderboard of
[`../puzzle-leaderboard-chart.md`](../puzzle-leaderboard-chart.md)), before Google crawls them hard.

Why: `/sitemap-players.xml` ran into the 120 s limit on production (Sentry WEB-CW). It was a correlated JSON
containment query nobody had timed on production-sized data, fixed in 1d700f37. This check looked for anything like it.

## Method

- **The local production copy** (data up to 2026-09-25: 523k solving times, 41k puzzles, 11k players), read-only,
  **with production's planner settings**: `random_page_cost = 1.1`, `effective_cache_size = 32GB`, `work_mem = 32MB`.
  With the local defaults (`random_page_cost = 4`) the planner estimated some queries 3x higher and PostgreSQL JIT-compiled
  them - the pairs leaderboard of London Postcard took 430 ms locally, of which ~390 ms was JIT. Local numbers taken
  with the defaults are misleading.
- `EXPLAIN (ANALYZE, BUFFERS)` of every query with worst-case parameters (Ravensburger = 60 % of all solves, the
  500-piece bucket, the deepest page, London Postcard = the biggest board, the busiest events), and `auto_explain` for
  every statement over 40 ms while crawling the pages.
- Every page end to end on a throwaway prod-mode server (`php -S`, `APP_ENV=prod`, own Redis, read-only database):
  83 URLs, cold (empty Redis) and warm, anonymous and as the most active player (2,328 times) with a membership.
  Times are the kernel's handle time, not network.
- To repeat it, give the connection production's planner through libpq, and log the plan of every slow statement:
  `PGOPTIONS='-c default_transaction_read_only=on -c random_page_cost=1.1 -c effective_cache_size=32GB -c work_mem=32MB
  -c session_preload_libraries=auto_explain -c auto_explain.log_min_duration=40 -c auto_explain.log_analyze=on'`
  (the plans land in the postgres container's log; `log_analyze` slows every statement a little, so time without it).
- Production: Sentry traces of the last 2 days (sampled) and a few single read-only `EXPLAIN (ANALYZE)` runs on the
  box (`BEGIN READ ONLY`, `statement_timeout = 10s`).

## Fixed

| Where | Before | After | Fix |
|---|---|---|---|
| Puzzle page: pairs / teams leaderboard (`GetPuzzleSolvers::duoByPuzzleId()` / `teamByPuzzleId()`), London Postcard, 541 pair times | production 83-92 ms: 34 ms JIT + a scan of all 56k pair times; copy 43-124 ms | production 20 ms, copy 11-12 ms | Members through `unnest()` (estimated at 10 rows) instead of `json_array_elements()` (always 100): estimate 216k → 18k, no JIT, and the planner now uses the `puzzle_id` index. Output byte-identical (5 boards, 1,272 rows compared) |
| Puzzle page London Postcard, whole page (copy, warm) | 292 ms (DB 148 ms) | 130 ms (DB 48 ms) | the above |
| Brand hub stats (`GetBrandHub::bySlug()`), cached 6 h per brand - also computed by the first puzzle page of the brand after expiry | Ravensburger: two passes over its 316k solves, 250-340 ms | one pass, ~150 ms | `GROUPING SETS ((pieces_count), ())`: the per piece count rows and the brand total from one scan |
| Puzzle page London Postcard with that cache cold (copy) | 688 ms | 257 ms | the two fixes above |
| `/sitemap-players-N.xml` (`GetPlayerIdsForSitemap::publicWithResultsPage()`) | first file: production 230-310 ms, copy 130-170 ms; estimated at 460k | the same time (production 250-300 ms, copy 130-160 ms); estimated at 41k (production 72k) | The page's players first, then one aggregate over their own times and one over their pair / team times, instead of two correlated `MAX()` subqueries per player - no faster, but out of JIT's reach: the old estimate was 8 % under `jit_inline_above_cost` (500k), and past it inlining + optimisation add ~170 ms (measured: 358 ms with 184 ms of JIT vs 157 ms without). Output byte-identical on all 7 files |
| Ladder solo tables (`GetFastestPlayers::perPiecesCount()`), 500 pieces top 10 | production 383 ms; copy 200-380 ms | production 94 ms; copy 55-80 ms | Best time per player by a hash aggregate, then the top N, then their time rows - instead of `DISTINCT ON (player)` sorting all 340k solo times of the size. Same players and times (6 lists compared); ties at the cut-off and between a player's equal best times, arbitrary before, now go to the lower player id / the earliest time |
| `/en/ladder` (two solo tables; production p50 508 ms, p95 556 ms) | copy 335 ms | copy 123 ms | the above; `/en/ladder/solo/500-pieces` 280 → 85-110 ms |
| Signed-in lists: brand hubs, brand × pieces, pieces hubs, puzzle database + "load more" | `GetRanking::allForPlayer()` ranked the viewer against everybody on every puzzle they ever solved to print their best time on 20-48 cards: 210-300 ms for the most active player | `GetPlayerBestSoloTimes::forPuzzles()` for the listed puzzles: 0.8-1.4 ms | Same value (the query was built as its twin for the activity feed, Sentry WEB-BP). Most active player: brand hub 360 → 50 ms, brand × pieces 278 → 54 ms, pieces hub 302 → 55 ms, `/en/puzzle` 250 → 53 ms |

No index, no migration.

## Checked, fine as it is

Copy with production's planner settings; production from Sentry (2 days, sampled) where there is enough traffic.

| Page / query | Measured | Note |
|---|---|---|
| Puzzle page, typical | 45-140 ms; production p50 40 ms, p95 110 ms | 12 queries anonymous, 20 signed in |
| `GetPuzzleSummary` | 1.5-3 ms | primary key lookups + small competition tables |
| `GetRelatedPuzzles` | 10-16 ms; worst 11-37 ms (brand-wide fallback, 6k Ravensburger candidates) | reads `puzzle_statistics` in full (37k rows, ~3 ms) - grows with the catalogue, fine for years |
| `GetTags::forPuzzle()` | 1-4 ms | |
| Leaderboard component (`PuzzleTimes`) | London Postcard after the fix: DB 18 ms solo + 10 ms pairs + 3 ms teams; the page's PHP ~80 ms | Builds the whole filtered list on purpose: tab counts, median, rank, position line, histogram and the country filter need every row. Rendering 100 instead of 10 rows costs only ~10-15 ms (measured) |
| Brand hub, brand × pieces, pieces hub pages 1..N (`GetCataloguePuzzles`) | 30-55 ms per page, deepest pages included (Ravensburger p127, 500 pieces p309); list 6-16 ms, count 2-4 ms | |
| Catalogue stats, cold (6 h cache, `CatalogueStatsProvider`) | pieces hub 500: 150-290 ms (production trace 286 ms), 1000: ~100 ms; A-Z directory 50-86 ms | once per 6 h per key; `LockRegistry` lets one request compute while the others wait. Keys are bounded (known slugs, allowed piece counts) |
| A-Z brand directory | 15-37 ms | cached |
| "How long" guides | 9-20 ms warm | solve-time distributions cached 6 h; cold: solo 320-450 ms, pairs/teams 25-100 ms, once per 6 h |
| Hardest / easiest lists | 10-45 ms warm, 7-19 ms per list cold | cached 1 h; availability index cheap |
| Event pages | 35-130 ms; production p95 154 ms | the slowest part is the pre-existing participants' statistics (50-80 ms, WJPC 2025) |
| Edition, series, round results, WJPC hub | 18-47 ms | |
| `EventTitle`, `CountCompetitionResults`, `GetCompetitionPuzzles` | < 10 ms | indexed on `competition_id` |
| `/sitemap.xml` | 50-62 ms | three counts |
| `/sitemap-puzzles-N.xml` | 15-95 ms per file | |
| `/sitemap-images-N.xml` | 90-230 ms per file (20k puzzles) | |
| `/sitemap-brands.xml` | 100-150 ms; production 218 ms | two uncached aggregates over all solves per fetch; fine for a file Google fetches a few times a day |
| Events, countries, guides, difficulty, static, marketplace, feature-request sitemaps | < 35 ms | |
| Homepage, tracker page catalogue numbers (`GetCatalogueNumbers`) | cached 6 h; 40-55 ms cold | |
| Hub (now `noindex`), players per country | 17-77 ms | |
| Collection pages, signed-in visitor (wishlist heart) | +1 statuses query, 4-25 ms | |
| Puzzle library, favourites, activity calendar, blog post, marketplace how-it-works (new meta descriptions) | 20-90 ms | no new SQL; `profile_head_name()` reads the loaded profile |

## Not changed - for a decision

- **JIT on production.** Production runs PostgreSQL's default JIT (`jit = on`, compile above an estimated cost of 100k,
  inline + optimise above 500k). For page queries it is pure overhead: 34 ms of the 83 ms London pairs query before the
  fix, and a query whose estimate grows past 500k pays another 150-400 ms. After these fixes the costliest statement of
  all 83 pages is estimated at 70k (the puzzle database search), so none is JIT-compiled today - but any query whose
  estimate grows past 100k will pay it silently. The systemic fix is `jit = off` for the web and api containers
  (`PGOPTIONS='-c jit=off'` in lily.srv's compose, which keeps JIT for the cron recalculation), or
  `ALTER DATABASE speedpuzzling SET jit = off`. An infra decision, not made here.
- **Tracker page** runs `GetStatistics::globally()` (a sum over all solving times, 40-55 ms, pre-existing) on every
  request; the homepage already caches the same numbers for 60 s (`HomepageStatistics`) and could share them.
- **Puzzle database search** (`SearchPuzzle`, 60-70 ms per page) - pre-existing, not part of this round.

## What could not be measured

- Production end to end after these fixes (not deployed yet); the production numbers for the fixed queries are single
  read-only runs of the same SQL.
- Crawl bursts / concurrency: no load test. The cold-cache paths were measured one request at a time.
- Sentry samples traces, so routes with little traffic have only a few samples in two days.
