# Unfinished Competition Results — Implementation Plan (phase 1b, refined)

## Refined 2026-11-16 - overrides the sections below where they differ

Jan's decisions of 2026-11-12 (D9 in [event-results-import](../event-results-import/README.md)) and 2026-11-16, plus
what changed in the code since September. **Where this section and the older text below disagree, this section wins.**

**Principle (Jan):** the form asks only what the system cannot work out itself - "do not bother the user where it does
not make sense, the system should be smart".

1. **The question appears only when a round with a time limit resolves** for the chosen competition + puzzle +
   solo/pair/team (the hint endpoint, `SolvingTimeRoundResolver`). No round, or a round without a limit
   (`minutes_limit` is nullable since the import) → no question at all. This replaces "works fully without a round"
   and open decision 6.
2. **Not asked when the typed time is within the limit** (≤ limit): the player obviously finished. The question shows
   while the time is empty or over the limit. Turning it on relabels the time field "Total time, if you finished it
   afterwards (optional)". This replaces the within-limit inline error and its "Clear time" button; the server and the
   API still refuse an unfinished result whose total is within the limit.
3. A switch "I didn't finish within the time limit" (off by default) instead of the Yes/No segmented question - with
   rule 1 and 2 it only shows when it is relevant, so a default answer no longer needs to be visible.
4. **Time spent (`seconds_spent`, new column, generated migration)** - the round's limit × 60 for the form and the
   import (a round with a limit is always known there), `finished_later_seconds` when they finished afterwards. The
   "How long did you puzzle?" question disappears.
5. **Tier 2 splits (D9):** counts (completed, "Completed N×", solved flags, most solved, unsolved lists, wishlist
   removal) exclude an unfinished result (`CountsAsSolve`); **pieces puzzled** sums `pieces_placed` for it and **time
   spent puzzling** sums `seconds_spent` (global, monthly, personal). The differential test therefore asserts
   "everything identical except those two sums, which rise by exactly the inserted pieces and seconds".
6. **Puzzle page:** unfinished rows after every ranked row, most pieces first, no rank - a new surface, so it joins the
   blocklist, private-profile and trackerless canaries (an unclaimed imported unfinished team keeps its "This was my
   team" button).
7. **Import:** a tab's "Pieces placed" rows are imported when the tab has a `Time limit`; a tab with such rows and no
   limit is skipped with a warning (D2/D3). Replaces `unfinished_not_supported_yet`.
8. **"Add to my profile" from an official unfinished result** (online and managed competitions already store them -
   101 on production) opens the form with the switch on and the pieces filled.
9. **XP and achievements:** an unfinished result is not a solve - no XP, no achievement progress.
10. **The write path stays beside the form's** (the add form must never break - Jan, 2026-11-14): the finished path of add and edit keeps
    its behaviour byte for byte, its tests untouched; the unfinished path is its own message + handler.
11. Open decisions 3 (stopwatch offers it - only past a known limit, by rules 1-2), 4 (an active day) and 5
    (favourites notified) are taken as recommended. Decision 1 is superseded by rule 5. **Decision 2: no DB CHECK
    constraints** (Jan, 2026-11-16) - the entity invariant and the domain value object guard it.
12. The audit's file:line list below predates trackerless rows, data import, My data, XP, the Players page precompute
    and rating history: readers are re-inventoried from `TrackerlessTimeQueryCoverageTest`'s list, not from this file.

Delivery: the five steps below, one PR each; step 4 (the form) is walked in a real browser before merge.

**Status step 1 (isolation), 2026-11-16 - merged (#394):** `puzzle_solving_time.seconds_spent` (generated
migration `Version20261116151500`), the entity invariant (`PuzzleSolvingTime::assertUnfinishedResultIsConsistent()`,
checked before anything changes; `RemovedResultSnapshot` carries `seconds_spent`), `Query\CountsAsSolve` (`condition()`
/ `SQL_CONDITION`, `piecesPuzzled()`, `secondsPuzzled()`) in every count of solves and every pieces / time sum. All 174
readers of `puzzle_solving_time` (SQL, DQL, PuzzleSolved handlers) are classified: 40 use `CountsAsSolve`, the rest are
listed with their reason in `tests/SolvingTimeReadersAreClassifiedTest.php` (performance, history, neutral, activity,
predictions, data import...); `tests/UnfinishedResultsAreInvisibleTest.php` is the differential test (+ a relax-results
control), `tests/Entity/PuzzleSolvingTimeUnfinishedTest.php` the invariant. Beside the SQL readers three PHP aggregators
of result lists split too: `PerCategoryStatistics` (profile statistics, via `SolvedPuzzle::$piecesPlaced` /
`$secondsSpent`), the digest (`PeriodResult`, `PlayerPeriodActivity`) and the team page (`PuzzlingTeamDetailController`).
Decisions taken in this step: achievements get no progress from an unfinished result at all (Night Owl, Online
Competitor and Competitor included - a participant row still counts as taking part); the Players page counts carry its
pieces placed (`CommunityStatsCalculator::resultsSql()` keeps the row, every count filters - it had excluded it entirely,
so pieces never reached the pieces totals); "N results" of an event / round, "puzzled together N times", first-try
integrity, the onboarding "logged a puzzle" and the model features of PuzzleIntelligence keep an unfinished result
(listed as history / activity). The activity calendar keeps its day active (the day's cell and the streak), while
its per-day solve pills and the weekday / hour "solves" histograms count solves only and its time sums `seconds_spent`;
the streak and quarter achievements (Steady Hands) count solves only - "no achievement progress" read strictly
(Jan's call if the streak achievements should follow the calendar instead). Loose ends: `docs/TODO.md` "Unfinished results - after step 1".

**Status step 2 (display), 2026-11-16 - merged (#397):** still nothing visible on production (no row can be
written before step 4); verified with rows added inside the tests (`tests/UnfinishedResultSeeding.php` - never global
fixtures: step 1 put their pieces and time spent into every sum). One partial `_unfinished_result_badge.html.twig`
("479 / 500 pcs", titled "Didn't finish within the time limit", "finished in 1:48:12"), texts under
`unfinished_result.*` (messages + emails, 6 locales). **Puzzle page:** `GetPuzzleSolvers::unfinishedByPuzzleId()` (one
statement for solo/pairs/teams, the ranked statements' blocklist / private / suspicious rules, an imported team without
a tracker through its anonymous slots) is loaded only when `untimedCountsByPuzzleId()` - the relax count's statement,
now also counting unfinished rows - counts one, so the page's statement budget is unchanged. Its own type
`UnfinishedPuzzleResult`, never in `$times`; `UnfinishedResultsBoard` keeps one row per player / exact pair-team
(`PuzzlesSorter::puzzlersIdentification()`, the ranked rows' key) **without a ranked row** (decided against the unfiltered
ranked rows of the tab), most pieces first, with the page's filters (first try / unboxed lead the row, country,
favourites, my teams, private). Rendered after every ranked row under "Didn't finish within the time limit", rank "–" -
**only once no ranked row is left below** ("Show more" / "Show all" reach them like any further row). Tab counts and
"disabled" count the bottom rows too (a tab with only unfinished results is open). "My attempts" merges the viewer's
unfinished results (`myUnfinishedAttempts`, `kind: unfinished`) - best / latest / PB / deltas / the chart from times
only; with no time the line says "Your result 479 / 1000 pcs". Whoever has a time keeps their ranked row; their
unfinished result is in the result detail (`PuzzleResultAttempt::$piecesPlaced`). Canaries: blocklist, private profile,
trackerless (an unclaimed imported team keeps "This was my team"). **Tier 3:** profile results (`SolvedPuzzle::isRelax()`
for the relax filter; tab counts = listed puzzles, unchanged), player feed + Hub feed (`GetRecentActivity` selects the
columns through `listed`), activity calendar (`ActivityCalendarDay::$unfinishedCount` = an hourglass pill on an active
day without solves, drill-down badge), notifications ("competed with puzzle" + badge - never "solved"), team page,
result detail, recap (panel: pieces, pieces left, the later total, "See round results" while the event is public),
share card ("479/500", "pieces placed in the time limit"; its version changes only for an unfinished result). My data's list shows the pieces (its row form is steps 3-4). Digest + "Your week": a part,
the busiest day or (the e-mail's preheader) a week with no solve reads "N pieces", never "0 puzzles"; a highlight shows
the pieces and "Didn't finish within the time limit"; the Players page's pieces milestone chip already says "N pieces placed" (no change). **API/export:** v1 results
and solving-time answers + v0 results gain `pieces_placed`, `finished_later_seconds` (v0 `is_relax_mode` false for
one), the export two last columns. Left for steps 3-4: below in `docs/TODO.md`.

**Status step 3 (write path backend + import), 2026-11-16 - merged (#399):** no form yet, so nobody can type an
unfinished result - but the **event results import writes them** (Sarah's sheet), so after this step real rows can exist
(imported teams, never editable by a person). *Domain:* `Value\UnfinishedResult::fromInput(piecesPlaced, totalSeconds,
puzzlePiecesCount, roundMinutesLimit)` - the one rule for every writer: no limit → `UnfinishedResultNeedsTimeLimit`,
`1 ≤ pieces < puzzle's pieces` else `InvalidPiecesPlaced` (`allPlaced()` tells "all of them" apart), a total `≤ limit × 60`
→ `UnfinishedTotalTimeWithinLimit`; `secondsSpent` = total ?? limit × 60. `SolvingTimeRoundResolver::resolveFor(competitionId,
puzzleId, PuzzlingType)` (`resolve()` delegates - one SQL) and `SeriesEditionResolver::resolveFor()` (scalar twin of
`resolve()`). `Services\UnfinishedResults\UnfinishedResultRound` settles event + round before any entity exists or changes:
round id (API, visibility checked) > competition > series pick; where the finished path saves without the link (unknown
competition, series not public) the unfinished one is refused, and **a series pick counts only when its edition is found by
its puzzle** (`SeriesEditionMatchKind::Puzzle` - a day match names no round). *Write path, beside the finished one:*
`AddUnfinishedPuzzleSolvingTime` / `EditUnfinishedPuzzleSolvingTime` + handlers (same steps, same services, same
follow-ups - `UnfinishedWritePathParityTest` compares the stored rows and queued messages of both paths; no duplicate-time
/ pace questions: there is no time). Transitions: finished/relax → unfinished and unfinished → unfinished through
`EditUnfinishedPuzzleSolvingTime` (entity `modify(null, …)` then `unfinishedWith()`), unfinished → finished/relax through
`EditPuzzleSolvingTime(endsUnfinishedResult: true)` (`endUnfinishedResult()` right before `modify()`); **without that flag
`EditPuzzleSolvingTimeHandler` refuses an unfinished result** (`UnfinishedResultNeedsItsOwnEdit`, before anything changes -
the only change to the finished handler; every existing case is unchanged and its tests untouched). An unfinished first try
is the puzzle's first try (first-try integrity keeps it, step 1). XP / achievements: the same `AwardXpForSolvingTime` /
`RecalculateBadgesForPlayer` / `RecalculateXpChainForSolve` are queued and award nothing (a finished result turned unfinished
loses its XP - tested). *Edit surfaces until step 4:* the edit page shows the result ("479 / 500 pcs") with "coming soon"
and Delete, saves nothing (a POST = 422); API v1 `PUT /me/solving-times/{id}` answers 422 `unfinished_result_not_editable`;
My data's row sheet and cells save through `EditUnfinishedPuzzleSolvingTime` with the stored pieces and total (a round that
changed since = `unfinished_result.round_changed`). *Import:* see [event-results-import](../event-results-import/README.md)
"Unfinished results (D9)". *Step 1/2 leftovers decided:* an unfinished result's photo is out of the image pipeline
(`GetFinishedPhotosForAnalysis`, `findWithFinishedPhotoOfPuzzles()`); the data import's "nothing to wish for"
(`ImportNeverTwice::hasSolved()`, `GetImportAddScreen` `can_wish`) no longer counts it; the claim screens show the pieces;
re-importing our own export still ignores `pieces_placed` (docs/TODO.md). **Step 4 wires:** the form (switch, pieces, the
relabelled time, mapping the three exceptions to field errors), `EditTimeController` routing an unfinished result to
`EditUnfinishedPuzzleSolvingTime` and a switched-off one to `EditPuzzleSolvingTime(endsUnfinishedResult: true)` (replacing
the "coming soon" page), the hint endpoint on `resolveFor()`, API v1 write fields (`piecesPlaced` on create/update - the
PUT refusal goes then), "Add to my profile" from an official unfinished result.

**Status step 4 (the form - the release), 2026-11-16 - PR #400, draft:** *Hint:* `Services\UnfinishedResults\UnfinishedResultHint::forPick(pick, puzzle, puzzlers, day)`
= `UnfinishedResultRound::forNewResult()` (the save's rule, series picks found by their puzzle) behind three more gates: the
pick's event publicly visible (a draft's round names nobody may see - `DraftCanaryTest` has the endpoint), the puzzle
writable by the viewer (`SecretPuzzleAccess`) and **not hidden entirely by the round itself** until it reveals (a public
catalogue puzzle can be a coming round's secret - the answer would say which round holds it; picture-only hiding answers).
`GET /{_locale}/solving-time-round-hint?competition=&puzzle=&puzzlers=&date=` (`SolvingTimeRoundHintController`,
signed-in, `{"round": {name, minutesLimit, category}}` or `{"round": null}` for anything else, `private, no-store`).
*Form:* `UnfinishedResultFields` adds `unfinished` (checkbox) + `piecesPlaced` (a text input, no constraint - neither can
invalidate a form) to both form types and maps `InvalidPiecesPlaced` / `UnfinishedTotalTimeWithinLimit` /
`UnfinishedResultNeedsTimeLimit` to their fields; the FormData read them only through `isUnfinished()` (Speed Puzzling +
switch on) / `piecesPlacedCount()`; the only change to the finished rule: "time required" skips an unfinished submit.
`UnfinishedResultForm::state()` (`Results\UnfinishedFormState`: round, on, typed time, piece count - no statement without a
competition) renders the state without JavaScript (deep link, edit, 422) and `check()` refuses an unfinished submit before
anything is dispatched (no round = `needs_round` on the switch - a new puzzle is in no round, so it never leaves a puzzle
behind). Shown: always while on, else when a round with a limit is known and the time is empty or over it; over the limit
with the switch off = the non-blocking nudge; on = pieces with "/ 500 · 21 pieces left", the time relabelled "Total time, if
you finished it afterwards (optional)"; hidden inputs are disabled (never posted). `unfinished_result_controller.js` (on the
`<form>`, lazy, the other controllers untouched) takes over: hint debounced 300 ms on any change/input of the form + the mode
buttons, `AbortController`, fail-open (a failed hint = no round, except for what the page was rendered with).
*Add:* `PuzzleAddController` dispatches `AddUnfinishedPuzzleSolvingTime` when `isUnfinished()` (stopwatch: its time is the
total, `created_via = stopwatch`), else exactly the old path; no pace check and no duplicate-time check for it (first-try
check with no seconds); a resend = same pieces + puzzle + day (`isSameEntry()`); recap as before. *Edit:* the "coming soon"
page and its templates are gone - `EditTimeController` opens an unfinished result in Speed mode, switch on, pieces + total
filled; on = `EditUnfinishedPuzzleSolvingTime`, off = `EditPuzzleSolvingTime(endsUnfinishedResult: <the stored row is
unfinished>)` (relax included). *API v1:* `piecesPlaced` on create/update (`time` required only without it; refusals = 422
violations, `UnfinishedResultViolation`); a `PUT` of an unfinished result without `pieces_placed` and `time` keeps it
unfinished (an older client never turns it into a relax solve), with a `time` it becomes finished; the PUT refusal is gone.
*Official results:* "Add to my profile" is offered for an unfinished entry of a round **with a limit**
(`GetPublishedRoundResults::withProfileStates()`), `OfficialEntryTime::$piecesPlaced` opens the form with the switch on and
the pieces filled. Kill switch `UNFINISHED_RESULTS_FORM` (`docs/features/feature_flags.md`). Tests:
`SolvingTimeRoundHintControllerTest`, `PuzzleAddUnfinishedResultTest`, `EditTimeUnfinishedResultTest`,
`UnfinishedSolvingTimeEndpointTest`, `PuzzleAddOfficialUnfinishedEntryTest`; every existing form test untouched. *Browser walk (Selenium, a fixtures clone):* found
two bugs the PHP tests could not see - the controller did not connect where no round was known yet (Stimulus refuses
`null` for an Object value - now `{}`, pinned), and step 2's recap panel never showed (`.recap-card` hides a card without
`.recap-row`, and it shared the progress card's grid cell - `recap-card-content`, `recap-layout-unfinished`).

**Status:** plan, 2026-09-18, refined 2026-11-16. Steps 1-3 merged, step 4 in review (above). Supersedes the "Unfinished results" and "How unfinished results appear elsewhere" sections of [round-results.md](round-results.md) where they differ — the differences are listed in [What changed against the earlier plan](#what-changed-against-the-earlier-plan).

## Goal

A competitor who ran out of time records what actually happened — **"479 of 500 pieces when the time ran out"**, optionally **"and I finished it afterwards in 1:48:12"** — in the add/edit-time form, in a way that is obvious on a phone right after a round, and that **cannot change any ranking, rating, statistic or count** anywhere on the platform.

## What exists (verified 2026-09-18)

- Storage and the round page are live since phase 1a: `puzzle_solving_time.pieces_placed`, `finished_later_seconds` (`PuzzleSolvingTime.php:77,84`), `GetRoundResults` orders finished → unfinished (by pieces) → over the limit, `round_results.html.twig:167` renders "479 / 500 pcs · finished in …".
- **Nothing can write those columns**: no form field, message, handler or API property references them. 0 rows in production.
- Production: **168 rounds, all with `minutes_limit` (10–240)** — the column is `INT NOT NULL` and the round form requires it. But only **864 of 17,406 competition-linked times (5 %) resolve to a round**; 120 of 194 competitions have no rounds at all. *So "no round, no known limit" is the normal case and "round with limit" is the bonus.*
- Of the 864 round-linked times, 8 are over the limit and 2 are exactly the limit (the "enter the limit, write the pieces in the comment" workaround); 75 competition times mention pieces in the comment.

## The three rules everything follows from

An unfinished result is a row with `pieces_placed IS NOT NULL` and `seconds_to_solve IS NULL`. Every reader of `puzzle_solving_time` falls into exactly one tier:

| Tier | What | Unfinished result | Mechanism |
|---|---|---|---|
| **1. Performance** | times, leaderboards, ladders, medians, difficulty, skill, MSP rating, baselines, predictions, improvement, milestones, distributions | **never included** | already true — every such query filters `seconds_to_solve IS NOT NULL` (audited, list below). Pinned by a test, no code change |
| **2. Solve counts** | "solved N×", solved-puzzle counts, total pieces, most solved, most active, "already solved" flags, unsolved lists, wishlist auto-removal, charts, admin counts | **not counted — an unfinished result is not a solve** | each query gains one shared condition `pieces_placed IS NULL` |
| **3. History** | profile results list, recent activity feeds, favourites' notifications, activity calendar + streak, "my attempts", puzzle page bottom rows, round page, export, API result lists, edit/delete | **shown, always labelled "479/500 pcs"**, never as ☕ Relax | template/DTO changes |

**This reverses one earlier decision** ("unfinished results keep counting as solved for now"). Reason: the audit found that "counting" is not one profile number — it is ~30 reads, and counting would mean a 479/500 result credits 500 "pieces solved", bumps the puzzle's "solved N×" (changing search order, related puzzles and the picker's community tier), removes the puzzle from the wishlist, from "unsolved" lists and from "borrowed but not solved", and flips the picker's "not solved yet" filter. Not counting is the option with zero effect on existing numbers — which is the requirement.

### Ratings, skill, difficulty — recommendation: exclude, and record cleanly

Considered including unfinished results in difficulty/skill, because dropping them is a small survivorship bias (the slowest solvers of a competition puzzle vanish, so the puzzle looks slightly easier):

- **Extrapolating a time** (`limit × total / placed`) is wrong in a known direction — the last pieces go fastest, so it overestimates — and it needs a limit, which 95 % of competition times do not have.
- **Treating it as a censored observation** ("time > limit") is statistically right but means rewriting the baseline, difficulty and rating calculators around censored data for what is today 75 rows in 515,000.
- **`finished_later_seconds` as a real time**: the solve was interrupted by the end of a round, often continued after a break; Jan already decided a later finish is not ranked.

So: **excluded from every calculator, no change to `src/Services/PuzzleIntelligence/*`**. What this feature *improves* is the input: today those solves enter the calculators as a fake time equal to the limit (2 rows exactly at the limit, more near it) or as an interrupted total. With a proper place to record them they stop polluting. Revisit censored-data handling once there are a few hundred real rows — the data will then exist in a clean form.

## The form — behaviour

### Layout (inside the existing collapsed "Competition result" card)

```
┌ 🏆 Competition result ─────────────────────────────┐
│ Competition / event                                │
│ [ WJPC 2026 · Valladolid                     ▾ ]   │
│                                                    │
│ ⏱ Individual B · 90 min limit          ← only when a round matches
│                                                    │
│ Did you finish within the time limit?              │
│ [ ✓ Yes, I finished ] [ ✗ No, time ran out ]       │
│                                                    │
│ ── shown after "No" ──────────────────────────     │
│ Pieces placed when the time ran out                │
│ [ 479 ] / 500            21 pieces left            │
│                                                    │
│ ⓘ Your result is saved as 479 / 500 pieces. It     │
│   appears in the round results, not in the time    │
│   leaderboard.                                     │
└────────────────────────────────────────────────────┘
```

- The question appears **only once a competition is chosen**; clearing the competition hides it and resets it to "Yes".
- Two big segmented buttons (radio inputs styled as `btn-check`), not a checkbox: the default "Yes" is visibly selected, and it reads as a question rather than as an obscure option.
- "/ 500" and "21 pieces left" update live from the known piece count (three existing sources: new-puzzle input → `ppm:piecesCountUpdated` event → server-rendered active puzzle). Unknown count → the suffix is simply absent.

### What happens to the time field when "No" is chosen

The time card sits above the competition card and is usually already filled. On "No":

- its label changes to **"Total time, if you finished it afterwards (optional)"**, the required mark disappears;
- helper text: *"Leave empty if you stopped when the time ran out."*;
- nothing is cleared automatically — predictable beats clever;
- if a round limit is known and the entered time is **≤ the limit**, an inline error appears under the time field right away (and the server enforces the same): *"That is within the 90 min limit. Leave the time empty if you stopped at the limit — enter a total only if you kept going and finished."* with a **Clear time** button. This is exactly the old workaround (typing the limit) being caught at the moment it happens.

Going back to "Yes" restores label, required mark and the normal rules.

### The round hint (time limit detection)

- A time's round follows from **competition + puzzle + solo/duo/team** — the same rule the server applies on save. The form asks the server for it whenever one of the three changes: competition picked, puzzle picked, co-puzzler added/removed/typed (debounced 300 ms; category = 1 + non-empty `group_players[]`, the counting `ppm-validator` already does).
- Found → chip "Individual B · 90 min limit", the wording everywhere uses the real number, and the two limit checks below switch on.
- Not found (no rounds, puzzle not attached, new puzzle being created, wrong category) → no chip, generic wording "the time limit", no limit checks. **The feature works fully without a round.**
- Request fails or JS is broken → same as "not found". The hint is advisory; nothing depends on it.

### The over-limit nudge (round known, answer still "Yes")

Entered time **> limit** → non-blocking inline note under the question: *"1:48:12 is over the 90 min limit of Individual B. If the time ran out before you finished, choose 'No, time ran out' — your 1:48:12 is kept as the total time."* One tap on "No" and the time already sits in the right place. Not blocking: some events let people finish, and the round page already lists such times in its "over the limit" group.

### Stopwatch finish flow (changed from the earlier plan)

The earlier plan hid the option there ("a measured time is by definition finished"). Refined: a stopwatch that ran past the limit is precisely the *finished afterwards* case. So the question is available; the read-only stopwatch time becomes the total (`finished_later_seconds`). With a known limit and a stopwatch time ≤ limit, "No" is rejected with the same message as above.

### Modes

Relax and Collection never show the question (the whole competition section is already hidden by `puzzle-add-form`). Hidden inputs are still posted, so the server ignores `unfinished`/`piecesPlaced` unless mode is Speed Puzzling — it does not raise an error for them.

### Edit form / modal

Same template, so it comes for free, with one fix that is **mandatory**: `EditTimeController:82` decides the mode from `time !== null`, so an unfinished result would open as ☕ Relax and a plain Save would silently destroy `pieces_placed`. New detection: `piecesPlaced !== null` → Speed Puzzling, answer "No", pieces pre-filled, time fields pre-filled from `finishedLaterSeconds`. `GetPlayerSolvedPuzzles::byTimeId` + `SolvedPuzzleDetail` gain both columns.

All transitions are supported and tested: finished ↔ unfinished, unfinished ↔ relax, with/without total time. Leaving "unfinished" always clears both columns.

## Validation — one rule, three layers

### 1. Domain (authoritative, shared by web and API)

New value object `src/Value/UnfinishedResult.php`:

```php
UnfinishedResult::fromInput(
    int $piecesPlaced,
    null|int $totalSeconds,        // the form's time, when filled
    int $puzzlePiecesCount,
    null|int $roundMinutesLimit,   // null when no round matches
): self  // ->piecesPlaced, ->finishedLaterSeconds
```

Throws (each a domain exception mapped to a form error / 422, like `SuspiciousPpm` today):

| Rule | Exception | Message key |
|---|---|---|
| `1 ≤ piecesPlaced` | `InvalidPiecesPlaced` | `forms.pieces_placed_invalid` |
| `piecesPlaced < puzzlePiecesCount` — equal means "all placed": that is a finished solve, enter the time | `InvalidPiecesPlaced` | `forms.pieces_placed_all` |
| limit known and total given → `totalSeconds > limit × 60` | `UnfinishedTotalTimeWithinLimit` | `forms.unfinished_time_within_limit` |
| total given → PPM guard as today (`≥ 100` → `SuspiciousPpm`) | existing | existing |
| competition must resolve (not just be posted) | `UnfinishedResultNeedsCompetition` | `forms.unfinished_needs_competition` |

Handlers call it **before touching the entity** (a rolled-back handler's mutations can leak through a later flush — known trap in this codebase). The handler needs the round *before* constructing the entity, so `SolvingTimeRoundResolver` gains a scalar entry point:

```php
public function resolveFor(string $competitionId, string $puzzleId, PuzzlingType $type): null|CompetitionRound
```

and the existing `resolve(PuzzleSolvingTime)` delegates to it — **one SQL, used by save, by validation and by the form hint**, so the three can never disagree.

Note the silent-drop path: `AddPuzzleSolvingTimeHandler` swallows `CompetitionNotFound` and saves without a competition. For an unfinished result that would produce an orphan, so in that branch the handler throws `UnfinishedResultNeedsCompetition` instead.

### 2. Entity invariant

`PuzzleSolvingTime` constructor and `modify()` assert `piecesPlaced === null || secondsToSolve === null` and `finishedLaterSeconds === null || piecesPlaced !== null` (`LogicException` — a programming error, never user-facing). `modify()` gains `piecesPlaced` and `finishedLaterSeconds` parameters; callers: the edit handler only.

### 3. Database CHECK constraints (**needs Jan's OK — hand-written migration**)

```sql
ALTER TABLE puzzle_solving_time
  ADD CONSTRAINT custom_pst_unfinished_has_no_time CHECK (pieces_placed IS NULL OR seconds_to_solve IS NULL),
  ADD CONSTRAINT custom_pst_finished_later_needs_pieces CHECK (finished_later_seconds IS NULL OR pieces_placed IS NOT NULL);
```

This is what makes "an unfinished result can never reach a leaderboard" a property of the data instead of a property of every future handler. Doctrine does not diff CHECK constraints, so it follows the custom-index convention: hand-written migration, mirrored in `tests/bootstrap.php`. On the 515k-row table both validate instantly in practice (0 offending rows; a full scan of two nullable int columns), but to stay lock-friendly during the boot-time migrate: `ADD CONSTRAINT … NOT VALID` + `VALIDATE CONSTRAINT` in the same migration. Not tied to `competition_id` on purpose — competition deletion nulls that column.

### Form-level checks (friendly, field-attached)

`PuzzleAddFormType` / `EditPuzzleSolvingTimeFormType` `applyDynamicRules()`:

- Speed mode requires a time **unless** `unfinished` — the existing rule at `PuzzleAddFormType:399` / `EditPuzzleSolvingTimeFormType:295` becomes conditional;
- `unfinished` → `piecesPlaced` required and ≥ 1 (error on the field); competition required (error on the competition field); upper bound checked here too when the puzzle is an existing one (`puzzle` is a uuid → piece count lookup), and against `puzzlePiecesCount` for a new puzzle;
- not Speed mode → `unfinished` and `piecesPlaced` are nulled, no error.

New `FormData` fields on both classes: `bool $unfinished = false`, `null|int $piecesPlaced = null` (`#[Range(min: 1, max: 99999)]`). While there: align the inconsistent `timeHours` (999 vs 99) and `puzzlePiecesCount` (99999 vs 25000) ranges between the two form data classes — separate commit.

Every failed submit answers **422** (the form object is passed to `render()`), never 200 — Turbo Drive drops a 200.

## Write path — files

| File | Change |
|---|---|
| `src/Message/AddPuzzleSolvingTime.php` | `time` → `null|string`; + `null|int $piecesPlaced = null` |
| `src/Message/EditPuzzleSolvingTime.php` | + `piecesPlaced`; `fromFormData()` maps `unfinished` |
| `src/MessageHandler/AddPuzzleSolvingTimeHandler.php` | resolve round first (`resolveFor`), build `UnfinishedResult` before the entity, `secondsToSolve: null`, skip the finished-time PPM guard, orphan guard |
| `src/MessageHandler/EditPuzzleSolvingTimeHandler.php` | same; all transitions; clear both columns when leaving unfinished |
| `src/Entity/PuzzleSolvingTime.php` | invariants, `modify()` signature |
| `src/Services/RoundResults/SolvingTimeRoundResolver.php` | `resolveFor()` |
| `src/Controller/PuzzleAddController.php` | remove `assert($timeString !== null)` (:287); map the three new exceptions to field errors; after an unfinished save redirect to the recap as today |
| `src/Controller/EditTimeController.php` | mode detection fix, pre-fill, exception mapping |
| `src/Query/GetPlayerSolvedPuzzles.php` + `Results/SolvedPuzzle*.php` | select `pieces_placed`, `finished_later_seconds` everywhere this query returns rows (one change feeds profile list, calendar drill-down, recap, edit, API lists) |
| `src/MessageHandler/RemoveFromWishListWhenPuzzleSolved.php` | skip when `piecesPlaced !== null` (tier 2: not a solve) |
| `src/MessageHandler/NotifyWhenPuzzleSolved.php` | unchanged — favourites are told, the template labels it (tier 3) |

`PuzzleSolved` keeps being recorded for unfinished results: the statistics and intelligence recalculation handlers must run on finished → unfinished edits anyway, and both are idempotent and filter on `seconds_to_solve`.

### The hint endpoint

`src/Controller/SolvingTimeRoundHintController.php` — single action, signed-in only:

```
GET /{_locale}/solving-time-round-hint?competition={uuid}&puzzle={uuid}&puzzlers={n}
→ 200 {"round": {"name": "Individual B", "minutesLimit": 90, "category": "solo"}}   |   {"round": null}
```

- Calls `SolvingTimeRoundResolver::resolveFor()` — literally the save-time rule.
- Invalid/unknown ids → `{"round": null}`, never an error page. `Cache-Control: private, no-store`.
- Leaks nothing: the caller supplies the puzzle id; the response names a round and its limit, both public on the event page. Hidden (embargoed) puzzles are therefore safe — no puzzle data is returned. (This is why rounds are **not** baked into the competition picker's option payload: that would put embargoed puzzle ids of every competition into every form render.)

### Stimulus

New `assets/controllers/unfinished_result_controller.js` on the competition card. Kept separate from `puzzle-add-form` and `ppm-validator`, which stay untouched apart from one event.

- **Values:** `hintUrl`, `activePuzzleId`, `activePuzzlePieces`, translated strings (all texts via data attributes).
- **Targets:** `question`, `answerYes`, `answerNo`, `details`, `piecesInput`, `piecesTotal`, `piecesLeft`, `roundChip`, `overLimitNote`, `withinLimitError`.
- **Listens to:** competition TomSelect `change` (the `competition-picker` controller's `onChange` override additionally dispatches a bubbling `competition:changed` — its blur behaviour stays), `ppm:piecesCountUpdated`, puzzle TomSelect change, `input`/DOM changes in the co-puzzler group, `input` on the three time fields.
- **Outside the card** it only toggles the time card's label/help/required mark through two `data-unfinished-result-*` hooks on the time section, via an outlet-free custom event `unfinished:changed` that a 10-line addition in `puzzle-add-form` handles — no cross-controller DOM reaching.
- Aborts in-flight hint requests (`AbortController`) so a slow response can never overwrite a newer one.
- `ppm-validator` needs no change: it already returns early when seconds are 0. With a total time entered it validates PPM as today, which is correct.

Server-rendered initial state (competition pre-filled by `?competition=` deep link from the round page, edit form, re-render after 422) must be correct **without JS running first**: Twig renders the question visible/hidden, the answer, the details block and the relabelled time field from form data; JS only takes over afterwards. The initial round chip is rendered server-side too when competition + puzzle are known at render time.

## Read side — tier 2 (not a solve)

One shared condition, same pattern as `IsCompetitionPubliclyVisible::SQL_CONDITION`:

```php
// src/Query/CountsAsSolve.php
public static function sql(string $alias = 'puzzle_solving_time'): string  // "{$alias}.pieces_placed IS NULL"
```

Applied to (file:line from the 2026-09-18 audit):

| Area | Where |
|---|---|
| **Puzzle statistics precompute** — the widest blast radius: feeds puzzle detail, search sort, related puzzles, most solved, picker community tier, API | `PuzzleStatisticsCalculator.php:29,36,43,50` (the four outer `COUNT(*)`), candidate list in `RecalculatePuzzleStatisticsConsoleCommand.php:34` |
| Relax count on the puzzle page | `GetPuzzleSolvers.php:299-307` |
| Player statistics (count + **total pieces**) | `GetPlayerStatistics.php:32-36,86-87,140-141`; `ComputeStatistics` / `PerCategoryStatistics` counts (streak input stays unfiltered — see tier 3) |
| Global + monthly counters and boards | `GetStatistics.php:20,66`; `GetMostSolvedPuzzles.php:88`; `GetMostActivePlayers.php:26,63,116` |
| "Already solved" flags | `GetPuzzlePickerSuggestions.php:202`; `GetPlayerPuzzleTimes.php:44`; `GetPlayerPuzzleSolves.php:46,50-51`; `GetUserPuzzleStatuses.php:39`; `GetUserSolvedPuzzles.php:27`; `GetUnsolvedPuzzles.php:42,101,140`; `GetBorrowedPuzzles.php:151,208` |
| Profile list counters | `GetPlayerSolvedPuzzles.php:164-172,302-309,422,569` ("N×" badge), `:702` (`countByPlayerId`) |
| Charts | `GetPlayerChartData.php:33-45,147-156` |
| Admin / account | `GetPuzzleMergeReviewQueue.php:151`, `GetPendingPuzzleProposals.php:141`, `GetAccountDeletionSummary.php:22-29` (adds a separate "unfinished results" number rather than hiding them — the person is deleting them too) |

Deliberately unchanged: `GetCompetitionSlugsForSitemap.php:110` (a round with only unfinished results has a real results page), `GetCompetitionParticipants.php:85-96` (already NULL-safe; add the explicit condition so it is safe by intent), puzzle merge migration (moves every row, correct), player deletion.

## Read side — tier 3 (shown, labelled)

One partial, `templates/_unfinished_result_badge.html.twig` ("479/500 pcs", `title` = "Didn't finish within the time limit", optional second line "finished in 1:48:12"), used by:

- `components/RecentActivity.html.twig:167` — all three `GetRecentActivity` reads (`forPlayer`, `latest`, `ofPlayerFavorites`) select the two columns
- `_player_solvings.html.twig:106` (profile)
- `components/PlayerSolvedPuzzles.html.twig` + `PlayerSolvedPuzzles.php:215` — "only relax" becomes `time === null && piecesPlaced === null`
- `notifications.html.twig:241` + `GetNotifications` selects the columns
- `components/ActivityCalendar.html.twig:164` — day drill-down (today it would render with no label at all)
- `added_time_recap.html.twig` — dedicated panel: big "479 / 500 pieces", "21 left", the total time when given, the round name and a **See round results** button; and the pre-existing unguarded `solved_puzzle.time` arithmetic in `added_time_recap/_history.html.twig:24,34` gets its guard (it is reachable by relax solves today)
- Export: `ExportableSolvingTime::toArray()` + `PuzzlerDataExporter` gain `pieces_placed`, `finished_later_seconds` columns (GDPR completeness)

**Activity calendar and streak: an unfinished result is an active day.** The person puzzled that day at a competition; breaking their streak for it would be the one user-hostile outcome in this feature. `GetPlayerActivityCalendar` stays unfiltered; the comment in `PerCategoryStatistics.php:22,50` ("null time = relax") is corrected.

### Puzzle detail page

As already decided, with the crash-safety made explicit:

- Ranked pipeline (`GetPuzzleSolvers` solo/duo/team, `PuzzlesSorter`, grouping, rank numbers, average/median in `PuzzleTimes.php:233-261`) is **not touched and never sees an unfinished row**. This is load-bearing: `puzzlingTime` is `formatTime(int)` — a null would be a `TypeError` in ~10 places of `PuzzleTimes.html.twig`.
- New `GetPuzzleSolvers::unfinishedByPuzzleId(puzzleId, PuzzlingType)` → rendered after the ranked rows under a section row **"Didn't finish within the time limit"**, no rank ("–"), ordered by pieces placed, country / first-try / unboxed filters and private-profile rule applied identically.
- A player/team with any finished time keeps their single ranked row; their unfinished entry goes under that row's "show more". Only players with no finished time get a bottom row.
- "My attempts": `PuzzleTimes.php:274-315` keeps computing best / last / delta / new-PB from timed attempts only; unfinished attempts are merged into the history **in PHP** as rows of `kind: 'unfinished'` and the template branches on `kind` before any time formatting. Fixes two latent null traps on the way (`isBest` with two nulls, `isNewPb` `null == null`).
- Header "solved N×" and all statistics: from `puzzle_statistics`, which no longer counts them.

## API v1 (additive, no BC break)

- `CreateSolvingTimeInput` / `UpdateSolvingTimeInput`: + `piecesPlaced` (`null|int`, `Positive`); `time` loses `NotBlank` on create and gets a class-level constraint "either `time` or `piecesPlaced`". With `piecesPlaced`, a given `time` is the total time. Same domain exceptions → 422 with the same messages. A competition is required (`roundId`, as today the API's only way to name one).
- `SolvingTimeResponse`, `PlayerResultResponse`: + `piecesPlaced`, `finishedLaterSeconds` (camelCase props, snake_case JSON by the converter).
- Docs (`docs/features/api/`): "a `null` time is a relax solve **unless** `pieces_placed` is set".
- Statistics/unsolved endpoints inherit tier 2 through their queries.

## Tests — what makes this "100 % reliable"

1. **`UnfinishedResultsAreInvisibleTest` (differential).** Capture the output of every tier 1 + tier 2 read model for a fixture player and puzzle; insert unfinished results (solo/duo/team × with/without total time, on a puzzle the player has also finished and on one they have not); run `PuzzleStatisticsCalculator` and the intelligence recalculation; capture again; **assert identical**. This is the test for "we do not negatively impact anything".
2. **`SolvingTimeReadersAreClassifiedTest` (ratchet).** Scans `src/` for `puzzle_solving_time` / `PuzzleSolvingTime` repository reads and fails for any file not listed in the test's classification map (`performance` / `solve-count` / `history` / `neutral`). A future query cannot silently treat null time as relax — whoever adds it has to decide.
3. **Domain:** `UnfinishedResultTest` — every rule and boundary (0, 1, count−1, count, limit−1 s, limit, limit+1 s, no limit).
4. **Handlers:** add + edit, solo/duo/team, with/without total, all six transitions, orphan guard, unknown competition, entity invariant.
5. **Forms (WebTestCase, real POSTs):** the existing matrix is unchanged and still green — speed without competition still requires a time, relax, collection, stopwatch finish; plus every new validation error answers 422 with the message on the right field; hidden-field posts in relax/collection are ignored; edit round-trip keeps `pieces_placed` (the silent-relax-conversion regression test).
6. **Hint endpoint:** match, category mismatch (the "same puzzle for Individual and Pairs" competitions), no rounds, hidden puzzle, garbage ids, anonymous → 401/redirect.
7. **Display:** puzzle page bottom rows (order, "finished in", both-kinds player, filters, private), my attempts with mixed kinds, badges in the five lists, "only relax" filter, recap panel, export columns, wishlist not removed.
8. **API:** create/update with `piecesPlaced`, validation errors, response fields, scopes unchanged.
9. **Panther (local, not CI):** pick competition → question appears; "No" → relabelled time, live "pieces left"; round chip for a fixture round; over-limit nudge; within-limit error + Clear time; modal edit flow.
10. Fixtures: `PuzzleSolvingTimeFixture` factory gains `finishedLaterSeconds`; two unfinished WJPC 2024 fixture rows (documented in `.claude/fixtures.md`).

## Delivery order

Each step is deployable on its own and safe because **no unfinished row can exist until step 4**.

1. **Isolation (no visible change):** `CountsAsSolve`, tier 2 conditions, CHECK-constraint migration (if approved), tests 1 + 2. Verifiable on production immediately: every number must be unchanged, since there are 0 such rows.
2. **Display:** badge partial, five lists, calendar label, puzzle page bottom rows + my attempts, recap panel, export, API read fields. Still invisible (0 rows) — verified locally with fixtures.
3. **Write path backend:** value object, resolver `resolveFor`, messages, handlers, entity invariants, wishlist handler, exception mapping, edit-mode detection. No UI yet.
4. **Form UI + hint endpoint + Stimulus + API write**, translations (English; other locales via the missing-translations run). This is the release.
5. **Docs:** fold this file into `round-results.md` (correcting its two wrong statements, below), `CLAUDE.md` round-results line, `.claude/fixtures.md`, API docs.

Service worker: no `CACHE_VERSION` bump (only content-hashed `/build` assets change).

## What changed against the earlier plan

| Earlier (`round-results.md`) | Now | Why |
|---|---|---|
| "Every … statistic … reads only `seconds_to_solve`, so unfinished results are excluded without touching those queries" | True for times and ratings, **false for counts** — ~30 reads count rows regardless of time, incl. the `puzzle_statistics` precompute | audit 2026-09-18 |
| Test: "an unfinished result changes no puzzle statistic" | Only achievable with the `PuzzleStatisticsCalculator` change in step 1 | same |
| Unfinished results keep counting as solved | **Not a solve** (tier 2) | counting touches total pieces, search order, wishlist, unsolved lists, picker |
| Checkbox, revealed via the one-way `toggle` controller | Yes/No question with its own controller | `toggle` cannot un-toggle; a default-visible "Yes" is clearer |
| No awareness of the round's limit | Round hint + within-limit error + over-limit nudge | catches the "type the limit" workaround at the source |
| Hidden in the stopwatch finish flow | Available; stopwatch time becomes the total | a stopwatch past the limit *is* "finished afterwards" |
| Pieces upper bound in the form types only | Domain value object shared with the API, + entity invariant, + DB CHECK | one rule, enforced where it cannot be bypassed |
| — | Edit form would silently convert unfinished → relax (`EditTimeController:82`) | found in audit; fixed in step 3 |
| — | Activity calendar / streak, notification dispatch, export, recap `_history` null arithmetic, API DTOs | found in audit |

## Open decisions for Jan

1. **Not a solve** (tier 2) instead of "keeps counting" — recommended, it is the only option that changes no existing number.
2. **DB CHECK constraints** by hand-written migration — recommended; needs an explicit OK because migrations are otherwise generated.
3. **Stopwatch flow offers the question** — recommended.
4. **Counts as an active day** for calendar and streak — recommended.
5. **Favourites are notified** about an unfinished result (labelled "479/500 pcs") — recommended; at a championship that is exactly the news followers want.
6. **Requires a listed competition** — recommended to keep; someone at an unlisted local event cannot record an unfinished result until the event exists.

## Later

- The ~85 historical rows (75 with pieces in the comment, 8 over the limit, 2 exactly at it): a one-off review list for Jan, or a gentle prompt to their owners on the edit form ("this time is over the round's limit — was it unfinished?"). They currently sit in statistics as finished times.
- Censored-data handling in difficulty/skill once a few hundred unfinished results exist.
- `qualified` marks, round name on badges (phase 2 of round results).
