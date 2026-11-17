# Event creation wizard (as built, 2026-11-17)

Jan: "rework the event creating for competition type to be UX user friendly wizard with choosing / toggling the
features ... if it is single round not needing to bother the user through multiple clicks ... and especially the
edition/series creation". Design of record: `toolkit/W-creation-wizard-research.md` (screens, click counts, risks);
delivery record: `toolkit/STATUS.md` "Creation wizard". **No feature flag** (Jan 2026-11-17): the wizard is every
signed-in player's "Add event"; the classic forms stay reachable from it until Jan retires them (`docs/TODO.md`).

## Principles
- **The draft is the wizard's state.** The first save creates a real draft (`AddCompetition` / `AddCompetitionSeries`
  with `isDraft: true`); every later step writes through the existing messages. No wizard storage: the step to resume
  is derived from the draft's data (`EventWizardSteps::of()` → `firstNotDone()`), `event_wizard_continue` /
  `event_wizard_series_continue` send a draft there ("Continue setting up" on the edit page, the draft banner, "You
  organize").
- **Ask only what cannot be inferred** (smart forms): the round's start = the event's date + start time in the zone of
  its country (`RoundTimezone`), a one-round event's round is named after its category, reveal delay and badge colour
  default. Review shows every inferred value with "edit". **Never a round's start**: the organiser schedules every round
  (Jan 2026-11-17) - the one-round path's start is Basics' date + time, the series' rounds start at the typed time, the
  several-rounds templates ask each generated round's day and time.
- **Composites for atomicity, no new domain concepts**: `StartEventWizardDraft`, `SaveEventWizardBasics`,
  `SetUpEventWizardRound`, `SetUpEventWizardStructure`, `SetUpEventWizardPuzzles`, `AddEventWizardEditions`,
  `PublishEventWizardSeries` dispatch the existing messages inside one transaction. `LockUntilCommittedMiddleware` is
  re-entrant per lock key, so a composite holding `CompetitionParticipantsLock` (or the `series-editions-*` key) may
  dispatch messages serialized by the same key.
- Every step: full-page POST → 303 to the next step, 422 with the form on errors (Turbo). Mobile-first; the step rail
  is a left column on desktop, "Step 2 of 4" on phones.

## Paths (`EventWizardPath`, steps `EventWizardStep`)
| Path | Steps | Writes |
|---|---|---|
| Entry `event_wizard` (`/{_locale}/new-event`) | "What are you organizing?" one round / several rounds (+ "do competitors qualify?") / a regular series / something else (→ classic form); organization picker for team members; "Copy one of your events" → Duplicate form | - |
| One round | Basics → Puzzle & format → Extras → Review | Basics creates the draft **and its round** (hidden `draftId` makes a double submit idempotent); Puzzle: category, limit, puzzle(s) (catalogue or new), secret + reveal (automatic / when I start the stopwatch / manual), pick K of N |
| Several rounds | Basics → Structure → Puzzles → Extras → Review | Basics without a start time. Structure templates "Qualification" (stages, top N go on) / "Every round counts" (solo/pairs/teams counts, add up per category) - a GET form listing the generated rounds, each with its name, day (prefilled only for a one-day event) and time (empty, required); "Create the rounds" (POST, the template's answer re-read from the query string) writes rounds + starts + steps/standings in one transaction and goes on to Puzzles - a missing start or one outside the event's days is a row error (422), nothing written before (no rounds with made-up starts in between); then the editable table; read-only once any entry, result or stopwatch run exists (re-checked under the lock). Stages → advancement steps, "add up" → one standing per category (`StandingsPerCategory`, never published). Puzzles grid: one row per round, one secret switch, row errors for the one-puzzle-per-category rule |
| Series | Series Basics → Dates + each date's round → Review | rule (weekly / Nth weekday / picked days, `EditionsPreview` shared with "Add several dates"), start time, a round per date or none; re-sending creates nothing twice; Publish publishes the series and its draft dates. Series page: "Add the next dates" (prefilled from the last dates) and a quick "Add puzzle" per date |

Path rule: an edition → series; a draft with no round that is not an edition → several rounds (the one-round path always
creates its round with the draft); one round → one round.

**Extras** (`EventWizardExtras`): registration on MySpeedPuzzling (limit, window, fee), registration/results elsewhere
(links), judges, and the toolkit rows - QR name tags (mode), competitor pages (+ live results, time proposals),
timer screen and stream overlay (preset), overall standings. Off by default, details only when switched on, an
existing display/standing is shown as "added - Manage" (never deleted from here). Each row writes through its own
message only when its value changed (separate transactions: a later refusal keeps earlier ones). No Extras for a
series - every feature belongs to a date.

**Review**: every value incl. the inferred ones, the approval note (`OrganizationApprovalPolicy`), Publish
(`PublishCompetition`) → the edit page with "Published - here's what's left", or Keep as draft.

## "Get ready" checklist (edit page)
`EventGetReadyChecklist` + one `GetReadyItem` class per row (`src/Services/EventWizard/GetReady/`), facts from
`GetEventGetReadyFacts` (exactly 2 statements, pinned). Rows only when meaningful: event details, rounds and puzzles,
judges (tip until added), registration (x of y taken), table numbers (entries + tables), name tags (QR), timer screen /
OBS overlay / stopwatch test run tips. Collapse remembered per event in localStorage; hidden once the event is over.

## Duplicate with a new date + moving rounds (W4, for every event)
- `duplicate_competition` GET = the form (new date required, name prefilled - an edition's trailing number +1, date
  prefilled one series gap later), POST copies with the `dateFrom` override (`DuplicateCompetition`): rounds and the
  registration window move by whole days, times of day kept in each round's zone (`EventDateShift` +
  `DuplicateDateShift`).
- Edit event: "Also move the rounds by the same number of days" (ticked, shown when the start date changes, with the
  concrete effect under it - "The start moves from 15.06.2027 to 22.06.2027, so every round moves …"; end date follows in
  the browser) →
  `EditCompetition::$moveRoundsWithDate`; not offered once a round has stopwatch state, an official result or a secret
  puzzle (a moved start would move its reveal).

## Click counts (browser-walked, from "Add event" to published)
S1 one round, one puzzle: 5 (was 8-10) · S2 12 monthly dates with a round each: 6 (was ~77) · S3 groups → semis → final:
7 (was ~67) · S4 6 solo + 6 pairs + 6 teams with sums: 7 (was ~132).

## Tests
`tests/Controller/EventWizard/*` (paths, Extras, checklist, series), `tests/Services/EventWizard/*`, handler tests
(`StartEventWizardDraftTwiceTest`, `SetUpEventWizardStructureHandlerTest`, `AddEventWizardEditionsHandlerTest`,
`PublishEventWizardSeriesHandlerTest`), `DuplicateCompetitionControllerTest`, `EditCompetitionMoveRounds*Test`,
`LockUntilCommittedMiddlewareTest`.
