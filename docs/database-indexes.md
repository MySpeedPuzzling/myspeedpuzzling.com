# Custom Database Indexes

Indexes Doctrine cannot express (GIN trigram, JSONB, partial and expression indexes) live outside the
entity mapping. The rules are in `CLAUDE.md` → "Custom Database Indexes": `custom_` prefix (so
`CustomIndexFilteringSchemaManagerFactory` never generates a `DROP` for them), written by hand in a
migration, and mirrored in `tests/bootstrap.php` so tests run against the same plan.

**Adding one:** write the migration, mirror it in `tests/bootstrap.php`, and add a row below — which
query it serves and what it measured. An index nobody can trace back to a query is an index nobody dares
to drop.

## Registry

| Index | Definition | Serves | Migration |
|---|---|---|---|
| `custom_puzzle_name_trgm` | `puzzle USING GIN (name gin_trgm_ops)` | similar titles in the approval queue (`GetPuzzleApprovals::possibleDuplicates()`: `p.name % src_name` per name of the new puzzle); the puzzle search left it for `custom_puzzle_search_names_trgm` in phase 1b | `Version20260102200000` |
| `custom_puzzle_search_names_trgm` | `puzzle USING GIN (search_names gin_trgm_ops)` | the phase 1b puzzle search (`PuzzleTextSearch`: `search_names LIKE '%\nq\n%'` / `'%\nq%'` / `'% q%'` / `'%q%'`, docs/features/puzzle-names/README.md "Search") over the folded key of every name the entity maintains. Replaced `custom_puzzle_name_unaccent_trgm`, `custom_puzzle_alt_name_trgm` and `custom_puzzle_alt_name_unaccent_trgm` (dropped in phase 1c-1, see below; `custom_puzzle_name_trgm` stays for the approval queue). Measured on a dev copy of production with 62,501 names: "a" 333 → 49 ms, "cat" 33 → 12 ms, browsing 201 → 45 ms, "van haasteren" 15 → 8 ms; on a copy of production (41,282 puzzles, 2026-10-05, phase 1b vs phase 0 query, page + count): "a" 120 + 26 → 48 + 10 ms, "cat" 9.2 + 1.3 → 5.6 + 0.7 ms, browsing 48 + 19 → 42 + 7 ms. Empty until `myspeedpuzzling:rebuild-puzzle-search-keys` ran | `Version20261004203522` |
| `custom_puzzle_search_codes_trgm` | `puzzle USING GIN (search_codes gin_trgm_ops)` | same, the codes key (`e:` EAN / `c:` brand-code lines): exact code `LIKE '%e:…\n%'` / `'%c:…\n%'`, part of a code `'%…%'`; the barcode lookups (`SearchPuzzle::allByEan`, `FindPuzzlesByExactEan`) `LIKE '%…\n%'` + `strpos(search_codes, '\ne:…\n')`. Measured on a copy of production (41,282 puzzles, 2026-10-05): barcode lookup 0.2 ms (as before, now exact), brand code 0.7 → 0.3 ms (catalogue page), part of an EAN "40055561" 20 → 11 ms. The check-digit aliases (`SearchText::VERSION` 3) added a `c:` line to 8,734 keys: 1.8 MB after `REINDEX` on the dev copy, an exact alias lookup 0.2 ms. Replaced `custom_puzzle_ean_trgm` and `custom_puzzle_identification_number_trgm` (dropped in phase 1c-1, see below) | `Version20261004203522` |
| `custom_pst_player_puzzle_type` | `puzzle_solving_time (player_id, puzzle_id, puzzling_type)` | player statistics and ranking queries | `Version20260102230000` |
| `custom_pst_type_time_valid` | `puzzle_solving_time (puzzling_type, seconds_to_solve) WHERE seconds_to_solve IS NOT NULL AND suspicious = false` | fastest players / pairs / groups | `Version20260102230000` |
| `custom_pst_team_puzzlers_gin` | `puzzle_solving_time USING GIN ((team::jsonb->'puzzlers') jsonb_path_ops) WHERE team IS NOT NULL` | team membership tests. Only the containment form uses it: `(team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', …))` — an `EXISTS (… jsonb_array_elements …)` form scans every team time | `Version20260102230000` |
| `custom_pst_intelligence` | `puzzle_solving_time (player_id, puzzle_id) WHERE puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve IS NOT NULL` | puzzle intelligence recalculation | `Version20260331200000` |
| `custom_pst_intelligence_first_attempt` | `puzzle_solving_time (puzzle_id, player_id) WHERE first_attempt = true AND puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve IS NOT NULL` | same, first attempts | `Version20260331200000` |
| `custom_player_favorite_players_gin` | `player USING GIN ((favorite_players::jsonb))` (default `jsonb_ops`) | "who follows these players": `GetSubscribedPlayers` (every added time) via `favorite_players::jsonb ??\| ARRAY[…]::text[]` — `??` is PDO's escape for a literal `?`; `jsonb_exists_any()` is never index-served and `jsonb_path_ops` cannot answer `?\|`. Before it every call unnested all favorites lists (12.6 ms average on prod). On prod the `?\|` form alone, still scanning, takes 12.1 → 4.7 ms; with the index, on a local copy of prod's shape, a typical player is 0.02–0.07 ms and the most followed one 1.4 ms. Also serves the `favorite_players::jsonb @> jsonb_build_array(…)` lookups in `GetPlayerConnections` and `DeletePlayerHandler` | `Version20260918171659` |
| `custom_pst_finished_at_solo` | `puzzle_solving_time (finished_at) WHERE puzzling_type = 'solo'` | the Hub's "Most active solo players" by calendar month (`GetMostActivePlayers::mostActiveSoloPlayersInMonth()`; the "this month" tab renders on every Hub view). Without it a parallel scan of every result. On a copy of production (523k results), warm cache, minimum of 5–9 runs: this month (3 days in) 50 → 4.9 ms, a full month 64 → 34 ms (the rest is joining player and puzzle). **Partial on purpose:** a plain `(finished_at)` index makes `MIN(finished_at)` of one player (`GetPlayerSolvedPuzzles::getOldestResultDate()`, no `puzzling_type` condition) walk the date index instead of the player's rows, 0.6 → 230 ms for a heavy player. No `COALESCE(finished_at, tracked_at)` index: every query using it is narrowed by player / puzzle / team first (measured 2026-10-03, docs/features/players-page/README.md) | `Version20261003173349` |
| `custom_chat_message_unread` | `chat_message (conversation_id, sender_id) WHERE read_at IS NULL` | unread message counts | `Version20260212002500` |
| `custom_user_account_email_lower` | `UNIQUE user_account (lower(email))` | case-insensitive unique e-mail of native sign-in | `Version20260724073022` |
| `custom_notification_unread` | `notification (player_id) WHERE read_at IS NULL` | the bell on every page, `GetNotifications::countUnreadForPlayer()` (17–21k calls a day) - an index-only scan, so it counts `*`, not `id`. Before it, the player's rows came through the `player_id` foreign-key index and were all read from the heap (up to 21k per player). Production without it, warm cache: p90 player 2.6 ms / 3,153 buffers, p99 7.6 ms / 10,473, the biggest 11.7 ms / 14,437; on a local copy with production's per-player counts, with it: 0.04–0.2 ms, 1.2 ms for the player with 16,947 unread. Also serves `markNotificationAsReadForPlayer()` and the `hasUnread…Notification()` lookups. Marking read is no longer a HOT update (`read_at` is in the predicate) - at most once per notification since 2026-09-30 | `Version20260930163000` |
| `custom_puzzle_hidden` | `puzzle (id) WHERE hide_until IS NOT NULL OR hide_image_until IS NOT NULL` | the secrecy check (`PuzzleSecrecy`) where a query looks for secret puzzles instead of filtering joined ones - the review queues' backlog badges in the key menu (`GetAdminQueueCounts`: merge requests touching no secret puzzle, `GetPuzzleMergeRequests::sqlNoSecretPuzzle()`) and the merge queue's own count. Without it every puzzle is read to find the ~16 with a hide date: production 2026-10-08 ~8 ms (14 ms in EXPLAIN); on a copy of production with it 35 → 0.16 ms, the same count | `Version20261008100000` |
| `custom_puzzle_unapproved` | `puzzle (id) INCLUDE (hide_until, hide_image_until) WHERE approved = false` | the unapproved puzzles - the approval queue's count (`GetPuzzleApprovals::countPending()`) and its badge in the key menu (`GetAdminQueueCounts`); the hide columns are included for the secrecy filter. Without it every puzzle is read: production 2026-10-08 ~5 ms for 1,642 of 42,112 puzzles; on a copy of production with it 5.4 → 0.12-0.17 ms | `Version20261008100000` |
| `custom_player_search_trgm` | `player USING GIN (LOWER(name) gin_trgm_ops, LOWER(code) gin_trgm_ops, LOWER(immutable_unaccent(name)) gin_trgm_ops, LOWER(immutable_unaccent(code)) gin_trgm_ops)` | `SearchPlayers::fulltext()` (header search, co-puzzler picker, autocomplete, players page; 11–14k calls a day) for searches of 3+ characters, which put `immutable_unaccent()` in the WHERE to match the index (1–2 characters keep plain `unaccent()`, faster when scanning). Production without it: 23–44 ms for any search (unaccent() on all ~11k players); local copy with it: "jan" 12.6 → 0.5 ms, "ann" 14.4 → 1.7 ms, "šár" 14.1 → 0.8 ms, "petra" 12.8 → 0.2 ms. One multicolumn GIN, the planner ORs four bitmap scans of it | `Version20260930163100` |

Trigram indexes only help patterns with at least 3 characters; 1–2 character searches still scan.

## Dropped

| Index | Why | Migration |
|---|---|---|
| `custom_pst_tracked_at_type` (`puzzle_solving_time (tracked_at, puzzling_type)`) | 0 scans on production in ~27 days (2026-09-30), 18 MB maintained on every write. Every tracked_at query plans on the Doctrine index `idx_fe83a93cafa9b124 (tracked_at)` - 646k scans in the same period | `Version20260930163200` |
| `custom_tracked_at_order_desc` (`puzzle_solving_time (tracked_at DESC)`) | created by hand on production, never in a migration; 0 scans, 17 MB. A b-tree is read backwards as well as forwards, so `(tracked_at)` already serves `ORDER BY tracked_at DESC` | `Version20260930163200` |
| `custom_puzzle_alt_name_trgm` (`puzzle USING GIN (alternative_name gin_trgm_ops)`, `Version20260102200000`) | 2026-10-05, puzzle names phase 1c-1: since phase 1b every puzzle text search reads the folded search key (`custom_puzzle_search_names_trgm`); no query in `src/` puts `immutable_unaccent()` of a puzzle name or `alternative_name` (no longer mapped, the column goes in 1c-2) in a condition, and production's `idx_scan` did not grow after the 1b deploy | `Version20261004235009` |
| `custom_puzzle_alt_name_unaccent_trgm` (`puzzle USING GIN (immutable_unaccent(alternative_name) gin_trgm_ops)`, `Version20260102200000`) | same | `Version20261004235009` |
| `custom_puzzle_name_unaccent_trgm` (`puzzle USING GIN (immutable_unaccent(name) gin_trgm_ops)`, `Version20260102200000`) | same | `Version20261004235009` |
| `custom_puzzle_ean_trgm` (`puzzle USING GIN (ean gin_trgm_ops)`, `Version20260918131133`) | 2026-10-05, puzzle names phase 1c-1: since phase 1b codes are searched in `search_codes` (`custom_puzzle_search_codes_trgm`); no query in `src/` matches the column with `LIKE` / `ILIKE` / trigram operators any more (the last one, `GetPuzzleOverview::byEan()`, only tests called, went with it), and production's `idx_scan` did not grow after the 1b deploy | `Version20261004235009` |
| `custom_puzzle_identification_number_trgm` (`puzzle USING GIN (identification_number gin_trgm_ops)`, `Version20260918131133`) | same | `Version20261004235009` |

## On production only

Created by hand on production, in no migration - so dev databases rebuilt from migrations and the test database
do not have them. Both are used (production, 2026-09-30, ~27 days of statistics): write them into a migration and
`tests/bootstrap.php` with the query they serve, or drop them once nothing needs them.

| Index | Definition | Scans |
|---|---|---|
| `custom_puzzlers_gin` | `puzzle_solving_time USING GIN ((team::jsonb -> 'puzzlers'))` (default `jsonb_ops`, no `WHERE`) | 1.35M |
| `custom_seconds_to_solve_order_asc` | `puzzle_solving_time (seconds_to_solve)` | 64k |

## Measuring and shipping an index

- **Measure first.** A scratch database whose name ends in `_test`, restored from a dump of the local production
  copy (`pg_dump`/`pg_restore`, never writing to `speedpuzzling`), `VACUUM ANALYZE`d, with production's planner
  settings for the session or database: `jit = off`, `random_page_cost = 1.1`, `effective_io_concurrency = 200`,
  `work_mem = 32MB`, `effective_cache_size = 32GB`, `maintenance_work_mem = 1GB`. Compare `EXPLAIN (ANALYZE, BUFFERS)`
  before and after; on production only read-only `EXPLAIN (ANALYZE)` of single statements (`BEGIN READ ONLY;
  SET statement_timeout = '10s'`). Production has `pg_stat_statements` since 2026-09-30 for the calls and times,
  `pg_stat_user_indexes.idx_scan` / `last_idx_scan` for what is used. The local copy has no `notification` rows
  (the backups leave them out) - synthesise them from production's per-player counts.
- **No `CONCURRENTLY`.** The web container runs the boot migrations (`bin/migrate-database`) with `--all-or-nothing`:
  one transaction for all pending migrations, and Doctrine refuses a non-transactional migration there
  (`MigrationConfigurationConflict`), which would fail every boot. So `CREATE INDEX` holds a SHARE lock - reads go
  on, writes to that table wait - until the deploy's migrations commit; measure the build on production-sized data
  and say it in the migration (`notification`, 4.17M rows: 0.22–0.33 s locally). `DROP INDEX` takes an ACCESS
  EXCLUSIVE lock that stops reads too: put it in the last migration of the batch and cap its wait with
  `SET LOCAL lock_timeout` (see `Version20260930163200`), so a long-running query makes the boot fail and try
  again (the rollout reverts if it never gets the lock) instead of queueing the whole site behind it.
