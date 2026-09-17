# Round Results Pages — Plan

**Status:** draft, decisions partly settled (2026-09-17)

## Goal

Every competition round gets its own public results page with a shareable, readable link. WJPC 2026 has 13 rounds, each with its own puzzle, and "the results of Individual B" should be one link — not a scroll through the event's puzzle list.

## Settled

- **A time's round is determined by its competition + puzzle + category**, materialised in `puzzle_solving_time.competition_round_id` by a reconciler (Jan's idea — see below).
- **URL uses a readable round slug**, e.g. `/en/events/world-jigsaw-puzzle-championship-2026/results/individual-b`.
- **Rounds get their own official results link**, because some organisers publish results per round.
- **Unfinished results follow competition rules**: time = the round's limit, ranked by pieces placed.

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
- **A puzzle is never in two rounds of the same category**, but **8 competitions reuse a puzzle across categories**: Ou La La SPC runs "Individual" and "Pairs" on the same puzzle every edition, "#1 – July 2024" shares one between Pairs and Teams. The 57 matches whose type differs from the round's category are all these. So *competition + puzzle* is not unique, *competition + puzzle + category* is.

### What the data says about unfinished results

Competitors who run out of time work around the form today: either they enter the limit and put the pieces in the comment (`5400` + "74 pieces left", "Poskládáno jen 574 dílků"), or they keep solving and enter the full time ("I placed 479 out of 500 pieces, still I finished the puzzle"). That is two separate facts — the **competition result** (limit + pieces placed) and the **real solving time** (optional) — and today only one fits. 75 competition-linked times mention pieces in the comment.

## How a time gets its round

### The rule

A time belongs to round R of its competition when

1. R's puzzles include the time's puzzle, **and**
2. R's category equals the time's `puzzling_type` (solo / duo / team), **and**
3. `finished_at::date` is within R's start day ±1.

Thanks to the invariant below, at most one round can match.

The ±1 day absorbs people adding their time the next morning with the form's default date, and online events whose `starts_at` is UTC while `finished_at` is the solver's local date. A time outside the window keeps its competition link and simply has no round; correcting the date on the time fixes it.

### The invariant

A puzzle can be in only one round per category per competition. `AddPuzzleToCompetitionRoundHandler` rejects a second one (form error on the add-puzzle-to-round page), and `EditCompetitionRoundHandler` rejects a category change that would create one. Production already satisfies this.

### The reconciler

`RoundResultsReconciler::reconcile(?string $competitionId)` — two set-based `UPDATE`s, idempotent:

1. set `competition_round_id` to the matching round wherever it differs (including `NULL` → round),
2. set it to `NULL` wherever no round matches any more.

Cheap: only the 17k competition-linked times are ever candidates.

**Runs synchronously, scoped to the affected competition(s)**, at the end of the handlers that can change the answer:

- `AddPuzzleSolvingTimeHandler`, `EditPuzzleSolvingTimeHandler` (competition, date, group/type change)
- `AddCompetitionRoundHandler`, `EditCompetitionRoundHandler` (date, category)
- `AddPuzzleToCompetitionRoundHandler`, `RemovePuzzleFromCompetitionRoundHandler`
- `ApprovePuzzleMergeRequestHandler` (moves times and round puzzles between puzzle ids)

Round and competition deletes already null the column.

**Plus a periodic safety net**: `myspeedpuzzling:reconcile-round-results` dispatches `ReconcileRoundResults`, handler reconciles everything; cron every 15 minutes next to the puzzle-intelligence one. Run once after deploy to link the historical 580.

**Why both, not cron alone:** during a live round people add their time and open the round page straight away — a cron-only link would leave them missing for up to 15 minutes. The cron repairs anything a write path misses.

**API:** `POST /api/v1/me/solving-times` keeps accepting `roundId` to derive the competition; the reconciler then applies the rule, so a `roundId` that does not fit (wrong puzzle, category or date) does not stick.

**No manual round picker is needed** — with the invariant, the round is fully determined.

## Unfinished results

- Replace the unused `missing_pieces` column with **`pieces_placed`** (nullable int) — what competitors and official results actually report. Both columns are empty in production, so this is a plain generated migration.
- **Add-time / edit-time form**, in the competition section: *"Didn't finish within the time limit"* → **Pieces placed** (required, `1 … pieces_count − 1`) and an optional **"I finished it afterwards — total time"**.
  - Stopped at the limit → `seconds_to_solve = NULL`, `pieces_placed = N`. A NULL time is already excluded from leaderboards, statistics and insights everywhere.
  - Finished afterwards → `seconds_to_solve` = the full time, `pieces_placed = N`. The full time is a real solve, so it stays in puzzle leaderboards and statistics; only the round ranking treats it as unfinished.
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
3. **Over the limit with no pieces reported** (the old workaround) — listed last, unranked, with their time.

Rows reuse the `PuzzleSolver` / `PuzzleSolversGroup` DTOs and `PuzzleTimes` row markup, so private players, secret puzzlers, ranking opt-outs and members-only skill tiers behave exactly as on the puzzle page. Suspicious times are excluded. Duo/team rows list the members. One row per player for solo.

### Content

1. **Header** — event name (back link), round name with badge colours, category, start date/time, time limit, **official results** button: the round's `results_link`, else the event's; UTM appended like every external link.
2. **Round puzzle(s)** — honours `hideUntilRoundStarts` / `hide_until` / `hide_image_until` (same rules as `GetEditionRounds`).
3. **State** — upcoming ("starts in …", no ranking), running / finished (ranking so far).
4. **"Add my time from this round"** — the existing `puzzle_add` deep link with the puzzle and `?competition=`; signed-in only, once the round has started.
5. **Round navigation** — previous / next and a round switcher; with 20 rounds this matters most.
6. **SEO** — indexable, title "{round} – {event} results", canonical from the base route, started rounds in `SitemapEventsController`.

### Event pages

The edition page's rounds section becomes a shared partial used by **both** the standalone event page and the edition page, with per round: status, result count, **Results** link, official results link. The tag's puzzle grid stays for events without rounds.

## Phases

### Phase 1a — result pages (aim: during WJPC 2026, ends Sept 20)

1. Migration (generated): `competition_round.slug`, `competition_round.results_link`, `puzzle_solving_time.pieces_placed` replacing `missing_pieces`; slug backfill.
2. Invariant validation (add puzzle to round, edit round category).
3. Reconciler + handler hooks + console command + message + cron; initial run.
4. `GetRoundResults` read model; the two round controllers + shared template; shared rounds partial; sitemap.
5. Round add/edit form: official results link field.
6. **Data**: remaining WJPC 2026 round puzzles attached and tagged `WJPC 2026` as they are revealed; per-round official results links filled in from the table below once the column exists.
7. **Fix `GetCompetitionRounds::ofCompetition()`** — it picks chip colours with `COLORS[$i]` from a 20-entry palette, so the participants component on an event page throws for a competition with more than 20 rounds. Cycle the palette.

Tests: reconciler (rule, category collision, ±1 day, re-link on edit, unlink on removal, merge), invariant, `GetRoundResults` ordering incl. unfinished and legacy over-limit, controllers for both routes, hidden puzzles before start, private players.

### Phase 1b — unfinished results entry

`pieces_placed` + "finished afterwards" in the add/edit-time forms and the API write DTO; round ranking already supports it from 1a.

### Phase 2 — optional

- Round name on time badges everywhere ("WJPC 2026 · Individual B") — cheap now that the link is stored.
- `AddPuzzleToCompetitionRound` also tags the puzzle with the event's tag, so organisers stop doing both.
- `qualified` marks for semifinal → final.
- Live refresh of a running round via Mercure (anonymous pages are shared-cached for 60 s today, which may be enough).
- API V1: `GET /api/v1/competitions/{id}/rounds/{roundId}/results`.

## Still open

1. **Date window** — round day ±1 (recommended; 95 % of matches), exact day, or the whole event?
2. **Over the limit with no pieces reported** — list last unranked (recommended), or hide?
3. **Does an unfinished result count as a solved puzzle** in the player's profile counts? Today every time row counts, including time-less ones. Excluding unfinished ones touches the player statistics queries — recommend leaving counts as they are in 1b and deciding separately.
4. **WJPC 2026 rounds** — you via the manage-rounds UI, or scripted from a list of rounds + puzzle ids?

## WJPC 2026 data

Entered on production 2026-09-17 from the official schedule (worldjigsawpuzzle.org, Europe/Madrid). `starts_at` is stored in UTC like `AddCompetitionRoundController` does, so Madrid time − 2 h.

| Round | Category | Madrid start | Limit | Puzzle | Official results |
|---|---|---|---|---|---|
| Individual A | solo | 17/09 09:00 | 90 | Yellowstone National Park | https://worldjigsawpuzzle.org/wjpc/2026/individual/a |
| Individual B | solo | 17/09 11:00 | 90 | Glacier National Park | https://worldjigsawpuzzle.org/wjpc/2026/individual/b |
| Individual C | solo | 17/09 13:00 | 90 | — | https://worldjigsawpuzzle.org/wjpc/2026/individual/c |
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

Side effect already live: the participants section on the event page shows the 13 rounds as filter chips; nobody is assigned to a round, so filtering by one shows an empty list.
