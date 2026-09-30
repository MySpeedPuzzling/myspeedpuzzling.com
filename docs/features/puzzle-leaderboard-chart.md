# Puzzle leaderboard: chart and "where am I"

Status: built 2026-09-30 (WS-C2 of the autumn SEO plan, follow-up to the capped leaderboard of WS-C); Jan's decisions of the same
day applied - see "Decisions" below.
Code: `src/Component/Chart/PuzzleTimesChart.php`, `src/Services/LeaderboardHistogramBuilder.php`,
`assets/controllers/leaderboard_chart_controller.js`, `src/Component/PuzzleTimes.php`,
`templates/components/PuzzleTimes.html.twig`, `templates/components/Chart/PuzzleTimesChart.html.twig`.

## The problem

Jan, after WS-C capped the table at 100 rows: *"think about the chart … showing only the top 100 would affect the chart a lot … it
would be good to have 'Show myself', or always display myself as the 101st row when I'm 600+, so I always know my position.
I understand a chart with 1,800 bars and 1,800 table rows is not useful either."*

The members' chart drew **one bar per leaderboard row**. On London Postcard that is 1,718 bars, under one pixel each: the viewer's
own bar is a single red hairline, labels are unreadable, and one 7-hour entry stretches the y axis so the whole field is a flat
strip at the bottom. It also shipped every row's label and colour in the HTML (118 KB of chart JSON on that page).

## What members use the chart and the table for

1. **Where do I stand?** – my position, as a number and relative to the field ("am I faster than most?").
2. **What does the field look like?** – where the bulk of the puzzlers are, how far the elite are ahead, how long the slow tail is.
3. **What is my next goal?** – how much faster I need to be to reach the next meaningful place.
4. **Who is around me / my friends?** – rivals of similar speed; friends via the "favourite players" filter.

Only (4) needs individual rows; (1)–(3) need an aggregate plus a "you are here" marker.

## How comparable products do it

Verified in this session (the other pages are login- or bot-gated, so they are described from product knowledge and not re-checked):

- **lichess** – a per-variant *rating distribution* chart (players per rating range) with the viewer's rating marked and the
  sentence "You are better than X% of Y players" (translation keys `weeklyPerfTypeRatingDistribution`,
  `youAreBetterThanPercentOfPerfTypePlayers` in lichess-org/lila `translation/source/site.xml`, checked).
- **Monkeytype** – the leaderboard page renders the viewer's own entry in a separate block ("You (Top 12.34%)", "GOAT" for #1,
  rank change "since you last checked") using the same row component as the table
  (`frontend/src/ts/components/pages/leaderboard/UserRank.tsx`, checked).
- **Strava segments** (product knowledge) – top of the leaderboard plus the athlete's own position, filters for following /
  clubs / age / weight; never the full list at once.
- **parkrun** (product knowledge) – one long results table per event, no chart; people find themselves with the browser's search.
- **speedrun.com** (product knowledge) – paginated leaderboards, own run highlighted, no distribution chart.
- **chess.com** (product knowledge) – "percentile" figure on the stats page.
- **Garmin / Strava comparisons** (product knowledge) – a percentile or band ("top 20 %") against peers rather than raw lists.
- **Duolingo leagues** (product knowledge) – small cohorts (~30) so every neighbour is visible; the lesson is that people care
  most about the few rows right around them.

Take-aways: nobody draws thousands of individual bars; the common pattern is **distribution + "you are here" + percentile**, and
**top of the list + the viewer's own row with neighbours**.

## Data (prod copy, 2026-09-29, best time per player / pair / team)

| Leaderboards with more than … rows | 30 | 50 | 100 | 300 | 1000 | max |
|---|---|---|---|---|---|---|
| Solo | 1,455 | 975 | 507 | 149 | 9 | 1,764 |
| Pairs | 287 | 197 | 90 | 5 | 0 | 362 |
| Teams | 58 | 18 | 1 | 0 | 0 | 102 |

Times are right-skewed: London Postcard (500 pcs) – fastest 19:02, median 53:25, P95 1:42, P99 2:30, slowest 7:11.

## Options evaluated

| Option | Where do I stand | Shape of the field | Friends / individuals | Mobile (375 px) | Payload |
|---|---|---|---|---|---|
| **(a) Distribution histogram** – "nice" time bins, your bar highlighted, median + you marked | ✅ marker + percentile | ✅ | ❌ (table / favourites filter) | ✅ 13–31 bars | ✅ ~30 numbers |
| **(b) Sorted curve / percentile line** – time vs % of the field | ✅ exact point | ⚠️ as a CDF, harder to read for most people | ❌ | ✅ | ✅ ~100 points |
| **(c) Top N bars + you** | ⚠️ your bar far off to the side | ❌ shows the elite only | ✅ for the top | ⚠️ | ✅ |
| **(d) Toggle distribution / individual** | ✅ | ✅ | ✅ | ⚠️ one more control | ⚠️ both datasets |
| Today: one bar per row | ❌ hairline | ⚠️ outliers flatten it | ⚠️ hover a 1 px bar | ❌ | ❌ 118 KB on London |

## Recommendation (built)

**Chart – pick the right view by size, no toggle.**
- **Up to 50 rows: one bar per row, as before** (`PuzzleTimesChart::INDIVIDUAL_BARS_MAX`). Every bar is at least ~6 px
  wide on a phone, names are in the tooltips, first attempts stay blue, drag-zoom stays. New: the same dashed **Median** line,
  here horizontal at the median time (y is time in this view), and **You** above the viewer's (solid red) bar, drawn by the same
  plugin and kept inside the chart on a 375 px phone; the same `role="img"` summary.
- **Above 50 rows: the distribution (a).** 50 is where individual bars stop being tappable on a phone and where a histogram
  starts to have a meaningful shape. It applies to 975 solo boards, 197 pair boards and 18 team boards.
- The switch counts the **filtered** rows. Filters that narrow the list – favourite players, a country, my pairs/teams,
  first attempts, unboxed – bring the bar per row back as soon as 50 or fewer rows are left, which covers "compare with friends".
- A manual toggle (d) was left out: individual bars are useless above ~100 rows (Jan's own point), and below 50 a histogram is
  too sparse, so the toggle would only matter for 51–100 rows at the price of one more control.
- (b) was not chosen: a percentile curve is precise but reads like a CDF, which most puzzlers do not parse at a glance; the
  histogram plus the percentile sentence gives the same answer in plain words.

**The distribution** (`LeaderboardHistogramBuilder`, pure PHP, unit-tested):
- Bar width = the narrowest of 10 s, 15 s, 30 s, 1, 2, 5, 10, 15, 30 min, 1, 2, 4 h that keeps the chart at ≤ 30 bars; bars start
  on multiples of it, so the axis reads 00:15:00, 00:20:00, 00:25:00 … Validated on every real board with more than 50 rows: 13–31 bars
  (median 21), 5-minute bars on 775 of 975 solo boards.
- Far outliers – beyond Q1 − 3 IQR / Q3 + 3 IQR – fold into one open-ended, lighter bar at either end ("≥ 2:30:00"); they hold a
  median 0.5 % of the rows (p90 1.9 %, max 5.3 %). One bogus 7-hour entry no longer squashes the field.
- Built from the **whole filtered leaderboard**, never from the 100 rows the table shows - see "What feeds the chart" below.
  Pairs and teams count pairs and teams ("Pairs" on the axis).
- Markers drawn by a tiny inline Chart.js plugin in `leaderboard_chart_controller.js` (no new npm dependency): dashed
  **Median** line, solid **You** line at the viewer's exact time; the viewer's bar is outlined in red (see the first-try split
  below). Labels point away from each other when the lines are close and never leave the chart. Tooltips name the bar's range
  ("00:45:00 – 00:50:00"), the count and its first-try split.
- Accessibility: the canvas is `role="img"` with an `aria-label` summary ("Times of 1718 puzzlers, median 00:53:25. Your time: …").
- Payload on London Postcard: leaderboard chart JSON 118,441 → 3,921 bytes; whole member page 1.12 MB → 0.50 MB.

**Table – "where am I" without loading everything.**
- Top 100 rows + "Show 100 more" / "Show all" stay as WS-C built them.
- When the viewer's row is further down, the table shows it **with two rows on either side** (`PuzzleTimes::NEIGHBOURS`) after a
  gap row: 1 … 100, **⋯ 497 more**, 598, 599, **600 (you)**, 601, 602. Rows are joined without a gap when the neighbourhood touches
  the top rows (e.g. you are 102nd), and a viewer inside the top 100 also sees the two rows below them. "Jump to me" always has a
  target. Like Duolingo's leagues: the people right around you are the ones you race.
- The gap row counts the rows it hides and is a button running the same "Show more" action as the button under the table – a
  row saying "497 more" invites a tap, so it must do something. After one tap it reads "⋯ 397 more"; once the top rows reach the
  neighbourhood it disappears.
- No separate "load rows around me" control: ±2 rows answer "who is right around me"; "Show more" / "Show all" remain for
  everything else.
- **Position line** in the viewer's own card, for every signed-in player (it only restates the public table):
  *"Rank 600 of 1718 · faster than 65% of puzzlers · 00:02:36 from the top 500"*.
  - Rank: the same tie rule as the table (equal time = rank of the row above); it used to be the position, which could disagree.
  - Percentage: share of the **other** rows that are strictly slower, rounded down; hidden below 10 rows, at 0 %, and for #1.
  - Next goal: the gap to the nearest of #1, 3, 10, 25, 50, 100, 250, 500, 1000, 2500, 5000 above the viewer
    ("… behind the fastest" for #2 and #3).
- Pairs / teams: the viewer's best pair or team row is "theirs" (position line, neighbourhood, chart marker); their other
  pairs stay highlighted wherever they are visible.

**HTML weight.** The row markup was ~75 % indentation. Twig's `~` modifier (trims spaces, never newlines) on every tag line of the
row loop and joining `<br>` with the element after it make the markup identical after whitespace normalisation (checked by
diffing both renders) and cut the anonymous page: Bavarian Romance 611 → 322 KB, London Postcard 963 → 441 KB (gzip 32 → 28 KB and
40 → 32 KB).

## What stays members-only

Unchanged: the chart itself (non-members see the locked placeholder and **no chart data is rendered for them at all** now –
before, their page still computed it). The distribution shows nothing from Puzzle Insights – no difficulty, no predictions, no
skill tiers – only the times already in the public table. The position line (rank, percentage, gap) is free, like the rank was.

## What feeds the chart

The chart gets exactly the rows the table would show if it were not capped - `PuzzleTimes::$times` after every filter:

- **Tab** (solo / pairs / teams): that tab's rows; a pair or team is one row.
- **First attempts only / unboxed only** (solo tab only - switching to pairs or teams turns them off): each player's row becomes
  their first (or unboxed) attempt, players without one drop out.
- **Country**: rows of that country; a pair or team counts when any member is from it.
- **Favourite players**: those players plus the viewer.
- **My pairs / teams** (pairs and teams tabs): only the rows the viewer took part in.
- **Private profiles**: a private player's row is left out for everyone who cannot see them (their allow list and they themselves
  see it); a pair or team is left out only when all its members are private. **Blocked players** are left out by the query.
- Relax-mode solves have no time and suspicious times are excluded, so neither is on the leaderboard nor in the chart.

The 50-row switch counts these filtered rows, so filtering a 1,700-row board down to 20 favourites shows 20 bars.
Covered by `PuzzleTimesDistributionChartTest::testEveryFilterFeedsTheChartAndTheSwitchCountsFilteredRows` (+ the pairs and
private-profile cases next to it).

## Decisions (Jan, 2026-09-30)

1. **Switch to the distribution above 50 rows** – kept.
2. **No toggle** between the two views – kept.
3. **±2 neighbours** – kept; the gap row now says how many rows it hides ("⋯ 497 more") and runs "Show more" when tapped.
4. **"faster than X %"** stays everywhere: "Top 97 %" would be ambiguous (faster than 97 %, or the slowest 3 %?).
5. **Median + You on the bar-per-row chart** – yes: horizontal dashed median, "You" above the viewer's bar, same summary.
6. **First attempts / repeats split in the distribution** – Jan asked to see it, then: "i love the first tries in histogram,
   that is great - ship the split". Shipped; see below.

## First tries / repeats in the distribution

Jan: the distribution "loses track of the first tries detailed info", and people use first tries a lot.

- Each bar stacks the rows whose shown time is a **1st try** (blue, the same colour as in the bar-per-row chart) under the
  **repeats** (red); the folded tails use lighter versions of both. `LeaderboardHistogramBuilder::build()` takes the first-try
  times as a third argument and counts them per bar (`LeaderboardHistogramBin::$firstAttempts`).
- The viewer's bar keeps the red "You" line and gets a **red outline** instead of the solid red fill – a solid fill would hide
  its own split (checked on the screenshots: the outline reads clearly on a phone too).
- A small legend under the chart ("1st try" – the site's existing word – / "Repeat"), only when the bars show both
  colours.
- One tooltip per bar: range + total + split, e.g. "00:45:00 – 00:50:00 · 211 puzzlers · 46 first tries · 165 repeats"; a part
  that would be 0 is left out.
- With the "1st tries only" filter every bar is a first try, so the chart is single-coloured and has no legend.
- What it shows on real boards: London Postcard's fast end is almost all repeats (people who trained on it), while a newer
  155-solver puzzle is mostly first tries.

## Tests

- `tests/Services/LeaderboardHistogramBuilderTest.php` – empty, one solver, identical times, nice widths and aligned starts,
  slow and fast outliers folded, even-count median, monster puzzles, randomised skewed boards (bar limit, no row lost),
  first tries counted per bar tails included.
- `tests/Component/Chart/PuzzleTimesChartTest.php` – 50 vs 51 rows, labels/ranges/markers/nouns, viewer bar, pairs; the
  bar-per-row chart's median line, "You" label, top padding and summary; the variant's split per bar, tooltip lines, lighter
  tails, outline instead of a red fill, stacked options, legend only when both colours are there.
- `tests/Component/PuzzleTimesDistributionChartTest.php` – members get the distribution; every filter (first attempts,
  unboxed, country, favourites, my pairs) feeds the chart and the switch counts filtered rows; hidden private profiles stay out;
  non-members get no chart data.
- `tests/Component/PuzzleTimesLeaderboardLimitTest.php` – neighbourhood far down / right below the top rows / inside the top
  rows, tied ranks in the neighbourhood, the position line (#1, small board, far down), the "⋯ N more" gap row (count, tap =
  "Show more", count after a tap, gone once the rows join) and its Czech plural forms.
