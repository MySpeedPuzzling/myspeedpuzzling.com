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

**Runs synchronously, scoped to the affected competition(s)**, at the end of the handlers that can change the answer:

- `AddPuzzleSolvingTimeHandler`, `EditPuzzleSolvingTimeHandler` (competition or group/type change)
- `AddCompetitionRoundHandler`, `EditCompetitionRoundHandler` (category change)
- `AddPuzzleToCompetitionRoundHandler`, `RemovePuzzleFromCompetitionRoundHandler`
- `ApprovePuzzleMergeRequestHandler` (moves times and round puzzles between puzzle ids)

Round and competition deletes already null the column.

**Plus a periodic safety net**: `myspeedpuzzling:reconcile-round-results` dispatches `ReconcileRoundResults`, handler reconciles everything; cron every 15 minutes next to the puzzle-intelligence one. Run once after deploy to link the historical 580.

**Why both, not cron alone:** during a live round people add their time and open the round page straight away — a cron-only link would leave them missing for up to 15 minutes. The cron repairs anything a write path misses.

**API:** `POST /api/v1/me/solving-times` keeps accepting `roundId` to derive the competition; the reconciler then applies the rule, so a `roundId` that does not fit (wrong puzzle or category) does not stick.

**No manual round picker is needed** — with the invariant, the round is fully determined.

## Unfinished results

- Replace the unused `missing_pieces` column with **`pieces_placed`** (nullable int) — what competitors and official results actually report. Both columns are empty in production, so this is a plain generated migration.
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

- **No separate "total time" field** — the form's normal time field carries it:
  - stopped at the limit → time empty → `seconds_to_solve = NULL`, `pieces_placed = N`. A NULL time is already excluded from leaderboards, statistics and insights everywhere;
  - finished afterwards → time filled → `seconds_to_solve` = the full time, `pieces_placed = N`. The full time is a real solve and stays in puzzle leaderboards and statistics; only the round ranking treats it as unfinished.
- **Server-side rules** (`PuzzleAddFormType` + `EditPuzzleSolvingTimeFormType`, `POST_SUBMIT`): speed mode still requires a time **unless** "didn't finish" is ticked; pieces placed is required when ticked, must be `1 … pieces_count − 1` (skip the upper bound for a new puzzle without a piece count), and requires a selected competition. Relax and collection modes never show or accept it.
- **Client side**: the checkbox reveals the pieces field (existing `toggle` controller pattern); `ppm-validator` must not warn on an empty time.
- **Stopwatch finish flow** (`finish_stopwatch`) uses the same form — a measured time is by definition finished, so the checkbox is hidden there.
- **API**: `piecesPlaced` on the solving-time write DTO, same validation.
- **Rollout: straight to everyone, no feature flag** (decided). Because this touches the platform's most critical form, the tests cover: normal speed add without competition still requires a time; relax and collection unchanged; stopwatch finish unchanged; unfinished solo, duo and team with and without a total time; edit form round-trip; every validation error.
- The limit time is never stored as a solving time — that is what pollutes statistics today.

## The round page

### URLs

- Standalone: `/en/events/{eventSlug}/results/{roundSlug}` (route `event_round_results`)
- Edition: `/en/series/{seriesSlug}/{editionSlug}/results/{roundSlug}` (route `edition_round_results`)
- Localised in all six locales, copying the parent routes (`/eventy/{slug}/vysledky/{roundSlug}` …).
- `competition_round.slug` — unique per competition, generated on create like edition slugs (`AddEditionHandler::generateUniqueSlug`), **kept on rename** so shared links survive. Existing 155 rounds get slugs in the same migration.
- 404 when the competition is not publicly visible (`IsCompetitionPubliclyVisible`).

### Ranking

1. **Finished within the limit** — by time, fastest first.
2. **Unfinished** (`pieces_placed` set) — shown as "{limit} · 479 / 500 pcs", by pieces placed, most first; equal pieces share a rank.
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

1. Migration (generated): `competition_round.slug`, `competition_round.results_link`, `puzzle_solving_time.pieces_placed` replacing `missing_pieces`; slug backfill.
2. Invariant validation (add puzzle to round, edit round category).
3. Reconciler + handler hooks + console command + message + cron; initial run.
4. `GetRoundResults` read model; the two round controllers + shared template; Results links on the event page's puzzle cards and the edition page; sitemap.
5. Round add/edit form: official results link field.
6. **Data**: remaining WJPC 2026 round puzzles attached and tagged `WJPC 2026` as they are revealed; per-round official results links filled in from the table below once the column exists.

Tests: reconciler (rule, category collision, re-link on edit, unlink on removal, merge), invariant, `GetRoundResults` ordering incl. unfinished, over-limit and earliest-time-per-player, controllers for both routes, hidden puzzles before start, private players.

### Phase 1b — unfinished results entry

"Didn't finish within the time limit" + pieces placed in the competition card of the add/edit-time form and the API write DTO, as specified in [Unfinished results](#unfinished-results); round ranking already supports it from 1a. No feature flag.

### Phase 2 — optional

- Round name on time badges everywhere ("WJPC 2026 · Individual B") — cheap now that the link is stored.
- `AddPuzzleToCompetitionRound` also tags the puzzle with the event's tag, so organisers stop doing both.
- `qualified` marks for semifinal → final.
- Live refresh of a running round via Mercure (anonymous pages are shared-cached for 60 s today, which may be enough).
- API V1: `GET /api/v1/competitions/{id}/rounds/{roundId}/results`.

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
