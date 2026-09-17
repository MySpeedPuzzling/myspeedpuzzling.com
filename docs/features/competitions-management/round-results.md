# Round Results Pages — Plan

**Status:** planned, all decisions settled (2026-09-17)

## Goal

Every competition round gets its own public results page with a shareable, readable link. WJPC 2026 has 13 rounds, each with its own puzzle, and "the results of Individual B" should be one link — not a scroll through the event's puzzle list.

## Settled

- **A time's round is determined by its competition + puzzle + category — no date check**, materialised in `puzzle_solving_time.competition_round_id` by a reconciler (Jan's idea — see below). People add results from home days later or backfill them; the event link the player chose is trusted.
- **A player's (or team's) earliest time for the round is their result**, not the fastest — without a date check a later practice run on the same puzzle also matches, and a repeat solve is quicker.
- **URL uses a readable round slug**, e.g. `/en/events/world-jigsaw-puzzle-championship-2026/results/individual-b`.
- **Rounds get their own official results link**, because some organisers publish results per round.
- **Unfinished results follow competition rules**: time = the round's limit, ranked by pieces placed. Entered in the existing collapsed **Competition result** card of the add/edit-time form, shipped to everyone (no feature flag).
- **Times over the limit without pieces reported are ranked after the unfinished ones.**
- **On the puzzle page, unfinished results — including ones finished later — sit at the bottom of the leaderboard without a rank.** A player who also has a finished time on that puzzle keeps one ranked row; their unfinished entry sits under "show more".
- **Unfinished results keep counting as solved** in profile counts for now; revisit with real data.

## What exists today (production, 2026-09-17)

- **Rounds** — `CompetitionRound` (name, `startsAt`, `minutesLimit`, category solo/duo/team, badge colours) + `CompetitionRoundPuzzle`, managed on the manage-rounds UI. 155 rounds across 73 competitions, 136 round↔puzzle links.
- **Times link to an event, never to a round.** `competition_id` is set on 17,102 times. `competition_round_id` exists and the handler + API accept a `roundId`, but no web form sends one: **0 rows**.
- **`missing_pieces` and `qualified`** exist on `puzzle_solving_time` from the original competition domain — **never written (0 rows)**.
- **Public pages** — the standalone event page shows only the tag's puzzle grid; the edition page lists rounds with puzzles; neither shows results.
- **WJPC 2026** — 108 active participants; its 13 rounds were entered on 2026-09-17 (see [WJPC 2026 data](#wjpc-2026-data)).

### What the data says about matching

Across every competition that has round puzzles, **613 times** are linked to the competition *and* are on one of its round puzzles:

| | times |
|---|---|
| finished within ±1 day of the round's start | **580** (95 %) |
| 2–7 days after | 10 |
| more than a week after | 8 |
| before the round | 15 |

- `first_attempt` does not separate the outliers — 28 of the 33 are marked first attempt.
- **Decision:** no date check anyway — some of the "later" ones are real results added from home with the form's default date. The ~5 % practice runs are neutralised by taking each player's *earliest* time for the round.
- **A puzzle is never in two rounds of the same category**, but **8 competitions reuse a puzzle across categories**: Ou La La SPC runs "Individual" and "Pairs" on the same puzzle every edition, "#1 – July 2024" shares one between Pairs and Teams. The 57 matches whose type differs from the round's category are all these. So *competition + puzzle* is not unique, *competition + puzzle + category* is.

### What the data says about unfinished results

Competitors who run out of time work around the form today: either they enter the limit and put the pieces in the comment (`5400` + "74 pieces left", "Poskládáno jen 574 dílků"), or they keep solving and enter the full time ("I placed 479 out of 500 pieces, still I finished the puzzle"). That is two separate facts — the **competition result** (limit + pieces placed) and the **real solving time** (optional) — and today only one fits. 75 competition-linked times mention pieces in the comment.

## How a time gets its round

### The rule

A time belongs to round R of its competition when

1. R's puzzles include the time's puzzle, **and**
2. R's category equals the time's `puzzling_type` (solo / duo / team).

No date condition. Thanks to the invariant below, at most one round can match.

### The invariant

A puzzle can be in only one round per category per competition. `AddPuzzleToCompetitionRoundHandler` rejects a second one (form error on the add-puzzle-to-round page), and `EditCompetitionRoundHandler` rejects a category change that would create one. Production already satisfies this.

### The reconciler

`RoundResultsReconciler::reconcile(?string $competitionId)` — two set-based `UPDATE`s, idempotent:

1. set `competition_round_id` to the matching round wherever it differs (including `NULL` → round),
2. set it to `NULL` wherever no round matches any more.

Cheap: only the 17k competition-linked times are ever candidates.

**Keeping the link current — two mechanisms, because of transaction timing** (pre-flight finding): handlers only `persist()`; the `doctrine_transaction` middleware flushes *after* the handler returns. A set-based `UPDATE` run inside a handler therefore cannot see the row that handler just created. So:

1. **A time's own writes resolve the round in PHP, before persist.** `SolvingTimeRoundResolver::resolve(competitionId, puzzleId, PuzzlingType): ?CompetitionRound` (one indexed query) sets `competitionRound` directly on the entity in `AddPuzzleSolvingTimeHandler` and `EditPuzzleSolvingTimeHandler` (so `PuzzleSolvingTime::modify()` gains a `competitionRound` argument — callers: the edit handler and the API `UpdateSolvingTimeProcessor` path). The group decides `puzzlingType`, so the resolver runs after the group is assembled. No flush dependency, no extra round trip.
2. **Round-side changes reconcile after flush, via domain events.** `DomainEventsSubscriber` dispatches recorded events on `postFlush`, still inside the transaction, where the flushed rows are visible. `CompetitionRound` and `CompetitionRoundPuzzle` become `EntityWithEvents` and record `CompetitionRoundsChanged(competitionId)` on create, category change, puzzle attach and puzzle removal; `PuzzleMergeRequest` (already `EntityWithEvents`) records it for every competition whose round puzzles or times the merge touched. A sync handler runs `RoundResultsReconciler::reconcile($competitionId)` — the two set-based `UPDATE`s above. Routed `sync` in `messenger.php` like `PuzzleSolved`.

Round and competition deletes already null the column.

**Plus a periodic safety net**: `myspeedpuzzling:reconcile-round-results` dispatches `ReconcileRoundResults`, handler reconciles everything. **Run once after deploy** to link the historical times. The 15-minute cron lives in the **`lily.srv` repo** (`apps/myspeedpuzzling/cron.d/myspeedpuzzling`, `lily-cron-run` + `sentry-cli monitors run` pattern, offset from `recalculate-puzzle-intelligence`), not in this repo.

**Why both, not cron alone:** during a live round people add their time and open the round page straight away — a cron-only link would leave them missing for up to 15 minutes. The cron repairs anything a write path misses (e.g. direct SQL like the WJPC 2026 round entry).

**API:** `POST /api/v1/me/solving-times` keeps accepting `roundId` to derive the competition; the reconciler then applies the rule, so a `roundId` that does not fit (wrong puzzle or category) does not stick.

**No manual round picker is needed** — with the invariant, the round is fully determined.

## Unfinished results

### Storage (decided)

- Replace the unused `missing_pieces` column with **`pieces_placed`** (nullable int) — what competitors and official results actually report — and add **`finished_later_seconds`** (nullable int): the total time of someone who kept solving after the limit. Both old columns are empty in production, so this is a plain generated migration.
- **An unfinished result never has `seconds_to_solve`** (`pieces_placed IS NOT NULL ⇒ seconds_to_solve IS NULL`, enforced in the handlers). Every leaderboard, statistic, insight, MSP rating, skill and ladder query reads only `seconds_to_solve`, so unfinished results — finished later or not — are excluded from all of them **without touching those queries**. Storing the later total in `seconds_to_solve` would have forced exactly that wide change, because Jan decided a later finish is not ranked either.
- `finished_later_seconds` is display-only ("finished in 1:48:12").
### Where it is entered (decided)

Inside the existing collapsed **🏆 Competition result** card of `_solving_time_form.html.twig`, under the event picker. 97 % of submissions never open that card, so the default path does not change. The same template serves the edit-time page and modal, so older entries can be corrected too.

```
┌ 🏆 Competition result ───────────────────┐
│ Competition / event                      │
│ [ WJPC 2026 · Valladolid          ▾ ]    │
│                                          │
│ ☑ I didn't finish within the time limit  │
│   Pieces placed  [ 479 ] / 500           │
│   ⓘ Time: leave empty if you stopped at  │
│     the limit, or enter your total time  │
│     if you finished it afterwards.       │
└──────────────────────────────────────────┘
```

- **No separate "total time" field in the form** — the form's normal time field carries it, the handler routes it:
  - stopped at the limit → time empty → `pieces_placed = N`, `seconds_to_solve = NULL`, `finished_later_seconds = NULL`;
  - finished afterwards → time filled → `pieces_placed = N`, `finished_later_seconds` = that time, `seconds_to_solve = NULL`.
  - Editing an unfinished result back to finished moves the time back to `seconds_to_solve` and clears both columns.
- **Server-side rules** (`PuzzleAddFormType` + `EditPuzzleSolvingTimeFormType`, `POST_SUBMIT`): speed mode still requires a time **unless** "didn't finish" is ticked; pieces placed is required when ticked, must be `1 … pieces_count − 1` (skip the upper bound for a new puzzle without a piece count), and requires a selected competition. Relax and collection modes never show or accept it.
- **Client side**: the checkbox reveals the pieces field (existing `toggle` controller pattern); `ppm-validator` must not warn on an empty time.
- **Stopwatch finish flow** (`finish_stopwatch`) uses the same form — a measured time is by definition finished, so the checkbox is hidden there.
- **API**: `piecesPlaced` on the solving-time write DTO, same validation.
- **Rollout: straight to everyone, no feature flag** (decided). Because this touches the platform's most critical form, the tests cover: normal speed add without competition still requires a time; relax and collection unchanged; stopwatch finish unchanged; unfinished solo, duo and team with and without a total time; edit form round-trip; every validation error.
- The limit time is never stored as a solving time — that is what pollutes statistics today.

## How unfinished results appear elsewhere

### Puzzle detail page (`PuzzleTimes`) — decided

```
 #   Puzzler            Time
 1.  Anna K.   🏆WJPC  0:42:17
 2.  Tom B.           0:47:03
 ...
12.  Petr N.          0:51:20
     ▾ show more
       0:58:02
       412/500 pcs 🏆WJPC
 ...
38.  Jan M.           1:52:40

 –   Eva S.   🏆WJPC  479/500 pcs
              (finished in 1:48:12)
 –   Lucie P. 🏆WJPC  390/500 pcs
```

- **Bottom of the same table, same tab (solo / duo / team), no rank** ("–"), pieces placed instead of a time, "finished in …" under it when `finished_later_seconds` is set, the usual event badge. Ordered by pieces placed, most first.
- **One row per player/team stays true**: someone with any finished time on the puzzle keeps their ranked row and the unfinished entry is listed under their "show more"; only players/teams with no finished time get a bottom row.
- **Implemented beside the ranked pipeline, not inside it**: a separate `GetPuzzleSolvers` read for unfinished entries, rendered after the ranked rows. `PuzzlesSorter`, grouping and rank numbering are untouched.
- Country / first-try / unboxed filters and the private-profile rule apply to the bottom rows exactly as to ranked ones.
- **"X× solved in relax mode"** (`relaxCountsByPuzzleId`, counts every time-less row today) must add `pieces_placed IS NULL`, or unfinished results would be counted as relax solves.
- **My attempts** panel: unfinished entries appear in the attempt history as "412/500 pcs"; best time, rank and median ignore them (no `seconds_to_solve`).

### Everywhere a time-less entry is shown as "Relax" today

These treat `seconds_to_solve IS NULL` as a relax solve and must show a "412/500 pcs" badge instead of the ☕ Relax badge:

- `components/RecentActivity.html.twig`
- `_player_solvings.html.twig` (player profile results)
- `components/PlayerSolvedPuzzles.html.twig` — the "only relax" filter must exclude unfinished results
- `notifications.html.twig`

Profile solved counts keep counting them (decided — revisit later).

## The round page

### URLs

- Standalone: `/en/events/{eventSlug}/results/{roundSlug}` (route `event_round_results`)
- Edition: `/en/series/{seriesSlug}/{editionSlug}/results/{roundSlug}` (route `edition_round_results`)
- Localised in all six locales, copying the parent routes (`/eventy/{slug}/vysledky/{roundSlug}` …).
- `competition_round.slug` — unique per competition, generated on create like edition slugs (`AddEditionHandler::generateUniqueSlug`), **kept on rename** so shared links survive. Existing 155 rounds get slugs in the same migration.
- 404 when the competition is not publicly visible (`IsCompetitionPubliclyVisible`).

### Ranking

1. **Finished within the limit** — by time, fastest first.
2. **Unfinished** (`pieces_placed` set) — shown as "{limit} · 479 / 500 pcs" (plus "finished in …" when `finished_later_seconds` is set), by pieces placed, most first; equal pieces share a rank. On the round page unfinished results *are* ranked — that is the competition's own ranking.
3. **Over the limit with no pieces reported** (the old workaround) — ranked after the unfinished ones, by time (decided).

**One result per player (solo) or team (duo/team): the earliest** — by `finished_at`, then `tracked_at`. Without a date check a later practice run also belongs to the round, and it would be faster.

Rows reuse the `PuzzleSolver` / `PuzzleSolversGroup` DTOs and `PuzzleTimes` row markup, so private players, secret puzzlers, ranking opt-outs and members-only skill tiers behave exactly as on the puzzle page. Suspicious times are excluded. Duo/team rows list the members.

### Content

1. **Header** — event name (back link), round name with badge colours, category, start date/time, time limit, **official results** button: the round's `results_link`, else the event's; UTM appended like every external link.
2. **Round puzzle(s)** — honours `hideUntilRoundStarts` / `hide_until` / `hide_image_until` (same rules as `GetEditionRounds`).
3. **State** — upcoming ("starts in …", no ranking), running / finished (ranking so far).
4. **"Add my time from this round"** — the existing `puzzle_add` deep link with the puzzle and `?competition=`; signed-in only, once the round has started.
5. **Round navigation** — previous / next and a round switcher; with 13+ rounds this matters most.
6. **SEO** — indexable, title "{round} – {event} results", canonical from the base route, started rounds in `SitemapEventsController`.

### Event pages

**Shipped already (095c8c4b):** the standalone event page's puzzle cards show their round ("Individual A · 17.09. 09:00") and follow the round schedule; the participants section only shows round filter chips when someone is assigned to a round.

Phase 1a adds a **Results** link (and the round's official results link) next to that round badge on each puzzle card, and to each round on the edition page.

## Phases

### Phase 1a — result pages (aim: during WJPC 2026, ends Sept 20)

1. Migration (generated by `doctrine:migrations:diff`, nothing hand-written): `competition_round.slug` (**nullable**) + unique `(competition_id, slug)`, `competition_round.results_link`, `puzzle_solving_time.pieces_placed` replacing `missing_pieces`, `puzzle_solving_time.finished_later_seconds`. All `ADD COLUMN … NULL` / `DROP COLUMN` — metadata-only in PostgreSQL 16, safe on the 514k-row table during the web container's boot-time migrate.
   **Slugs for existing rounds** come from a `myspeedpuzzling:backfill-round-slugs` command (message + handler) using the same `SluggerInterface` + uniqueness logic as new rounds, so old and new slugs can never differ — SQL slugification in a migration would not match Symfony's slugger. Run once after deploy, together with the reconcile command. Links to a round page are only rendered for rounds that have a slug.
2. Invariant validation (add puzzle to round, edit round category).
3. Reconciler + handler hooks + console command + message + cron; initial run.
4. `GetRoundResults` read model; the two round controllers + shared template; Results links on the event page's puzzle cards and the edition page; sitemap.
5. Round add/edit form: official results link field.
6. **Data**: remaining WJPC 2026 round puzzles attached and tagged `WJPC 2026` as they are revealed; per-round official results links filled in from the table below once the column exists.

Tests: reconciler (rule, category collision, re-link on edit, unlink on removal, merge), invariant, `GetRoundResults` ordering incl. unfinished, over-limit and earliest-time-per-player, controllers for both routes, hidden puzzles before start, private players.

### Phase 1b — unfinished results entry

"Didn't finish within the time limit" + pieces placed in the competition card of the add/edit-time form and the API write DTO, as specified in [Unfinished results](#unfinished-results); round ranking already supports it from 1a. No feature flag.

Ships together with [how unfinished results appear elsewhere](#how-unfinished-results-appear-elsewhere) — the puzzle page bottom rows, the relax count fix and the "pcs" badge in the four lists — so no unfinished result is ever shown as a relax solve.

Tests on top of the form matrix: puzzle page bottom rows (ordering, "finished in", player with both kinds under "show more", filters, private players), relax count excludes unfinished, profile / activity / notification badges, "only relax" filter, and that an unfinished result — with or without a later total — changes no puzzle statistic.

### Phase 2 — optional

- Round name on time badges everywhere ("WJPC 2026 · Individual B") — cheap now that the link is stored.
- `AddPuzzleToCompetitionRound` also tags the puzzle with the event's tag, so organisers stop doing both.
- `qualified` marks for semifinal → final.
- Live refresh of a running round via Mercure (anonymous pages are shared-cached for 60 s today, which may be enough).
- API V1: `GET /api/v1/competitions/{id}/rounds/{roundId}/results`.

## Technical pre-flight (2026-09-17)

Verified before implementation, so the plan holds against the code:

| Check | Result |
|---|---|
| Transaction timing | Handlers `persist()`, `doctrine_transaction` flushes after → reconcile split into resolve-before-persist + post-flush domain events (above) |
| Domain events | `DomainEventsSubscriber` dispatches on `postFlush`, sync routing exists (`PuzzleSolved`, `PuzzleSolvingTimeModified`) |
| Enums | `PuzzlingType` and `RoundCategory` share the values `solo` / `duo` / `team` — compared directly |
| Existing fixtures | `PuzzleSolvingTimeFixture` already links WJPC 2024 times to rounds, and they satisfy the rule (Qualification times on its puzzle, Final times on its puzzle) → reconciling changes no existing test data. Its factory's `missingPieces` argument becomes `piecesPlaced` |
| Test schema | `tests/bootstrap.php` builds the test DB with `doctrine:schema:create` from entities — new columns need no test setup |
| Route space | Only `/en/events/{slug}` and `/en/series/{seriesSlug}/{editionSlug}` exist under those prefixes — `/results/{roundSlug}` is free in every locale |
| Production slugs | No duplicate round names inside a competition, every name has letters/digits, longest 28 chars |
| Form tests | `PuzzleAddControllerTest` / `EditTimeControllerTest` already POST real forms (WebTestCase) — the whole validation matrix is testable without a browser; `CreateSolvingTimeEndpointTest` / `UpdateSolvingTimeEndpointTest` cover the API; `Sitemap*ControllerTest` exist |
| Browser checks | Chrome extension not connected. Public pages (round results, event page) → headless Chrome screenshots against the local stack. Logged-in JS (the "didn't finish" toggle, `ppm-validator`) → a Panther test via the `chrome` compose service and `/_test/login`; Panther does not run in CI, so it is run locally |
| Local migrations | The local dev DB needs the new migration for browser checks; `doctrine:migrations:migrate` is only run with Jan's OK (CLAUDE.md). Tests do not need it |

## Later

- **Should unfinished results count as solved** in profile counts? Kept counting for now; revisit once real unfinished results exist. Excluding them touches every player statistics query.

## WJPC 2026 data

Entered on production 2026-09-17 from the official schedule (worldjigsawpuzzle.org, Europe/Madrid). `starts_at` is stored in UTC like `AddCompetitionRoundController` does, so Madrid time − 2 h.

| Round | Category | Madrid start | Limit | Puzzle | Official results |
|---|---|---|---|---|---|
| Individual A | solo | 17/09 09:00 | 90 | Yellowstone National Park | https://worldjigsawpuzzle.org/wjpc/2026/individual/a |
| Individual B | solo | 17/09 11:00 | 90 | Glacier National Park | https://worldjigsawpuzzle.org/wjpc/2026/individual/b |
| Individual C | solo | 17/09 13:00 | 90 | Zion National Park | https://worldjigsawpuzzle.org/wjpc/2026/individual/c |
| Individual D | solo | 17/09 15:00 | 90 | — | https://worldjigsawpuzzle.org/wjpc/2026/individual/d |
| Individual Semifinal S1 | solo | 17/09 18:00 | 75 | — | https://worldjigsawpuzzle.org/wjpc/2026/individual/s1 |
| Individual Semifinal S2 | solo | 17/09 19:45 | 75 | — | https://worldjigsawpuzzle.org/wjpc/2026/individual/s2 |
| Pairs A | duo | 18/09 09:00 | 90 | — | https://worldjigsawpuzzle.org/wjpc/2026/pairs/a |
| Pairs B | duo | 18/09 11:00 | 90 | — | https://worldjigsawpuzzle.org/wjpc/2026/pairs/b |
| Teams A | team | 19/09 09:00 | 180 | — | https://worldjigsawpuzzle.org/wjpc/2026/teams/a |
| Teams B | team | 19/09 12:30 | 180 | — | https://worldjigsawpuzzle.org/wjpc/2026/teams/b |
| Teams Final | team | 19/09 17:00 | 180 | — | https://worldjigsawpuzzle.org/wjpc/2026/teams/final |
| Pairs Final | duo | 20/09 09:30 | 120 | — | https://worldjigsawpuzzle.org/wjpc/2026/pairs/final |
| Individual Final | solo | 20/09 12:00 | 75 | — | https://worldjigsawpuzzle.org/wjpc/2026/individual/final |

Individual C → Zion National Park was attached after this table was first written. Round puzzles are attached and tagged by SQL as Jan reports them.
