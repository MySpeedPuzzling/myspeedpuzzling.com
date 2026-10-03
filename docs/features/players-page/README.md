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

Each stream owns its own partial, component, query and test files. Translation keys go under `players.<section>.*`,
translated into all six locales in one pass at the end.

## As built (2026-10-03)

### Routes and URLs

| Route | Path (en) | Notes |
|-------|-----------|-------|
| `players` | `/en/puzzlers` | `?scope=cz` re-scopes everything (unknown codes = World); `?search=` is the instant search's state. Only the bare URL is indexable |
| `players_directory` | `/en/puzzlers/all` | `scope`, `sort` (`active` default, `recent`, `newest`, `followed`, `name`), `active=1`, `events=1`, `swaps=1`, `instagram=1`, `limit` (steps of 24, max 480). The bare world page is indexable and in the static sitemap; any query string is `noindex` |
| `players_per_country` | `/en/players-from-country/{cc}` | Spotlight (without its "Browse all" link) + the directory with the country fixed. `noindex` without public players or with a query string |
| `player_card` | `/en/puzzler-card/{id}` | Turbo Frame `player-card`; a direct visit is a minimal `noindex` page, canonical = the profile, `Vary: Turbo-Frame`. 404 for hidden players and for private players the viewer may not see. Disallowed in `robots.txt` |
| `set_my_country` | `/en/puzzlers/my-country` | POST, CSRF, signed in. `SetPlayerCountry` → `Player::changeCountry()` |

### Section notes

- **Scope switch.** World + home country (the profile's; for guests a guess from `navigator.languages` in
  `assets/country_guess.js`, shared with the nudge) + "Another country…".
- **Spotlight.** 3 statements: scope and world numbers, upcoming events (`IsCompetitionPubliclyVisible`), and the
  three lists in one `UNION ALL`. For non-members the country leaderboard link is locked and opens the members modal.
- **This week.** On a roll plus up to 2 moment chips (`PlayerMomentChip::mostNotable()`):
  - a personal best on 500 or 1000 > a puzzles milestone > a pieces milestone > another personal best > the first
    result
  - personal bests below 300 pieces are never a chip
- **Suggested for you.** Reasons in this order: puzzles with someone you puzzle with, same event, similar 500 time
  (±7 %, never for ranking opt-outs), 10+ puzzles in common.
  - "In common" is counted only for a daily pool of 30 active puzzlers, because counting it against everybody cost
    ~280 ms.
  - Blocks are excluded in both directions. The order is seeded by date and viewer, so it holds for the day.
- **Countries and Cup.**
  - The tiles' three orders and the Cup's four boards are rendered once and switched client side
    (`players_tabs_controller.js`).
  - In the first 7 days of a month the Cup opens on the last month.
  - `GetCommunityScopeStats::countries()` is memoized per request (`ResetInterface`).
- **"Competes in events".** One definition, `GetPlayersDirectory::competesInEventsSql()`: connected to a publicly
  visible event. It is used by the directory and the card.
- **Nudge.** `HintType::PlayersCountryNudge`. It is also shown on the player's own profile when the Getting started
  checklist is not.

### Measured

**Production, read-only, 2026-10-03** (532k results): the lists computed live would cost ~350 ms per page view, see
"Data model".

**The recalculation cron**, run as the real command on a copy of production (523k results):
- 2.8 s per run, moment detection included
- a second run rewrites 0 player rows
- 1,028 personal bests, 108 puzzles milestones, 40 pieces milestones and 135 first results were in the 14-day window
  on production

**Page queries**, timed on the same copy, warm, median of 15 runs:

| Query | Median |
|-------|--------|
| Countries (scope switch + tiles + Cup, once) | 0.8 ms |
| Scope + world numbers | 0.2 ms |
| Upcoming events | 0.1 ms |
| Spotlight people: World / US / CZ | 11.7 / 7.6 / 7.2 ms |
| This week: World / CZ | 3.0 / 2.1 ms |
| Directory World: default sort | 17.0 ms |
| Directory World: most followed | 15.5 ms |
| Directory World: events + swaps | 2.1 ms |
| Directory World: by name, 480 rows | 21.9 ms |
| Directory US active | 4.9 ms |
| Player card, heaviest player, member | 1.0 ms |
| Suggestions: heaviest / most teams / most events / light viewer | 15.4 / 22.5 / 30.8 / 14.1 ms |
| Search "jan" + counts | 1.0 ms |

**Statements per page view** (`tests/Controller/PlayersPageQueryBudgetTest.php`): the Players page is 5 for a guest
and 11 signed in (6 of those are what every signed-in request loads, plus Suggested for you and favorites); the
directory 2, a country page 5, a player card 2.

**Indexes**: only `custom_pst_finished_at_solo`, for the Hub (see `docs/database-indexes.md`).
- A plain `finished_at` index was measured and rejected: it made `getOldestResultDate()` 0.6 → 230 ms.
- No `COALESCE(finished_at, tracked_at)` index: every query using it is narrowed by player, puzzle or team first.
- The precomputed tables needed none at this traffic.

### Guards

- `tests/PlayersPageCanaryTest.php` rebuilds the stats and pushes the tested player to the top of every list, then:
  - blocks: the blocked player is everywhere for a bystander and nowhere for the blocker
  - private profiles: in no people list for anybody, the friend on the allow list included
  - the card: only the friend may open a private player's card, and the blocker gets a 404
- Every person-listing query is in `PrivateProfileQueryCoverageTest::RAW_COLUMN` (public-only for everybody).

## Out of scope / follow-ups

Tracked in `docs/TODO.md` → "Players page".
