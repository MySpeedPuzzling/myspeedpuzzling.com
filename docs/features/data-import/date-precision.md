# Results that know only their month or year (stage 6c)

Spreadsheets often know only the month or the year of a solve - "March 2021", "2019". README D13 decided that **the
precision is stored**: such a result is added as what the file knows, and every place that shows or uses the date of
a result is honest about it. Before this stage the import refused these rows (`needs_exact_day`).

Code: `src/Value/DatePrecision.php` (the enum and the SQL fragments), `src/Services/ResultDateFormatter.php` +
`src/Twig/ResultDateTwigExtension.php` (showing), `PuzzleSolvingTime::$finishedAtPrecision`. Tests:
`tests/DatePrecision/`, `tests/DatePrecisionCoverageTest.php`, `tests/MessageHandler/DataImport/ImportDatePrecisionTest.php`.

## The column

`puzzle_solving_time.finished_at_precision` - `VARCHAR(8)`, nullable, no default.

| Value | Meaning |
|---|---|
| `NULL` | the day is known - **every result typed into a form or sent through the API, and every row older than the column** |
| `month` | only the month is known |
| `year` | only the year is known |

`day` is never stored (`DatePrecision::ofStored(null)` is `Day`). Invariant: a precision is set only together with a
`finished_at` (`PuzzleSolvingTime::solveDayKnownOnlyTo()` refuses anything else), so SQL may read the column without
asking whether `finished_at` is there.

**The migration** (`Version20261115083249`, generated):

```sql
ALTER TABLE puzzle_solving_time ADD finished_at_precision VARCHAR(8) DEFAULT NULL
```

A nullable column without a (non-null) default is added by Postgres as a change of the catalogue only: no rewrite of
the table, no scan, no index. It takes the table's `ACCESS EXCLUSIVE` lock for the moment of that change - as every
`ALTER TABLE` does - and holds it for nothing. NULL = day was chosen over a `DEFAULT 'day'` for that reason and because
"is the day real?" is then `finished_at_precision IS NULL` everywhere. `DatePrecisionCoverageTest` pins the statement.

Written by: `ImportedResultWriter` (`ImportedResult::$precision` → `solveDayKnownOnlyTo()`), `PuzzleSolvingTime::restore()`
(an Undo of an automatic removal - `RemovedResultSnapshot` carries `finished_at_precision`, so do the graveyard and the
tombstones' snapshot), and `PuzzleSolvingTime::modify()` (below). Nothing else. **The add-result form is not touched:**
its constructor call is what it was and yields a day (`ImportWritePathParityTest` - the column is one of the documented
differences, with a test of its own).

## The anchor

A result known to its month or year is stored on **the first day of that period**: `finished_at` = that day at
midnight, `tracked_at` = noon of that day in the player's zone plus the row's steps (the import's rule for every
result, writer.md). `DatePrecision::anchorOf()`.

The first day and not the middle: `parsed.date` and the source keys already write a period as its start (`2021-03`,
`2021`), a period's start is the earliest moment the solve can have happened (so nothing that orders results by time
reads data from *after* the solve), and a month's own first day is inside that month for every "this month" window.

**That day is invented.** Nothing may read it as a day. A reader asks `finished_at_precision IS NULL`
(`DatePrecision::sqlExactDay()`), `PuzzleSolvingTime::hasExactDay()`, or a result object's `datePrecision`.

## Never twice (the import)

`parsed.date` is written at its precision (`2021-03-15`, `2021-03`, `2021`) and so is the result's source key - **keys
did not change**: an exact day is keyed as before, a month as its month, a year as its year (`ImportSourceKey::dateAt()`,
frozen since stage 4a). The same month row in another file is "already yours" by its key (`earlier_import`).

Against what the player has (`ImportNeverTwice`), a date is compared **within its period, never by the anchor**:

| Row | Result on the profile | |
|---|---|---|
| `Mar 2021 · 1:02:03` | `14 Mar 2021 · 1:02:03` | The same result - "already yours" (the result lies in the row's period and knows at least as much) |
| `14 Mar 2021 · 1:02:03` | a result that knows only "March 2021" | Not equal - an exact day is never the result that merely stands on 1 March. If that result is free (typed, or of a puzzle no row speaks for): asked (`similar_result_exists`) |
| `1 Mar 2021 · 1:02:03` | a result that knows only "March 2021" | The same: never "already yours" by the stored first day |
| `Mar 2021 · 1:02:03` | `14 Apr 2021 · 1:02:03` | The same time in another period - asked, never added quietly |
| `Mar 2021 · 1:02:03` | `14 Mar 2021 · 1:10:00` | Unrelated - "another time in the same month" says nothing (a puzzle is solved again and again); added |
| `2021`, no time | any result of the puzzle | "Already yours" (README step 2, the last pile) - one of that year and kind first |
| `Sept 2022 · x` and `14 Sep 2022 · x` in one file | - | One result, the finer one, whatever the rows' order (`ImportRowAdder::finerQueuedRows()`); the month row is `equal_row` |

- **The exact ones first** (README step 2): only rows with an exact day are settled in the batch's first pass; a month
  or year row is compared when it is added, after every exact row found its result.
- "Somebody else's result" (`somebodyElseHasIt()`) compares within the coarser of the two precisions.
- 5a's own-result hint (`GetImportRowsOnProfile`) still asks only rows with an exact day (pairing.md) - and now only of
  results with an exact day: a row of 1 March never hits a result that only knows "March".
- `GetImportAddScreen::resultsBeside()` builds the row's day by `DataImportRowRepository::sqlRowAnchorDay()` (a `CASE` -
  nothing is cast before its shape is known) and applies the same rules; it returns each result's `precision`.
- **Ready:** a row with a result is ready when it has a date - a day, a month or a year. `needs_exact_day` remains for a
  result **without any date** (the player gives it one); its texts say so in all 6 locales. The code's name is kept:
  old batches' outcomes hold it.
- The time verification's check before "Add" judges a month/year row as of its anchor.

## Showing

**One formatter** - `ResultDateFormatter::format($date, $precision, $style, $locale)` - and **one Twig function** -
`result_date(date, precision, style = 'numeric', locale = null)` (+ `result_date_machine()` for `<time datetime>`:
`2021-03-15`, `2021-03`, `2021`).

| Style | A day | A month | A year |
|---|---|---|---|
| `numeric` (compact lists, leaderboards) | `15.03.2021` | `Mar 2021` | `2021` |
| `medium` (sentences, e-mails) | ICU medium: `15 Mar 2021` (en-GB), `Mar 15, 2021` (en) | `March 2021` | `2021` |

**A day is written exactly as the templates wrote it before** - `|date('d.m.Y')` resp. `|format_date('medium')`, the
same zone (Twig's `date.timezone`) and locale; `ResultDateFormatterTest` renders both side by side in 6 locales, also
for a time-of-day near midnight. So nothing changes for any result that knows its day. The month and the year come
from ICU skeletons (`yMMM`, `yMMMM`, `y`): "březen 2021", "mars 2021", "2021年3月", "2021年".

Routed through it (the query selects `finished_at_precision`, the result object carries `datePrecision`):

| Surface | Template | Query → result object |
|---|---|---|
| Profile results | `_player_solvings.html.twig` | `GetPlayerSolvedPuzzles` → `SolvedPuzzle` (+ `SolvedPuzzleDetail`, `SolvedPuzzleOverview`) |
| Puzzle leaderboard rows, "my time", my attempts | `components/PuzzleTimes.html.twig`, `_time_date_line.html.twig` | `GetPuzzleSolvers` → `PuzzleSolver`, `PuzzleSolversGroup` |
| Ladders | `_time_date_line.html.twig` (`LadderTable`) | `GetFastestPlayers` / `Pairs` / `Groups` → `SolvedPuzzle` |
| Result detail (modal + page) | `puzzle_result/_body.html.twig` | `GetPuzzleResultDetail` → `PuzzleResultAttempt` |
| The chart of one's times of a puzzle (a personal-best timeline) | - | `PlayerPuzzleTimesChart`: the point's label is the month / year |
| Recent activity (Hub, `/recent-activity`, a player's feed) | `components/RecentActivity.html.twig` | `GetRecentActivity` → `RecentActivityItem`: **the month or year instead of "… ago"**, no `data-relative-time` (nothing counts it up) |
| Pair / team page, "is this guest you?" | `pairs_and_teams/team_detail.html.twig`, `guest_link.html.twig` | `GetPuzzlingTeamDetail`, `GetGuestLinkRequests` → `PuzzlingTeamTime` |
| The recap's history of attempts | `added_time_recap/_history.html.twig` | `SolvedPuzzle` |
| Compare | `comparison/_cell_meta.html.twig` | `GetComparisonResults` → `ComparisonTimeRow` → `ComparisonCell::$dayPrecision` |
| XP history | `xp_history.html.twig` | `GetXpHistory` → `XpHistoryBlock` |
| "Review your results": copies, removed copies, late first tries, results awaiting verification | `review_results/_set.html.twig`, `index.html.twig`, `_suspicious_times.html.twig`, `first_try/_conflicts.html.twig` | `GetPlayerDuplicateCases`, `GetFirstTryTimes`, `GetPlayerSuspiciousTimes` → `DuplicateReviewCopy`, `AutoRemovedResult`, `FirstTryTime`, `PlayerSuspiciousTime` |
| The "Your results" e-mail | `emails/result_review.html.twig` | `GetResultReviewEmails` → `ResultReviewEmailCase`, `…MarkedTime`, `…VerificationAnswer`, `AutoRemovedResult` |
| Round results (online events) | `round_results/_player_times.html.twig` | `GetRoundResults` → `RoundResult` |
| Admin: time verification card ("only the month is known"), removed copies | `admin/suspicious_times/_case_card.html.twig`, `admin/duplicate_results.html.twig` | `GetSuspiciousTimeCaseDetail` → `SuspiciousTimeCaseCard`, `GetDuplicateResultsOverview` → `AutoRemovalListItem` |
| The import's own screens: a parsed row, the review before "Add", the results beside a row that was not added | `data_import/_parsed_row.html.twig`, `_add_row.html.twig`, `not_added_rows.html.twig` | `ImportSheetRow::period()`, `GetImportAddScreen::resultsBeside()` (`precision`) |
| Results export | - | `GetExportableSolvingTimes` → `ExportableSolvingTime`: a new last column **`date_precision`** (`day` / `month` / `year`, empty without a `finished_at`); `finished_at` keeps its value |
| API v1 | - | `PlayerResultResponse` (`/me/results`, `/players/{id}/results`) and `SolvingTimeResponse` (`POST` / `PUT /me/solving-times`): an added **`date_precision`** (`day` / `month` / `year`); `finished_at` keeps its value. The "For developers" table lists endpoints, not fields - unchanged |

## Counting

Rankings, totals, counts, medians, "Completed N×", personal bests as times, XP, achievements counted in results: **a
result is a result** - unaffected. What needs a real day leaves a result without one out:

| Reader | Decision |
|---|---|
| Activity calendar: days, the month's totals, weekday bars, the list of a picked day - `GetPlayerActivityCalendar`, `ActivityCalendar::loadSelectedDaySolvings()` | **Left out** (`finished_at_precision IS NULL`) |
| Streaks - calendar (`activeDaysInMonth`), achievements (`GetPlayerStatsSnapshot::allTimeLongestStreakDays()`, `AchievementReplayState::addDay()`), statistics page (`PerCategoryStatistics::$activeDays`), digest (`PlayerPeriodActivityReader`) | **Left out** |
| Time spent per day (statistics page) - `PerCategoryStatistics` | **Left out** of the per-day sums; in every total |
| Weekend Puzzler - `GetPlayerStatsSnapshot`, `AchievementFirstMetDates` (`exact_day`) | **Left out** - a weekday of an invented day is a lie |
| Steady Hands (quarters) - the same two | A month is in its quarter; a **year is left out** (`quarter_known`) |
| Night Owl, the hour-of-day chart | Left out already: never a spreadsheet import (stage 6b) |
| Insights weekday, "n-th solve of the day", days since the previous solve - `InsightsRowsPreparer`, `HabitsBuilder` | **Left out** (`InsightsRow::NO_WEEKDAY`); the result itself counts |
| The profile's chart of average times per week / month - `GetPlayerChartData` | Per week: **left out**. Per month: a month is in its month, a year is left out |
| "Most active of the month" - `GetMostActivePlayers::mostActiveSoloPlayersInMonth()` | A month is of its month; a **year is left out** |
| Players page: last 7 / 30 / 60 days - `CommunityStatsCalculator` (`day_at`) | **Left out** |
| Players page: this month, last month, the 12 monthly counts (`month_at`) | A month is of its month; a **year is left out** |
| Players page moments (personal best, n-th result, pieces milestone, first result) - `PlayerMomentDetector` | **Never a moment** - a moment happened on a day. It still counts in the running totals and is a time to beat for what came after |
| Weekly digest: "solved this week", its highlights, who gets one - `PlayerPeriodActivityReader`, `GetContentDigestRecipients` | **Left out** |
| Series: finding the edition by the solve day - `SeriesEditionResolver`, `SeriesEditionReconciler` | **Never by the anchor**: the rule gets no day (`NULL`), so `SeriesEditionMatch` matches by the puzzle of exactly one edition or not at all (series-level - a normal, permanent state). An automatic date link of such a result is released by the next reconcile |
| Rounds | Derived from competition + puzzle + solo/pair/team - no date (unchanged) |
| Duplicate results: "the same day" - `GetDuplicateCandidates` (`DatePrecision::sqlSolvedPeriod()`) | **The period is compared**: two results are of "the same day" when both know the same day, or both know only the same month / year. The first day of a period never equals a real day |
| Duplicate puzzles signal - `GetDuplicatePuzzleSignalCandidates` | **Left out** - no day to share with another puzzle's result |
| The form's "sent twice" twin - `GetRecentIdenticalSolvingTime` | A typed entry's twin knows its day |
| Time verification: "the same day" evidence - `GetSuspiciousTimeEvidence`, `GetSuspiciousTimeCaseDetail` | Two real days only |
| **Which solve of a puzzle was first** (the baseline's qualifying first attempt and its twins) - `PlayerBaselineCalculator::qualifyingFirstAttemptsQuery()`, `PlayerSkillCalculator`, `StatisticalInputsSql`, `PredictionReconstructor::inFirstAttemptOrder()` | A first-try tag first, then the oldest - where a month / year result stands at the **end** of its period (`DatePrecision::sqlLatestPossibleMoment()`): it is the first one only when it certainly was. "June 2024" is not first ahead of 8 June 2024; "2023" is first ahead of 2024 |
| Prediction history, pace history, difficulty as-of, MSP rating order, XP chain, repeat history - everything in "the insights order" `(COALESCE(finished_at, tracked_at), tracked_at)` | **The anchor** = the as-of moment (the earliest the solve can have happened - nothing from after it is read). An imported result is never predicted live: its prediction is `reconstructed`, as for any import |
| Personal-best timelines | The point is shown as its month / year (the chart's label, the attempts list); the order uses the anchor |
| Long windows back from now (rating window, recent form, "solved since", seating seed times, similar speed), first / last solved, "last together" | **The anchor** - listed with the reason in `DatePrecisionCoverageTest` |
| Date-range filters (profile results from - to, statistics page period, comparison period) | **The anchor**: a result of "March 2021" is in a range that holds 1 March 2021 |
| First-try integrity (`FirstTryAssessor`, late first tries) | **The anchor** - see "What is left" |

**The guard:** `DatePrecisionCoverageTest` - every file whose SQL reads `finished_at` of `puzzle_solving_time` names
`finished_at_precision` (or a `DatePrecision::sql…()` fragment), or is listed there with the reason the anchor is right
for it. A new reader fails until its author has decided.

## Editing

`PuzzleSolvingTime::modify()`: **a saved date on another day makes the result exact** (the player said the day);
**a date left as it was keeps what is known**. The edit form shows the stored date (the first of the month) in its date
field like any date; `EditPuzzleSolvingTime`, its handler, the form type and the form's templates are untouched - the
rule lives in the entity, which the handler already calls. The same holds for `PUT /api/v1/me/solving-times/{id}`.

Not possible this way: saying "it really was the 1st" (the date is unchanged, so the precision stays), and seeing
"only the month is known" next to the form's field (its templates are off limits for this stage).

## Looked at and left

- **The add / edit form and everything inside it** (`_solving_time_form.html.twig`, `first_try/_notice.html.twig`,
  `duplicate_check/_notice.html.twig`, the messages, handlers, form types): never touched by a data-import stage.
- `added_tracking_recap.html.twig` / `GetPuzzleTracking`: reads `puzzle_tracking`, another table.
- `event_result_claim/*` / `GetEventResultClaims`: an organizer's imported event result is dated by the event's day.
- `insights/*` templates (`solvedAt|format_date`): the Insights page is not public (`INSIGHTS_PUBLIC`); its counting is
  done (above), its dates are not routed yet.
- API v0 (`GetPlayerResultsController`): deprecated, unchanged.
- The puzzle library export's `my_first_solved_at` / `my_last_solved_at`: minimum / maximum of the stored dates.
- "… ago" of an aggregated last date (puzzle library "last solved", the picker card, the Players card, "last together"):
  relative wording from the anchor; for anything older than a few weeks it reads the same on any day of the month.

## What is left

1. **The add form's notices** print the day of the player's other results (`first_try/_notice`, `duplicate_check/_notice`):
   for a month / year result that is its first day. Needs a change inside the add form - its own, browser-tested stage.
2. **First-try integrity compares the anchor** ("an earlier-day solve without the tag", late first tries): a month
   result may produce or miss a *warning* against a result of the same month. Never a block - a block depends on who
   holds a first try, not on dates.
3. **The edit form cannot show or set the precision** (above).
4. **Two files that date one solve differently** ("March 2021" in one import, 14 March 2021 in a later one) make two
   results - as two files with two different exact days always did: a result an import made is spoken for by its row
   and is not offered as "similar". Within one file it is one result.
5. **Month and year rows pair off in the file's order** among themselves (README: months before years).
6. **Reading our own export back**: the import does not read the new `date_precision` column yet, so a month result
   exported and imported again arrives as the exact day `…-01`. Not handled and not tried in this stage - the known
   export layout (stage 4) should map the column to the row's precision before anybody is told to round-trip a file.
7. **The Insights templates** (above).
8. A result **without any date** still waits (`needs_exact_day`): the question "which year?" per sheet (README Q3) is
   the columns screen's.
