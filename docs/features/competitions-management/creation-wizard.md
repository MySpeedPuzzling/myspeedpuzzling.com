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
| Entry `event_wizard` (`/{_locale}/new-event`) | "What do you want to add?" an event that already happened (Backfill) / one round / several rounds (+ "do competitors qualify?") / a regular series / something else (→ classic form); organization picker for team members; "Copy one of your events" → Duplicate form | - |
| One round | Basics → Puzzle & format → Extras → Review | Basics creates the draft **and its round** (hidden `draftId` makes a double submit idempotent); Puzzle: category, limit, puzzle(s) (catalogue or new), secret + reveal (automatic / when I start the stopwatch / manual), pick K of N |
| Several rounds | Basics → Structure → Puzzles → Extras → Review | Basics without a start time. Structure templates "Qualification" (stages, top N go on) / "Every round counts" (solo/pairs/teams counts, add up per category) - a GET form listing the generated rounds, each with its name, day (prefilled only for a one-day event) and time (empty, required); "Create the rounds" (POST, the template's answer re-read from the query string) writes rounds + starts + steps/standings in one transaction and goes on to Puzzles - a missing start or one outside the event's days is a row error (422), nothing written before (no rounds with made-up starts in between); then the editable table; read-only once any entry, result or stopwatch run exists (re-checked under the lock). Stages → advancement steps, "add up" → one standing per category (`StandingsPerCategory`, never published). Puzzles grid: one row per round, one secret switch, row errors for the one-puzzle-per-category rule |
| Backfill (WB) | The event → Puzzles → Link my results → Done | see "Backfill" below - submitted at once, never a draft |
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

## Backfill: an event that already happened (WB, 2026-11-17)

Jan: "the most common real use is BACKFILLING. Players who did NOT organise an event (that's fine) add a past event -
often creating the organization/series too - because they want to link their existing solving times/results to it.
Today they had to create the event, add a round and add the puzzle, and in 99% they skipped the round/puzzle step."

- **Entry**: "What do you want to add?" - "An event that already happened" is the first card (its own group above "An
  event you are organising"; the organiser's one round stays preselected) → `EventWizardPath::Backfill`
  (`event_wizard_past`, steps `PastEvent` → `PastPuzzles` → `LinkResults` → `PastDone`). **Not a draft**: the event is
  submitted for approval when it is created (the admins' e-mail as the classic form; `OrganizationApprovalPolicy` may
  approve it at once), so the path is never derived from a draft (`EventWizardPath::of()` never answers it) and needs
  no "Continue setting up": every later page works on any event the player may edit (Puzzles, Done) or link to (Link),
  the rail is `EventWizardSteps::forBackfill()` (the steps before the shown one done).
- **The event** (`EventWizardPastEventController`, `PastEventFormType`): name, day (past is normal), in person (place,
  country - the player's preselected) / online; "It belongs to a series or an organization" opens: series = the ones
  the player may add dates to (`GetPastEventSeriesChoices` over `CompetitionPermissions::editableSeriesIds()`) or a
  new one by name; organization = the ones they are on the team of (`OrganizationSelectChoices`, said in the help: no
  other can be picked) or a new one by name. `CreatePastEvent` = `AddOrganization` / `AddCompetitionSeries` (pending,
  e-mail as today) + `AddCompetition` or `AddEdition` (a date of the series), one transaction.
- **"Is it one of these?"** before anything is created: a valid submit first asks `GetPastEventMatches` (folded name
  words - a year/number alone never matches - date ±3 days, country / online; public items + the viewer's own
  pending/draft ones, never a rejected one or somebody else's draft). Matches → the same page, 422, cards above the kept
  form, one action each by the player's rights: an event they may edit → its Puzzles step; one they may not → "Link my
  results"; a series they may add dates to → "Add this date to it" (`into_series`, this submit again); one they may
  not → "Link my results" to the series (`?date=` = the typed day, a series pick); an organization they are on the team
  of → "Add it under this organization" (`into_organization`); any other organization → "you are not on its team" +
  its page. "None of these - add it" (`create_anyway`) creates it.
- **Puzzles** (`EventWizardPastPuzzlesController`): rows of category + W1's picker (`EventWizardPuzzleSlotFormType` -
  brand + puzzle autocomplete, a new puzzle by name and pieces; "+ another puzzle" reveals the next of 6 rows). **One
  round per row** (`SetUpPastEventRounds`, the event's lock, one transaction): `AddCompetitionRound` on the event's day
  at noon in its zone with `start_time_known = false` (pages show no time), no limit, named after the category (+ " -
  puzzle" when the event has more than one round of that category) + `AddPuzzleToCompetitionRound`. A row whose puzzle
  is already in a round of that category is skipped (a resent page creates nothing twice). Nothing filled = no round
  ("I don't know - skip"). Somebody who may not edit the event is sent to Link.
- **Link my results** (`EventWizardLinkResultsController`, routes `event_wizard_link_results` /
  `event_wizard_series_link_results`): the player's own results (`GetLinkableSolvingTimes` - solo + pairs/teams they are
  in, never an imported event result nor an unfinished one) of the event's puzzles visible now
  (`RoundPuzzlesVisibleNow`) or within 7 days of its days, the event's days first. **Pre-ticked**: of the event's day
  (and puzzle, when it has puzzles) and linked to nothing; a result linked elsewhere says so, unticked; one already here
  shows a tick. Submit → **`LinkSolvingTimesToEvent`** (all or nothing: `canBeModifiedBy()` per time - every pair/team
  member, never an imported result; an unfinished result refused). The link is set through the edit's entity path
  (`PuzzleSolvingTime::linkToEvent()` = what `modify()` does to the link) and its edition/round derived like the edit
  (`SolvingTimeEventDerivation`, now shared with `EditPuzzleSolvingTimeHandler`); members' badges recalculated.
- **The rule** (`EventLinkRule`): a public event/series → linked now; not public but the player may edit it (their new
  pending event, their own draft, their organization's) → **the link waits** (`waiting_event_link`, one row per time);
  anybody else's draft/pending event, or a rejected one → refused (404 on the page). **A time is never linked to an
  event that is not public**: every reader of `puzzle_solving_time` names a time's event without checking its
  visibility, so instead of changing ~15 readers the link waits and none of them has anything to show
  (`WaitingEventLinkCanaryTest`: profile, puzzle page, result detail - absent while waiting, present once applied).
  **Applied at the approval, in its transaction** (`WaitingEventLinksApplier`, deciding on the entities in memory):
  `ApproveCompetitionHandler`, `ApproveCompetitionSeriesHandler` (its own and its dates' links),
  `PublishCompetitionHandler` / `PublishCompetitionSeriesHandler` (an approved draft published) and
  `OrganizationApprovalPolicy` (approved at once under a trusted organization; an organization's approval approving
  its pending items). An event public at creation (trusted organization) never gets a waiting row - its links are made
  at once. Safety net: `ApplyWaitingEventLinks` in the 15-minute `myspeedpuzzling:reconcile-round-results` cron, after
  the reconcile. A link is applied only while the time still has the link the player saw when ticking it (none, or
  another event it moves from - `WaitingEventLink::seenLinkIsStillOn()`; a link made meanwhile, e.g. in the edit form, wins) and its linker may still change it; **a rejected event's are dropped** (the times stay unlinked), a
  deleted event's go with it (FK cascade). The player reads "Linked once an admin approves the event (usually within a
  day)" on Link, Done and next to such a result on Link; the edit form of a waiting time is untouched (its link is
  empty until applied).
- **Done** (`EventWizardPastDoneController`): the event (+ "Waiting for an admin's approval" while not public), the
  rounds made, results linked (`CountCompetitionResults`) + waiting; Open the event / Link more / Edit.
- **"Were you there? Link my results" on the event and edition pages** (G1, `LinkMyResultsEntry`,
  `event_parts/_link_my_results.html.twig` under the facts strip): for a signed-in player with results most likely
  from this event - `GetLinkableSolvingTimes::countLikelyFromEvent()`, a strict subset of what Link lists, so the entry
  never leads to an empty page: their own / pair / team results (the same `mine`), linked to nothing and no link
  waiting, not imported, not unfinished, a known day; of one of the event's puzzles visible now (the page's own rounds =
  `RoundPuzzlesVisibleNow`) solved from its first day to **7 days after** its last (logged later is common; a solve
  before the event is practice, so the Link page's symmetric window is not used), or - an event without puzzles - on
  its days (what Link ticks). "2 of your results may be from this event" + "Link my results" →
  `event_wizard_link_results?from=event` (an edition is a competition, so the same route). Shown only where linking is
  allowed (`EventLinkRule`: public, or not public but the viewer may edit it, never rejected). **Cost**: no statement
  for a guest, an event that has not begun (its first day after today) or one without days; one `COUNT` over the
  viewer's own times otherwise (`DetailPagesQueryBudgetTest`: the begun Season One edition 19 → 20 signed in; the
  upcoming Hilltop event unchanged). `from=event` (a closed value, never a URL) keeps itself on the form's action,
  hides the rail, adds "Back to the event" and sends the submit to the event's page (flash) instead of Done - the
  entry is gone then (the times are linked or waiting).
- **The waiting note**: a result whose link waits says "Linked to <event> once an admin approves it (usually within a
  day)" (`event_wizard_backfill.link.waiting_for`) on the result detail (`GetPuzzleResultDetail`: a correlated
  subquery in its attempts statement, the name only when the viewer is the time's tracker or a registered member of
  its pair/team - `PuzzleResultAttempt::$waitingEventName`; the template checks `isEditableBy()` too) and on My data
  (`GetMyDataResults`: the same subquery in its page statement - every row is the player's own; under the puzzle's
  name). +0 statements on both. `WaitingEventLinkCanaryTest`: the owner reads it, a guest / another player never sees
  the name.

Click count "backfill a past one-round event and link 2 of my times" (browser-walked, non-admin): **before** ~14
(Add event → classic form → save → Manage rounds → Add round (form) → save → Puzzles → Add puzzle → save, then per
time: edit → pick the event - impossible while it is pending - → save); **after** 5 (Continue on the entry → Continue
on the event form → Save on the puzzles → Link the ticked results → Done page), +1 when "Is it one of these?" shows
candidates ("None of these - add it").

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
`LockUntilCommittedMiddlewareTest`. Backfill: `EventWizardBackfillTest`, `LinkMyResultsEntryTest`, `LinkSolvingTimesToEventHandlerTest`,
`WaitingEventLinkCanaryTest`.
