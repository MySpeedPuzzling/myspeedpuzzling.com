# Puzzle leaderboard: chart and "where am I"

Status: built 2026-09-30 (WS-C2 of the autumn SEO plan, follow-up to the capped leaderboard of WS-C); Jan's decisions of the same
day applied - see "Decisions" below.
Code: `src/Component/Chart/PuzzleTimesChart.php`, `src/Services/LeaderboardHistogramBuilder.php`,
`assets/controllers/leaderboard_chart_controller.js`, `src/Component/PuzzleTimes.php`,
`templates/components/PuzzleTimes.html.twig`, `templates/components/Chart/PuzzleTimesChart.html.twig`.

## Update 2026-10-02: numbers strip and the puzzle header

Jan's redesign of the puzzle page (mobile first, designs on a claude.ai canvas):

- Right under the Solo / Pair / Team tabs a strip of three numbers **for the selected tab**: Fastest · Median · Your best
  (with "#rank of total", a tap jumps to the viewer's row; with any pair or team in Pair/Team). Without a time of one's own
  the third number is the Average. It lives in the Live component, so a tab switch re-renders it with the list and chart.
- The old "my attempts" card became one slim line under the strip: latest time (or "My time") with its edit button, then
  "faster than N %" and the gap to the next milestone - parts that do not fit move to the next line whole, the "·" shows only
  between parts on one line (`.lb-parts`) - then "All my times (N)" opening the history and the personal chart as before.
  The rank moved into the strip (`data-testid="my-rank"`), "Jump to me" into the strip's link.
- The page header (outside the component): the box in its own proportions (≤ 112 px square on phones, 160 px tablets,
  340 px wide on desktop - never cropped), name, "500 pieces · Brand" (the piece count stays in the H1), difficulty and
  badges, then Add time · Stopwatch · ⋯ (every action - wishlist, collections, sell/swap, lend, offers, QR, suggest a
  change), then Insights / Details toggles. No breadcrumb below 576 px (the meta line links to the same pages).
- Once those buttons scroll away a compact bar (thumbnail, name ≤ 2 lines, pcs · brand, ⋯ with the same menu) slides in
  under the site header (`compact_bar_controller.js`, shared with the player pages since #224; `inert` while hidden).
  The menu is on the page twice, so `puzzle/_dropdown_actions.html.twig` carries a class instead of an id and its
  stream replaces every copy (`targets`).

## Chart switch: distribution or rankings (built 2026-10-02)

Replaces decision 2 ("no toggle") and the 50-row switch: some members preferred the bar per row.
Code: `Value\LeaderboardChartView`, `ChangeLeaderboardChartView` + handler, `PuzzleTimes::changeChartView()`,
`PuzzleTimesChart::$view`, `templates/components/Chart/PuzzleTimesChart.html.twig`, `LeaderboardHistogramBuilder::maxBins()`.

### What the player gets

- **From 20 rows a member chooses:** the distribution is the default, and a switch turns it into the **rankings**, the
  old chart with one bar per row, fastest first. `PuzzleTimesChart::SWITCH_MIN_ROWS` = 20 **filtered** rows.
- **Below 20 rows it is always the rankings, without a switch.** A distribution of a handful of puzzlers has no shape
  (Jan, 2026-10-02, after the first version showed it at every size). Measured on every solo board with `maxBins()`:
  10–14 rows give ~7 bars, the tallest with 4 puzzlers and half of them holding a single one; 20–24 rows give ~8 bars,
  the tallest with 6 and 60 % with two or more. 2,087 of 15,367 solo boards have 20 rows or more. A filter decides too:
  a dozen favourite players are shown by name. The stored choice stays untouched for the next long board.
  The old 50-row threshold (`INDIVIDUAL_BARS_MAX`) is gone.
- **The switch lies on the chart's top right corner,** `top: 0; right: 20px`, over the plot (Jan, 2026-10-02: no row of
  its own, saves space). It covers the plot's 18 px top padding and a few pixels below it. In the distribution that is
  the slow tail's empty space; in the rankings the slowest bars stay visible to its right, and the buttons are
  translucent. Checked on screenshots of London Postcard's 1,722 times at 375 px and 1,280 px.
  - The shown chart's button is filled (`.lb-chart-switch` in `_leaderboard.scss`).
  - The rankings' zoom-reset button (`.zoom-reset`, top right on every other chart) moves left of the switch and takes
    its height (`$lb-chart-switch-width`, measured 63–67 px).
  - Icons: Symfony UX Icons, imported into `assets/icons/` (`ux:icons:import`), so nothing is fetched from Iconify at
    runtime: `clarity:bell-curve-line` for Distribution, `bi:bar-chart` for Rankings (Jan's pick, 2026-10-02, out of four
    pairs). They are picked in one place, the `view_icons` map at the top of the members' branch of the template.
- **Who sees it:** members, on boards of 20 or more rows. Non-members keep the locked placeholder and no chart data.
- **Remembered:** the choice is stored on the player and applies to every puzzle page, every tab and every device. The
  default is `distribution`, so boards with 51 or more rows looked as before until the player tapped.
- **Behaviour change:** boards with 20–50 rows open on the distribution instead of the bar per row.

### Small boards: fewer, wider bars

The distribution was tuned for boards with more than 50 rows: up to `MAX_BINS` = 30 bars, with the narrowest nice width
that fits. On small boards that gave about 20 slots for a handful of people, so the bar limit now grows with the board:
`maxBins(rows) = min(30, max(5, 2 × ⌈√rows⌉))`. Bar widths, aligned starts and outlier folding are unchanged. Measured
on the dev copy of prod (solo, best time per player; medians per bucket; "empty" = bars with nobody in them):

| Rows | Boards | Before: bars / empty / tallest | Now: bars / empty / tallest |
|---|---|---|---|
| 2–5 | 9,801 | 21 / 88 % / 1 | 4 / 40 % / 1 |
| 6–10 | 2,243 | 21 / 69 % / 2 | 5 / 17 % / 3 |
| 11–20 | 1,321 | 21 / 50 % / 3 | 7 / 14 % / 5 |
| 21–35 | 735 | 21 / 33 % / 4 | 9 / 10 % / 7 |
| 36–50 | 292 | 20 / 23 % / 7 | 11 / 9 % / 11 |
| 51–100 | 468 | 21 / 17 % / 10 | 13 / 8 % / 14 |
| 101–300 | 358 | 21 / 8 % / 24 | 19 / 6 % / 26 |
| 301+ | 149 | 25 / 0 % / 72 | 25 / 0 % / 72 (unchanged) |

- **One formula, no threshold.** It also makes boards of 51–100 rows a bit coarser (21 → 13 bars, often 10-minute
  instead of 5-minute bars, fewer gaps). Boards of more than ~200 rows barely change.
- Rejected:
  - `min(30, rows)`: 11–35-row boards still get 27–30 % empty bars.
  - `3 × ⌈∛rows⌉`: it also coarsens the big boards (301+ → 20 bars).

### Storing the choice without an extra query

- Column `player.leaderboard_chart_view` (string `enumType`, NOT NULL, default `'distribution'`), written by
  `Player::changeLeaderboardChartView()` through `ChangeLeaderboardChartView` and its handler.
  - The migration (`Version20261002174420`) was generated against a scratch DB built from the committed migrations. It is
    a single `ADD COLUMN … DEFAULT 'distribution' NOT NULL`, which is metadata-only in Postgres 11+ and safe for blue-green.
- **Read side:**
  - only `GetPlayerProfile::byUserId()` selects the column, as the optional `leaderboard_chart_view` key of
    `PlayerProfileRow`;
  - `PlayerProfile::$leaderboardChartView` uses `tryFrom()` and falls back to Distribution;
  - `byId()`, somebody else's profile, does not read the column, so it always says Distribution.

  `RetrieveLoggedUserProfile` already loads that row once per request, so the page and every re-render get the choice
  with **no new query**. `PuzzleTimesChartViewTest::testTheStoredChoiceCostsNoQuery` pins it: one statement mentions the
  column, and the ranking page runs as many queries as the distribution page.

### Live component

- `PuzzleTimes::$chartView`, a `LiveProp` holding the enum, is set in a `#[PostMount]` hook from the profile
  (distribution when there is no profile). Only the action changes it; the browser cannot write it.
  - Why a LiveProp and not "read the profile on every render": `RetrieveLoggedUserProfile` can load and cache the profile
    before the action runs in the same request. That render would then still see the old value.
- `#[LiveAction] changeChartView(#[LiveArg] string $view)`:
  - an unknown value changes nothing;
  - otherwise it sets the prop and, for a signed-in player whose stored choice differs, dispatches
    `ChangeLeaderboardChartView`;
  - it does **not** reset `$limit`, so the table stays as it is;
  - it does not require membership: a lapsed member keeps their choice, and non-members never see the switch.
- `PuzzleTimesChart::$view` decides `isDistribution()`. The buttons use `data-action="live#action"`
  (`data-live-view-param`), like the tab buttons. The chart is a plain Twig component inside the Live one, so the action
  reaches `PuzzleTimes`.
- **Icon only, still words:** each button has `aria-label` + `title`, and the group has `aria-label`. That is three keys,
  `puzzle_times.chart.view.{label,distribution,ranking}` ("Chart view", "Distribution", "Rankings"), in all 6 locales.
  `aria-pressed` marks the shown chart.

### Swapping one chart for the other

The ux-chartjs controller's `viewValueChanged()` keeps the Chart.js instance and only swaps `data` and `options`. The
plugins handed over in `chartjs:pre-connect` stay. That is right while the view stays the same (filters, tabs, "Show more"),
but the two views are different charts:

| | Distribution | Rankings (bar per row) |
|---|---|---|
| Datasets | 2, stacked | 1 |
| x axis | time ranges | rows, ticks hidden |
| y axis | count | time (h:mm:ss, set by `time-chart`) |
| Controllers on the wrapper | `leaderboard-chart` | `time-chart leaderboard-chart` |
| Extras | legend under it, tooltips from `ranges` | drag/pinch zoom + reset button |

Before this change, the same morph ran whenever a filter crossed 50 rows. It was found by reading the code and was never
reproduced in a browser:

- `time_chart_controller.js` `disconnect()` removed `this._onPreConnect.bind(this)`. That is a new function, so nothing
  was removed. After bars → distribution, the listener stayed on the reused wrapper. On every later re-render it rewrote
  the distribution's options: `scales.y` (losing `stacked` and the title), the tooltip callbacks, and zoom.
- Distribution → bars only worked because idiomorph syncs the wrapper's attributes before it morphs the canvas.

So **one chart is never morphed into the other; it is rebuilt:**

- The controller `<div>` sits in a container keyed by the view: `<div id="leaderboard-chart-{distribution|ranking}">`.
  When an element's `id` differs, Live Component's morph (`beforeElUpdated` in `live_controller.js`) replaces its
  `innerHTML` instead of morphing it. The wrapper and the canvas then arrive as new nodes in one mutation, and Stimulus
  connects them in tree order:
  1. the eager `time-chart` and `leaderboard-chart` controllers on the wrapper;
  2. the Chart.js controller on the canvas, which fires `chartjs:pre-connect` into both.

  The old canvas disconnects and runs `chart.destroy()`.
- The id is on the outer container, not on the controller `<div>`. With the id there, only that div's children would be
  replaced, and its `data-controller` would be morphed afterwards, so the canvas would connect before `time-chart`.
- The same view (filters, tabs, "Show more") keeps the same id, so the chart is updated in place, as before.
- `time_chart_controller.js` now binds its listeners once in `connect()`, as `leaderboard_chart_controller.js` does.
- Both views are 200 px high (the bar per row was 180 px), so the table does not jump on a switch.

### Performance

- **Page load:** no new query; the switch and its two inline icons add well under 1 KB of markup (members only).
- **Distribution (default):** small boards get fewer bars, so their chart JSON shrinks.
- **Rankings on a big board:** back to the weight before 2026-09-30, 118 KB of chart JSON on London Postcard (1,718 bars).
  It is re-sent with every re-render of the component. This is opt-in.
  - A cheap trim if it matters: the per-bar colour strings are about a third of the payload. A `firstAttempt` flag array
    mapped to colours in JS would cut about 40 KB. Not done; measure first.
- **Switching:** one Live re-render plus one `UPDATE player`, the cost of changing a filter. It happens about once per
  member.
- Rejected alternatives:
  - **Both charts in the page, switched in the browser.** Every member would pay for the ranking's data.
  - **A separate JSON endpoint.** It would duplicate the filter pipeline in `PuzzleTimes::populate()`.
  - **localStorage.** The server must render the right chart on first paint, and localStorage would not follow the player
    across devices.

### Tests

- `LeaderboardHistogramBuilderTest`: `maxBins()` per size, a handful of puzzlers gets a few wide bars, and the skewed
  boards stay within `maxBins()`.
- `PuzzleTimesChartTest`: the distribution by default for 3 and 500 rows, a bar per row whenever `view` is Ranking.
- `PuzzleTimesDistributionChartTest`: every filter feeds the distribution; the hidden-player case runs on the ranking.
- `PuzzleTimesChartViewTest`:
  - the default is pressed;
  - switching rebuilds into `#leaderboard-chart-ranking`, stores the choice, the next page opens on it, and switching
    back works;
  - the table's rows are untouched;
  - small boards have the switch too;
  - an unknown value changes nothing;
  - non-members and guests get no switch, and a guest's call stores nothing;
  - the query parity described above.
- `ChangeLeaderboardChartViewHandlerTest`: stored and read back through `byUserId()`; `byId()` and an unknown stored value
  read as Distribution; an unknown player throws.

### Decisions (Jan, 2026-10-02)

1. **Icons only.** They still carry `aria-label` + `title`.
2. **No 50-row distinction:** the distribution is the default, and the switch leads to the old ranking, named
   "Rankings". The bar limit by row count was approved with it. *Refined the same day, see 6.*
3. **All locales** for the three accessible-name texts.
4. **Icons:** pair C, `clarity:bell-curve-line` / `bi:bar-chart`, out of four pairs compared on
   https://claude.ai/artifact/JwZzJfXe3DfhdzQmwf7MLk. Another pair swaps in through `view_icons`.
5. **The switch lies on the chart,** top right, 20 px from the right edge, instead of a row above it.
6. **Below 20 rows always the rankings, without a switch.** "The distribution chart is weird with not enough records";
   20 was Jan's suggestion, and the measurements above back it.

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

**Chart – pick the right view by size, no toggle.** *(Superseded 2026-10-02: from 20 rows the distribution is the default
and members switch to the bar per row themselves, below 20 rows always the bar per row - see "Chart switch" above. Kept for
the reasoning of 2026-09-30.)*
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
- Bar width = the narrowest of 10 s, 15 s, 30 s, 1, 2, 5, 10, 15, 30 min, 1, 2, 4 h that keeps the chart at ≤ 30 bars (since
  2026-10-02 at ≤ `maxBins(rows)`, fewer on small boards - see "Small boards" above); bars start
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
- **That controller loads eagerly, never lazily.** It hands the plugin to Chart.js in `chartjs:pre-connect`, which the lazy
  Chart.js controller fires once, when it builds the chart. As a lazy chunk it lost that race on some page loads (typically the
  first puzzle page after a deploy) and the chart came without Median, You and outline - reported 2026-09-30.
  `tests/StimulusControllerLoadingTest.php` keeps every `chartjs:pre-connect` listener eager. No comment in a controller may
  even mention the stimulus-bridge directive: its loader evaluates any comment that does, and the build fails.
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

Filtering a 1,700-row board down to 20 favourites shows the distribution of those 20 (or 20 bars on the ranking).
Covered by `PuzzleTimesDistributionChartTest::testEveryFilterFeedsTheChart` (+ the pairs and private-profile cases next to
it).

## Decisions (Jan, 2026-09-30)

1. **Switch to the distribution above 50 rows** – kept. *Replaced 2026-10-02: from 20 rows, by the member's choice.*
2. **No toggle** between the two views – kept. *Replaced 2026-10-02: a remembered switch, see "Chart switch".*
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
  its own split. The outline is a 1 px hairline in a lighter red than the You line (Jan, 2026-09-30), so it marks the bar
  without competing with the line.
- A small legend under the chart ("1st try" – the site's existing word – / "Repeat"), only when the bars show both
  colours.
- One tooltip per bar: range + total + split, e.g. "00:45:00 – 00:50:00 · 211 puzzlers · 46 first tries · 165 repeats"; a part
  that would be 0 is left out.
- With the "1st tries only" filter every bar is a first try, so the chart is single-coloured and has no legend.
- What it shows on real boards: London Postcard's fast end is almost all repeats (people who trained on it), while a newer
  155-solver puzzle is mostly first tries.

## Row layout (2026-10-01)

One row = rank · player · time, and **the table never scrolls sideways** (320 px included):

- Rank and time cells take their content's width (`width: 1%; white-space: nowrap`), the player cell takes the rest
  (`width: 100%; max-width: 0`) and names truncate with an ellipsis. No per-width media queries needed; the
  measured worst case (40-char name, rank 5667, time 181:18:00) fits at 320 px.
- Every cell is top-aligned on the 28 px avatar line, so rank (black, monospace, no dot), name and time share one line.
- Player = avatar (photo, else the initial on a tint picked by the last hex digit of the player id; incognito
  for a private player) with the **country flag on the avatar's corner** (costs the name no width), skill tier
  icon, name - `_leaderboard_player.html.twig`. Pair/team members stack, one 28 px line each.
- Under the name (`_leaderboard_time_badges.html.twig`): pair/team page pill, "Solved N×" pill (from 2 attempts),
  1st try, unboxed, event - truncated, wrapping only when needed. Date and PPM are not in the row any more.
- Time column: time + its **gap to the fastest time** (①, `bi-1-circle` - a trophy read as "competition", user feedback 2026-10-02; `PuzzleTimes::$leaderTime`) and **to the closest faster
  time** (↑, `PuzzleTimes::$gapsToFaster`, only when that is not the fastest one - rank 2 shows one gap); `gapTime`
  filter: `+00:07`, `+12:05`, `+01:02:05`.
- **A row opens the result detail** (date, PPM, every attempt with its deltas, the members' chart) in the global
  modal, loaded on demand - the time is the row's real link (`puzzle_result_detail`, `data-turbo-frame="modal-frame"`),
  a tap anywhere else on the row clicks it (`row_link_controller.js`). Nothing of it is in the page HTML.
  See `docs/features/puzzle-result-detail.md`.
- Below 380 px: 1 px smaller names and times.
- **The same row everywhere a ranking lists players** (shared `_leaderboard_player.html.twig`, `_leaderboard_time_badges`,
  `.ps-*` / `.lb-*` in `_leaderboard.scss`): the MSP rating ladder (`MspRatingLadder`), the ladders `/en/ladder` and
  `/en/ladder/{solo|pairs|groups}/{pieces}` (`LadderTable`: player first, puzzle as a line under it, the image hidden
  below 380 px) and the player profile results (`_player_solvings`: image · puzzle name, brand · pieces, members,
  rank chip + badges · time). Rows that are a time open the result detail; none of them scroll sideways at 320 px.

## Tests

- `tests/Services/LeaderboardHistogramBuilderTest.php` – empty, one solver, identical times, nice widths and aligned starts,
  slow and fast outliers folded, even-count median, monster puzzles, randomised skewed boards (bar limit, no row lost),
  first tries counted per bar tails included, the bar limit by board size.
- `tests/Component/Chart/PuzzleTimesChartTest.php` – distribution by default / ranking on request at any size,
  labels/ranges/markers/nouns, viewer bar, pairs; the
  bar-per-row chart's median line, "You" label, top padding and summary; the variant's split per bar, tooltip lines, lighter
  tails, outline instead of a red fill, stacked options, legend only when both colours are there.
- `tests/Component/PuzzleTimesDistributionChartTest.php` – members get the distribution; every filter (first attempts,
  unboxed, country, favourites, my pairs) feeds the chart; hidden private profiles stay out; non-members get no chart data.
- `tests/Component/PuzzleTimesChartViewTest.php` – the switch: default, rebuild + remembered choice, table untouched, small
  boards, unknown value, non-members and guests, no query of its own.
- `tests/MessageHandler/ChangeLeaderboardChartViewHandlerTest.php` – stored and read through the viewer's profile only.
- `tests/Component/PuzzleTimesLeaderboardLimitTest.php` – neighbourhood far down / right below the top rows / inside the top
  rows, tied ranks in the neighbourhood, the position line (#1, small board, far down), the "⋯ N more" gap row (count, tap =
  "Show more", count after a tap, gone once the rows join) and its Czech plural forms.
