# Time verification (suspicious time review)

Status: **built (2026-10-07), awaiting go-live** - see "Go-live runbook". Internally the feature is "suspicious time
review" (`SuspiciousTime*` classes, `suspicious_time_*` tables, `puzzle_solving_time.suspicious`); everything a person
reads says **time verification** / **awaiting verification**.

The rule for a flagged time - neither counted nor timed anywhere, only in the player's own results - is
`suspicious-times.md`. This document is about *who sets the flag and how*: a scan proposes, a moderator decides, the
player is told once and can fix it or disagree. Since a mark takes the result out of every count as well, telling the
player and letting them fix it matters even more.

## What production showed (2026-10-07)

### The times flagged by hand

Before the feature existed, 11 times of 9 players had been looked at by hand (one player had three). All are solo
results. "Usual" = the player's baseline for the piece count (`player_baseline`) or, without one, their pace on other
puzzles. The players are anonymised; puzzle names and entered times are left out on purpose.

| | Pieces | Faster than usual | Expectation | Most likely cause |
|---|---|---|---|---|
| Player A | 500 | 6.3× | baseline | hours box left empty; also many pair/team 500s |
| Player B | 1000 | 5.1× | baseline | hours box left empty |
| Player C | 1000 | 5.3× | pace | a team of 4 saved as solo - the comment says so, the teammates saved a team result that day |
| Player D | 1000 | 3.5× | baseline | hours box left empty |
| Player E | 1000 | 2.7× | baseline | hours box left empty - the corrected time matches the puzzle's median |
| Player F | 1000 | 3.0× | pace | unclear (a pair?) |
| Player G | 500 | above 40 PPM | none | first and only result; the fastest 500 recorded otherwise is 30 PPM |
| Player H ×3 | 1000 | 1.3× | prediction | **valid - allowed by Jan** (after a first "not credible" the same day; flags cleared). Each sits within 1-3 % of its prediction and inside the player's own band on that puzzle |
| Player I | 300 | 1.0× | prediction | **catalogue error** - the puzzle was catalogued as 1000, fixed to 300; the time is normal |

The three expected causes all show up (hours left out is the biggest, a group saved as solo, a wrong edition - in the
wider data one comment literally says *"Only 500 and not 1000 piece puzzle"*), plus a fourth: **the puzzle's piece
count is wrong**. That one is not the player's mistake and needs a catalogue fix, not a flag on the player.

### The whole population

Solo results only (pair/team see "Not in v1"). Measured on production and on the local production copy (523k
results, 2026-09-25).

- **77 % of solo times carry a stored prediction** made only from data before the solve
  (`puzzle-intelligence/prediction-history.md`; backfill complete on production: 348k of 452k). It already knows the
  player's baseline, the puzzle's difficulty and the attempt number. Its own error is tight: predicted ÷ entered has
  p99.9 = 1.6 (repeat attempts) - 1.8 (first attempts). On production 217 times are ≥ 2.0× faster than predicted and
  86 are ≥ 2.5×.
- **The other 23 % is where the flagged times are**: all 7 real cases above had no prediction (a rare puzzle without a
  difficulty, or a player without a baseline). A fallback is necessary.
- **"N× faster than my average pace" alone is noisy.** Of 512 times 2.0-2.2× faster than the player's pace, 437 are
  repeat attempts and 167 are on puzzles where *everybody* is that fast (an easy puzzle, or a wrong piece count).
  Difficulty and attempt number must be part of the expectation - which is exactly what the prediction is.
- **"+1-3 hours would fit" is not evidence by itself**: at 2.0-2.2× it fits 392 of 512 times. It is an *explanation*
  for a time already found, never a trigger.
- **Volume**: ~27k solo results a month (Jun-Aug 2026). Backlog ~485 cases (below), then about 5 fast and 2-3 slow a
  week.

## Principles

1. **The scan never marks.** It opens a case for a moderator. `suspicious = true` is set only by a person - in the
   moderator queue or by SQL.
2. **Find, explain, suggest.** Every case carries reasons with their numbers and, where the data allows, the likely
   fix: "3:45:00?", "the 500-piece edition", "add the people you puzzled with".
3. **The player is told once per mark**, can fix it themselves and can disagree.
4. **Versioned.** A check is final for its detector version. A new version re-checks everything a person has not
   decided.
5. **The player's own other times are the evidence** (Jan, 2026-10-07): a time is judged against what this player
   does, never against how fast the community thinks anybody can be. The community scale only makes piece counts
   comparable (pace) and judges a player who has no times of their own yet.
6. **Neutral wording** (Jan, 2026-10-07): nothing a person reads - player pages, e-mails, the moderator queue - says
   "suspicious". A flagged time **needs verification** / is **awaiting verification** (the badge reads "Verification
   needed"). Internal names stay.

## Detection

### The expected time

For each solo time, the first available of:

1. **`prediction`** - the stored prediction (`predictable = true`): baseline × difficulty × attempt, with no knowledge
   of the solve itself. Repeat attempts always have one (the personal method).
2. **`baseline`** - the player's baseline for the piece count (`player_baseline`, direct or derived from other piece
   counts) × the puzzle's difficulty when it has one. These are first attempts without a prediction.
3. **`pace`** - the player's pace on their other solo results within ±180 days of this one (≥ 5 results): each
   result's PPM ÷ the community median PPM for its piece-count range, median of those, × the community median for
   this piece count. This covers players with results but no baseline.
4. **none** - only the absolute check below applies.

`R = expected ÷ entered`.

**A prediction built on a far too slow attempt is not trusted**: a fast raise against a personal prediction whose
earlier attempt is itself far too slow - an earlier attempt of the puzzle has a pending or marked slow case, or the
attempt before (`prediction_last_time_seconds`) is ≥ 5× the baseline / pace expectation, without one below the
community's slow floor - is judged as if there were no prediction; a raise then carries the moderator-only reason
`prediction_from_slow_attempt`. It removed 20 false fast raises from the backlog - honest attempts after a first
attempt with days counted (a few minutes for 99 pieces after a first attempt of 9 hours).

### Triggers (v1)

| Reason | Rule | Tier |
|---|---|---|
| `faster_than_predicted` | R ≥ 2.0 against a prediction (beyond the model's p99.9) | strong from 2.5 or with a fitting explanation |
| `faster_than_usual` | R ≥ 2.5 against a baseline or pace (a looser model needs a higher bar) | strong from 3.0 or with a fitting explanation |
| `beyond_known_pace` | only without an expected time (no prediction, baseline or pace - a new account): PPM above the community's 99.9th percentile for the piece-count range | strong |

**A fast raise needs at least the community's median pace** (`FAST_MIN_COMMUNITY_MEDIAN_SHARE` = 1.0 × the solo median
PPM of the piece-count range): a time slower than a typical puzzler of its range is never raised as fast, whatever the
player's own history says. A history with days counted (a baseline of 40 h for 200 pieces) made an ordinary 42-minute
solve "69× faster"; on the production copy 63 of the 245 fast raises were slower than the median of their range and
none was an obvious mistake - a real typo (hours left out) always lands far above it. Without a reference for the range
the rule does not apply. The form's check is the same classifier.

Thresholds are constants of `SuspiciousTimeClassifier` and part of its `VERSION`.

### Explanations (computed for raised times only)

| Reason | Rule | The player reads | The fix offered |
|---|---|---|---|
| `hours_left_out` | entered + 1-5 h lands within 0.75-1.33 × expected (closest one wins); carries the `entered` time it was worked out for | "Perhaps the hours box was left empty - 3:45:00?" | edit, time prefilled - only while the time is still `entered` |
| `teammates_saved_group` | another player saved a pair/team result of this puzzle the same day, within ±2 min - never a result of somebody the tracker blocked (its tracker or a member), unless the tracker took part in it | "Someone saved this puzzle as a pair/team result that day" | add the people |
| `comment_mentions_group` | the comment has team/pair words (6 languages) | "Your note mentions puzzling with others" | add the people |
| `often_in_group` | ≥ 3 pair/team results and the time fits the player's group pace | (moderator only - a weak hint) | |
| `other_edition` | a puzzle of the same brand with a similar name (every name, trigram ≥ 0.6 - the matching of `DuplicatePuzzleSignalScoring`) and another piece count, where the time fits the player; **never a puzzle a competition keeps secret or a hidden one** | "Maybe it was the 500-piece edition: *name*" | change the puzzle (edit form) |
| `fastest_on_puzzle` | would be #1 on a puzzle with ≥ 5 results | (moderator only) | |
| `new_player` | < 5 results | (moderator only) | |
| `confirmed_while_saving` | the player answered "Yes, it's right" in the form | (moderator only) | |
| `prediction_from_slow_attempt` | see "The expected time" | (moderator only) | |

### The piece count is wrong

A **puzzle card** in the queue groups a puzzle's pending cases of one direction - "Several players are much faster here
than usual - which is it?" - when either

- **≥ 2 different players are raised on it and their raised results are ≥ 20 % of its comparable results** (its
  results of the types raised - solo; pair/team below the slow floor - as `puzzle_statistics` counts them, flagged ones
  left out, the raised ones included). Without the share, popular puzzles collected a card by chance (17 fast and 19
  slow cards on the production copy, 1 and 12 with it); or
- its `difficulty_score` is below 0.5 (fast) or above 2.0 (slow - a catalogued count too low makes everybody slow),

and offers: **the piece count is wrong** - edit the puzzle (the moderator's direct edit); the piece count is part of
each time's fingerprint, so the next run re-checks all of the puzzle's times and the cases close by themselves; **it is
a hard puzzle** (slow cards, below); or **neither** - every case of the card is a full case card inside it, decided
like any other (since 2026-10-08 - before, its cases could only be decided after "the piece count is right" broke the
card up, which nobody understood). The catalogue error of Player I would have ended here instead of on the player.

### A hard puzzle (moderator's slow threshold, 2026-10-08)

Some puzzles take everybody many times longer than their piece count suggests - all of one colour (Krypt Black, 736
pieces: solo median 10:24:12, fastest 6:50:28), "impossible" puzzles, expert editions. Their puzzle difficulty is
usually unknown (too few first attempts by players with a baseline - Krypt Black had 4, `confidence = insufficient`),
so every solver lands 5-13× above their own times. On production 2026-10-08 the slow cards were nearly all such
puzzles (Krypt Black 10 cases, Krypt Universe Glow, Krypt Pink, Frozen 2 Impossible, The Clearly Impossible Puzzle,
Stitch Challenge, Jan van Haasteren Expert ...).

A moderator gives such a puzzle a **slow threshold** on its card: "too slow here only from N× the expected time"
(3-100, prefilled a quarter above the slowest line of the card). It is the moderators' own call, not the computed
difficulty, and applies to every slow rule of the puzzle's times: the prediction's 3×, the baseline / pace 5× and the
community floor's 10× (pair/team results, new players) become max(rule, N); strong from max(10, 2N); "the prediction
was built on a far too slow attempt" uses the same bar. **Fast rules never change.** Bound to the piece count it was
given for (a fix of the count lapses it). Without a threshold nothing differs, so it is no new detector version.

Saving it (`SetPuzzleSlowThreshold`, logged `slow_threshold_set` / `slow_threshold_removed` with the threshold and the
one before) judges the puzzle's undecided times again right away (`DetectSuspiciousTimes` with `onlyPuzzleId` - its
candidates only, the stored references, nothing reconciled): the cases within the new bar are gone, the flash says how
many closed and how many still need a look. Should that run fail, the next scan does it: a check written in or before
the second the threshold was set is not current (`suspicious_time_check.checked_at <= confirmed_at`). The add/edit
form's check reads the threshold too. Times a person decided about stay decided.

### Too slow (same mechanism)

Very slow times are mistakes too (Jan, 2026-10-07) - minutes typed into the hours box (52:10:00 for a 52-minute solve),
or days counted instead of the time spent puzzling. They inflate time totals, averages and the slowest times. Measured
on production:

- The community's pace falls with the piece count, so one floor for all is wrong ("< 2 PPM" would hit 708 normal
  1000-piece solves). Solo median PPM by range: 1-199: 12.7 · 200-499: 8.1 · 500-750: 7.7 · 751-998: 4.1 · 999-1200:
  5.1 · 1201-2000: 3.3 · 2001-5000: 2.4 · 5001+: 2.9. The community reference is therefore kept **per piece-count range
  and puzzling type** (`SuspicionPiecesRange`), for both directions.
- Against the player's own prediction the slow tail is very clean: p99.9 of entered ÷ predicted is 1.53; only 31 of
  348k solo times are ≥ 3× slower, 24 of them ≥ 30×.
- Without a prediction, against the baseline (no difficulty in it): 162 of 95k are ≥ 5× slower, 42 ≥ 10×.
- The typo shape: below 1 PPM ~40 % have no seconds and read sensibly with the boxes shifted; another large group is
  ≥ 24 h (days counted).

| Reason | Rule | Tier |
|---|---|---|
| `slower_than_predicted` | entered ≥ 3× the stored prediction | strong from 10× or with a fitting slow explanation |
| `slower_than_usual` | entered ≥ 5× the baseline/pace expectation | strong from 10× or with a fitting slow explanation |
| `below_slow_floor` | only without an expected time (new player) **or for pair/team results** (no personal expectation): PPM below 1/10 of the community median of its range and type - e.g. 500 pieces > ~11 h, 1000 > ~32 h | strong |

A consistently slow player is never raised: with an expected time only the ratio rules apply. Slow explanations:
`minutes_in_hours_box` (seconds = 0, hours ≥ 1 and H:M:00 read as H min M s fits the expectation, or 0.5-2× the
community median time without one → suggested time, "Perhaps the minutes ended up in the hours box - 52:10?") and
`includes_breaks` (≥ 24 h → "If you puzzled over several days, enter only the time spent puzzling"). Pair/team results
are candidates **only** for the slow floor; everything fast stays solo-only. Each case has a `direction` (`fast` /
`slow`).

### How v1 treats the times flagged by hand

| | Path | R | Tier | Explanation found |
|---|---|---|---|---|
| Player A | baseline | 6.3 | strong | hours, often in a group |
| Player B | baseline | 5.1 | strong | hours |
| Player C | pace | 5.3 | strong | teammates saved a group, comment, hours |
| Player D | baseline | 3.5 | strong | hours |
| Player E | baseline | 2.7 | strong (explained) | hours |
| Player F | pace | 3.0 | strong | hours |
| Player G | none | - | strong | beyond known pace, new player |
| Player H ×3 | prediction / baseline | ≤ 1.3 | not raised | inside the player's own band - valid (Jan) |
| Player I | prediction | 1.0 | not raised | |

All 7 times flagged and mailed are raised; Player H's (valid, Jan) and Player I's are not. `SuspiciousTimeClassifierTest`
pins these shapes with made-up entries.

### The backlog (local production copy, after the prediction backfill)

**422 raised - fast 182, slow 240** (dry run of a first scan, 2026-10-07). Fast: against the prediction 142
(production: 217 before the slow-attempt and the community-median rules), baseline 31, pace 6, beyond known pace 3;
strong 152, possible 30 - the community-median rule took 63 of the 245 fast raises before it. Slow: below the floor 76
(24 solo times of new players, 42 pairs, 10 teams), slower than predicted 31, slower than usual 133 (baseline 120, pace
13); strong 154, possible 86. Explanations: hours left out 124, often in a group 119, fastest on the puzzle 22, other
edition 11, teammates' group 3, comment 1; includes breaks 94, minutes in the hours box 31.

## Checks and versions

- `SuspiciousTimeClassifier::VERSION` (int, like `TimePredictionCalculator::MODEL_VERSION`) - bumped on any change of a
  threshold, a signal or the expected-time chain.
- **`suspicious_time_check`** - one row per checked time: `version`, `outcome` (`clear`, `no_data`, `raised`),
  `fingerprint`, `checked_at`.
- **Fingerprint** (`SuspicionFingerprint`) = md5 of puzzle id, piece count, `seconds_to_solve`, solo/pair/team and
  number of puzzlers; the scan's SQL computes the same string (parity test). A changed fingerprint means a different
  entry: an edit, a move to another puzzle, a merge, a piece-count fix, an SQL repair. Every side door is caught
  without wiring a single event.
- A run checks a time when it has **no row, an older version, another fingerprint, or `no_data` and was solved in the
  last 180 days**. A new player's first result can't be judged yet, but it can be judged once they have a level. After
  180 days, `no_data` is final for the version.
- A run **never** checks a time a person decided (marked or trusted) while its fingerprint is unchanged, whatever the
  version.
- **A marked time whose entry changed without the edit form** (a piece count fixed, a merge, an SQL repair, an edit whose
  re-check failed - the case's fingerprint differs from the time's) is judged again by the scan: unmarked automatically
  when the new entry is clear (any mark, also one set by SQL), otherwise back to the moderators. A merge does not change
  the result, so it must not announce the mark again. The candidates leave flagged times out, so this is a step of its
  own. (A player's edit is different - see "When the player edits a marked time".)
- **Trust belongs to the entry**: an edit that changes the fingerprint of a trusted time lapses the trust, and the new
  entry is checked like any other.
- `--dry-run` prints what the current code would raise (counts by direction, tier, source) without writing anything.
  Run it before deploying a version bump. No CSV: the backlog is reviewed in the moderator queue (Jan).

## Moderator queue - `/admin/time-verification`

Admins and moderators (`SuspiciousResultsVoter::REVIEW_SUSPICIOUS_TIMES`, its own access_control rule
`^/admin/time-verification` above `^/admin`), linked from the key menu with the number of pending cases. **English only,
not translated** (Jan, 2026-10-07).

**Private profiles**: a moderator sees a private player's time **only while it is in the queue** (a case exists) - the
card and the player's numbers it needs. Nothing else of the private profile opens up (Jan, 2026-10-07); the card links
no result detail for a private player.

Page title "Time verification". **Tabs**: **Too fast** · **Too slow** (pending cases of that direction) · Player replied
· Needs verification (marked) · Verified fine (trusted) · Decision log · Numbers. Times on a secret competition puzzle
(`PuzzleSecrecy`) stay out of the tabs until the reveal, like in every moderator queue.

- **Order of "Too fast"**: strong first, then the times leading their puzzle (`seconds ≤
  puzzle_statistics.fastest_time_solo` - the statistics count the pending time itself), then bigger leaderboards, then
  the score. The exact place ("is #3 of 465 solo results") is counted only for the cards of the listed page (for every
  pending case it costs ~45 ms on the production copy). **"Too slow"**: strong first, then the score (how many times
  slower) - a slow time takes no place anybody cares about.
- **Puzzle cards** ("The piece count is wrong") are shown on the first page of their tab; their cases are lines in the
  card, not cards of the list.
- **A card** shows the time, PPM, R and where the expectation came from; the puzzle (box image, piece count, median and
  fastest, its other editions); the player (results count, baselines per piece count, pair/team share, other results
  that day); the comment, the finished photo, competition / round; the reasons (moderator-only hints included), the
  suggested time; who was told and what they answered.
- **Layout (2026-10-08 redesign, `assets/styles/_time-verification.scss`)**: work queues (Too fast, Too slow, Player
  replied) left, records right, one scrolling row of tabs on a phone; a closed "How deciding works" panel. A card reads
  top down: whose time on what → the figures (entered + PPM, expected + source in words, how far off, place on the
  puzzle; a pair/team or new player: what most pairs/teams/puzzlers take and how many times slower) → "Why it is here"
  (the reasons the player may read are the checkboxes themselves, moderator-only hints follow with an eye-slash) and a
  grey panel with the player's and the puzzle's numbers → the decision bar (Needs verification / Looks fine, side by
  side, each with one line saying what happens; the note is folded). A puzzle card offers its choices as option boxes,
  a compact table of its times with short reason labels (`SuspiciousTimeReasonCode::moderatorLabel()`), then the cases.

**Actions** (POST + CSRF, each a Messenger handler writing the decision log; serialized per case - the handlers read
the case under its row lock (`SuspiciousTimeCaseRepository::getForUpdate()`), the scan and an edit's re-check take the
same lock before they write to a case, so nobody writes over a decision taken meanwhile):
- **Needs verification** (= mark, `MarkSolvingTimeSuspicious`) - pick which reasons the player sees (the detected ones
  ticked, only codes a player may read) + an optional note (≤ 1000 characters). `PuzzleSolvingTime::markSuspicious()`
  sets the flag and records `PuzzleSolvingTimeModified`, so statistics, insights and round results follow - no manual
  `recalculate-puzzle-statistics`.
- **Looks fine** (`TrustSolvingTime`) - trusted; this entry is never raised again. On a marked time it is the unmark:
  `unmarked`, and every open reply of the mark ("The time is correct", an edit that still looked off) is answered
  `trusted` - an earlier `kept` answer too, which is then told again (`answer_contact_id` reset).
- **Keep marked** (`KeepSolvingTimeSuspicious`, on a player's reply or edit) - a note for the player is required; open
  replies - "The time is correct" and edits that still looked off - are answered `kept` with the note, which reaches the
  review page and the next "Your results" e-mail. A later edit or "The time is correct" asks again: the earlier answer
  makes way for the next one.
- On a puzzle card: **Edit the puzzle** (the direct edit) / **It is a hard puzzle** - slow cards only
  (`SetPuzzleSlowThreshold` → `suspicious_time_puzzle_confirmation.slow_threshold` for the current count, then the
  puzzle's times judged again) / every case of the card decided in its own card inside it.
- **"Changed meanwhile"** (`SuspiciousTimeCaseChanged`, nothing saved, a warning flash): another status than the page
  showed, the time's fingerprint differs from what the moderator saw, a pending case whose time was edited after the
  scan (its reasons are about the old entry - the card says so and offers no action), Keep with nothing left to answer,
  a piece count changed since the card.

**Decision log** - `suspicious_time_decision`, append-only, no FKs, decider id/name/code copied (like
`puzzle_moderation_decision`): it outlives the time, the player and the moderator.

**Numbers** - per detector version: raised / marked / trusted / gone, precision per reason, and what players did
(fixed, said correct, left it, no reaction). These are what the next threshold change is judged by.

**Measured** on the production copy (485 pending): counts 1.2 ms, menu count 0.1 ms, puzzle cards 2.5 ms, one page of
ids 1.2-2 ms, the 30 cards of a page 10-24 ms ("Too fast" - the place counted) and 5-6 ms ("Too slow"); the whole request
with rendering 27-41 ms (Numbers 7 ms, Decision log 7 ms).

## Telling the player

### The notice run (end of every scan)

Every time with `suspicious = true` - marked in the queue **or flagged by SQL** - whose people have no notice for this
mark yet:
- a time flagged by SQL without a case gets one (origin `manual`, no reasons) and its puzzle's statistics are
  recalculated (the scan's flag reconciliation);
- each registered person of the time (the tracker, plus every member of a pair/team) gets one notice: the banner and a
  section in the next "Your results" e-mail. **No bell notification** (Jan, 2026-10-07). A member an edit adds to a
  still marked time is told by the next run; a member an edit takes out keeps the notice row, but nothing is shown,
  counted, answered or e-mailed to them any more (`GetPlayerSuspiciousTimes::sqlStillInTime()`: the tracker or a
  registered member of the time's `puzzling_team_id` - the review page, the banner, a reply, the e-mail planning and
  the e-mail itself).

**Once per mark**: a notice exists per (case, person, mark). An unmark followed by a new mark is a new mark; a mark that
simply stays is never announced again. Marking happens at any time of day, telling only after the run, so a
moderator's slip can be undone before anybody is told. Notices are `via = run`; only the go-live run records
`manual_email` (below) - the banner and the e-mail tell marks of `via = run` only. A moderator's answer to the player's
reply is e-mailed whatever the notice's `via`: the player asked for it.

### Where they see it

- **Banner** on the Hub and the own profile: one more scalar subquery in `GetPlayerReviewCounts` - the person's run
  notices of the mark in force, unanswered, on a still flagged time (0.013 ms on the production copy). Nobody else pays
  a query.
- **"Review your results"** (`review_results`) opens with **"Awaiting verification"** (`#awaiting-verification`):
  puzzle, time, day, the chosen reasons in the player's language with their numbers, the moderator's note, and:
  - **Fix the time** → the edit form, prefilled with the suggestion when there is one - only while the result still
    holds the time the suggestion was worked out for (the reason's `entered`; the link carries it as
    `suggested_for`, so a page opened before an edit brings nothing stale);
  - **Add the people I puzzled with** → the edit form;
  - **Change the puzzle** (when `other_edition`, tracker only) → the edit form's puzzle picker;
  - **The time is correct** → an optional message (≤ 500 characters) → the queue's "Player replied" tab, once per mark;
  - **Leave it as it is** → the time stays marked, the banner stops asking.
  Plus the moderators' answers of the last 30 days.
- **E-mail**: a section of the existing "Your results" e-mail (`result_review_contact`, planner, pacing, one-click
  unsubscribe, `result_emails_enabled`). A mark is treated like an automatic removal - told **always and once**, even
  to players who ignored earlier e-mails, because we changed how their data counts.
- **A moderator's answer to a reply or an edit** ("Your time counts again" / "Stays set aside: *note*") is shown on the
  review page and goes into the next "Your results" e-mail, once - no bell. A later answer that replaces it (an unmark
  after "Stays set aside") is told once more.

### When the player edits a marked time

**A changed result is another result** (Jan, 2026-10-07): changing the time, the puzzle or the people (e.g. adding the
co-puzzlers) of a marked time **removes the label** - for every mark, also one set by SQL or at go-live: the flag
cleared, the case `corrected`, every notice of the mark answered `fixed` (the banner and the card disappear for every
member), logged `unmarked_after_edit`. A flagged time without a case yet (set by SQL, before the next scan) loses the
flag the same way. Nothing is judged in the edit handler: the next scan judges the new entry like any other result -
still far off → a new **pending** case, and a moderator may mark it again (a new mark, told again). A one-second tweak
does not slip through unnoticed: the edit form itself asks before saving a time the scan would raise ("Catch it while
typing").

The edit takes the case's row lock first (a "Looks fine" in flight either sees the edit and refuses, or the edit sees
the unmark); if the lock or a read fails, the edit is saved anyway with the label on and the next scan judges the changed
entry as a change outside the edit form ("Checks and versions"; the card then says "The entry changed").

Any registered member may edit (`group-time-editing.md`); only the tracker may change the puzzle.

### Go-live runbook

The marks existing at go-live were all e-mailed by hand on 2026-10-07 - the automatic system must never tell those
players again (Jan, 2026-10-07). Nothing in the code knows which times they are:

1. Before deploying, check production's flagged times (`SELECT id, player_id FROM puzzle_solving_time WHERE suspicious`):
   every flag must be one that was e-mailed by hand. Clear a flag on a time that turned out valid. A flag set since and
   **not** e-mailed: clear it now and set it again after step 3 (or mark the time in the queue), so it gets the normal
   notice.
2. Deploy (the migration creates the tables).
3. Once: `bin/console myspeedpuzzling:detect-suspicious-times --existing-marks-told-by-hand`. The scan reconciles the
   SQL flags into marked cases, the notice run records every notice it creates as already sent (`via = manual_email`) -
   neither the banner nor the e-mail ever mentions them. If its notice run fails, the command says so: run it again
   **with the option** before anything else - a plain run would tell those players.
4. Then add the cron row (below). From here on every new mark is told normally.

A player who fixes one of these times later removes its label by the edit, like any mark ("When the player edits a
marked time"); the scan then judges the new entry. A new mark of the same time later is a new mark with its own notice.

## Catch it while typing (the add/edit form)

Most cases are typos the player would fix in a second if asked. Before, the form knew only the extremes: the
`ppm_validator` modal above 40 or below 1 pieces per minute (the same numbers for everybody) and the server's hard
refusal at 100 (`SuspiciousPpm`). Every hand-flagged time above passed both.

**Personal instead of global**: the scan's classifier, for one time, inside the existing live check (`first_try_check`,
fetched on every change of puzzle, time, date or people, 250 ms debounce) and again server-side on submit:

- Raised → a notice: "You entered 0:58:30 - your usual time here is about 3:10:00. Perhaps the hours box was left
  empty - 2:58:30? Did you puzzle with someone?" with **"Yes, it's right"**. The comparison line says what the trigger
  reason would, so the form leaves the trigger out and lists the explanations only; without an expected time (a new
  player, a pair/team below the floor) there is no comparison and the trigger is the line that says what is off.
- Submit without that answer → 422 with the same notice (the duplicate check's `duplicate_confirmed` pattern, here
  `pace_confirmed`); the 422 keeps photos and ids like every refused add.
- **The answer is bound to what was asked**: "Yes, it's right" carries the key of the values judged (puzzle, time, day,
  number of people - `SuspiciousTimeFormCheck::confirmationKey()`), and the server accepts it only for exactly those
  values. The script drops the answer - and the notice's `data-pace-checked` - at once on any change (`input` and
  `change`), so a submit right after typing another time is never "confirmed" and the generic modal asks again until
  the new answer arrives.
- **Asked of the tracker only**: an edit of a pair/team result by a member who did not save it is never judged - the
  expectation comes from the tracker's own times and is nobody else's to see.
- A player with an expectation gets this instead of the generic 40 PPM modal; players without one (new accounts) keep
  the modal. The 100 PPM refusal stays.
- A time saved after "Yes, it's right" is still raised by the scan, with the reason `confirmed_while_saving`, so the
  moderator knows the player was asked.
- **No "another edition" here**: that lookup compares names against every puzzle of the brand (20-40 ms) and adds
  nothing to whether the time is asked about. The scan and the edit re-check keep it.

**Measured** (2026-10-07, local production copy, warm): a typical check (not raised) is the prediction alone, ~1 ms
(`SingleTimeSuspicionCheck::forEntry`, 200 random recent solo results as entered: 0.6 / 0.9 / 1.3 ms p50 / p95 / max).
**Raised** (the same entries at a third of the time, every step runs): 200 random recent results 2.2 / 4.8 ms p50 / p95,
the 10 heaviest players × the 5 most solved puzzles 4.8 / 7.2 ms. With the edition lookup it was 18-22 / 40-47 ms.

## Data model

| Table | Purpose |
|---|---|
| `suspicious_time_check` | PK `time_id` (FK cascade), `version`, `outcome`, `fingerprint`, `checked_at`. ~450k narrow rows; written by the scan with batched native upserts (a genuine bulk operation, commented as such) |
| `suspicious_time_reference` | PK (`pieces_range`, `puzzling_type`): `median_ppm`, `p999_ppm`, `sample_size`, `computed_at`. Community pace per piece-count range and type (≥ 30 non-flagged results; a row whose sample falls below keeps its last values), refreshed by every scan |
| `suspicious_time_case` | One per time a scan raised or a person flagged: `time_id` (unique, FK cascade), `origin` (`detector`/`manual`/`moderator`), `status` (`pending`/`marked`/`trusted`/`corrected`/`gone`), `direction` (null for a flag the scan never raised), `tier`, `score`, `reasons` (jsonb `[{code, params}]`), `expected_seconds`, `expected_source`, `detector_version`, `fingerprint`, `detected_at`, `last_checked_at`, `decided_at`, `decided_by_id` (plain id), `reasons_shown`, `moderator_note`, `marked_at`, `player_edited_at`. Index (`status`, `direction`) |
| `suspicious_time_notice` | One per person per mark: `case_id` (FK cascade), `player_id` (FK cascade), `marked_at`, `notified_at`, `via` (`run`/`manual_email`), `contact_id` (the e-mail that carried the mark), `response` (`fixed`/`says_correct`/`left_as_is`), `response_text`, `responded_at`, `answer` (`trusted`/`kept`), `answer_note`, `answered_at`, `answer_contact_id`. Unique (`case_id`, `player_id`, `marked_at`) |
| `suspicious_time_decision` | Append-only log, no FKs: `decision` (`marked`, `trusted`, `unmarked`, `kept_after_reply`, `corrected_automatically`, `unmarked_after_edit`, `marked_outside_app`, `unmarked_outside_app`, `slow_threshold_set`, `slow_threshold_removed`; `pieces_confirmed` until 2026-10-08), `decided_at`, `puzzle_id`, `time_id`, `tracker_id`, `case_id`, `reasons_shown`, `note`, `snapshot` (seconds, piece count, expected, version), `decided_by_id/_name/_code` |
| `suspicious_time_puzzle_confirmation` | A moderator's word about a puzzle - "a hard puzzle": PK `puzzle_id` (FK cascade), `pieces_count` (lapses when the puzzle's count differs), `slow_threshold` (null on the earlier "the piece count is right" rows, which no longer change anything), `confirmed_by_id`, `confirmed_at` (when last set - older checks of its times are not current) |
| `suspicious_time_confirmation` | "Yes, it's right" in the form: `time_id`, `player_id` (both FK cascade), `expected_seconds` (what the notice compared it with), `confirmed_at` |
| `result_review_contact.suspicious_notice_ids` | jsonb list next to `case_ids` / `removal_ids`: the notices an e-mail really told |

`puzzle_solving_time.suspicious` stays the single source of truth for "no time figure uses it". Every table above only
explains how a flag got there. The whole schema is one migration.

## Cron (`~/www/lily.srv`, `apps/myspeedpuzzling/cron.d/myspeedpuzzling`)

```
19 4,16 * * *  myspeedpuzzling:detect-suspicious-times   (scan, then the notice run)
```

04:19 runs after the 01:34 prediction backfill and `detect-duplicate-results` (04:14), and before
`plan-result-review-emails` (04:29), so the morning run's notices go into that day's e-mail. 16:19 runs after the 13:34
backfill; its notices reach players by the banner straight away and by e-mail the next morning. The command
dispatches messages only; one failing case never rolls back the run (the duplicate detection's pattern). Measured on
the production copy: first run 14 s, an immediate second run 2 s (it re-checks only the recent `no_data` times), a
version bump 19 s; peak memory 76 MB.

## Not in v1 (later detector versions)

- **Pair/team fast times** - the model is solo-only today. Later: a group time faster than its fastest member's solo
  expectation can justify.
- **Report from a page** - "Needs verification" for moderators on the result detail (origin `moderator`).

## As built

Namespace `SpeedPuzzling\Web\`. Conventions of `CLAUDE.md` everywhere: single-action controllers, every state change
through a Messenger handler, repositories never flush, `ClockInterface`, `Uuid::uuid7()`. Player-facing text in the
`messages` / `emails` domains; the admin area literal English, no `|trans`.

**Values** (`src/Value/`): `SuspicionCheckOutcome`, `SuspiciousTimeCaseStatus`, `SuspiciousTimeCaseOrigin`,
`SuspiciousTimeTier`, `ExpectedTimeSource`, `SuspicionDirection`, `SuspiciousTimeNoticeVia` (`run`, `manual_email`),
`SuspiciousTimeResponse`, `SuspiciousTimeReplyAnswer`, `SuspiciousTimeDecisionKind`, `SuspiciousTimeQueueTab`;
`SuspiciousTimeReasonCode` (every reason above, `isShownToPlayer()`, `direction()` for triggers);
`SuspiciousTimeReason` (code + scalar params, stored as `[{code, params}]`; `suggestionFor(reasons, seconds)` - the
suggested time only while the time is still the reason's `entered`); `PaceFormCheck` (the form's assessment + the key of
the values judged, `isConfirmedBy(answer)`); `SuspicionAssessment` (`outcome`, `tier`,
`ratio` - < 1 when slow, `expectedSeconds`, `expectedSource`, `reasons` - trigger first, `suggestedSeconds`, `score`);
`SuspicionInput` (piece count, seconds, type, prediction, baseline, difficulty, pace factor, `PaceReferences`,
evidence, the prediction's previous attempt and whether an earlier attempt has a slow case); `SuspicionEvidence` /
`SuspicionEvidenceRequest`; `SuspicionPiecesRange` (`of()` / `sql()`, parity-tested), `PaceReference(s)`,
`PaceRequest`.

**Entities** (`src/Entity/`): `SuspiciousTimeCheck`, `SuspiciousTimeReference`, `SuspiciousTimeCase` (`detected()`,
`flaggedOutsideTheApp()`, `refreshDetection()`, `reopen()`, `markGone()`, `mark()` - `marked_at` to the second,
`markedOutsideTheApp()`, `trust()`, `markCorrected()`, `playerEdited()` - also an entry changed outside the edit form,
`keptAfterReply()`, `isDecidedFor(fingerprint)` - a decision is bound to the entry it was made for),
`SuspiciousTimeNotice` (`respond()` - "The time is correct" once per mark; a fix or "The time is correct" clears an
earlier answer, `awaitsAnswer()` - fixed or says correct and not answered yet, `answer()` - resets
`answer_contact_id`, so a new answer is told once, `sentInContact()`, `answerSentInContact()`, `isAbout(case)`),
`SuspiciousTimeDecision` (written
only by `Services\SuspiciousTimes\SuspiciousTimeDecisionRecorder`), `SuspiciousTimePuzzleConfirmation`,
`SuspiciousTimeConfirmation`. `PuzzleSolvingTime::markSuspicious()` / `clearSuspicion()` set the flag and record
`PuzzleSolvingTimeModified` (its consumers recompute statistics + insights and re-run duplicate detection, nothing
notifies); `suspicionChangedOutsideTheApp()` records only the event for flags changed by SQL - the scan hands it to
the bus itself, since Doctrine sees no change.

**Detection** (`src/Services/SuspiciousTimes/`, `src/Query/`)
- `SuspiciousTimeClassifier` - pure, `VERSION = 1`, every threshold a constant; `classify(SuspicionInput)`, helpers
  `expectation()`, `needsPace()`, `predictionBuiltOnSlowAttempt()`, `belowCommunityMedian()` (no fast raise below
  `FAST_MIN_COMMUNITY_MEDIAN_SHARE` × the solo median of the range), `hoursLeftOut()`, `minutesInHoursBox()`,
  `groupWordIn()`.
- `Query\GetSuspiciousTimeCandidates` (the times to check with everything the classifier needs except the pace,
  streamed by a cursor planned for the whole result - on statistics not yet refreshed the planner's first-rows plan ran
  for minutes), `Query\GetPlayerPaces` (the pace for a set of players, window medians in PHP),
  `Query\GetSuspiciousTimeReferences`, `Query\GetSuspiciousTimeEvidence` (explanations' facts for raised times only;
  `forTimes($requests, withOtherEditions)`; the teammates' group results leave out results of anybody the tracker
  blocked; `sqlMayBeNamed()` = neither `PuzzleSecrecy` nor `hide_until` - the condition for a puzzle a reason may name;
  `withoutUnnameable(reasonLists)` drops an `other_edition` whose puzzle became secret or hidden since - the review
  page, the queue's cards and the decision log).
- `DetectSuspiciousTimes(dryRun)` → `SuspiciousTimeScanSummary`: reference refresh, flag reconciliation (a marked case
  whose time is no longer flagged → `trusted` + `unmarked_outside_app`; a flagged time without a marked case → case
  `marked`, origin `manual`, or the pending case becomes marked, + `marked_outside_app`), marks whose entry changed
  outside the edit form (`GetSuspiciousTimeCandidates::markedWithChangedEntry()` →
  `MarkedTimeEditRecheck::afterOutsideChange()`, counted as "Changed marks: unmarked / back to moderators"), checks
  with batched upserts, cases (new → `pending`, still raised → reasons refreshed, no longer raised → `gone`, raised
  again → reopened). The scan never marks. Before it writes to a case it read a while ago, it locks the case's row and
  reads it again (`SuspiciousTimeCaseRepository::lockForDecision()` / `lockByTimes()`, in id order, only the cases'
  rows) and writes only while the case is still in the state it read - a decision taken while the scan ran stands.
- `NotifySuspiciousTimes(toldByHand)` → number of notices created (`via = run`, or `manual_email` for the go-live run).
- `SuspiciousTimeScan` (used by the command): Detect, then Notify, each top-level, the entity manager cleared or reset
  between them, failures logged as warnings. Console `myspeedpuzzling:detect-suspicious-times [--dry-run]
  [--existing-marks-told-by-hand]` (the two together are refused); a failed go-live notice run says to run it again
  with the option (a plain run would tell the players).
- `SingleTimeSuspicionCheck::forEntry(playerId, puzzleId, seconds, puzzlingType, puzzlersCount, ?excludeTimeId,
  SolveMoment, withOtherEditions = true): ?SuspicionAssessment` - the classifier for one entry, exactly like the scan:
  the live prediction (`GetPlayerPrediction::forPuzzle()` as of the solve, the edited time left out) with its last
  attempt (`TimePredictionResult::$lastTimeSeconds` when personal) and `GetSuspicionEntryFacts::earlierAttemptRaisedSlow()`
  (asked only when the time is ≥ 2× faster than a personal prediction), then the baseline, then the pace; pair/team by
  the slow floor; stored references. **Fails open** (any exception → null + warning).

**Moderator queue** - `src/Controller/Admin/SuspiciousTimes/*`: `GET /admin/time-verification?tab=&page=`
(`admin_time_verification`), POST + CSRF (`time-verification-{caseId}`, puzzles `time-verification-puzzle-{puzzleId}`)
`…/{caseId}/mark` (`admin_time_verification_mark`), `…/{caseId}/trust`, `…/{caseId}/keep`,
`…/puzzles/{puzzleId}/slow-threshold` (`admin_time_verification_slow_threshold`, `remove` takes it away); every action redirects back to
its tab and page with a flash. Messages `MarkSolvingTimeSuspicious`, `TrustSolvingTime`, `KeepSolvingTimeSuspicious`
(their handlers read the case with `getForUpdate()` - the row lock the scan and the edit re-check take too),
`SetPuzzleSlowThreshold` (+ `SuspiciousTimeScan::forPuzzle()` right after). Queries `GetSuspiciousTimeQueue` (tab counts, the menu count over the (`status`,
`direction`) index, puzzle cards - `CARD_MIN_PLAYERS` 2, `CARD_MIN_SHARE` 0.2, `CARD_EASY_BELOW` 0.5,
`CARD_HARD_ABOVE` 2.0 - and one page of ids), `GetSuspiciousTimeCaseDetail` (the cards of a page, 5 statements + 1 for
pair/team people + 1 when a reason names another edition; other results of that day never of a puzzle a competition
keeps secret), `GetSuspiciousTimesOverview` (Numbers, Decision log - a decision about a puzzle kept secret now shows
neither its name, piece count nor reasons until the reveal). Templates `templates/admin/suspicious_times/*`,
menu entry + count (`TimeVerificationTwigExtension`) in `base.html.twig`.

**Player side** - `GetPlayerReviewCounts` + `PlayerReviewCounts::$suspiciousTimes` (+ banner line); the review page
section `templates/review_results/_suspicious_times.html.twig` (`#awaiting-verification`), fed by
`GetPlayerSuspiciousTimes::openOf()` / `recentlyAnsweredOf()` (only reasons a player may read; an `other_edition` whose
puzzle became secret or hidden after the mark is dropped - one more statement only when such a reason exists). Every
place a notice is shown, counted, answered or e-mailed asks `GetPlayerSuspiciousTimes::sqlStillInTime()` (the person
is still the tracker or a registered member of the time's `puzzling_team_id`; `SuspiciousTimeNoticeRepository::
getCurrentOf()` the same in DQL). POST `review_results_suspicious_correct` (`ReplySuspiciousTimeIsCorrect`, text ≤ 500)
and `review_results_suspicious_leave` (`LeaveSuspiciousTimeAsItIs`) - only for a person with a notice of the mark in
force (404 otherwise), both count as a reaction to the latest e-mail. "Fix the time" links to `edit_time` with
`?suggested_seconds=` + `suggested_for=` (the time it was worked out for - the form prefills only while the result
still holds it) and `context=review-results` (`EditTimeReturnContext::ReviewResults`, back to
`#awaiting-verification`). The edit re-check: `MarkedTimeEditRecheck::afterEdit()` in `EditPuzzleSolvingTimeHandler` -
the case's row lock taken in a savepoint of its own, the reads in an always rolled-back savepoint, failures logged
(a lock it cannot get included: the case then stays about the old entry and the next scan judges the new one), the
edit is always saved; a changed time, puzzle or group always removes the label (`unmarked_after_edit`), the scan
judges the new entry. Keys `suspicious_time.*` in `messages` (`.reason.<code>` rendered by
`templates/suspicious_time/_reason.html.twig`, shared with the admin cards; `.review.*`, `.banner`, `.flash.*`,
`.form.*`).

**E-mail** - `GetResultReviewEmailCandidates`: a run notice whose mark is still in force (case marked, same
`marked_at`, time still flagged), not e-mailed and not reacted to on the site, or a moderator's answer not e-mailed
(any `via` - also to a mark told by hand); the person still in the result.
`ResultReviewContactPlanner` treats them like automatic removals (always and once, the removals' activity rules) →
`PlannedResultReviewContact` → `result_review_contact.suspicious_notice_ids`. Sending re-checks every notice (stale
ones dropped, nothing left = skipped) and stamps `sentInContact()` / `answerSentInContact()`.
`ResultReviewEmailComposer` + `templates/emails/result_review.html.twig`: an "Awaiting verification" section (up to 3 +
"…and N more"), a line per answer, the review link to `#awaiting-verification`; own subject/title/intro/preheader when
the e-mail has verification content only. Keys `result_review.verification_*`, `result_review.*_verification` and
`*_verification_answered` in `emails`. Preview: `myspeedpuzzling:send-result-review-email-preview <email>
--variant=verification` / `--variant=answered`.

**Form** - `SuspiciousTimeFormCheck` reads the form like the handlers do (the date the handler stores, the group around
the tracker: solo / pair / team by the number of people, the tracker's own `#code` skipped) and calls
`SingleTimeSuspicionCheck` without the edition lookup; it answers a `PaceFormCheck` (the assessment + the key of the
values judged). **An edit is judged only when it changes the entry** (time, puzzle or number of people), and only for
the tracker - a member who did not save the result is never asked. Live: `FirstTryCheckController` judges the time
only with `pace=1` (the script sends it unless a stopwatch measured the time); `pace_confirmed` = the key the notice's
"Yes, it's right" button carried (`data-first-try-check-key-param`), accepted only while it is the key of the values
sent. The notice `suspicious_time/_form_notice.html.twig` sits between the duplicate and the first-try parts: "You
entered X - your usual time here is about Y" and the explanations shown to players (the trigger reason only when there
is no expected time to compare with), "Did you puzzle with someone?" on a fast time, **Use {suggested}** and **Yes,
it's right**. `data-pace-checked` marks a time judged against the player's own times or asked about here - the generic
40/1 PPM modal (`ppm_validator_controller.js`) then stays quiet; `first_try_check_controller.js` drops the marker and the
answer synchronously on `input` / `change` of anything the key covers. Submit: `PuzzleAddController` /
`EditTimeController` answer 422 (`suspicious_time.form.error`) while a raised time is not confirmed for exactly the
values submitted - no stopwatch save, relax, new puzzle, and the API is untouched. A confirmed raise travels as `paceConfirmedExpectedSeconds` on `AddPuzzleSolvingTime` /
`EditPuzzleSolvingTime`; the handler stores a `SuspiciousTimeConfirmation` (the expected time, or without one the
community's mark the notice named).

**Guards** - every raw-SQL file is compliant or listed in `SuspiciousTimeQueryCoverageTest`,
`BlocklistQueryCoverageTest` (admin/own-data reasons) and `PrivateProfileQueryCoverageTest`; the row locks are pinned
with a second database connection holding a case (`tests/HoldsSuspiciousTimeCaseLock.php`); fixtures
`tests/DataFixtures/SuspiciousTimesFixture.php` (own players and puzzles, UUID prefix `018d0031-`, three stored
references), documented in `.claude/fixtures.md`.

## Changes made while revising the first draft

1. **The expectation comes from the stored prediction, not from an average PPM.** The plain "N× my average" flags
   repeat attempts and easy puzzles (437 and 167 of 512 at 2.0-2.2×); the prediction already accounts for both. Average
   pace stays as the third fallback, normalised per piece count.
2. **Typo, pair/team and wrong edition are explanations, not triggers.** "+1 h fits" is true for most moderately fast
   times. These checks only describe a time that is already raised, and supply the suggested fix.
3. **A fourth cause: a wrong piece count** → one puzzle card in the queue, not flags on players.
4. **"Passed = never again" except without data**: a `no_data` outcome is re-checked for 180 days, otherwise a new
   player's mistyped first result would pass forever.
5. **Fingerprint instead of events** decides "this is a different entry now", and trust is bound to it.
6. **Reasons are structured and translated; the note is optional free text.** A prefilled free-text note would freeze
   the reasons in the moderator's language. Players read 6 languages, moderators write English or Czech. The detected
   reasons *are* the prefill - ticked boxes, each rendered in the player's language with its numbers.
7. **Automatic unmark after a fix** - first only for a clear entry of a detector mark; **changed by Jan after go-live
   (2026-10-07)**: a player's edit of the time, puzzle or people always removes the label, any mark - a changed result
   is another result, the scan judges it again (and may raise it again).
8. **The notice run handles SQL flags too** (a case, the statistics recalculation and the notice), so a flag set by hand
   behaves exactly like one set in the queue.
9. **Considered and dropped: checking top pace against event results.** A history wrong from the start would agree
   with every later prediction. Jan, 2026-10-07: the player's own other times are enough - there is no other reliable
   evidence.

## Settled decisions (Jan, 2026-10-07)

1. Moderators may see private players' times **while they are queued for a check** - nothing more.
2. **No bell notification** - the banner and the "Your results" e-mail are enough.
3. Player H's three times are **valid** - Jan allowed them after first judging them not credible the same day; the
   flags were cleared and the player is not mailed.
4. "Not suspicious" is a normal outcome of a review. The player's **"Leave it as it is"** stays.
5. The warning while typing is wanted **if it is fast** - it is (see "Catch it while typing").
6. The admin area is **English only, not translated**.
7. **The player's own other times are the evidence** - no check against event results or any other outside source; the
   community scale only for players without times of their own.
8. **Too slow is detected too**, same mechanism: thresholds that catch obvious mistakes (box shifts, days counted),
   piece-count ranges for the community scale.
9. **No CSV** - the backlog is reviewed in the admin, tabs **Too fast** / **Too slow**.
10. **Never "suspicious" in anything a person reads** - "Needs verification" / "Awaiting verification".
11. **The marks existing at go-live were told by hand** - recorded by the go-live option, never by ids in the code
    (the repository is public).
12. **A hard puzzle gets the moderators' own slow threshold** (2026-10-08) - set by hand on the puzzle, not the
    computed difficulty; every time of a puzzle card can be decided right in the card.

## Queries

The calibration (community median PPM per piece count, each player's pace, the ratio buckets, the explanation
coverage) was run on the local production copy; `GetSuspiciousTimeCandidates` is its production form. The core of it:

```sql
-- expected vs entered, prediction path (production: 217 rows >= 2.0, 86 >= 2.5)
SELECT count(*) FILTER (WHERE predicted_seconds::float / seconds_to_solve >= 2.0)
FROM puzzle_solving_time
WHERE puzzling_type = 'solo' AND seconds_to_solve > 0 AND predicted_seconds IS NOT NULL;

-- pace fallback: a result's speed relative to the community for its piece count
-- norm = (pieces * 60 / seconds) / median_ppm(pieces, 'solo'); player pace = median(norm) of their
-- other solo results within ±180 days (>= 5); expected = pace * median_ppm(pieces)
```
