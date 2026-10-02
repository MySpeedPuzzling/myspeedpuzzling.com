# Duplicate results ("saved twice")

GitHub #221 · status: **P1 (Layer 1), P2 (detection + admin) and P4 (review page, automatic removal, banner) built**,
the rest planned (analysis 2026-10-01/02).

Players end up with the same result saved more than once. A copy counts twice in "completed N×", activity, player
statistics and recaps, feeds Puzzle Insights a second attempt that never happened (attempt numbers, improvement
ratios, predictions) and shows up as a false "two first tries" conflict (`docs/features/first-try-integrity.md`).

The plan has four layers plus reporting:

1. **A save happens once** - a resent form never creates a second row (invisible to the player).
2. **Check while adding** - same time already saved → confirm (same day) or an information line (other day).
3. **Catch what gets through** - stored cases, a review page, and automatic removal of the few cases that are
   certain (always reversible, always told).
4. **Close the side doors** - edit can change the puzzle, API idempotency, the catalogue signal.

Plus: a banner, a paced weekly "Your results" e-mail, and an admin overview with the numbers.

## What production shows

All numbers measured read-only on production (530,267 results) on 2026-10-01/02. Queries at the end.

### An identical time is strong evidence, not proof

"Twin" = same player, same puzzle, same `seconds_to_solve`. To tell copies from genuine repeats, exact matches are
compared with near misses: pairs 1 s apart are certainly two different solves, and half of their count is what pure
chance puts on the identical second.

| Same player + puzzle | 0 s apart | 1 s | 2 s | 3–6 s |
|---|---|---|---|---|
| same day | **597** | 101 | 108 | ~100 each |
| different day | **604** | 814 | 876 | ~900 each |

- Same day: ~50 of 597 are coincidences, **~545 are copies (~92 %)**.
- Different day: chance alone explains ~400 of 604 → **mostly genuine** - never flagged afterwards. Exception:
  different days but saved within an hour of each other (72 found vs ~18 by chance → mostly a wrong date).
- 243 same-day twins were saved again sooner than the puzzle could have been solved again
  (`tracked_at` gap < `seconds_to_solve`).
- Near misses show no excess at small differences, so "a typo fixed by re-entering" is not a pattern -
  **fuzzy matching on almost-equal times is not worth it**.
- 8 triplets; 60 relax trackings (no time) saved twice on the same day.

### When the copy was saved (same-day twins)

| Saved again within | Twins | Identical in every field* | …and not a practice session**, nothing saved in between | Genuine coincidences expected among them |
|---|---|---|---|---|
| 5 s | 11 | 11 | 8 | ≈ 0 |
| 10 s | 27 | 26 | **21** | **≈ 0.15** |
| 60 s | 137 | 119 | 98 | ≈ 1.75 |
| 2 min | 180 | 148 | 120 | |

\* same finish date, group, competition + round, first try, unboxed, comment, photo yes/no.
\** "practice session" = the player has other results of that puzzle on the same day. That is where genuine
near-identical times live: 128 of the 163 real different solves saved within 60 s of each other are short puzzles
solved repeatedly. The last column = such real solves in the window (outside practice sessions), divided by 20.

- Fast twins are **not double taps**: 6 under 3 s, the peak is 10–60 s (111). 152 of 181 have no photo (not slow
  uploads), 5 involve a stopwatch, 3 a newly created puzzle.
- **How fast can a person save twice?** The fastest two *different* timed results of one puzzle ever saved are 6 s
  apart (player `018f9200-bc43-71bf-9883-ce04022ee93e`, four attempts of a 4-minute puzzle on 2025-05-05) -
  "Add time → the same puzzle" on the recap preselects the puzzle, only the time is typed. So above ~6–10 s a person
  *could* have typed it again; below 10 s it is the same form sent again.
- Re-sends never have anything else saved in between: 0 of 27 under 10 s, 5 of 110 at 10–60 s.
- Twins saved more than a day apart (299): **every** later copy was back-dated to the first copy's day (median 44
  days later, 204 both marked first try) - people catching up on their history twice.

### Kinds of duplicate beyond "same tracker"

Compared with chance the same way (exact vs near misses / 20):

| Situation | Same time | By chance | Copies |
|---|---|---|---|
| Teammate copy - the same registered people, different tracker, same day | 37 | 0.5 | ~99 % |
| Teammate copy - overlapping people (a guest instead of B, a member missing), same day | 52 | 0.3 | ~99 % |
| B's solo result + a pair/team result with B, same day | 20 | 0.4 | ~98 % |
| Teammate copy - the same people, different day | 6 | 0.6 | ~90 % |
| Same tracker, same group, same day | 44 | 1.9 | ~96 % |
| Same tracker, same group, different day | 21 | 4.2 | ~80 % |
| Solo, different days | 13 | 10.9 | mostly chance |

- B is never told that A logged their pair (no notification on add), so B logs it again; B's duo count shows 2×.
- **The same time on two different puzzle records** (same player, same day, same piece count): 446 vs ~150 by chance
  → ~290 results over 280 puzzle pairs. 65 of them had both puzzle records created in the same minute as their
  result (the "add new puzzle" form sent twice → a duplicate puzzle record); many others picked the wrong puzzle and
  added the result again on the right one, because the edit form cannot change the puzzle. **These are not
  duplicate results** - they are a catalogue signal (see Layer 4) and become twins only once the puzzles are merged.
- Puzzle merges are a minor source (18 twin pairs involve a moved result, issue #221); player deletion
  (`transferOwnership`) and guest → player linking can also create twins. None needs its own logic - detection picks
  up whatever they create.

## Causes in the code

1. **The save went through, the answer never arrived, the form unlocked.** `submit_prevention_controller.js:22,63`
   releases the lock on every `turbo:submit-end` - also for failed and aborted fetches - so a dropped mobile
   connection leaves a filled form with a live button. Every POST gets a fresh id (`PuzzleAddController.php:341`,
   `CreateSolvingTimeProcessor.php:53`), so the server cannot recognise a retry. Fits the 10–60 s peak.
2. **The lock also opens early on success**: Turbo fires `submit-end` when the recap's headers arrive, before it
   renders. A second tap aborts the first request in the browser only - the server already committed it.
3. **Deliberate re-entry**: back to the form, or "Add time → the same puzzle" on the recap
   (`added_time_recap.html.twig:15`), because the person doubts it saved, forgot to tick something, or picked the
   wrong puzzle (edit has no puzzle field).
4. **Re-entry long after**: catching up on history; teammates logging the same pair.
5. **Side doors**:
   - Stopwatch save commits the result (`PuzzleAddController.php:349`) before `FinishStopwatch` (`:368`) runs in a
     separate transaction; if finishing fails, `handleException()` (`:469-489`) shows *any* unknown error as
     "too high pieces per minute" on a still-filled form → the player saves again.
   - Without JavaScript (failed bundle) the form posts with no lock at all.
   - API v1 has no idempotency.
   - Nothing on the server compares a new result with existing ones; no unique constraint is possible (genuine
     coincidences exist).

## Definitions

A duplicate is judged **per person**, never per row: a person's results are their solo results plus every pair/team
result they are a registered member of (the `mine` set of `GetFirstTryTimes`). Guests are a name, not a person -
they are ignored, exactly as in first-try integrity. Two results are a **twin** when they share a person, the puzzle
and `seconds_to_solve` (not null).

| Tier | Rule | Handling |
|---|---|---|
| **A - certain** | Same tracker, identical in every field, same finish date, saved ≤ 10 s apart, no other same-day result of that puzzle by the player, nothing else saved in between | Removed automatically (reversible) and told |
| **B - strong** | Same-day solo twins outside a practice session; every teammate copy (same or overlapping people, any day); solo + pair/team of the same person on the same day; same tracker + same group on the same day | Asked: banner + e-mail. "Most likely saved twice", the older copy preselected; for groups the other person is named |
| **C - possible** | Same-day twins inside a practice session; same tracker + same group on different days; different days but saved within 1 h | Asked: banner + review page only, neutral wording, nothing preselected. In the e-mail only if one goes out anyway |
| not a duplicate | Same time on two **different puzzles** | Catalogue signal only (Layer 4) |
| not flagged | Solo on different days, saved later | Only the information line in the add form |

Hidden people (blocked, private without the viewer on their allow list) are left out of every line the player sees,
exactly like first-try integrity - a case involving them is still detected and shown to *them*.

## Layer 1 - a save happens once

- **The result id travels in the form.** The add form renders a hidden UUIDv7 for the new result (and one for a new
  puzzle). The controller uses them instead of generating fresh ones; a refused form (422) keeps them.
  `AddPuzzleSolvingTimeHandler`: a row with that id **of the same player** already exists → nothing is created,
  `SolvingTimeAlreadySaved` → redirect to that result's recap with "This result was already saved". Another player's
  id → refused. The primary key also stops two simultaneous requests. `AddPuzzleHandler` the same for the new puzzle
  id - this alone ends the duplicated puzzle records.
- **Safety net in the handler** (no JS, an old open form, API without a key): a new result identical in every field
  to one the same tracker saved ≤ 10 s ago (the Tier A rule) is not created; the existing one is answered instead.
- **Button lock**: stays locked after a *successful* submit until the next page replaces the form; unlocks only on
  failure / 422.
- **Stopwatch**: finished inside the same handler/transaction as the result (or `FinishStopwatch` made idempotent).
- **Error message**: unknown exceptions no longer say "too high pieces per minute" - a generic "could not be saved"
  that does not invite a blind retry.
- **`puzzle_solving_time.created_via`** (nullable enum: `form`, `stopwatch`, `api`), so the admin overview can show
  which path still produces twins. Today nothing records where a result came from.
- Every caught re-send is logged (`result_duplicate_prevention`, kind `resend_caught`).

**As built (P1, 2026-10-02):**
- The ids are plain hidden inputs `time_id` / `new_puzzle_id` next to the Symfony form (like `first_try_resolution`);
  an invalid or missing value gets a fresh UUIDv7. Relax mode uses `time_id` as the tracking id.
- Another player's id: `SolvingTimeIdTaken` / `PuzzleIdTaken` → the generic error, both ids regenerated.
- `resend_caught` is written by a second dispatch (`RecordDuplicatePrevention`) from whoever caught
  `SolvingTimeAlreadySaved` (add controller, API processor) - the refusing handler's transaction is rolled back, a
  row written inside it would vanish with it.
- Safety net = `GetRecentIdenticalSolvingTime` (window `WINDOW_SECONDS` = 10): finish *date*, group compared by
  `puzzling_team.composition_key` (computed from the group, so nothing is created before the check), a round only when
  one was chosen explicitly (otherwise it follows from competition + puzzle + group). Runs before the first-try check,
  so a copy of a first try is answered, not refused as a second first try.
- Stopwatch: `stopwatchId` on `AddPuzzleSolvingTime`; finished after the result in the same handler (already
  finished = no-op; still running = paused at the save, then finished; another player's = 403). `FinishStopwatch`
  had no other user and was removed. The save page keeps refusing an already saved stopwatch (a second tab), except
  the very same form sent again (its `time_id` exists) - that one lands on its result.
- Button lock: stays locked when the submit succeeded **with a redirect** (a full-page save); a 422, a network
  failure and stream/frame answers (edit modal) unlock as before.
- API: the replay answer is built from the stored result (status 201, same fields as a fresh create).
- Accepted gap: two requests with the same id in flight at the same moment - the primary key stops the second row,
  but that request ends in a 500 (the entity manager is closed after the failed flush).

## Layer 2 - check while adding

Extends the first-try check instead of building a second one: `FirstTryAssessor` + `GetFirstTryTimes::ofPlayersOnPuzzle()`
already return every result of the puzzle that anybody in the new result took part in, with seconds, day and people
(one query). The assessment gains a "same time already saved" part; `first_try_check_controller.js` starts sending
the time fields and stops requiring the first-try box to be ticked; the notice partial gets a duplicate block that
renders **before** the first-try one (today a twin of a first try is offered "Make this result my first try", which
is the wrong answer).

- **Same day, same time** → saving needs a confirmation:
  "You saved exactly 1:02:33 on this puzzle today at 14:05 - [View it] · [It's another solve, save it]".
  A teammate's copy: "Petr already saved this pair result - it is on your profile."
  Server side: 422 with the notice unless `duplicate_confirmed` travels with the form (like `first_try_resolution`).
- **Different day** → an information line only ("You have 1:02:33 on this puzzle from 14.09.2026"), no confirmation.
- The edit form gets the same check (date, time and group can collide), excluding the edited result itself.
- "Save it" stores the pair as already confirmed real (no case is ever raised for it); every shown warning and every
  "save it" is logged in `result_duplicate_prevention`.
- **API v1**: no new rejections (no BC break). Optional `Idempotency-Key` header: the result id is derived from it
  (UUIDv5 of player + key), so a retry answers the existing result; the Layer 1 safety net covers clients without it.
  Same-day twins from the API end up as cases (Layer 3).

**As built (P3, 2026-10-02):**
- One read, two answers: `FirstTryAssessor::check(FirstTryEntry, firstAttempt)` reads `GetFirstTryTimes::ofPlayersOnPuzzle()`
  once (now also `tracked_at` + tracker) and returns `Value\ResultEntryCheck` = the first-try assessment (only when
  the tag is ticked) + `Value\DuplicateAssessment` from `Services\DuplicateResults\DuplicateAssessor` (pure, only
  with a time). Neither tag nor time = no query. `FirstTryEntry` carries the entered seconds (and, for an edit, the
  previous seconds and day). Hidden people are filtered before either part sees the rows.
- Lines (`DuplicateNoticeLine`): `own` (the viewer saved it: "today at 14:05" when saved today, else the day; a
  pair/team gets "– pair with …"), `with_viewer` (somebody else saved a pair/team with the viewer: "Petr already saved
  your pair result … – it is on your profile"), `teammate` (a result of a teammate of the new result without the
  viewer). "View it" opens `puzzle_result_detail` in a new tab - only when the viewer took part or nobody in it is
  hidden.
- **Edit tolerance**: an edit that changes neither the seconds, the day nor adds anybody is never asked (the twin is
  older than the edit; a confirmation would record an old copy as `saved_anyway`). The lines are shown as info.
- Live check: `first_attempt=0|1` (missing = ticked, what the old script sent), `seconds`, `duplicate_confirmed`;
  the script asks when the puzzle is known and the tag is ticked or a time is entered. "It's another solve" is
  remembered for exactly the puzzle/time/date/co-puzzlers it was given for, any change resets it.
- The add form sent again after its result was saved (its `time_id` is among the rows) is not checked at all - the
  handler answers the resend (Layer 1); otherwise its own twin would refuse it.
- Server: the duplicate refusal comes before the first-try one (`ResultEntryCheck::firstTryBlocks()` is false while
  a same-day twin is unanswered), so a copy of a first try is never offered "Make this result my first try".
- `result_duplicate_prevention.time_id`: `warning_shown` = the result being entered (the add form's `time_id`, so a
  later `saved_anyway` row carries the same id; the edited result for an edit), recorded on every 422 that shows the
  same-day block (not by the live check - a GET writes nothing). `saved_anyway` is written by the add/edit handler in
  its own transaction, only when the controller saw a same-day twin and the player confirmed (a stale confirmation
  without a twin records nothing). P4 turns `saved_anyway` rows into `both_real` cases.

## Layer 3 - cases, review page, automatic removal

### Detection

- Daily cron `myspeedpuzzling:detect-duplicate-results` (~1.3 s for the whole site, measured; 878 per-person pairs
  today) inserts new cases, closes cases that no longer match as `gone` (edited, deleted, merged away) and plans the
  e-mails. First run = backfill (`detected_by = backfill`), sends nothing by itself.
- Saving also writes: "It's another solve, save it" → case stored as `both_real` right away.

Settled while building it (P2):

- **Kinds**: `same_tracker` = solo twins of one tracker on the same day; `same_tracker_group` = one tracker saved a
  pair/team result twice - also when the two groups differ but both contain the person (a guest added later), which
  is Tier B on the same day and otherwise only `saved_within_hour`; `teammate_copy` = two different trackers, both
  pair/team results; `solo_and_group` = the person's solo result + a pair/team result with them (B on the same day,
  otherwise only `saved_within_hour`); `saved_within_hour` = different days, saved < 1 h apart.
- **Practice session** = the person has another result of the puzzle on either day with a *different* time (or
  none). A third copy of the same time is not a practice session - otherwise a triplet of re-sends would only be C.
  The practice-session demotion to C applies to same-tracker solo twins only; a same-tracker group on the same day
  stays B as in the table (and is A only outside a practice session).
- "Nothing saved in between" is checked only for same-tracker pairs saved within an hour (it decides only Tier A).
- `GetDuplicateCandidates` runs two statements: the self-join finds the pairs, a second one reads the details of
  just those (~1k). As one statement the planner's estimate for the per-pair subqueries made JIT compilation alone
  double the run time. Measured on a local copy of production (523k results): 2.0 s in total vs 2.3 s for the
  plain self-join on the same machine; 939 per-person pairs, 593 people, every pair classified: A 25, B 753, C 161.
- A case that is `gone` gets `resolved_at` (when it was noticed) and no `resolved_via`. Admin tabs: Resolved =
  `copy_deleted` + `auto_removed`, Confirmed real = `both_real` + `undone`. Cards and the tier/kind tables count
  cases (per person); the monthly trend and the gap classes count pairs of results.

### Review page

`/{_locale}/first-try-conflicts` becomes **"Review your results"** (`/{_locale}/review-results`, old URL 301),
translated into all 6 locales like the rest of the player-facing UI (the first-try texts already are):

1. **Saved twice** - Tier B first, then C. Each case shows both copies (date, time, group, photo, comment, first try,
   when saved, "same time to the second"). Actions:
   - **"Keep this one"** - deletes the other copy and carries over what only the deleted copy had: photo, comment,
     first-try tag, competition. Tier B preselects the older copy.
   - **"Both are real"** - case closed for this person (others in a group decide for themselves).
   - Only the tracker can delete a copy. A teammate copy usually has two trackers → each sees "Delete my copy"; the
     first deletion closes the case for both. A copy tracked by someone else: "Only Petr can delete it." No "remove
     me from this group" here - it would turn the tracker's pair into a solo time (`group-time-editing.md`).
2. **Removed automatically** (last 90 days) - each with **Undo**.
3. First-try conflicts and late first tries as today. Deleting a twin often resolves a first-try conflict too.

The recap after saving shows a twin straight away with the same actions. The player's results list marks open cases
("Possibly saved twice").

### Automatic removal (Tier A only)

- Keeps the **older** copy (the one that committed first). Before deleting, `result_auto_removal` stores the full
  snapshot (incl. team members) and the kept copy's id; links to the removed id (recap, shared result image) redirect
  to the kept one.
- **Undo** recreates the row with its original id from the snapshot; the case becomes `undone`.
- Runs once for the existing 21, then daily as a safety net (after Layer 1, nothing from the web form should reach
  it - only concurrency or API clients without a key).
- The **undo rate** is the quality measure. Widening the 10 s window is decided by data only (e.g. ≥ 99 % of the
  10–60 s cases end in "delete the copy" over a few months).

### As built (P4, 2026-10-02)

- **One detection code path**: `DuplicateCaseRecorder` (classify → skip known pairs → store, a result with a
  `result_duplicate_prevention` row of kind `saved_anyway` *of that person* → the case is stored `both_real` /
  `resolved_via = form`, in the daily run too). Used by `DetectDuplicateResultsHandler` (all results) and
  `DetectDuplicateResultsOnSave` (one result).
- **Save-time detection** = `DetectDuplicateResultsOnSave`, a sync handler of `PuzzleSolved`,
  `PuzzleSolvingTimeModified` and `PuzzleSolvingTimeDeleted` (postFlush, inside the save's transaction):
  `GetDuplicateCandidates::ofPeopleOnPuzzle()` for the result's people on its puzzle, keeping only pairs with this
  result; an edited result's open cases that stop matching → `gone`; a deleted result's open cases → `gone` (the event
  now carries the result id). Never removes anything. **Failure never costs the save**: it runs in its own savepoint,
  every read comes before the first write, a failing query is rolled back to the savepoint and logged as a warning.
  It stores only pairs that include the result just saved - for an added result a row nobody else can see before the
  commit, so the daily run cannot insert the same case concurrently (no unique-key failure at the flush). Cost of a
  save without a twin: the savepoint pair + one indexed query (3 statements). It also runs while fixtures load, so the
  test database starts with `DuplicateResultsFixture`'s cases (`.claude/fixtures.md`).
- **Automatic removal**: the daily handler dispatches `AutoRemoveCertainDuplicate` for every open Tier A case; the
  handler re-runs the scoped candidate query + classifier and removes only if the pair is still Tier A (else the case
  stays open). `result_auto_removal.player_id` = the **tracker** of the removed copy (only they may undo); every
  person's case of the pair → `auto_removed`; other cases with the removed copy (a triplet) → `gone`. Snapshot =
  `Value\RemovedResultSnapshot` (every column incl. the `team` JSON and prediction fields; the puzzling team is
  resolved again from the group on Undo).
- **Undo** (`UndoAutoRemoval`): `PuzzleSolvingTime::restore()` - same id, same row; records
  `PuzzleSolvingTimeModified`, not `PuzzleSolved` (statistics and insights follow; no second follower notification,
  no wishlist change). A competition deleted meanwhile is dropped, the round is re-derived. Cases of the pair →
  `undone`.
- **Keep** (`KeepDuplicateCopy`) deletes the other copy only when the viewer tracks it; with both copies the viewer's,
  `PuzzleSolvingTime::takeOverFrom()` first fills what the kept one lacks (photo, empty comment, first-try tag,
  competition; round re-derived; a group copy notifies the other members like any edit). Both copies must still
  exist (else `DuplicateCaseChanged`, nothing deleted). Every open case with the deleted copy → `copy_deleted`.
- Review page `review_results` (`/{_locale}/review-results`), POST routes `review_results_keep_copy`,
  `review_results_both_real`, `review_results_undo_removal`; the first-try POST routes kept their paths and redirect
  to the review page, `first_try_conflicts` answers 301. A case is shown only while both copies exist. Recap:
  `GetPlayerDuplicateCases::openOfTime()` (one query, + one when there is a case); `via=recap` returns to the recap.
  Removed ids: `added_time_recap` and `result_image` answer 301 to the kept copy (`ResultAutoRemovalRepository`,
  looked up only after a not-found).
- Banner `templates/review_results/_banner.html.twig` on the Hub and the own profile, one query
  (`GetPlayerReviewCounts`: open cases with both copies + removals in 30 days not undone + the first-try conflict
  count as a scalar subquery of `GetFirstTryTimes::conflictCountSql()`). Replaces the profile's first-try banner.
- Not built yet: the "Possibly saved twice" marker in the results list (`docs/TODO.md`).

## Telling players

### Banner

On the **Hub and the player's own profile** (other viewers pay no query, like the first-try banner): open Tier B/C
cases, and copies removed automatically in the last 30 days. **No bell notification** - the bell is flooded for
players with many favourites.

### Weekly "Your results" e-mail

A separate e-mail, not part of the chat digest (that one is triggered by unread messages and follows its own
frequency setting). Built from sections so first-try conflicts or guest-link requests can join later.

- **First e-mail** = backlog version ("We checked all your results…"), sent in waves (see Sending).
- After that **at most weekly and only when something is new**:
  - copies removed automatically since the last e-mail - told **always and once**, even to players who ignore review
    e-mails (we changed their data);
  - new review cases - each case **at most once in an e-mail ever**.
- Up to 3 cases listed (puzzle, time, date), one "Review" button, plain-text part, no images, no tracking pixels.
- **No action links** - mail scanners open links in advance (Outlook Safe Links once consumed sign-in links).
  "Review" and "Undo" only open the page; the action is a POST there. The link carries `?from=rc-<contactId>`
  (own domain, no redirect service) so the visit is attributed.

### Contact rules (no nagging)

1. A player who did not react to the last e-mail gets **no further review e-mails**; the banner stays.
   (Automatic-removal notices still go - see above.)
2. A player who reacted (resolved or confirmed anything) may be e-mailed again for **new Tier B cases only**, at
   most once every 30 days.
3. Never about cases already part of an e-mail.
4. The **first e-mail** (the backlog) goes to **everybody** with a case or an automatic removal, active or not -
   active players first in the waves, dormant ones last, so bounces from stale addresses come after the reputation
   has been checked. **Later e-mails** only to players active in the last 3 months (`player_activity_day`).
5. Only with the new **`result_emails_enabled`** - its own switch in edit-profile → "Messaging & Notifications",
   **on by default for everybody**. It is neither the newsletter nor the chat digest, so `newsletter_enabled` and
   `email_notifications_enabled` do not apply to it.

### Sending

Not Listmonk: campaigns send one content to a list (ours is personal per player and would have to be copied into
subscriber attributes), `/api/tx` makes Listmonk a mere relay that we would pace ourselves anyway, Listmonk sends
only as `newsletter@news` (Seznam "Newsletters" folder, replies eaten by the bounce scanner, shared ~9k/day quota),
its unsubscribe means "no newsletter", and the reaction must land in our database.

- Sent by the app through the **`notifications`** transport (`notify@`, like the chat digest) - **never
  `transactional`** (`robot@`: sign-in links and password resets keep their own quota and reputation). All three
  mailboxes share the Seznam relay's IP reputation (Apple blocked it in July 2026), so pacing matters.
- **Planned, then sent**: the cron creates `result_review_contact` rows as `planned`; a sending job (hourly,
  08–20 Europe/Prague) sends at most N per run and M per day. M is a setting, raised without a deploy.
- **Audience** (measured 2026-10-02): 560 players have a case (same day or saved within 1 h, per person incl.
  teams; 879 cases). 15 of them (2.7 %) have e-mail notifications off - the same as all players with results
  (239 of 7,682, 3.1 %). 432 were active in the last 3 months; **418 are both active and reachable** - the upper bound
  of the backlog (Tier C alone never triggers an e-mail, so the real number is lower). 540 of the 545 with
  notifications on still have the default 24-hour frequency, so "on" is mostly the default, not a choice - one more
  reason for the separate one-click unsubscribe.
- **First wave**: 20 e-mails (most active players first) → wait 2–3 days → check bounces (e-mail audit log),
  complaints, reaction rate, and the folder it lands in at Gmail / iCloud / Seznam → then ~50 a day (the backlog of
  ~560 players takes under two weeks). After the backlog: a few a week.
- **One-click unsubscribe**: `List-Unsubscribe` + `List-Unsubscribe-Post` → a POST that switches off
  `result_emails_enabled` only (not the chat digest). Also a toggle in edit-profile.

## Data model

| Table | Purpose |
|---|---|
| `result_duplicate_case` | One row per **person** per pair: `player_id` (the person), `time_a_id`, `time_b_id` (older first), `tier`, `kind` (`same_tracker`, `teammate_copy`, `solo_and_group`, `group_other_day`, `saved_within_hour`…), `detected_at`, `detected_by` (`backfill`/`cron`/`save`), `status` (`open`/`copy_deleted`/`both_real`/`auto_removed`/`undone`/`gone`), `resolved_at`, `resolved_via` (`review_page`/`recap`/`form`/`automatic`/`admin`), `snapshot` (puzzle, seconds, days, gap, differences). Unique (`player_id`, `time_a_id`, `time_b_id`). **No FKs on the result ids** (like `puzzle_moderation_decision`) - the row outlives the deletion it records |
| `result_auto_removal` | Snapshot of every automatically removed copy + kept id, `undone_at`, `reported_at` (in which e-mail) |
| `result_duplicate_prevention` | Append-only: `player_id`, `kind` (`resend_caught`, `warning_shown`, `saved_anyway`), `time_id`, `puzzle_id`, `created_at`, `via` |
| `result_review_contact` | One row per contact: `player_id`, `type` (`first`/`weekly`), `status` (`planned`/`sent`/`skipped`), `planned_at`, `sent_at`, `email_audit_log_id`, case + removal ids, `page_visited_at`, `reacted_at` |
| `player.result_emails_enabled` | bool, default true |
| `puzzle_solving_time.created_via` | nullable enum (Layer 1) |

All state changes go through Messenger handlers (`DismissDuplicateResult`, `KeepDuplicateCopy`,
`UndoAutoRemoval`, …); the cron only dispatches.

## Admin overview - `/admin/duplicate-results`

Admin only (`ADMIN_ACCESS`, not moderators - players' own results), English only, linked in the admin dropdown next
to "Free trial", built like `/admin/free-trial`. Reads the stored tables (fast); live scans only in the cron.
Ships right after the backfill, **before** Layers 2–3 reach players, so the starting numbers are recorded.

**Statistics**
- Cards: open cases, players affected, resolved (30 days), confirmed real, removed automatically (+ undone),
  re-sends caught (30 days), "saved anyway" after a warning.
- Kind × status, tier × status.
- Monthly trend: new twins (by when the later copy was saved) next to prevented saves - the success measure (twins
  saved within 2 minutes: 5–19 a month today, target ≈ 0).
- Gap classes (under 10 s … more than a day), by `created_via` once it exists.
- Per tier: share confirmed real (the real precision of each tier).
- **Contacts funnel**, by wave: planned → sent → accepted by SMTP → page visited → reacted → everything resolved;
  median time to react; unsubscribes; players who stopped getting e-mails (ignored).
- Totals for communication: results cleaned up (by players + automatically), players who fixed their stats.

**Lists**
- Cases: tabs Open · Resolved · Confirmed real · Gone, filter by tier/kind, paginated. Columns: person (profile +
  moderation history), puzzle, time, day(s), both copies with when saved, gap, what differs, detected / e-mailed
  dates, status.
- Removed automatically: snapshot, kept id, undone yes/no.
- Catalogue signal: puzzle pairs with the same time from the same person → **"Propose merge"** (opens a merge
  request in the existing queue). No admin delete of results - players own them.

## Layer 4 - side doors

- **Edit can change the puzzle** (with the same first-try and duplicate checks), so a wrong pick is not fixed by
  adding the result again.
- **Catalogue signal**: same person, same time, same day, two puzzles with the same piece count → shown to moderators
  as a merge candidate; once merged, normal detection finds the twin.
- API idempotency (Layer 2).

**As built (P6, 2026-10-02):**
- **Puzzle change in edit** - only the tracker (`EditPuzzleSolvingTimeFormType` option `can_change_puzzle`; for the
  other members brand + puzzle are disabled fields, so whatever they send keeps the puzzle, and they read "Only the
  puzzler who saved this result can change its puzzle"). The chosen-puzzle card gets "Change puzzle", which opens the
  add form's brand → puzzle picker (`time_form_autocomplete_controller.js` with `allowNew = false`: no new brand, no
  new puzzle, no scanner; the brand's puzzles are fetched only when the picker opens, `optionsOnDemand`). The edit form
  lost its unused new-puzzle fields. Server side: a UUID of an existing puzzle (`edit_time_puzzle.choose_from_list`
  otherwise, 422, the picker comes back open); the card then shows the picked puzzle.
- `EditPuzzleSolvingTime::$puzzleId` (null = stays; the API never sends it). The handler refuses a move by anybody but
  the tracker (`CanNotModifyOtherPlayersTime`), runs the first-try guard and the PPM check against the new puzzle and
  calls `PuzzleSolvingTime::moveToPuzzle()` before `modify()`: it records `PuzzleSolvingTimeMovedToOtherPuzzle` (the
  puzzle it **left**, its tracker and piece count - consumed like a deletion by the statistics and incremental
  insights handlers, routed `sync`) and forgets the prediction; `modify()`'s `PuzzleSolvingTimeModified` then names
  the new puzzle, the round is re-derived after `modify()` as before, `reconstructIfPending()` queues the backfill, and
  a pair/team gets the usual `GroupSolvingTimeEdited`.
- On another puzzle the result counts as **new there**: neither the first-try tolerance (unchanged tag and people) nor
  the duplicate edit tolerance (unchanged time, day, people) applies - `FirstTryFormCheck::forEditedResult(…, $puzzleId)`
  and the handler pass no "before the edit" values. The live check takes `puzzle` on an edit only from the tracker.
- **Catalogue signal** - `DetectDuplicatePuzzleSignals` runs right after `DetectDuplicateResults` in
  `myspeedpuzzling:detect-duplicate-results` (no new cron). `GetDuplicatePuzzleSignalCandidates` = one statement, per
  person like the case detection (solo by tracker + registered team members), same seconds + same day + same piece
  count, pair ordered by id; a full pass is unavoidable ("any two puzzles"), the self-join is a merge join on
  person + seconds + day: ~1 s on the dev copy of production (523k results → 458 pairs; about a third chance).
- `duplicate_puzzle_signal` (`DuplicatePuzzleSignal`): as planned + `merge_request_id` (no FK) so the proposal can be
  traced; the example player has no FK either (only shown). A new run refreshes open signals, **deletes open ones that
  no longer match** (no decision to keep), never touches `merge_proposed` / `dismissed` (the pair is never raised
  again). Puzzle FKs cascade, so an approved merge removes the signal.
- Admin: section "Possible duplicate puzzles" (`templates/admin/_duplicate_puzzle_signals.html.twig`, 30 per page,
  most matching results first). "Propose merge" → `ProposeDuplicatePuzzleMerge` dispatches the regular
  `SubmitPuzzleMergeRequest` (puzzle A = source, reporter = the admin) in the same transaction and lands on the merge
  request detail; "Dismiss" → `DismissDuplicatePuzzleSignal`. Both refuse an already handled signal
  (`DuplicatePuzzleSignalAlreadyResolved` → flash).

## Performance

Nothing that runs on a page view scans results site-wide; everything heavy is precomputed by the cron.

| Where | What runs | Budget |
|---|---|---|
| Add/edit form live check | the first-try read model (`GetFirstTryTimes::ofPlayersOnPuzzle`), **one** query by `puzzle_id` - the duplicate part reuses its rows, no second query | unchanged from today |
| Add handler safety net | one indexed lookup (`player_id`, `puzzle_id`) on the existing `custom_pst_player_puzzle_type` index | < 1 ms |
| Hub / own profile banner | one count on `result_duplicate_case` (`player_id`, `status`) + `result_auto_removal` (`player_id`, `removed_at`), folded into **one** query; other viewers pay **nothing** (guarded by a query-count test like `FirstTryPagesTest`) | < 1 ms |
| Review page, recap notice | stored cases for one person + their two results by id | a few indexed queries |
| Admin overview | aggregates over the stored tables (~1k rows), no result scans | < 50 ms |
| Detection cron (daily) | the per-person self-join over all results (~1.3 s measured on production) | daily only |
| E-mail planning / sending | stored cases + contacts | trivial |

Query-count tests pin the banner (own vs other viewer) and the form check (no extra query).

## Implementation spec (binding names)

Namespace `SpeedPuzzling\Web\`. Conventions from `CLAUDE.md` apply everywhere (single-action controllers, every
state change through a Messenger handler, repositories never flush, `ClockInterface`, `Uuid::uuid7()`,
migrations generated by Doctrine against a scratch DB built from committed migrations, tests on handlers not
commands, all player-facing text in **6 locales** `cs en de es fr ja`, admin English only).

**Values (enums)** - `Value\SolvingTimeSource` (`form`, `stopwatch`, `api`) · `Value\DuplicateTier` (`certain` = A,
`strong` = B, `possible` = C) · `Value\DuplicateKind` (`same_tracker`, `same_tracker_group`, `teammate_copy`,
`solo_and_group`, `saved_within_hour`) · `Value\DuplicateCaseStatus` (`open`, `copy_deleted`, `both_real`,
`auto_removed`, `undone`, `gone`) · `Value\DuplicateDetectedBy` (`backfill`, `cron`, `save`) ·
`Value\DuplicateResolvedVia` (`review_page`, `recap`, `form`, `automatic`, `admin`) · `Value\DuplicatePreventionKind`
(`resend_caught`, `warning_shown`, `saved_anyway`) · `Value\ResultReviewContactType` (`first`, `weekly`) ·
`Value\ResultReviewContactStatus` (`planned`, `sent`, `skipped`).

**Entities** - `Entity\ResultDuplicatePrevention` (`result_duplicate_prevention`) · `Entity\ResultDuplicateCase`
(`result_duplicate_case`) · `Entity\ResultAutoRemoval` (`result_auto_removal`) · `Entity\ResultReviewContact`
(`result_review_contact`) · new columns `puzzle_solving_time.created_via` (nullable `SolvingTimeSource`) and
`player.result_emails_enabled` (bool, default true). Result ids in these tables are plain UUID columns without FKs;
`player_id` is a FK with `ON DELETE CASCADE` (GDPR deletion takes them along).

**Layer 1** - `AddPuzzleSolvingTime` gains `createdVia` and `stopwatchId` (the stopwatch is finished inside the same
handler, already finished = no-op). New `Exceptions\SolvingTimeAlreadySaved` (carries the existing `timeId`) thrown by
`AddPuzzleSolvingTimeHandler` when the id already exists for the same player, or when the same tracker saved an
identical result ≤ 10 s ago (`Query\GetRecentIdenticalSolvingTime`). The add form carries hidden `time_id` and
`new_puzzle_id` (UUIDv7 rendered on GET, kept on 422, validated as UUID). `AddPuzzleHandler`: puzzle id already
exists and was added by the same player → nothing created. API `POST /api/v1/me/solving-times`: optional
`Idempotency-Key` header → time id = UUIDv5(fixed namespace, `playerId|key`); an existing result answers as today's
201 body of that result (no new field, no BC break).

**Detection** - `Query\GetDuplicateCandidates` (the per-person self-join, returns `Results\DuplicateCandidate` with
everything the classifier needs: both rows' tracker, team id, registered member sets, seconds, finish dates,
tracked_at, comment/photo/first-try/unboxed/competition/round, and whether the person has other same-day results of
the puzzle and whether anything was saved in between) · `Services\DuplicateResults\DuplicateClassifier` (pure, unit
tested: candidate → tier + kind or null; same time on two puzzles never reaches it) · message `DetectDuplicateResults`
(+ console `myspeedpuzzling:detect-duplicate-results`, daily) inserts new cases, marks no-longer-matching open cases
`gone`, auto-removes Tier A (`AutoRemoveCertainDuplicate`), never sends anything itself.

**Player side** - route `review_results` `/{_locale}/review-results` (the first-try conflicts page grows into it;
`first_try_conflicts` keeps working as a 301), POST actions `/{_locale}/review-results/duplicates/{caseId}/keep`
(`KeepDuplicateCopy`), `/{_locale}/review-results/duplicates/{caseId}/both-real` (`ConfirmDuplicateIsReal`),
`/{_locale}/review-results/removed/{removalId}/undo` (`UndoAutoRemoval`) · `Query\GetPlayerDuplicateCases` · banner
partial `templates/review_results/_banner.html.twig` on the Hub and the own profile, fed by one query
(`GetPlayerReviewCounts`), nobody else pays a query.

**E-mail** - `PlanResultReviewEmails` (daily, creates `planned` contacts) + `SendPlannedResultReviewEmails` (hourly
08-20 Europe/Prague, caps per run and per day from parameters `result_review_emails_per_run` /
`result_review_emails_per_day`) → template `templates/emails/result_review.html.twig` (domain `emails`, 6 locales),
`X-Transport: notifications`, `List-Unsubscribe` + `List-Unsubscribe-Post` to a signed one-click POST route
`result_emails_unsubscribe` (`UnsubscribeFromResultEmails`) · switch `resultEmailsEnabled` in the edit-profile
"Messaging & Notifications" form · visits attributed by `?from=rc-<contactId>` (`RecordResultReviewVisit`).

**Admin** - `/admin/duplicate-results` (`admin_duplicate_results`, `ADMIN_ACCESS`, English), `Query\GetDuplicateResultsOverview`.

## Build phases (one PR, one commit per phase)

| Phase | Content | Depends on |
|---|---|---|
| P1 | Layer 1: form ids, `SolvingTimeAlreadySaved`, safety net, stopwatch in the same transaction, button lock, error message, `created_via`, API `Idempotency-Key`, `result_duplicate_prevention` + `resend_caught` logging | - |
| P2 | Case model, candidate query, classifier, detection message + command + backfill, admin overview (cases part) | - |
| P3 | Layer 2: duplicate part of the form check (add + edit), notice, `duplicate_confirmed`, prevention logging, "save it" → `both_real` case | P1, P2 |
| P4 | Review page, keep / both-real / undo, automatic removal + redirect of removed ids, banner (Hub + profile), recap notice, results-list marker, admin removals tab | P2 |
| P5 | Settings switch, weekly e-mail, planning + paced sending, one-click unsubscribe, visit/reaction tracking, admin contacts funnel | P4 |
| P6 | Edit can change the puzzle; catalogue signal + "Propose merge" in admin | P2 |

## Rollout order

1. Layer 1 (+ button lock, stopwatch, error message, `created_via`) - ships alone, stops the fast twins.
2. Data model, detection cron, backfill, **admin overview** - baseline numbers, nothing player-facing.
3. Layer 2 - the form check.
4. Review page + banner + automatic removal of Tier A (with Undo).
5. Weekly e-mail, staged waves.
6. Layer 4 - puzzle change in edit, catalogue signal, API `Idempotency-Key`.

New crons (detection daily, sending hourly) go into `~/www/lily.srv` `cron.d` as part of the work.

## Settled decisions (2026-10-02)

- Automatic removal only for Tier A (≤ 10 s, identical, not a practice session, nothing in between); everything
  else is asked. Reversible, told on the banner and in the e-mail.
- Teammate copies are Tier B; the same time on two different puzzles is not a duplicate.
- "Both are real" is per person.
- E-mail follows only its own `result_emails_enabled` (new switch under "Messaging & Notifications", on by default,
  one-click unsubscribe); a separate weekly e-mail, not part of the chat digest; the first (backlog) e-mail goes to
  everybody with a case, later ones only to players active in the last 3 months; `notifications` transport; paced
  waves (20, then ~50/day).
- Everything player-facing (form notices, review page, banner, recap notice, e-mail, settings) in all 6 locales; the
  admin overview English only.
- API: no new rejections; optional `Idempotency-Key`.
- `created_via` column: yes.

## Open questions

- Retention of `result_review_contact` / `result_duplicate_prevention` (proposal: keep, they are small).

## Queries

```sql
-- same-day twins (same tracker)
SELECT count(*)
FROM puzzle_solving_time a
JOIN puzzle_solving_time b
  ON b.player_id = a.player_id AND b.puzzle_id = a.puzzle_id
 AND b.seconds_to_solve = a.seconds_to_solve AND b.id > a.id
 AND COALESCE(a.finished_at, a.tracked_at)::date = COALESCE(b.finished_at, b.tracked_at)::date
WHERE a.seconds_to_solve IS NOT NULL;

-- chance baseline: replace "b.seconds_to_solve = a.seconds_to_solve" with
--   abs(a.seconds_to_solve - b.seconds_to_solve) = 1   (expected exact matches ≈ count / 2)
--   abs(...) BETWEEN 1 AND 10                          (expected exact matches ≈ count / 20)

-- per-person detection (solo by tracker + every registered team member), ~1.3 s on production
WITH person_result AS (
  SELECT t.id, t.player_id AS person_id, t.puzzle_id, t.seconds_to_solve,
         COALESCE(t.finished_at, t.tracked_at)::date AS solved_day, t.tracked_at
  FROM puzzle_solving_time t
  WHERE t.puzzling_team_id IS NULL AND t.seconds_to_solve IS NOT NULL
  UNION ALL
  SELECT t.id, m.player_id, t.puzzle_id, t.seconds_to_solve,
         COALESCE(t.finished_at, t.tracked_at)::date, t.tracked_at
  FROM puzzle_solving_time t
  JOIN puzzling_team_member m ON m.team_id = t.puzzling_team_id AND m.player_id IS NOT NULL
  WHERE t.seconds_to_solve IS NOT NULL
)
SELECT a.person_id, a.id, b.id
FROM person_result a
JOIN person_result b
  ON b.person_id = a.person_id AND b.puzzle_id = a.puzzle_id
 AND b.seconds_to_solve = a.seconds_to_solve AND b.id > a.id
 AND (a.solved_day = b.solved_day OR abs(extract(epoch FROM b.tracked_at - a.tracked_at)) < 3600);
```
