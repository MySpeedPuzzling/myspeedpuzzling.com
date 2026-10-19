# Insights - the page as built (Phase 6)

The player's own Insights page: the first response built by stream **6a**, the lazy frames below the fold by **6b**
("Lazy frames" below). The personal time estimates (6c) on the puzzle page, the lists, the Picker and the
marketplace: [`estimates.md`](./estimates.md). Decisions:
[`README.md`](./README.md). Plan: [`implementation-plan.md`](./implementation-plan.md) §7. Visual design of record:
[`prototype.html`](./prototype.html). Numbers: [`computation.md`](./computation.md).

## Routes and flag

| What | Where |
|---|---|
| Page | route `insights`, `GET /{_locale}/insights`, `InsightsController` - signed in (`IS_AUTHENTICATED_REMEMBERED`), always the viewer's own data (no player in the URL), `noindex, nofollow`, `Cache-Control: private, no-store`; the floating Compare launcher is hidden (`hide_comparison_launcher`) |
| Live component | `Insights` (`src/Component/Insights.php`, `templates/components/Insights.html.twig`, partials in `templates/insights/`) |
| Profile tab | `PlayerHeaderTab::Insights` after Statistics, owner-only (`isOwnerOnly()`), its link has no player (`routeParameters()`) |
| Menu row | user menu, after Statistics (`data-testid="menu-insights"`) |
| "See why" | the added-time recap (`recap-see-why`) and the result detail (`result-see-why`), own solo results only (`insights_see_why_url()`): to `#insights-expected` for members and `#insights-median` for free players once the player has `InsightsReport::MIN_RESULTS` results that count (`GetInsightsResultCount`, one short statement, only while Insights is visible), to the page itself (its empty state) before that |
| Flag | `INSIGHTS_PUBLIC` (default `0`) → `InsightsFeature::isVisibleTo(?PlayerProfile)` = flag **or** `ADMIN_ACCESS`; Twig `insights_visible()`. While it is off, a non-admin gets a 404, no tab, no menu row, no "See why", and the Live endpoint renders nothing. See `docs/features/feature_flags.md` |

## How a render works

1. `Insights::load()`: the signed-in profile (the time zone and `time_predictions_opted_out` are only on the viewer's own
   row), then `InsightsFeature`.
2. The props are normalised through `InsightsCriteria::fromUserInput(props, isMember, hasTimezone)`. A free player's
   members-only values are dropped, and "Some filters of this link are for members" says so - read from this
   request's criteria, never from the cached report (two links that strip to the same filters share one report). The normalised values
   go back into the props, so the URL always says what is shown.
3. `InsightsCache::keyFor(playerId)` costs **one statement**: the generation rides on the fingerprint
   (`GetInsightsFingerprint::withGeneration()`). The cached part is `page`, with context `criteria` =
   `InsightsCriteria::cacheKey($timezone)` (filters, membership, time zone) plus `predictions` (bool).
4. On a miss, `InsightsPageLoader::load($ownId, $criteria, $timezone, $predictionsAllowed)` runs four statements (solo
   results, close calls, context, hydrate). It is always called with the signed-in player's own id.
5. `InsightsChartsFactory` builds the charts from the report. It never computes anything new.

**The period select** is the `when` prop: a period value, or `year-2025` for a past year. It is not a prop hook. Live
re-sends `<select data-model>` values with every request, so a hook would run every time (the Comparison component's
gotcha). `onDateUpdated` only switches to the exact range when a date is typed.

**Charts** are Chart.js through `ChartBuilderInterface`. Each one sits in a `data-controller="comparison-chart"`
container, which reuses `comparison_chart_controller.js`: the server writes the tooltips and the tick labels. Each
chart container's id carries `stateKey` (a hash of the criteria), so a filter change rebuilds the chart instead of
morphing it. The skill map strips, the profile bars and the pace bars are plain HTML/CSS.

## Sections (prototype order)

| # | Section | Builder data | Free | Members |
|---|---|---|---|---|
| 1 | Question chips | `Insights::getQuestions()` | all; a lock icon on members' answers | all |
| 2 | Findings + tiles | `findings`, `summary`, `consistency`; the MSP rating tile from `InsightsReport::$rating500` / `$ratingPlace500` (the context statement); "Try next" + "MSP rating" cards = lazy frame `insights_top` | every finding but `surprises` (a lock with the count); speed + form tiles; "Try next" / "MSP rating" locked with counts | + surprises, consistency tile, MSP rating tile (rating + place on today's ladder), "Try next" (best shelf pick + likely window), "MSP rating" (what the best 5 moves add) |
| 3 | Puzzler profile (7 axes) | `profileCard` | yes (radar + bars) | yes |
| 4 | Form | `form` | yes - the criteria limit free players to 3 / 6 months | any period |
| 5 | Strengths and weak spots | `skillMap` | takeaway with the top strength + weak spot by name; lock: "N clear findings" | every row with its range; tap = drill-down (`toggleDrill`, the row's `drillDown` + one `GetInsightsPuzzles::byIds()` for puzzles the first response did not hydrate) |
| 6 | Expected vs. actual | `expectedVsActual`, `predictionAccuracy` | lock: "N puzzles went better than expected" | scatter, better / slower lists with "why" chips, "your margin", the hit rate of the likely window and of the 80 % range (calibrated predictions, model ≥ 3, from 10 each; a hit is judged against the window as shown - 3c's `PredictionWindow::rounded()`, so it agrees with the recap) |
| 7 | You against the median solver | `histogram` | the histogram + takeaway; lock: "N outliers found" | + the outliers both ways |
| 8 | Close calls | `closeCalls` | yes (places only, nobody named) | yes |
| 9-10 | Rating opportunities + forecast | lazy frame `insights_rating` (`RatingOpportunities` + `RatingForecast` from one `RatingModel`) | one lock each: "N moves found, worth up to +X points" / "N entries leave your rating within 2 months" | moves (most points / per hour), portfolio strips, pending puzzles, the path onto the ladder (near the ladder only), forecast chart + milestones |
| 10b | Try next from your shelf | lazy frame `insights_shelf` (`ShelfPicks`) | lock: "N unsolved puzzles on your shelf" | up to 12 picks: likely window (`_shelf_estimate.html.twig`, 6c replaces it), share of solvers beaten |
| 11 | Re-solve radar | lazy frame `insights_resolve` (`ResolveRadar`) | lock: "N old puzzles you would likely beat now" | scatter + best candidates + Puzzle Picker link |
| 12 | Learning curve | `learningCurve` | lock: "your learning curve across N repeated puzzles" | chart you vs all solvers + puzzles improved most |
| 13 | Pairs & teams | lazy frame `insights_pairs` (`PairsTeamsSynergy`) | takeaway with the count of results together; lock: "N pairs and teams analysed" | pairs and teams apart, synergy rows, together vs. alone, best results together |
| 14 | Habits | `habits` | results per weekday (counts only); lock with the long-break count | + pace in the weekday tooltip, pace after a break, by the time you logged (with a time zone) |
| 15 | Improvement curve + rating over time | lazy frame `insights_improvement` (`ImprovementCurve` + `RatingOverTime`) | lock: "N big jumps in your pace over M first tries" | pace curve (+ typical newcomer at 500), biggest jumps, plateau, other piece counts, MSP rating day by day |
| 16 | Make your insights sharper | `nudges` (`NudgesBuilder`) | yes | yes |

**Accessibility:** the findings are a labelled region; a locked question chip says "members only" to screen readers;
the weekday and histogram charts carry a visually hidden table with every bar's numbers (the weekday pace only for
members). List rows show the name in the viewer's language under the main title (`puzzle/_name.html.twig`).

**Free players never receive members-only content.** The builder computes everything for everybody, so the counts in
the locks are real. The templates render a members-only part only for members. The members-only charts (expected,
learning) are built only for members, and the weekday chart's tooltips carry the pace only for members. Each section
has one lock (`insights/_lock.html.twig`) with the free trial button (`FreeTrialSource::Insights`) or, for a player
whose trial is not open, the unlock date and the membership link. There are no other calls to action.

**Results thresholds** (`InsightsReport::MIN_RESULTS` = 10, `UNLOCK_THRESHOLDS` = 10 / 25 / 50):
- fewer than 10 solo results that count: the friendly empty state ("Insights start at 10 results") and the nudges;
- from 10: the page;
- the first nudge says what the next threshold unlocks: at 25 strengths and weak spots start to show (a split needs
  10 results of its own); at 50 the form and the profile card become steady.

**Predictions switched off** (`time_predictions_opted_out`): `InsightsBuilder::build(..., predictionsAllowed: false)`
leaves expected vs. actual, the prediction accuracy and the `surprises` finding empty, and sets
`InsightsReport::$predictionsOff`. The section says predictions are off and links to the setting. One nudge says the
same.

**Nudges** (`InsightsNudgeCode`), only the ones that apply:
- more results (with the next threshold);
- first results on a puzzle not marked as first tries;
- predictions off;
- no time zone;
- never marked a solve without the box (from 10 results on);
- puzzles without a difficulty yet.

**Filters:**
- free: when (3 / 6 months) and pieces;
- members: everything `InsightsCriteria` knows, in the sheet `#insights-filters-modal`;
- brands and past years come from the player's own results (`InsightsFilterOptions`);
- the time of day is offered only with a time zone;
- image traits are marked as a preview.

## Performance (measured 2026-10-19)

- **Statements of the first response:** 10 for every player size, pinned by `InsightsPageQueryBudgetTest`:
  - the insights themselves: 5 - cache key 1, solo results, close calls, context, hydrate;
  - the page: 5 - account, profile, unread conversations, unread notifications, the header's `GetPlayerProfile::byId()`.

  The test pool is an array, so every request in the test is the miss case. `InsightsQueryBudgetTest` still pins the
  loader at 4.
- **Server time:** test env in a one-off container on Jan's Mac, fixture member grown to 1,011 solo results, cache miss
  every time: 27-34 ms for the full request, `InsightsPageLoader::load()` 14-15 ms. Production-copy numbers for the
  heaviest players are in computation.md §11 (< 150 ms for all first-response parts).

## Tests

- `tests/Controller/InsightsControllerTest.php`:
  - the flag: off = 404, no tab, no menu row; admins always;
  - owner-only and guests;
  - every section for a free and a member player, in all 6 languages;
  - the free page has locks and no members-only markup;
  - the profile card from a seeded `player_insights_profile` row, radar data included;
  - predictions off and the empty state;
  - canaries: a secret puzzle (`hide_until`) is gone and its results are not counted; a suspicious result is not counted;
  - "See why".
- `tests/Component/InsightsComponentTest.php`:
  - a free player's filters are stripped;
  - the `when` select;
  - the drill-down;
  - nothing renders while the flag is off.
- `tests/Controller/InsightsPageQueryBudgetTest.php`: the statement budget.
- `tests/Services/Insights/PredictionAccuracyBuilderTest.php`: the 80 % hit rate (only predictions with a stored 80 %
  range, judged against the shown range, from 10 on).
- `tests/Services/Insights/InsightsBuilderTest.php`:
  - predictions off;
  - drill-down order;
  - filter options;
  - nudges.
- `tests/OnlinePluralMessagesTest.php` now also covers `insights.*`: every plural renders for every count in every
  language.
- Not in `BlocklistCanaryTest` / `PrivateProfileCanaryTest`: those canaries need a page that shows another player to a
  bystander. Insights is self-only and never names another player: close calls are places, and the 6b pairs section
  lists the player's own teammates through `GetInsightsGroupResults`, which already honours blocks and private profiles.

## Wording

Prediction windows follow stream 3c (`PredictionWindow`, `PredictionWindowFormatter`, `puzzle_intelligence.prediction.window.*`):
"likely window", "Your margin" for first tries, a hit judged against the shown window. The page shows aggregates
(hit rates, margins), no per-result window. Register matches `puzzle_intelligence.*`: Czech and French formal (vy /
vous), German du, Spanish tú; Czech says "puzzler", "predikce", "pravděpodobné rozmezí", "rezerva"; French says
"fourchette probable", "marge".

## Not built yet

- The prototype's "Expected for you / Prediction as shown" switch: the builder exposes only the calibrated view (it
  would need the raw deviation per point from `ExpectedVsActualBuilder`).
- The histogram's median-solver and "your usual" marker lines: the bars are coloured faster / slower than the median
  instead, and the legend says so (the takeaway names your usual).
- A sticky filter bar: it would collide with the player header's compact bar, so the filter row scrolls with the page.

## Lazy frames (6b)

Sections 9-11, 13 and 15 load after the first response, each part in its own Turbo Frame below the fold. The first
response is unchanged by them: the placeholders cost no statement (`InsightsPageQueryBudgetTest` still pins 10).

| Part (`InsightsLazyPart`) | Route | Controller | Sections | Filters it reads | Statements on a cache miss |
|---|---|---|---|---|---|
| `rating` | `insights_rating`, `GET /{_locale}/insights/rating` | `InsightsRatingFrameController` | 9 rating opportunities + pending + portfolio, 10 forecast | none (MSP rating = 500 pieces, its own 24-month window) | key 1 + model ≤ 8 (+ forecast 2, members) = ≤ 11 member / ≤ 9 free |
| `top` | `insights_top`, `GET /{_locale}/insights/top-findings` | `InsightsTopFrameController` | the top row's "Try next" + "MSP rating" finding cards (in the findings grid, eager) | pieces (the shelf pick) | key 1 + rating ≤ 10 + shelf ≤ 6 = ≤ 17 member (14 measured) / ≤ 10 free; predictions off = 0 and no frame |
| `shelf` | `insights_shelf`, `GET /{_locale}/insights/shelf` | `InsightsShelfFrameController` | "Try next from your shelf" (anchor `insights-try-next` - `insights-shelf` is the members' shelf filter) | pieces | key 1 + ≤ 6 = ≤ 7 member / 2 free; predictions off = 0 |
| `resolve` | `insights_resolve`, `GET /{_locale}/insights/re-solve` | `InsightsResolveFrameController` | 11 re-solve radar | pieces | key 1 + ≤ 4 = ≤ 5; predictions off = 0 |
| `pairs` | `insights_pairs`, `GET /{_locale}/insights/pairs-and-teams` | `InsightsPairsFrameController` | 13 pairs & teams | period, pieces | key 1 + ≤ 4 = ≤ 5 |
| `improvement` | `insights_improvement`, `GET /{_locale}/insights/improvement` | `InsightsImprovementFrameController` | 15 improvement curve + rating over time | period (rating over time), pieces (which curves) | key 1 + 1 (+ rating over time 1, members) = ≤ 3 |

Pinned by `tests/Services/Insights/InsightsFramesBudgetTest.php` on the insights ladder fixture (also < 100 ms per part,
and a cache hit = the key only). Measured 2026-10-19 in the test env (one-off container on Jan's Mac, cache miss):
- `InsightsFrames` on the ladder fixture (best of 3): rating member 11 statements / 10.3 ms, rating free 9 / 7.5 ms,
  re-solve 5 / 3.2 ms, pairs 5 / 2.2 ms, improvement member 3 / 2.6 ms, improvement free 2 / 2.8 ms;
- the whole frame request (kernel boot, security, profile, render) for a fixture player on the ladder: rating 48 ms
  (member) / 78 ms (free, first request of the kernel), re-solve 29-36 ms, pairs 27-41 ms, improvement 25-46 ms.

**How a frame works:**
- The placeholder (`templates/insights/frames/_lazy.html.twig`, included by the four `_lazy_*` partials) is
  `<turbo-frame loading="lazy" target="_top" data-live-ignore>` with the sections' heads and anchors (the question
  chips jump there before it loads). Twig `insights_frame(part, criteria)` gives its id and src.
- **The frame id is keyed by the filters the part reads** (`InsightsLazyPart::frameId()`). The live component leaves a
  frame with the same id alone (`data-live-ignore`); when a filter the part reads changes, the id changes, Live puts
  the placeholder back with the new `src` and Turbo loads it again. A filter the part ignores never reloads it.
- **The top row** needs no rating model in the first response: the MSP rating tile reads the stored 500-piece rating
  and its place on today's ladder from the context statement (`GetInsightsContext`, the header's
  `GetPlayerRatingRanking::sqlRankOf()` as one more column - the first response stays 10 statements). The "Try next"
  and "MSP rating" finding cards need the shelf picks and the rating model, so they are the `top` frame: inside the
  findings grid (`display: contents`, so it loads eagerly - a lazy frame without a box never comes into view), two
  skeleton cards while it loads, built from the cache entries the `shelf` and `rating` frames share (whichever loads
  first builds them; afterwards each costs only the key). Opted out of rankings: the tile shows the rating and says
  neutrally that there is no place; private: no rating.
- `InsightsFrameRequest::context()` applies the page's rules: signed in, `InsightsFeature` (404 while hidden), the
  signed-in player's own id only (the URL carries no player), membership with trials counted
  (`PlayerProfile::$activeMembership`), `time_predictions_opted_out`, the time zone for the criteria, and the page's
  filters from the URL - only the part's keys, a free player's members-only values dropped (`InsightsCriteria`).
- A frame URL opened without the `Turbo-Frame` header (a new tab) redirects to its section of the page.
- `InsightsFrames` builds each part through `InsightsCache` (parts `frame_rating`, `frame_resolve`, `frame_pairs`,
  `frame_improvement`; the context = membership + the part's filters (+ the day for period-relative parts)). A free
  player's part is built only for the counts of its lock: no forecast, no rating over time.
- Charts (`InsightsFramesChartsFactory`, members only): forecast lines, the re-solve scatter, the pace curve, the
  rating over time - Chart.js through `ChartBuilderInterface` and `comparison_chart_controller.js`, like the first
  response. The portfolio strips, the synergy rows and the together-vs-alone bars are HTML/SVG. Dates come from ICU
  skeletons (`EventsPageDates`).
- Styles: `assets/styles/_insights-frames.scss` (after `_insights.scss`).

**Prediction windows** follow stream 3c: a move's, a re-solve candidate's or a shelf pick's expected time is shown only
as the likely window (`RatingMove::window()` / `ResolveCandidate::window()` / `ShelfPick::window()` = `PredictionWindow::rounded()`, Twig
`prediction_window_range`, wording `puzzle_intelligence.prediction.window.likely`), never as a point time.

**Rules carried into the frames:**
- Every ladder place is a place **on today's ladder** and says so (other players' ratings move too).
- Players opted out of rankings (`RatingStanding::OptedOut`) get their rating and its moves but no place, no path
  onto the ladder and no ladder milestone; the takeaway says it neutrally ("You chose not to appear in rankings").
  Private players have no MSP rating (rankings are public-only): the section says so.
- The path onto the ladder shows only while it is within reach (`RatingStanding::NearLadder`, ≤ 10 new results).
- **Time predictions switched off:** no moves (a move is worth what its expected time gives), no "best moves" forecast
  line, no re-solve radar and no shelf picks (they are nothing but predictions), no "Try next" / "MSP rating" cards - each says so and links to the setting. The rating, its
  entries, pending puzzles and the pace milestones stay (they are no predictions).
- Pairs and teams are separate lists and never mix into solo. A private teammate who has not let the viewer see them
  is "Hidden Puzzler" (no player id reaches the page); a guest is shown by name, without a score (no solo pace); a
  pair/team with a blocked member is left out (`PairsTeamsSynergy`).
- Secret puzzles (`hide_until` / `hide_image_until`) are never named: the builders leave them out of every suggestion;
  an entry or a jump on one keeps its number without the puzzle ("A puzzle kept secret for now").
- Free players get one lock per section with real counts and the free-trial button - no moves, strips, charts, times,
  names of partners or puzzles.

**Tests:**
- `tests/Controller/InsightsFramesControllerTest.php` (the fixture players join the insights ladder):
  - flag off = 404 for every frame; guests to sign-in; a frame opened on its own redirects to its section;
  - the page's frame ids and srcs are the ones that answer;
  - every frame for a member and a free player; the free HTML carries locks with counts and no members-only markup;
  - predictions switched off; opted out of rankings; only the viewer's own data;
  - canaries: secret puzzles, a suspicious result, a hidden private teammate.
  - the top row: "Try next" + "MSP rating" cards (members), locked with counts (free), absent with predictions off; the
    rating tile in the first response (locked for free players).
- `tests/Services/Insights/InsightsFramesBudgetTest.php`: statements and time per part; the top row shares the rating
  and shelf cache entries; the rating tile's place rides on the context statement (none while opted out or private).

## Phone layout notes

- The strips' axis (`.ins-saxis`, skill map and pairs) shows from md up only: in a phone's narrow strip column its
  labels were cut off (6a) or overlapped (pairs), and every row's tag says faster or slower in words. The pairs axis
  wraps its two labels in the narrower half of `.ins-two`.
- The question chips' row scrolls sideways on a phone; it is `position: relative`, so the locked chips'
  visually-hidden labels stay inside it instead of widening the page.
- Checked 2026-10-19: no text overflow and no sideways page scroll in 6 locales × member / free × 320, 375, 390, 414,
  768, 992, 1200 px (buttons, labels, tags, chips, the skill-map and pairs axes, every lazy frame).
