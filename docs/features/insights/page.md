# Insights - the page as built (Phase 6)

The player's own Insights page, built by stream **6a**. Phase 6b and 6c add parts, listed at the end. Decisions:
[`README.md`](./README.md). Plan: [`implementation-plan.md`](./implementation-plan.md) §7. Visual design of record:
[`prototype.html`](./prototype.html). Numbers: [`computation.md`](./computation.md).

## Routes and flag

| What | Where |
|---|---|
| Page | route `insights`, `GET /{_locale}/insights`, `InsightsController` - signed in (`IS_AUTHENTICATED_REMEMBERED`), always the viewer's own data (no player in the URL), `noindex, nofollow`, `Cache-Control: private, no-store` |
| Live component | `Insights` (`src/Component/Insights.php`, `templates/components/Insights.html.twig`, partials in `templates/insights/`) |
| Profile tab | `PlayerHeaderTab::Insights` after Statistics, owner-only (`isOwnerOnly()`), its link has no player (`routeParameters()`) |
| Menu row | user menu, after Statistics (`data-testid="menu-insights"`) |
| "See why" | the added-time recap (`recap-see-why`) and the result detail (`result-see-why`), own solo results only; they jump to `#insights-expected` for members and `#insights-median` for free players |
| Flag | `INSIGHTS_PUBLIC` (default `0`) → `InsightsFeature::isVisibleTo(?PlayerProfile)` = flag **or** `ADMIN_ACCESS`; Twig `insights_visible()`. While it is off, a non-admin gets a 404, no tab, no menu row, no "See why", and the Live endpoint renders nothing. See `docs/features/feature_flags.md` |

## How a render works

1. `Insights::load()`: the signed-in profile (the time zone and `time_predictions_opted_out` are only on the viewer's own
   row), then `InsightsFeature`.
2. The props are normalised through `InsightsCriteria::fromUserInput(props, isMember, hasTimezone)`. A free player's
   members-only values are dropped, and "Some filters of this link are for members" names them. The normalised values
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
| 2 | Findings + tiles | `findings`, `summary`, `consistency` | every finding but `surprises` (a lock with the count); speed + form tiles | + surprises, consistency tile |
| 3 | Puzzler profile (7 axes) | `profileCard` | yes (radar + bars) | yes |
| 4 | Form | `form` | yes - the criteria limit free players to 3 / 6 months | any period |
| 5 | Strengths and weak spots | `skillMap` | takeaway with the top strength + weak spot by name; lock: "N clear findings" | every row with its range; tap = drill-down (`toggleDrill`, the row's `drillDown` + one `GetInsightsPuzzles::byIds()` for puzzles the first response did not hydrate) |
| 6 | Expected vs. actual | `expectedVsActual`, `predictionAccuracy` | lock: "N puzzles went better than expected" | scatter, better / slower lists with "why" chips, your margin, the likely-window hit rate (not sure yet until 3b's calibrated model) |
| 7 | You against the median solver | `histogram` | the histogram + takeaway; lock: "N outliers found" | + the outliers both ways |
| 8 | Close calls | `closeCalls` | yes (places only, nobody named) | yes |
| 9-11 | rating + forecast, re-solve radar | - | 6b placeholders | 6b |
| 12 | Learning curve | `learningCurve` | lock: "your learning curve across N repeated puzzles" | chart you vs all solvers + puzzles improved most |
| 13 | Pairs & teams | - | 6b placeholder | 6b |
| 14 | Habits | `habits` | results per weekday (counts only); lock with the long-break count | + pace in the weekday tooltip, pace after a break, by the time you logged (with a time zone) |
| 15 | Improvement curve + rating over time | - | 6b placeholder | 6b |
| 16 | Make your insights sharper | `nudges` (`NudgesBuilder`) | yes | yes |

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
  - predictions off and the empty state;
  - canaries: a secret puzzle (`hide_until`) is gone and its results are not counted; a suspicious result is not counted;
  - "See why".
- `tests/Component/InsightsComponentTest.php`:
  - a free player's filters are stripped;
  - the `when` select;
  - the drill-down;
  - nothing renders while the flag is off.
- `tests/Controller/InsightsPageQueryBudgetTest.php`: the statement budget.
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

## Left for 6b

- The four placeholder partials, `templates/insights/_lazy_{rating,resolve,pairs,improvement}.html.twig`. Each one
  becomes a lazy Turbo Frame with `data-live-ignore`, so the component's re-renders do not reload it. Add the
  section's question chip in `Insights::getQuestions()`, and if it has a finding, its anchor in `_findings.html.twig`.
- The MSP rating tile, the "Try next" shelf pick, and the rating findings of the prototype's top row.
- Screenshots at 320 / 390 / 1200 px and the button-overflow audit in 6 languages; the §6.3 canaries for the lazy parts.
- The prototype's "Expected for you / Prediction as shown" switch was not built: the builder only exposes the
  calibrated view. Add it if 6b's review wants it (it needs the raw deviation per point from `ExpectedVsActualBuilder`).
