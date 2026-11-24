# SPWS demo UX leftovers (2026-11-24)

The fresh-eyes UX walk of the SPWS sandbox demo (PR #491, `docs/TODO.md` "SPWS demo UX walk") left items that the
organisers would trip over on the demo call (~2 Dec). This package fixes the ones below - each the smallest natural
solution, opt-in or invisible to events that do not use it (brief `cm-delivery/brief-spws-common.md`).

## 1. Event page: "Your competitor page"

**Problem.** A signed-in competitor finds their page only through Competitors (alphabetical, 350+ rows at SPWS).

**Options.** (a) the viewer's own rows first on the competitor list - still a detour; (b) a link on the event page from
the attendance statement that is already run - chosen; (c) a link per round card - needs the viewer's entries per
round (a statement more) and repeats the same page N times.

**Chosen.** `GetEventAttendance` reads, in its existing statement and only when the event has competitor pages on, the
viewer's going participant (`own_participant_id`; the registration variant already selects `mine.id` - used when the
row is going). `EventAttendance::$ownParticipantId` → the event/edition page shows a primary **"Your competitor page"**
button next to "You are going" (attendance block) and in the rounds' links row next to "Competitors"
(`/competitor/{cid}/p/{participantId}` - the person page lists every round of theirs: solo, pair, team).

- Competitor pages off, guests, a viewer who is not going: nothing changes (statement text unchanged when pages are
  off, guest HTML unchanged). 0 statements more (`DetailPagesQueryBudgetTest`).
- Privacy/drafts: the link is the viewer's own page; a draft event page is seen only by its editors, the competitor
  page keeps its own rules (404 when off). No new public surface - no canary change.

## 2. Judge page: a typed table name opens after a pause

**Problem.** `a1` highlights A1 but waits for Enter or a tap, because A10…A19 continue it (C7: a table opens at once only
when nothing else continues the typed text). Opening A1 at once would break typing "a10".

**Chosen.** A pure module `assets/live_entry_table_pause.js`: `pausedTable(index, query, {labels, tableNumbersOff})` =
the entry whose table label key is exactly the typed key (ignoring labels that continue it) - only on rounds with
table labels, text with a digit, exactly one entry; `TABLE_PAUSE_MS = 700`. The controller (only when the per-phone
"open a table at once" preference is on - the same switch as C7) arms one timer per input; every input, opening an
entry, leaving the find view and disconnect clear it; when it fires it re-checks that the input still holds the same
text and the find view is shown, then opens. Enter keeps opening an exact match at once (`entryForEnter`).
Numbers: unchanged (C7's instant open; no pause-open). Harness `tests/live-entry-table-pause-harness.mjs` run by
`LiveResultsScriptsTest`-style PHPUnit wrapper.

## 3. Judge page: "Didn't finish" on a Solve-all block

**Problem.** On a K = N round ("Solve all 2 (order recorded)") with no puzzle tapped, "Didn't finish" asks
"Pieces placed" without saying of which puzzle; the card says "Which 2 puzzles? Tap them in solving order." and
"Save puzzle choice" although every puzzle is solved and only the order is recorded.

**Chosen.**
- `solveAllOrder(format, choice)` (pure, `live_entry_puzzles.js`): on a K = N round with N ≥ 2 the choice completed
  with the puzzles not in it, in the round's order (position). Applied when "Didn't finish" opens (also a card opened
  after time's up, which opens in it) - so the open puzzle is the next one after those finished (a team with Challenge
  1 in gets the pieces box of Challenge 2); a plain card open keeps the choice as it is (proposal merge, `canSaveChoice`,
  split candidates unchanged). The chips stay tappable: another order is an untap and a retap.
- Texts on K = N rounds: head "Order solved" (new key `live_results.puzzles.order_solved`), button "Save order"
  (`live_results.puzzles.save_order`). K < N rounds keep today's texts.
- There is no "fixed order" setting; K = N is the trigger (the round's order is what a block's rules fix at SPWS; at
  any other K = N event the preselection is visible and changeable).

## 4. Displays

**4a. Empty panels.** A panel is three elements (a box, a title text, the list) - hiding the list alone leaves the box
over the camera. New value of the existing per-element visibility `when.results`: **`has_rows`** ("When results are
in") - the element shows only while the state carries at least one result row (`results.top` or `results.latest`
non-empty, any view). Works for any element (a box, a title). Default stays `any` - every stored design is unchanged.
PHP `DisplayDesignSanitizer` + JS `schema.js` + `tests/fixtures/display-design-cases.json` together, editor option
label in 6 locales. `resultsDemand()`: a box with `has_rows` asks nothing - the state carries results only when a
results element asks; a design that hides a box by `has_rows` always has the list next to it. Documented: alone, a
`has_rows` element without any results element is never shown.

**4b. Names cut off.** New optional leaderboard prop **`nameLines`** (1-3, stored only when set, like `division`):
the name (and members line) wrap to that many lines instead of one line with an ellipsis (`-webkit-line-clamp`).
Absent = today's single line. Editor: a number field "Name lines" (generic prop field). The SPWS demo displays set
`nameLines: 2` and `when.results: has_rows` on the overlay's board column + finishers box (box, title, list) and the
hall screen's top-10 panel; the overlay's board column gets wider (19.5 → 23 %) so a team of four with flags keeps
its name. Checked in the editor (each element's properties) and the public renderer at 1920×1080.

## 5. Sandbox demo: the running block's stopwatch

**Problem.** Teams - Challenges 1 + 2 has results but its stopwatch is "Not started": no "Finished now", Challenge 2
can't be tapped.

**Chosen (opt-in per demo round, `SandboxDemoRound::$stopwatchFromStart`, only SPWS `t12`).** On every run, while the
round's stopwatch has no status yet:
- its start (10:00 Toronto on `--date`) still ahead → `ScheduleRoundStart(startsAt)` as the organiser (the clock runs
  by itself from that moment - "a scheduled start counts as running once its moment has passed", nothing written);
- already past (a build on the call day) → `StartSandboxDemoRoundStopwatch` (sandbox-only message,
  `SandboxMode::assertOn()`) = `CompetitionRound::startStopwatch(<scheduled start>)`.
A stopwatch a rehearsal started/stopped/cancelled is never touched (status not null) → idempotent.

**Consistent splits.** Today's running seeds reach ~108 min (finished) and ~54 min (Challenge 1 only), so they fit the
clock only from ~11:48 Toronto. New opt-in `SandboxDemoRound::$seededWithinMinutes` (SPWS `t12`: 60, base seconds
lowered so ~a third finish): a running round's first third finishes only when its total ≤ the cap (else Challenge 1
only, when that fits), the middle third has Challenge 1 only when ≤ the cap, the rest nothing. So from 11:00 Toronto
(= 17:00 CET) to the end of the limit (12:00) every seeded time lies in the clock's past.

**On the call day** (`--date` = that day): before 10:00 Toronto the round is `scheduled` (stopwatch page, judge and
screens count down to 10:00 Toronto); 10:00-12:00 running (Finished now, Challenge 2 for the teams with Challenge 1
in); 11:00-12:00 the seeds are all in the past; after 12:00 time's up (still running until stopped). Teams 3 + 4 stays
planned for the organisers to start with the reveal. Other days: rebuild with that day. The old demo + Carnival plan
exactly as before (flags off). Guide + `spws-demo-event.md` updated.

**Future `--date` (coordinator, Jan 2026-11-24 - binding).** The demo will most likely be built on its real dates
(`--date=2027-06-03`, the real Teams day) and tried by the organisers now, months ahead, starting any stopwatch early.
So when the demo day is after today (Toronto): the running round with `stopwatchFromStart` gets **no seeded results and
no stopwatch** (`SandboxDemoEvents::waitsForItsDay()`) - seeded splits would be later than a stopwatch started live
today and refuse "Finished now", and a start scheduled months ahead would refuse the organisers' own Start (Start
while `scheduled` = `StartScheduled`). The Pairs day stays recorded and published (standings, tie, no-shows, missing
pieces, plates); nothing else of the build depends on today (the secret puzzles need their round's start ahead - it
is). Today or past: as above.

## 6. Hub claim policy

"Automatic" selected with a note "Now: Ask me first - …" reads as a contradiction. New text:
"Automatic picks **Ask me first** here, because competitors can propose their own times at this event." /
"Automatic picks **Link at once** here - nobody proposes their own time at this event." (6 locales).

## 7. Small items

- **Reveal note for unboxed puzzles** (stopwatch page, online control room): an unboxed puzzle's scope line says
  "its name everywhere, its picture once the results are published" (template-only: the controllers pass the ids of the
  round's unboxed puzzles while its results were never published; `SecretRevealPreview` and its hash unchanged). The
  desk's missed-reveal dialog runs at the publish, when the picture comes out - unchanged.
- **Studio columns**: the line-up and rank/time cells keep one line (`white-space: nowrap`), CSS only.
- **Results desk at 375 px, teams**: the flags fold inline after the name (no float stacking 4 flags above it);
  checked that no column scrolls sideways.
- **Wizard day fields**: a day picked in one row fills the empty day fields of the rows below it (amendment 8) -
  one pick per day of a several-day event instead of one per round; one-day events are prefilled as today.

## 8. Findings of the sandbox reveal rehearsal (coordinator, 2026-11-24 - binding)

1. **Displays behind the sandbox gate**: `/display/{token}` and its cookie-less `/state` + `/design` were answered by
   the welcome page (401 - the screens froze on their first picture). `SandboxGate::OPEN_DISPLAY_PATTERN` lets them
   through (like the studio's JSON, #489); the editor and every other page stay behind the gate.
2. **"Didn't finish" counted as finished** (production too): the displays' `finished` counted every ranked entry, and
   the live board ranked a DNF right after the finishers while other teams were still solving. Now `finished` = finished
   results only; while the round is not published and its time is not over (`roundIsOver()`: stopwatch stopped, or
   running past the limit) the live board lists finishers only - after the time (or published) the DNFs follow the
   finishers as ranked. The ticker ("Just finished") and the spotlight are unchanged.
3. **Challenge board headings**: a new `field` value `puzzle_label` with an optional `position` (1-50, stored only when
   set) = the label of the round's puzzle at that position ("Challenge 3"); the state carries `puzzleLabels`
   (position => label) only for a design naming one (labels are public before the reveal - the event page shows them).
   The demo's challenge board uses it instead of the fixed "Challenge 1 / 2" texts.
4. **A stopwatch started early beats the schedule**: `EditionRoundDetail::$stopwatchStartedAt` (the stopwatch's first
   start while it has a status, in the existing rounds statement) and `startedAt($now)` = that start when it is earlier
   than the schedule, else the schedule. Event/edition timeline (Live / Past / Next, the sidebar's and facts strip's
   "Next round" - a running round wins), the round results page ("This round starts in 8 days" no more). The competitor
   page already picks the running round (LiveResultsCurrentRound); its badge says "Next" instead of "Now" while that
   round has neither begun nor a stopwatch. Without an early start every page is as before.
5. **Studio**: "Picture shown later" only for a puzzle whose picture waits (`imageLater`, an unboxed or image-hidden
   puzzle); a puzzle without a picture gets the plain tile.
6. **Wording**: a round with 2+ hidden puzzles says "The puzzles of this round have not been revealed yet."
7. **Competitor page**: "You took back …" shows only while the competitor can tap again (running, no result).
8. Hall screen names beside four flags: §4b (`nameLines` + flags two by two).
9. **Demo box pictures**: stand-ins already prefer catalogue puzzles with a picture; the two new secret puzzles get a
   made-up GD-drawn box picture (`SandboxPlaceholderBox`, "DEMO BOX" + the name on coloured pieces - never real
   artwork), so the unboxed effect shows: no picture before the results, a picture after.

Left on purpose: a team whose only entries are no-shows is left out of the standing (Jan asks SPWS).

## Review amendments (independent review 2026-11-24 - binding)

| # | Finding | Applied |
|---|---|---|
| 1 (H) | `has_rows` on "any row in the state" is wrong: `top` is filled as soon as any leaderboard is live, a spotlight fills `latest` without a ticker | `hasResultRows(elements, state)` = a not-hidden leaderboard (`top`) / ticker (`latest`) **of this design** shows a row now, through its own `resultsFor()` gating and `resultRows()` view. A published-only list before publishing never counts |
| 2 (H) | Two-line names could push the bottom rows out of the box | Only the name wraps (the members line stays one line, CSS on `.msp-el__name` only); checked in the browser at 1920×1080 that 8 / 10 rows of the longest names fit |
| 3 (M) | The overlay's "finished" count sits in the board column | It gets `has_rows` too |
| 4 (M) | A pause armed on "a1-" / while the scanner or another field has the focus | No pause-open on text ending in a separator; the fired timer opens only while the find box still has the focus (`pauseStillValid(…, stillTyping)`). 700 ms kept (the brief's 600-800 ms) |
| 5 (M) | "Never at card open" is wrong: a card opened after time's up opens in Didn't finish | Said so (§3); `choiceFromProposal` is left as it was (the choice only grows) |
| 6 (M) | A re-run keeps old rounds/results; a Reset (status null) would be restarted | The start is the round's **stored** `starts_at`; the cap needs the event rebuilt (the orchestrator rebuilds it); a rehearsal's Reset gets the 10:00 start again on a later run - documented in the guide |
| 7 (M) | The consistency window depends on the call time | Kept 60 min (more finished teams to show); the report states the window exactly (11:00-12:00 Toronto all seeds in the past) |
| 8 (M) | Prefilling the first day breaks "no made-up starts" (creation-wizard.md) | No prefill: a day picked in one row fills the **empty** rows below it (`event_wizard_days.js` + `event_wizard_days_controller.js`, harness); every day is still the organiser's pick |
| 9 (L) | Managed registration has no "You are going" span; a pending event 404s the page | The button sits in `_taking_part.html.twig` (only on publicly visible pages), the rounds-row link only next to an existing "Competitors" link; deterministic `ORDER BY own.id LIMIT 1` |
| 10 (L) | The shared timeline partial is on 4 pages | `attendance.ownParticipantId ?? null` - the series page passes none |
| 11 (L) | One unboxed text does not fit every scope | `scope_unboxed.everywhere / name_everywhere / event` |
| 12 (L) | The editor's sample data always has rows | The editor's "Data: display" preview shows the real state; documented |

## Test plan

Node: `live-entry-table-pause-harness` (exact match, prefix ambiguity, no digit, two entries, labels off),
`live-results-harness` (`solveAllOrder`), `display-schema` + `display-render` harnesses (`has_rows`, `nameLines`),
shared sanitizer cases. PHP: `DisplayDesignSanitizerTest`, `IsThisYouCardTest`-style competitor link test (pages
on/off, guest, not going, registration managed), `DetailPagesQueryBudgetTest`, `SandboxDemoEventsTest` (scheduled
before / started after the start via `TestClock`, re-run idempotent, old demos unchanged), stopwatch page reveal note,
wizard prefill. Goldens unchanged. Browser: every changed UI at 1440 and 375 px on a local SPWS demo build.
