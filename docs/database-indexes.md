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
| `custom_puzzle_name_trgm` | `puzzle USING GIN (name gin_trgm_ops)` | `ILIKE '%…%'` on the puzzle name (`SearchPuzzle`, header search, library) | `Version20260102200000` |
| `custom_puzzle_alt_name_trgm` | `puzzle USING GIN (alternative_name gin_trgm_ops)` | same, alternative name | `Version20260102200000` |
| `custom_puzzle_name_unaccent_trgm` | `puzzle USING GIN (immutable_unaccent(name) gin_trgm_ops)` | accent-insensitive name search (`immutable_unaccent()` is the IMMUTABLE wrapper index expressions need) | `Version20260102200000` |
| `custom_puzzle_alt_name_unaccent_trgm` | `puzzle USING GIN (immutable_unaccent(alternative_name) gin_trgm_ops)` | same, alternative name | `Version20260102200000` |
| `custom_puzzle_identification_number_trgm` | `puzzle USING GIN (identification_number gin_trgm_ops)` | `identification_number ILIKE '%…%'` in `SearchPuzzle` — before it, every search scanned the whole puzzle table (135 ms average, Sentry WEB-B4; "wasgij" 75 → 12 ms, "cat" 79 → 8.5 ms) | `Version20260918131133` |
| `custom_puzzle_ean_trgm` | `puzzle USING GIN (ean gin_trgm_ops)` | `ean ILIKE '%…%'` in `SearchPuzzle` (same measurement) | `Version20260918131133` |
| `custom_pst_player_puzzle_type` | `puzzle_solving_time (player_id, puzzle_id, puzzling_type)` | player statistics and ranking queries | `Version20260102230000` |
| `custom_pst_tracked_at_type` | `puzzle_solving_time (tracked_at, puzzling_type)` | date-range (monthly) queries | `Version20260102230000` |
| `custom_pst_type_time_valid` | `puzzle_solving_time (puzzling_type, seconds_to_solve) WHERE seconds_to_solve IS NOT NULL AND suspicious = false` | fastest players / pairs / groups | `Version20260102230000` |
| `custom_pst_team_puzzlers_gin` | `puzzle_solving_time USING GIN ((team::jsonb->'puzzlers') jsonb_path_ops) WHERE team IS NOT NULL` | team membership tests. Only the containment form uses it: `(team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', …))` — an `EXISTS (… jsonb_array_elements …)` form scans every team time | `Version20260102230000` |
| `custom_pst_intelligence` | `puzzle_solving_time (player_id, puzzle_id) WHERE puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve IS NOT NULL` | puzzle intelligence recalculation | `Version20260331200000` |
| `custom_pst_intelligence_first_attempt` | `puzzle_solving_time (puzzle_id, player_id) WHERE first_attempt = true AND puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve IS NOT NULL` | same, first attempts | `Version20260331200000` |
| `custom_player_favorite_players_gin` | `player USING GIN ((favorite_players::jsonb))` (default `jsonb_ops`) | "who follows these players": `GetSubscribedPlayers` (every added time) via `favorite_players::jsonb ??\| ARRAY[…]::text[]` — `??` is PDO's escape for a literal `?`; `jsonb_exists_any()` is never index-served and `jsonb_path_ops` cannot answer `?\|`. Before it every call unnested all favorites lists (12.6 ms average on prod). On prod the `?\|` form alone, still scanning, takes 12.1 → 4.7 ms; with the index, on a local copy of prod's shape, a typical player is 0.02–0.07 ms and the most followed one 1.4 ms. Also serves the `favorite_players::jsonb @> jsonb_build_array(…)` lookups in `GetPlayerConnections` and `DeletePlayerHandler` | `Version20260918171659` |
| `custom_chat_message_unread` | `chat_message (conversation_id, sender_id) WHERE read_at IS NULL` | unread message counts | `Version20260212002500` |

Trigram indexes only help patterns with at least 3 characters; 1–2 character searches still scan.
