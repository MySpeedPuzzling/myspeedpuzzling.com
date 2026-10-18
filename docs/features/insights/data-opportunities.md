# What else the data can do - brainstorm checked against the database

Status: research, 2026-10-18 (brainstorm kept for later features - Jan: "yearly report later, keep the brainstorm"). Read-only analysis of the local production copy (data until 2026-10-11): 523,499 results (440,713 valid timed solo), 41,282 puzzles, 7,598 players with ≥ 1 result. Scripts lived in the local lab `.claude/worktrees/insights-data-lab/scripts/` (git-excluded). Per-brand quality signals (e.g. missing-piece mentions in comments) were measured but are deliberately left out of this public file. No player names; brands only in aggregates.

Out of scope (covered elsewhere): the personal Insights page, the image pipeline, image-based prediction (prediction lab).

## 1. TL;DR

Seven numbers nobody else has:
1. **Doubling pieces ≈ 2.7× the time**, not 2×: within player pieces^1.30 (300→500), ^1.43 (500→1000); fastest puzzlers scale best (1.25 vs 1.47).
2. **Difficulty is skill-invariant**: hard ÷ easy 500 = 1.49-1.54 in every speed band → golf-style **par/handicap is fair**; one result lands within ≈ ±11 % of par (robust SD, in-sample - backtest first).
3. **Power law of practice**: newcomers −15 % after 50 puzzles, −23 % after 150 (exponent ≈ 0.05-0.06). Fixed 2024+2026 panel: 70.0 → 59.5 min (−15 % in two years).
4. **Ringelmann curve**: pair 1.46× faster on 500 (73 % efficiency/person), team of 4 2.3× (57 %); pair on 1000 83 %.
5. **Six degrees of puzzling**: 57 % of 3,039 co-puzzlers in one network, median distance 6; 15 % of links cross a border.
6. **Events look 11 % faster, mostly easier puzzles** (median difficulty 1.00 vs 1.05); adjusted boost ≈ 2.5 %, events less noisy (SD 9.7 % vs 11.4 %).
7. **Second day is the retention lever**: 1 result in first 30 days → 20 % retained at 6-12 months; 3+ distinct days 66 %; an event result 89 %. Paid conversion 5.6 % (1-4 results in month 1) vs 29 % (15+).

Top 10 ideas (value per effort):

| # | Idea | For | Value | Effort |
|---|---|---|---|---|
| 1 | Handicap index + par times, handicap leagues/events, "beat your par" | players, organisers | wow | M |
| 2 | "State of Speed Puzzling" yearly report + evergreen data pages (6 locales) | media, SEO | wow | S-M |
| 3 | Onboarding "second day" loop + churn-risk re-engagement | business | big money | S-M |
| 4 | Organiser round builder: target difficulty, "already solved by N entrants", balanced heats, seeding | organisers | wow | M |
| 5 | Year in review ("Wrapped") - 5,144 players with 2026 results, ship by mid-December | players | wow | M |
| 6 | Structured result signals: enjoyment ★, perceived difficulty, missing-piece flag | everyone downstream | compounds | S |
| 7 | Brand data pages + B2B brand report (aggregates only; 114 brands ≥ 100 results/yr) | brands, SEO | useful → B2B | M |
| 8 | Puzzle recommender (CF + image embeddings) in the Puzzle Picker | members | useful | M |
| 9 | Partner finder for pair/team events (pace-matched, nearby, network-aware) | players, organisers | useful | M |
| 10 | Live win probabilities / predicted finish in online rounds | players, spectators | wow | M |

Not ready (collect first): price guide / collection value (890 priced sales), wishlist demand (8,348 items from only 337 players).

## 2. Teaser analyses
Solo, `suspicious = false`. Pace = time ÷ player's own baseline for the piece count. Difficulty = medium/high confidence (6,285 puzzles). Medians.

**T1 scaling law (within player, first attempts)** - all: 300→500 exp 1.30 (n 1,529), 500→1000 exp 1.43 (n 1,217, t1000/t500 2.69), 1000→2000 1.52 (n 105). <45-min band: 1.32 / 1.25 (2.37×); 60-90 band: 1.28 / 1.47 (2.76×).

**T2 learning curve (newcomers since 2023, 500 pc, difficulty-adjusted, vs own first three)**

| nth 500 | 1-3 | 5 | 10 | 20 | 30 | 50 | 75 | 100 | 150 |
|---|---|---|---|---|---|---|---|---|---|
| cohort ≥ 50 (931) | 1.00 | 0.97 | 0.93 | 0.91 | 0.88 | 0.85 | | | |
| cohort ≥ 150 (276) | 1.00 | 0.97 | 0.93 | 0.92 | 0.89 | 0.85 | 0.82 | 0.79 | 0.77 |

**T3 pairs and teams (solo median ÷ group median per puzzle)**

| puzzlers | pieces | puzzles | speedup | efficiency |
|---|---|---|---|---|
| 2 | 500 | 1,865 | 1.46× | 0.73 |
| 2 | 1000 | 304 | 1.66× | 0.83 |
| 3 | 500 | 120 | 1.76× | 0.59 |
| 3 | 1000 | 79 | 2.09× | 0.70 |
| 4 | 500 | 196 | 2.28× | 0.57 |
| 4 | 1000 | 306 | 2.93× | 0.73 |

**T4 clock/pauses (25,687 stopwatch sessions; local time via single-zone countries, 20,467 sessions)** - within player: 12-15 h 0.977, Sunday 0.984 vs Monday 1.004, 1 lap 0.967 vs 5+ laps 1.107, 4th+ session of the day 0.939 (warm-up, not fatigue), September 0.968 vs December 1.031. Raw "morning people are faster" is pure selection.

**T5 brand fingerprint (500 pc, ≥ 15 rated)** - Ceaco 0.88, Mudpuppy 0.91, Jumbo 0.96, Galison 0.98, Buffalo Games 1.00, Trefl 1.03, Cobble Hill 1.06, Ravensburger 1.07, Castorland 1.09, Clementoni 1.12, Educa 1.15. ≈ 30 % between ends, within-brand spread as large; image features will separate art style from cut/print.

**T6 events vs home** - in person raw 0.886 / adjusted 0.975 (1,063 players); online 0.920 / 0.989 (814).

**T14/T15 skill-invariant difficulty** - hard ÷ easy: <50 min 1.49, 50-70 1.53, 70-100 1.54, 100+ 1.54. Residual around par: robust SD 11.2 %; 80 % of results within −12 % … +19 %.

**T8/T16 retention (registered 2024-07…2025-09)** - 1 result 20 %, 2-4 36 %, 5-9 54 %, 3+ days 66 %, a pair/team result 64 %, 10+ 78 %, an event result 89 %. Paid: 5.6 % / 12 % / 29 % (1-4 / 5-14 / 15+ results in month 1), 35 % with an event result. Correlations.

**Small numbers** - typical times (own baselines): 300 pc 39 min (p10 27 / p90 61), 500 pc 81 (56/122), 1000 pc 192 (121/337). Box worth ≈ 3 % at 500 (unboxed ×1.026). Co-puzzler network: 6,125 links, largest component 1,734. Ravensburger share 63 % → 60.5 % (2024H2 → 2026). Marketplace 500 pc median EUR 5; by condition new 8 / like new 6 / normal 5 / missing pieces 3.

## 3. Idea catalogue (E exists / P partial / M missing; self = self-only, agg = public aggregate with thresholds)

**Puzzle science** - scaling law (E, agg, SEO); practice law + plateau detection (E, members); re-solve memory law (E, 65,854 re-solves); multiplicative par (E, enables handicap); time of day/weekday/season/pauses/warm-up (E/P, self + agg); events vs home (E); Ringelmann + pair synergy vs par (E); brand cut fingerprint (P, needs image pipeline, B2B); piece-count honesty (M - real count/grid field, box OCR); what makes a puzzle hard (P, with prediction lab); box effect by skill (E, niche); do 1000s make you faster at 500s (E, niche); co-puzzler network science (E, agg only, never named paths).

**Players** - handicap index + par on puzzle pages + net-time rankings (E; 3,907 with a 500 baseline; members index, free par); handicap monthly online challenges; Wrapped (E); goals + forecast ("sub-60 by March: 64 %"); training plans (difficulty ladder, no-break sessions); "people who started when you did" cohorts; recommender (P - fun signal missing); partner finder (public players, blocks respected); re-solve planner; collection value / price guide (later); "1 year ago" anniversaries.

**Organisers** - round builder + freshness check (counts only); balanced heats + fairness report (current multi-puzzle rounds: median 1.02×, worst 1.39×, 16 rounds); seeding/table assignment from ratings; live win probability (83,526 stored predictions to calibrate); qualification forecasting; handicap divisions for local events; post-event report (≥ 10 players); team-size planner.

**Brands/retailers (aggregates only, never player rows)** - public brand data pages (≥ 20 players); B2B brand report (27 brands ≥ 1,000 results/yr); "MSP difficulty" badge on boxes (1,915 puzzles ≥ 30 solo results; must stay independent of who pays); pre-release difficulty from artwork (NDA images); quality monitoring (structured missing-piece flag); demand signals (views/missed scans not logged yet); release adoption curve; out-of-print "most wanted"; where-to-buy/affiliate (M).

**Media/SEO/community** - "State of Speed Puzzling 2026" (January); evergreen answer pages in 6 locales ("How long does a 1000 take?", "Is a 1000 twice a 500?", "Are pairs twice as fast?"); country pages with data (≥ 30 public players); verified records/milestones; trending / puzzle of the week; open CC BY aggregate dataset; research partnerships (pseudonymised + data-use agreement); newsletter data stories.

**Platform/business** - second-day loop (day-2/3 goal, pair invite, nearby event); churn-risk re-engagement e-mail; trial offer at "15 results / first event / first pair" moments; member feature-usage → renewal model (not logged); data quality (EAN groups + embeddings, piece-count outliers from times; 786 EAN groups / 2,292 puzzles); moderation queue ranked by impact; suspicious-time v2 from lap patterns; feature-request value model.

## 4. Start collecting now (cheap, compounds)
1. Link a stopwatch session to its result (today only player + puzzle join them).
2. One-tap start time of day for manual entries (75 % of results have no stopwatch); time zone shipped (#254).
3. Optional enjoyment ★ + perceived difficulty per result.
4. Missing / extra / damaged piece flag (results, lending returns).
5. Stopwatch split buttons ("border done", "half").
6. Copy listing `added_at` + asking price onto `sold_swapped_item` (time-to-sell, discount).
7. Log zero-result searches + unknown EAN scans (aggregate, no player id).
8. Daily page-view counts per puzzle/brand (aggregate).
9. Optional puzzle facts: real piece count/grid, cut style, finish, release year, artist.
10. Price paid / new or used on collection items (self-only).
11. Member feature-usage counters (daily aggregate).
12. Immortal yearly aggregates before the 24-month prunes.

## 5. Risks and ethics
Self-only by default for personal effects; public = aggregates with ≥ 20 players (≥ 30 countries, ≥ 5 sales for prices), no drill-down to one person; public aggregates from public-profile players; blocks respected; secret puzzles (`hide_until` / `hide_image_until`) excluded everywhere until revealed. B2B: never player-level data, privacy policy update before the first paid report, rating independent of who pays with public methodology. Comments: aggregated counts after a measured local classifier, never quoted. Research: solve-level rows re-identifiable → aggregates openly, finer only pseudonymised under agreement. Handicap gaming: best N of last M, weight events, cap index rises, out-of-sample backtest first. Observational data: confounding (T4) must be stated. Positive framing of plateaus.

## 6. Jan's decisions (2026-10-18)
1. **Handicap:** compute and store it apart, never shown; it feeds a later update of the skill / MSP rating formulas.
2. **Year in review:** Spotify Wrapped / Apple Music Replay style, self-only, opt-in share card.
3. **Brand reports:** OK, aggregates only.
4. **Add form extras** (enjoyment ★, perceived difficulty, missing-piece flag, start time): only behind a collapsed "More optional details" toggle - keep the form short.
5. **Aggregate logging** (page views per puzzle/brand, searches with no result, unknown barcode scans; no player id): OK.
6. **Yearly report / open dataset:** later.

## 7. Method notes
Read-only, every query seconds. Baseline + difficulty analyses partly circular (noted). Stopwatch laps UTC; local time = country proxy (US/CA/AU excluded). `t11_copuzzler_graph.py` needs the edge list re-exported (export query not saved in the script).
