# Players page (`/en/puzzlers`) - people discovery

The Players page helps a puzzler **find people worth following**. Every section ends in an action that already
exists: Favorite, Compare, Message or the profile.

Proposal with the reasoning, the data and the clickable mockups (round 2, 2026-10-03):
https://claude.ai/artifact/98kqDhiJ7q2zxE23CozKma

## Why

Production traffic on 2 Oct 2026 (Tempo, user agents without "bot"):

| Page | Visitors | Views |
|------|----------|-------|
| Players | 53 | 131 |
| Leaderboard | 617 | 788 |
| Hub | 656 | 2,086 |

- Search was in 26 of the 131 views; the wall of 83 country links and the "Most popular" list were scrolled past.
- The country pages got 553 requests, but 438 of their 471 clients made exactly one request, spread over six locales
  and dozens of countries. That is crawler traffic.

Measure every release against this baseline with the same Tempo search.

## Decisions (Jan, 2026-10-03)

| Topic | Decision |
|-------|----------|
| Direction | **Atlas, world-first**: a spotlight on the world or one country, then the countries |
| Default scope | The page **always opens on World**; your country is one tap away (`?scope=cz`), not remembered |
| Player card | **Stat card**, Players page only. Six public numbers; the 12-month activity strip and the skill tier are **members only**, like the profile's charts |
| Country Cup | Yes. **Per active puzzler** by default (10+ active puzzlers), total pieces one tap away |
| New faces | Public players with **at least one result** |
| Most followed | World **and** per country, with **Show more** |
| Where you stand | **Not in this push**; it goes to the leaderboard later (`docs/TODO.md`) |
| Hub | **Not touched in this push**. Its future: catch-up + trending + recent activity of all sorts + digests of other sections, later likes and comments |

## Information architecture: Players vs Hub vs Leaderboard

1. **The Hub shows things that happened; Players shows people.** Players never lists a single result. It shows
   people, summarized over a week or a month ("14 puzzles this week"). Milestones and personal bests are events: they
   belong to the Hub's future feed and only appear on Players as a chip on a person.
2. **Hub widgets are teasers; Players is "See all".** The future Hub digests Players lists (On a roll, New faces, the
   Cup) and links here.
3. **Country is a lens, not the spine.** One switch, `World · <your country> · another country…`, re-scopes every
   people list. Comparing countries (tiles, Cup) is world-level, so it is in every view.

| List | Hub | Players | Leaderboard |
|------|-----|---------|-------------|
| Latest results | owns it (feed) | never | - |
| Most active players | teaser (today: 4 rows) | full list, World or a country | - |
| Most followed | - | World or a country, Show more | - |
| New faces | - | public players with a result | - |
| On a roll (7 days) | future digest | owns it | - |
| Milestones, personal bests | future feed items (likes) | chips on people | - |
| Fastest | - | a link | owns it (country filter) |
| Countries, Cup | future digest | owns it | country filter |

## Page layout (top to bottom)

1. **Header**: H1, instant search (name or #code), scope switch `World | <home country> | Another country…`.
   - Home country: the signed-in player's profile country.
   - For guests, a guess from `Accept-Language`'s region (`de-AT` → Austria). It is only offered, never applied.
   - Signed in without a country: the guess, plus the "Add your country" nudge.
2. **Spotlight** (World or the selected country):
   - Numbers: registered puzzlers; active in the last 30 days; puzzles solved in the last 30 days with the change on
     the 30 days before; pieces this month; median best 500 (a country also shows the difference to the world);
     upcoming events. A sparkline of solves per month.
   - Three columns: **Most active this month · Most followed · New faces**, five rows each, **Show more** to ten,
     then a link to the full list.
   - Footer: "Fastest in X → leaderboard" and "Browse all puzzlers from X →" (the country page).
   - Small countries (under 15 active) get a "small and growing" note instead of looking empty.
3. **This week** (scope): **On a roll**. People with the most solves in the last 7 days, each with chips for their
   moments (new best on 500, reached 500 puzzles, first result).
4. **Suggested for you** (signed in only): people with a visible reason. Reasons, in this order:
   - puzzled with someone you puzzle with
   - at the same event
   - similar 500 time (`FindSimilarSpeedPuzzler`)
   - many puzzles in common
5. **Puzzlers around the world**: twelve country tiles, sortable Most active · Most puzzlers · Rising, then
   "All N countries".
6. **Country Cup** (this month): per active puzzler (10+ active) or total pieces. The viewer's country is marked and
   added below the top ten when it falls outside it.
7. **Your favorites**: the signed-in player's favorites (kept from today's page), compact.

Country page `/{_locale}/players-from-country/{cc}`: the same spotlight for that country plus a **directory**:

- filters: active this month, competes in events, has a sell/swap list, links Instagram
- sorts: most active, recently active, newest, most followed, A–Z
- cards, and "Show more"

## Data model (precomputed, every 15 minutes)

Measured on production read-only, 2026-10-03 (532k solving times, 11.5k players):

| Query, computed live | Time |
|----------------------|------|
| Country stats over 60 days | 121 ms |
| Median best 500 per country | 104 ms |
| Most active this month, world (`finished_at` has no index, so a sequential scan) | 80 ms |
| Most followed, world (JSON over every player) | 53 ms |
| On a roll, 7 days | 21 ms |
| Most active this month, one country | 18 ms |
| Cup | 11 ms |
| One player's favorites count | 6 ms |
| New faces | 6 ms |

Together that is about 350 ms per page view. So everything a page lists is precomputed by
`myspeedpuzzling:recalculate-community-stats` (message `RecalculateCommunityStats`, cron every 15 minutes). Pages
read small tables, which keeps `puzzle_solving_time` free of new indexes.

### Definitions

- **result**: a solving time a registered player took part in. That is the tracker plus every registered member of a
  pair/team (`team -> puzzlers[].player_id`), each counted once.
- **solved at**: `LEAST(COALESCE(finished_at, tracked_at), now)`.
- **months**: calendar months in UTC.
- **active**: has a result solved in the window.

### `community_player_stats` (one row per player, `CommunityPlayerStats`)

| Column | Meaning |
|--------|---------|
| `player_id` | PK, cascades with the player |
| `solved_total`, `pieces_total` | All results |
| `solves7d`, `pieces7d` | Results solved in the last 7 days |
| `solves30d`, `solves_prev30d` | Last 30 days, and the 30 before |
| `solves_this_month`, `pieces_this_month`, `solves_last_month`, `pieces_last_month` | Calendar months |
| `best500_seconds`, `best1000_seconds` | Best solo time on exactly 500 / 1000 pieces |
| `first_solved_at`, `last_solved_at` | |
| `monthly_solves` | JSON list of 12 ints, oldest first, the last one is the current month |
| `favorites_count` | How many players have this player in favorites (the count only, never who) |
| `computed_at` | |

Rows are upserted in one statement and only rewritten when a value changed.

### `community_scope_stats` (one row per scope, `CommunityScopeStats`)

`scope` is `world` or a lowercase country code (`CountryCode::name`).

| Column | Meaning |
|--------|---------|
| `registered_players` | |
| `active30d`, `solves30d`, `solves_prev30d` | Solves count person-results: a pair's result counts for both people |
| `active_this_month`, `pieces_this_month`, `active_last_month`, `pieces_last_month` | Feed the Cup |
| `median_best500_seconds`, `puzzlers_with500` | |
| `monthly_solves` | JSON, 12 months |
| `new_faces14d` | Public players registered in the last 14 days with at least one result |
| `computed_at` | |

The World row counts players without a country too. Aggregates include private players (no identity) and are not
filtered by blocks, the same rule as every aggregate (`docs/features/player-blocklist.md`).

### `player_moment` (`PlayerMoment`)

Things that happened to a player. They are stored, so the future Hub feed (and its likes) reads the same rows.

| Type | `value` | `previous_value` | `pieces_count` | `dedupe_key` |
|------|---------|------------------|----------------|--------------|
| `personal_best` (solo, beats an earlier time of the same piece count) | new seconds | previous best seconds | yes | `pb:<time id>` |
| `puzzles_milestone` (50, 100, 250, 500, 1000, 1500, 2000, 2500, 3000, 4000, 5000) | the milestone | - | - | `puzzles:<n>` |
| `pieces_milestone` (100k, 250k, 500k, 1M, 2M, 3M, 5M, 10M) | the milestone | - | - | `pieces:<n>` |
| `first_result` | - | - | - | `first` |

- `occurred_at` is the result's solved at; `solving_time_id` cascades.
- Unique on `(player_id, dedupe_key)`; ids are stable.
- Detection looks at results solved in the last 14 days. Moments in that window that no longer hold (an edited time)
  are removed; older moments are never touched.

## Privacy, blocks, opt-outs

- Every query returning other players' identity embeds `HiddenPlayers::sqlExclude()`. Every person list is
  **public-only for everybody** (`p.is_private = false`), like today's country pages and "Most popular", so nobody
  is ranked differently for different viewers. List the query file in `PrivateProfileQueryCoverageTest::RAW_COLUMN`
  with that reason.
- Add every new page and fragment that shows players to `BlocklistCanaryTest` and `PrivateProfileCanaryTest`.
- The **player card** 404s for players hidden from the viewer, and for private players unless the viewer may see
  them (`GetPlayerProfile::byId()` rules).
- Members-only on the card: the activity strip and the skill tier. The tier is never shown for a player with
  `ranking_opted_out`.
- Ranking opt-out does not remove anyone from activity lists (same as the Hub's "Most active").
- Never use the word "rival".

## Performance budgets

- Every section reads the precomputed tables: **one statement per section**, at most 8 for the full page, and no
  per-request aggregation over `puzzle_solving_time`.
- Player card: 1 statement, plus 1 for the activity strip when the viewer is a member. Nothing until it is opened.
- The cron run stays under a few seconds on production data (timed on the dev copy before shipping).
- Show more reveals rows already rendered (at most 10 per column); the full list is a separate page.

## Build streams

The foundation (`CommunityScope`, `ViewerCountry`, the three tables, the recalculation with moment detection,
`GetCommunityScopeStats`, the page shell with the scope switch) comes first. Then:

| Stream | Scope |
|--------|-------|
| S1 | Instant search + player card (frame route, members-only strip, Favorite and Compare in place) |
| S2 | Spotlight: scope numbers + Most active / Most followed / New faces with Show more |
| S3 | This week: On a roll with moment chips |
| S4 | Country tiles (active, registered, rising) + Country Cup |
| S5 | Country page = spotlight + directory with filters and sorts, SEO |
| S6 | Suggested for you |
| S7 | "Add your country" nudge |

Each stream owns its own partial, component, query and test files. Translation keys go under `players.<section>.*`.
English only; the other locales come in one pass at the end.

## Out of scope / follow-ups

- Leaderboard "Where you stand" (best-time distribution per country with your marker).
- The Hub rework: digests and "See all" into Players, moments as feed items with likes and comments.
- The player card on leaderboards, the feed and puzzle pages.
