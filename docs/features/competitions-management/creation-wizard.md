# Event creation wizard (as built, 2026-11-17)

Jan: "rework the event creating for competition type to be UX user friendly wizard with choosing / toggling the
features ... if it is single round not needing to bother the user through multiple clicks ... and especially the
edition/series creation". Design of record: `toolkit/W-creation-wizard-research.md` (screens, click counts, risks);
delivery record: `toolkit/STATUS.md` "Creation wizard". **No feature flag** (Jan 2026-11-17): the wizard is every
signed-in player's "Add event" - and since 2026-11-18 the only way to create an event, series or edition on the web
(see "Classic create retired"; the classic form stays only as the edit form of an existing event).

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
| Entry `event_wizard` (`/{_locale}/new-event`) | "What do you want to add?" an event that already happened (Backfill) / one round / several rounds (+ "do competitors qualify?") / a regular series / something else (→ calendar path); organization picker for team members; "Copy one of your events" → Duplicate form | - |
| Calendar ("Something else", 2026-11-18) | Basics → Review | Basics (`?path=calendar`) creates the draft alone - no round, no start time; "What kind of event" asked up front and required, never "Competition"; an online event may stay undated (ongoing), in person needs its day. See "Calendar path" |
| One round | Basics → Puzzle & format → Extras → Review | Basics creates the draft **and its round** (hidden `draftId` makes a double submit idempotent); Puzzle: category, limit, puzzle(s) (catalogue or new), secret + reveal (automatic / when I start the stopwatch / manual), pick K of N |
| Several rounds | Basics → Structure → Puzzles → Extras → Review | Basics without a start time. Structure templates "Qualification" (stages, top N go on) / "Every round counts" (solo/pairs/teams counts, add up per category) - a GET form listing the generated rounds, each with its name, day (prefilled only for a one-day event) and time (empty, required); "Create the rounds" (POST, the template's answer re-read from the query string) writes rounds + starts + steps/standings in one transaction and goes on to Puzzles - a missing start or one outside the event's days is a row error (422), nothing written before (no rounds with made-up starts in between); then the editable table; read-only once any entry, result or stopwatch run exists (re-checked under the lock). Stages → advancement steps, "add up" → one standing per category (`StandingsPerCategory`, never published). Puzzles grid: one row per round, one secret switch, a row error for a puzzle twice in one round; rounds of one category may share a puzzle (S2) - the grid says "Same puzzle as <round>" under a saved one |
| Backfill (WB) | The event → Puzzles → Link my results → Done | see "Backfill" below - submitted at once, never a draft |
| Series | Series Basics → Dates + each date's round → Review | rule (weekly / Nth weekday / picked days, `EditionsPreview` shared with "Add several dates"), start time, a round per date or none; re-sending creates nothing twice; the rule fills the series' "When it happens" in the creator's language while it is empty ("Third Saturday of the month at 7:00 PM", picked days: "At 7:00 PM on each date" - `SeriesScheduleText`, `CompetitionSeries::scheduleFromDatesRule()`, also on "Add the next dates"; a typed text is never replaced); Publish publishes the series and its draft dates. Series page: "Add the next dates" (prefilled from the last dates) and a quick "Add puzzle" per date |

## Challenges template, labels, unboxed, standing rules (SPWS 1:1 demo, 2026-11-24)
Design + as built: [spws-wizard.md](spws-wizard.md). A fourth Structure template **"No - challenges added up per
championship"** (`EventWizardStructureTemplate::Challenges`, World Series style): rows of who / challenges back to back
(1-4) / how many such rounds / limit for the whole round → rounds "Pairs – Challenges 1 + 2" (several = one round
solving them in order), challenges numbered per category, one standing per category with the World Series rules
prefilled and editable. The plan rides to the Puzzles step as `?challenges[<roundId>]=1,2` (a prefill only): n slots,
labels "Challenge k", "Solve all n". The Puzzles grid (toolkit) has a label and "Unboxed" per new puzzle
(`ArrangeRoundPuzzles` / `ChangeRoundUnboxed` inside `SetUpEventWizardRound`), Every round counts and Extras' "Overall
standings" show the standing rules (untouched = today's standing), Review shows labels, unboxed, rules.

## Classic create retired (2026-11-18)

Jan: "retire now, keep classic only for single updates, i would like the wizzard to be default and no 'Use classic form'
button - the classic form ever makes sense only for some specific update".

- **Every create entry point is the wizard**: "Add event" on the events page (header, agenda, search results, foot),
  "You organize", For organizers, an organization's ⋯ menu and its empty "Coming up" (→ `event_wizard` with
  `?organization=`); a series' manage page, its ⋯ menu and its empty upcoming list (→ `event_wizard_series_next_dates`,
  one picked day or many - the classic "Add edition" and "Add several dates" buttons are gone). The entry's "Prefer one
  form? Use the classic form" link is gone (`event_wizard.entry.classic` removed).
- **Old addresses redirect, never 404** (old bookmarks, e-mails): `add_competition` (`/en/add-event`, `?classic=1` too) →
  `event_wizard` (`?organization=` kept), `?recurring=1` → `event_wizard_series_basics`; `add_edition` →
  `event_wizard_series_next_dates`; `add_editions` → the same with its rule's query string (the same fields -
  `EventWizardDatesFormData` extends `AddEditionsFormData`; `return`/`return_title` dropped). A GET answers 302; a POST
  (a classic form left open in a tab) answers 303 to the same place and **creates nothing** - the organiser sets it up
  again in the wizard, nothing is half saved. The controllers (`AddCompetitionController`, `AddEditionController`,
  `AddEditionsController`) are redirect-only.
- **Removed as dead**: `add_competition.html.twig`, `add_edition.html.twig`, `add_editions.html.twig`,
  `approval_note_controller.js`, `EditionFormType` (`EditionFormData` stays - the internal API's edition validation),
  `CompetitionFormType`'s `draft_button` option and the `data-approved-at-once` option attribute, and 23 keys used only
  there (all 6 locales). `AddEditionsFormType`/`AddEditionsFormData`/`EditionsPreview`/`checked_count_controller.js`/
  `_add-editions.scss` stay - the wizard's dates use them.
- **The classic form stays as the edit form** (`edit_competition`, `edit_competition_series`) - nothing changed there.
- What the classic create had and the wizard did not: "Something else" (an event without rounds) → the calendar path
  below; an undated ongoing online event → allowed on the calendar path; several dates at once with their own "Who can
  enter" / kinds / "Save as draft" → the wizard's dates follow the series (draft only while the series is); a single
  edition with its own name, end day, links and description → one picked day in "Add the next dates", the rest on its
  edit page.

### Calendar path ("Something else")
- Why: "A meetup, puzzle swap, workshop or marathon - an event on the calendar, no rounds" was the only entry answer
  still opening the classic form. A several-rounds Basics + Structure would ask about rounds such an event never has.
- `EventWizardPath::Calendar` (`calendar`), steps Basics → Review (no Extras: its switches are a competition's -
  registration and links are on the edit page and its "Get ready" checklist). Basics = `EventWizardBasicsFormType` with
  `calendar: true`: no start time, "What kind of event" (`EventTypesType` without `Competition`) shown first and
  required (`event_wizard.calendar.types_required`), the date optional for an online event
  (`event_wizard.calendar.date_help`). Saved like several rounds' Basics (`saveWithoutRound()`: `AddCompetition`
  draft, no round). Review shows the kinds.
- **No storage - the kinds are the signal**: `GetEventWizardState::$calendarEvent` = `event_types <> '[]' AND NOT
  event_types @> '["competition"]'`; `EventWizardPath::of()` answers Calendar for a non-edition draft with no round and
  that flag. So a classic draft of a workshop resumes on Review, and a several-rounds Basics saved with only
  non-competition kinds goes on as a calendar event: after a no-round Basics the next step is derived from the saved
  draft (`nextAfterBasics()`), never a Structure page that would send it to the edit page. Adding a round later (edit
  page) makes it a one-round / several-rounds draft again.
- **"Get ready" of a calendar event** (FU-V, 2026-11-18): no "Round and puzzle" to-do and no "Add judges" tip -
  `GetReadyFacts::$calendarEvent` (the same kinds signal, no round; `GetEventGetReadyFacts`' first statement, +0
  statements), `RoundsAndPuzzlesItem` / `JudgesItem` answer null. Review's summary line of an undated online event
  starts with "Online event" (no leading separator).

## Puzzle search (G2, 2026-11-17)
Every wizard puzzle picker (`EventWizardPuzzleSlotFormType`, the one-round Puzzle step, the Puzzles grid, Backfill's
Puzzles) starts with **one field, "Find the puzzle"** (name, EAN or brand code - `puzzle_search_picker_controller.js`,
partial `event_wizard/_puzzle_search.html.twig`): `puzzle_search_autocomplete` (`PuzzleSearchAutocompleteController`)
= the site's own search (`SearchPuzzle::byUserInput`, best match, 15 rows, from 2 letters - `PuzzleTextSearch` on the
trigram indexes), each option with its brand (`PuzzleChoicesBuilder::buildWithBrands()`). Never a secret puzzle
(`hide_until`), a secret picture only by name, without picture and codes (as on `/puzzle`); public like the brand
picker - an organiser's own secret puzzles stay on the brand path. A pick hands the option to the brand + puzzle picker
(`time_form_autocomplete#pickFound`): the brand is set silently, the puzzle selected - **the form sends exactly what the
brand path sends** (brand + puzzle id), so no composite changed; the brand's whole list is fetched only when its puzzle
picker is opened. Enter in the field never sends the form (a hardware scanner's digits + Enter pick the only result
once it is in). The brand path (brand → puzzle, a new puzzle by name + pieces) stays below, hidden while the picker is
empty behind "Choose the brand instead, or add a new puzzle", shown by a pick, by a search with no result and whenever
it holds a value or an error.

## Rounds added later (G2)
A round added in the several-rounds schedule table **joins every unpublished standing that adds up every round of its
category** (`StandingsJoiningNewRounds`, decided under the lock in `SetUpEventWizardStructureHandler` on the event as it
was before the save - `EditCompetitionStanding`, name and rules kept). A standing narrowed by hand (a round of its
category left out) or published is never touched; the table says beforehand which standings take new rounds ("new
Solo rounds count in it"). **Advancement steps are never joined** (group, semifinal or final is the organiser's call):
the table says so and links "Set up qualification". The normal "Add round" page is unchanged (the organiser's deep tool;
standings are managed on their page).

Path rule: an edition → series; a draft with no round whose event types are set and are not "Competition" → calendar; any
other draft with no round that is not an edition → several rounds (the one-round path always creates its round with the
draft); one round → one round.

**Extras** (`EventWizardExtras`): registration on MySpeedPuzzling (limit, window, fee), registration/results elsewhere
(links), judges, and the toolkit rows - QR name tags (mode), competitor pages (+ live results, time proposals),
timer screen and stream overlay (preset), overall standings. Off by default, details only when switched on, an
existing display/standing is shown as "added - Manage" (never deleted from here). Each row writes through its own
message only when its value changed, **all in one transaction** (`SaveEventWizardExtras`, G2): a row refused while
writing (the display limit, a standing) writes nothing at all - 422 with that row's error (`EventWizardExtraRefused`
names the row), the event re-read from the database (`EntityManager::refresh`: the rolled-back rows stay in the
request's memory otherwise). The composite holds the event's participants lock and the referees' and displays' keys its
nested messages take (`SerializedByLocks` - `LockUntilCommittedMiddleware` takes every key, sorted, until the commit).
No Extras for a series - every feature belongs to a date.

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
- Round puzzles are not copied, so a `pick` round's copy is "One result for the round": the form lists each such round
  with its format ("Puzzle formats reset … Round 2 was “Pick 2 of 3”") and a warning flash repeats it after the copy
  (competition toolkit G3, round-formats.md "Known edges").
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
`LockUntilCommittedMiddlewareTest`, `PuzzleSearchAutocompleteControllerTest`. Classic create retired:
`AddCompetitionControllerTest` (the old addresses' redirects, POSTs create nothing), `EventWizardCalendarTest`,
`EventWizardNextDatesRuleTest` (the rule/preview tests of the retired "Add several dates", now on "Add the next dates"). Backfill: `EventWizardBackfillTest`, `LinkMyResultsEntryTest`, `LinkSolvingTimesToEventHandlerTest`,
`WaitingEventLinkCanaryTest`.
