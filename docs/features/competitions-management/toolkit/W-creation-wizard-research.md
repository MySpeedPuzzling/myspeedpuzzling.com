# W - Event creation wizard: UX research and proposal (step 2, no implementation)

Status: research draft, 2026-11-17. Not built. Written from the code on branch `competition-toolkit` (every claim
below about today's flow was read in the controllers, forms, messages and templates named next to it - not browser
walked; click counts are counted from the templates and redirects and may be off by one or two per scenario).

Jan's request (verbatim):

> rework the event creating for competition type to be UX user friendly wizard with choosing / toggling the features
> and as well if it is single round not needing to bother the user through multiple clicks adding round to be able to
> add puzzle there and especially the edition/series creation but overall i think wizard-like approach would be more
> friendly than native single CRUD and needing to orient itself - i think this would need UX research and we can do as
> step 2

Constraints taken from the repo: reuse the existing messages (no new domain concepts), every toolkit feature opt-in and
off by default (`00-context.md` rule 1), Jan's "smart forms" feedback - *ask only what the system cannot infer;
conditional fields only when meaningful* - all 6 locales, mobile-first, Turbo form gotchas (no 200 on a full-page POST,
explicit `action:` in frames).

---

## 1. Today's creation flow, end to end (as built)

### 1.1 The pages an organiser meets

| # | Page (route) | What it asks / does | Writes (message) | Goes to after save |
|---|---|---|---|---|
| A | Add event (`add_competition`, `AddCompetitionController`, `CompetitionFormType`) | One long form, **17 fields** at once: name, shortcut, description, organization, in person/online, "Recurring" checkbox, location, country, date from, date to, event types, who can enter, (series: when it happens), website, registration link, results link, logo, maintainers. Two buttons: Save / Save as draft | `AddCompetition` or (Recurring ticked) `AddCompetitionSeries` | Event: **back to the edit form of the same event** (`edit_competition`), draft: the event page with the draft banner. Series: `manage_competition_series` |
| B | Edit event (`edit_competition`) | The same form again + a row of 8 buttons: Manage rounds, Registration, Participants sheet, Results overview, Referees, Competition toolkit (flag), Page sections, Duplicate; "Convert to series" card; danger zone | `EditCompetition` | - |
| C | Manage rounds (`manage_competition_rounds`) | "Add round" button + one card per round with 7-9 buttons (Puzzles, Participants, Tables (admin only), Stopwatch, Move, Live entry, Results desk, Seating…) | - | - |
| D | Add round (`add_competition_round`, `CompetitionRoundFormType`) | Name (required), time limit (required), start date+time (time only when the event is single-day), time zone (pre-filled from the other rounds / the country), reveal delay (default 10 min), category solo/pair/team, members per team, results link, badge colour | `AddCompetitionRound` | Manage rounds (C) |
| E | Round puzzles (`manage_round_puzzles`) | List of the round's puzzles, "Add puzzle", per puzzle a reveal form (hide mode, when: automatic/scheduled/manual/**stopwatch**), "How the puzzles are solved: Change" (toolkit), "Arrange" labels | `ChangeRoundPuzzleReveal`, `ArrangeRoundPuzzles` | - |
| F | Add puzzle to round (`add_puzzle_to_round`, `RoundPuzzleFormType`) | Brand (autocomplete), puzzle (autocomplete or new: pieces, photo, EANs, brand codes), "Hide until the round starts" + hide mode | `AddPuzzleToCompetitionRound` | Round puzzles (E) |
| G | Edit round (`edit_competition_round`) | As D + (toolkit flag) "How are this round's puzzles solved?" total / pick K - **only meaningful after the puzzles exist** (`to_solve_help`: "Add the round's puzzles first") | `EditCompetitionRound` (`puzzleFormat`, `puzzlesToSolve`) | C |
| H | Series management (`manage_competition_series`) | Editions table, "Add edition", "Add several dates", page sections, create organization | - | - |
| I | Add edition (`add_edition`, `EditionFormType`) | name, dates, description, links, types, who can enter | `AddEdition` | H |
| J | Add several dates (`add_editions`, `AddEditionsFormType`) | rule (every week on / Nth weekday of the month / picked days), starting, count, name pattern, types, who can enter → GET preview with a checkbox per date → POST | `AddEditions` (≤ 24, idempotent ids) | H - **editions without any round** |
| K | Registration (`manage_competition_registration`) | managed on/off, capacity, opens/closes, zone, fee text, payment instructions | `ChangeCompetitionRegistrationSettings` | - |
| L | Referees (`competition_referees`) | add players (autocomplete), QR for the live entry | `AddCompetitionReferee` | - |
| M | Competition toolkit hub (`competition_toolkit`, flag) | 5 cards: Competitor pages & QR (inline form: QR mode, pages, live results, time proposals), Displays & overlays (→ list → add → editor), Standings & bracket (→ standings page incl. "one per category" preset, → advancement steps page), Round formats (read-only summary linking to E), Judges (link to L) | `ChangeCompetitorPageSettings`, `AddCompetitionDisplay`, `CreateCompetitionStanding`, `CreateCompetitionAdvancementStep` | - |
| N | Seating (`round_seating`, per round) / table layout (`manage_round_tables`, **admin only**) | auto-assign tables once entries exist | `AssignTableNumbers`, `GenerateTableLayout` | - |
| O | Name tags (`competition_name_tags`) | print page, kinds people/lineups/tables/entries | - | - |
| P | Duplicate (button on B, ⋯ menu) | copies details, settings, rounds (no puzzles), sections, standings, displays, advancement steps | `DuplicateCompetition` (web: **no overrides**) | B of the copy (a draft) |
| Q | Publish (draft banner) | | `PublishCompetition` / `PublishCompetitionSeries` | - |

### 1.2 Pain points found in the code

1. **Creating an event ends on the same form you just filled** (non-draft → `edit_competition`). Nothing says "now add
   your round". The next step is a button among eight.
2. **A single-round event costs a full round form** with fields the system already knows: the date (the event's), the
   zone (the country's), the name (for one round, "the round" *is* the event), the badge colour, the reveal delay.
3. **Puzzles live two levels below rounds**: rounds list → round's puzzles page → add form → back. Three page loads for
   what an organiser thinks of as "the puzzle of my competition".
4. **Order dependencies the UI does not explain**: "pick K of N" can only be set on the *edit* round form *after* the
   puzzles are added; "reveal at stopwatch start" is chosen per puzzle *after* adding it (the add form only offers
   "hide until the round starts", which means the automatic reveal).
5. **Series are a checkbox** ("Recurring") on the event form that hides fields; the editions that "Add several dates"
   makes have **no rounds** - every edition then needs C → D → E → F by hand (6 clicks × N editions).
6. **The web "Duplicate" does not move dates**: `DuplicateCompetition` supports a `dateFrom` override that shifts
   `dateTo`, every round start and the registration window (`DuplicateDateShift`), but only the internal API passes it.
   On the web the copy keeps the old dates, and editing the copy's dates does not move its rounds
   (`EditCompetitionHandler` never touches rounds). Since editions are dated by their first round
   (`OccurrenceDates`), a copy whose rounds were not edited still shows on the old day. This is the most likely real-life
   trap for series organisers today - and a cheap fix independent of the wizard (W4).
7. **Toolkit features are scattered over six places**: the hub (4 cards), referees page, registration page, name tags
   page, seating per round, table layout (admin only). A new organiser cannot tell what exists or what is "on".
8. **The add form shows 17 fields to everybody**; registration/results links are irrelevant once managed registration
   or official results are used; shortcut, eligibility, event types and maintainers are rarely needed at creation.
9. **The one-round-per-category invariant** (`PuzzleAlreadyInCompetitionRoundCategory`): the same puzzle cannot be in
   two solo rounds of one event. A "groups solve the same puzzle" set-up has to be modelled differently (one round with
   seating groups, or different puzzles) - a wizard must know this instead of failing at the last step.

### 1.3 Step / click counts today (4 scenarios)

Convention: a *click* = a press that loads a page or submits a form (typing and picking inside a form are not counted;
the number of *fields filled* is given separately). Starting point: the "Add event" button. Catalogue puzzles assumed
(a new puzzle adds ~5 fields). Back-navigation clicks excluded (they would add ~20-30 %).

**S1 - simple one-round solo competition, one puzzle**

| Step | Page | Clicks | Fields |
|---|---|---|---|
| Add event, Save | A → B | 2 | ~6 (name, place, country, dates, online/in person) |
| Manage rounds | C | 1 | |
| Add round, Save | D → C | 2 | ~4 (name, limit, start time, category) |
| Puzzles | E | 1 | |
| Add puzzle, Save | F → E | 2 | 2 (brand, puzzle) |
| (if secret: change reveal) | E | 1-2 | 2 |
| **Total** | **5 distinct pages** | **8 (10 with secret)** | **~12-14** |

**S2 - series with monthly editions, 1 round each (12 dates)**

| Step | Clicks | Fields |
|---|---|---|
| Add event with "Recurring", Save → series page | 2 | ~6 |
| Add several dates → Preview → Create | 3 | ~6 (rule, Nth, weekday, starting, count, name pattern) |
| Per edition: Manage rounds → Add round → Save → Puzzles → Add puzzle → Save | 6 × 12 = 72 | ~6 × 12 = 72 |
| (Publish each draft edition, if drafts) | 12 | |
| **Total** | **~77 (89 with drafts)** | **~84** |

Alternative today via Duplicate: build one edition fully (8 clicks), then per copy Duplicate → fix dates → Save →
Manage rounds → Edit round → fix start → Save → Publish ≈ 7 × 11 = 77 - about the same, plus the date trap (pain 6).

**S3 - WJPC-like: 4 qualification groups → 2 semifinals → final (7 solo rounds), secret puzzles revealed at the
stopwatch start, judges, QR name tags, timer screen**

| Step | Clicks |
|---|---|
| Event (A → B), Manage rounds | 3 |
| 7 × (Add round, Save) | 14 |
| 7 × (Puzzles, Add puzzle, Save, reveal → "when the stopwatch starts" + Save) | 7 × 5 = 35 |
| Advancement steps: hub → steps page → 2 × (Add, Save) | 6 |
| Registration: open, Save | 2 |
| Judges: Referees page, add, Save | 2 |
| Competitor pages & QR: hub card form Save | 1 |
| Timer screen: Displays → Add (kind, preset, name) → Save (→ editor, optional) | 3 |
| Name tags (print, later) | 1 |
| **Total** | **~67 clicks over ~12 page types, ~25 form submits** |

**S4 - World-Series-like: 6 solo + 6 pair + 6 team rounds, sum standings per category, "pick 2 of 3" in 6 rounds**

| Step | Clicks |
|---|---|
| Event, Manage rounds | 3 |
| 18 × (Add round, Save) - category + team size on each | 36 |
| 12 single-puzzle rounds × (Puzzles, Add, Save) | 36 |
| 6 pick rounds × (Puzzles + 3 × (Add, Save)) | 42 |
| 6 pick rounds × (Edit round, set pick 2 + Save) | 12 |
| Standings: hub → standings → "One per category" | 3 |
| **Total** | **~132 clicks**, every round form re-asking zone, colour, reveal delay |

---

## 2. Patterns worth borrowing (desk research)

From tools organisers already know (product knowledge, not a fresh web crawl - worth a 30-minute re-check before W1):

| Tool | Pattern | What we take |
|---|---|---|
| **Luma** (lu.ma) create event | One screen that *looks like the finished event card*; title, date, place inline; everything else ("Tickets", "Require approval", "Capacity") is an optional row with a sensible default; publish is one button | The basics step looks like the event header; options as one-line rows, closed by default |
| **Eventbrite** create | Build → Tickets → Publish as a left rail of steps; you can jump between them; "Save draft" always visible; a publish checklist that blocks only on what is required | A step rail that is also the resume point; draft saved automatically; required vs recommended separated |
| **start.gg / Toornament / Challonge** | Pick a *format* first (single elimination, pools → bracket, Swiss); **stages/phases** are generated from a few numbers (number of pools, how many advance), editable later | "Structure" step for multi-round events: stages from numbers, advancement generated, edit afterwards on the normal pages |
| **Typeform / Tally** | One question per screen, branching on answers, progress shown, each answer saves | The entry question and the branching (single round? series?); never show a question whose answer is already implied |
| **Shopify / Stripe onboarding checklist** | After sign-up, a "Setup guide" card with 5-7 tasks, each derived from data (done when the thing exists), dismissable, collapsible | "Get ready" checklist on the event's edit page, derived from data like our existing `GettingStartedProgress` (player onboarding) |
| **Google Calendar recurring events** | Recurrence as a sentence ("Monthly on the third Saturday") with a preview of dates | We already have it (`add_editions`); move it *into* the series path and add the per-date round template |
| **GitHub repo templates / Notion templates** | Start from a template, then strip | Templates = a pre-filled wizard, never a hidden configuration |

Principles we derive (and Jan's rule restated):

1. **Ask only what cannot be inferred.** Date → round start date; country → zone; one round → its name; category →
   team size prompt only for teams; secret puzzle question only when a puzzle is chosen.
2. **Branch early, once.** One entry question decides which steps exist.
3. **The draft is the wizard state.** No separate wizard storage - the first step creates a real draft event/series
   (`isDraft: true`); every later step writes through the existing messages. Resuming = opening the draft; the step
   shown is derived from what the draft lacks.
4. **Opt-in features as plain toggles** with one sentence each, off by default, details asked only when switched on
   (progressive disclosure), the deep editors (display editor, seating) stay on their own pages and are linked from
   the checklist.
5. **Nothing irreversible before Review** except the draft itself - publish/submit-for-approval happens only on the
   last step.

---

## 3. Proposed design

### 3.1 Entry: "What are you organizing?"

```
+--------------------------------------------------------------+
|  Add an event                                        [x]     |
|                                                              |
|  What are you organizing?                                    |
|                                                              |
|  ( ) A competition - one round, one puzzle                   |
|      The most common: everyone solves the same puzzle.       |
|  ( ) A competition with several rounds                       |
|      Solo, pair and team rounds, qualification, finals.      |
|  ( ) A regular series                                        |
|      The same event every week or month (club nights,        |
|      monthly online cups).                                   |
|  ( ) Something else (meetup, swap, workshop, marathon)       |
|      Just an event on the calendar, no rounds.               |
|                                                              |
|  Under: [ Wisconsin JPA            v ]  (only if on a team)  |
|                                                              |
|  [Continue]          Prefer one form? Use the classic form   |
+--------------------------------------------------------------+
```

- "Something else" = today's form minus rounds (event types pre-ticked from the choice) - no wizard needed.
- "Several rounds" asks one follow-up on the next screen: *Do competitors qualify for later rounds?* (Yes - groups,
  semifinals, final / No - every round counts, times are added up / Not sure yet). This chooses the S3 or S4 template;
  "Not sure" = an empty round list.
- Organization picker only when the player is on a team (same rule as today, `OrganizationSelectChoices`); preselected
  from `?organization=`.
- **Duplicate stays the fastest path for a repeat of last year's event** - the entry screen lists "Copy one of your
  events" when the organiser has any (`DuplicateCompetition` with the new date as override, see W4).

### 3.2 Step map

```
Entry ─┬─ One round ──── Basics ─ Puzzle & format ─ Extras ─ Review
       ├─ Several ────── Basics ─ Structure ─ Puzzles ─ Extras ─ Review
       ├─ Series ─────── Basics ─ Dates ─ Each date's round ─ Extras ─ Review
       └─ Something else ─ classic form (shortened)
```

Step rail on desktop (left), "Step 2 of 4" bar on phones; every step has *Back*, *Save & continue*, and a quiet
*Finish later* (the draft already exists after Basics).

### 3.3 Step "Basics" (all paths)

```
+--------------------------------------------------------------+
| 1 Basics  ·  2 Puzzle  ·  3 Extras  ·  4 Review              |
|--------------------------------------------------------------|
| Name             [ Minnesota Fall Speed Puzzle       ]       |
| Where            (•) In person  ( ) Online                   |
|   Place          [ St Paul Library, St Paul          ]       |
|   Country        [ United States        v ]  (from profile)  |
| When             [ 14.12.2026 ]  starts at [ 10:00 ]         |
|                  + it lasts more days                        |
|                                                              |
| ▸ More details (description, website, logo, co-organisers)  |
|                                                              |
|                        [Finish later]  [Save & continue ->]  |
+--------------------------------------------------------------+
```

- Fields: name, in person/online, place + country (country pre-filled from the organiser's profile or organization),
  date, *start time* (new here: it becomes the single round's start), "it lasts more days" reveals the end date.
- Collapsed "More details": description, website, logo, shortcut, maintainers, who can enter, event types. Registration
  and results links are *not* here - they move to Extras ("Registration elsewhere?" row) because they are alternatives
  to features.
- Series path: "When" becomes the rule (next step), Basics has no date.
- Writes: `AddCompetition(isDraft: true)` / `AddCompetitionSeries(isDraft: true)` on first save, `EditCompetition` /
  `EditCompetitionSeries` when coming back. Admin e-mail is only sent on publish (already true for drafts).

### 3.4 Step "Puzzle & format" (one-round fast path)

```
+--------------------------------------------------------------+
| Who competes?   [Solo] [Pairs] [Teams of _4_]                |
| Time limit      [ 90 ] minutes                               |
|                                                              |
| Puzzle          [ Ravensburger … search by name or EAN    ]  |
|                 ( ) Decide later                             |
|   ┌──────────────────────────────────────────┐               |
|   │ [img] Ravensburger · Tropical Fish · 500 │  change       |
|   └──────────────────────────────────────────┘               |
|   [ ] Keep it secret until the start                         |
|       └ reveal: (•) when I start the stopwatch               |
|                 ( ) 10 min after the planned start           |
|                 ( ) at a time I choose / manually            |
|                                                              |
| + Competitors choose between several puzzles (toolkit)       |
|                                                              |
|                       [<- Back]  [Save & continue ->]        |
+--------------------------------------------------------------+
```

- The round is created silently: name = the category label ("Solo") or the event name - editable later; start = Basics
  date + time; zone = `RoundTimezone::resolve()` from the country (today's logic in `AddCompetitionRoundController`);
  reveal delay default; colour automatic (`RoundBadgeColor`).
- Puzzle not in the catalogue → "Add a new puzzle" expands brand / name / pieces / photo inline (the existing
  `RoundPuzzleFormType` fields).
- "Keep it secret" appears only once a puzzle is chosen; the reveal choice appears only when ticked. "When I start the
  stopwatch" is offered only with the toolkit flag; the default stays today's automatic reveal.
- "Several puzzles" expands to "+ add another puzzle" and "Each competitor solves [all | K] of them" - the order
  dependency of today (pain 4) disappears because the wizard writes puzzles first, format second, in one request.
- Writes: `AddCompetitionRoundWithPuzzles` (exists - one transaction, used by the internal API) for round + plain
  puzzles; a secret or new puzzle goes through `AddPuzzleToCompetitionRound` (it carries `hideMode` +
  `shownAutomaticRevealAt`), then `ChangeRoundPuzzleReveal` for the stopwatch / scheduled / manual mode, then
  `EditCompetitionRound(puzzleFormat, puzzlesToSolve)` for pick K. A thin composite message (pattern of
  `AddCompetitionRoundWithPuzzles`: one handler dispatching the existing messages inside one transaction) keeps it
  atomic - an orchestration, not a domain concept.

### 3.5 Step "Structure" (several rounds)

Template "Qualification" (S3):

```
+--------------------------------------------------------------+
| How is it played?                                            |
|                                                              |
|  Stage 1  [Groups      ]  [ 4 ] rounds   solo v  90 min      |
|             top [ 10 ] of each group go on          [remove] |
|  Stage 2  [Semifinals  ]  [ 2 ] rounds   solo v  90 min      |
|             top [ 15 ] of each go on                [remove] |
|  Stage 3  [Final       ]  [ 1 ] round    solo v  120 min     |
|  + add a stage                                               |
|                                                              |
|  Schedule                                                    |
|   Groups A  14.12. 09:00   B 11:00   C 13:00   D 15:00       |
|   Semifinal 1 15.12. 10:00  Semifinal 2 13:00                |
|   Final 15.12. 16:00                                         |
|   (filled every 2 h from the event start - change any)       |
|                                                              |
|  Preview:  A B C D ──▶ SF1 SF2 ──▶ Final                     |
+--------------------------------------------------------------+
```

Template "Every round counts" (S4):

```
+--------------------------------------------------------------+
|  Solo   [ 6 ] rounds   60 min                                |
|  Pairs  [ 6 ] rounds   60 min                                |
|  Teams  [ 6 ] rounds  of [4]  60 min                         |
|  [x] Add up the times per category (overall standings)       |
|      ▸ rules: time limit + penalty per missing piece …       |
|  Order: (•) solo, pairs, teams interleaved  ( ) by category  |
|  Schedule table as above, editable                           |
+--------------------------------------------------------------+
```

- Round names generated ("Group A", "Semifinal 1", "Solo 1"…) and editable in the schedule table.
- Writes: `AddCompetitionRound` × N; `CreateCompetitionAdvancementStep` per stage arrow (settings: top N per round /
  overall, distribution) - toolkit flag only; otherwise the stages are just rounds and qualification stays the manual
  desk action of today; `CreateCompetitionStanding` per category (the "one per category" preset logic of
  `CreateStandingsPerCategoryController`).
- Same-puzzle groups (pain 9): if the organiser says "all groups solve the same puzzle", the wizard proposes *one round
  with seating groups* instead, or explains the rule - to be validated with organisers (Q3).

**As built (W5, 2026-11-17):**
- Path: a draft without any round (not an edition) is on the several-rounds path (`EventWizardPath::of()`) - the
  one-round path creates its round with the draft, so a several-rounds draft between Basics and Structure (or "Not sure
  yet") resumes at Structure without anything stored; a classic-form draft without rounds resumes there too. Structure
  never saves exactly one round (that is the one-round path).
- Basics creates no round on this path; its start time goes to Structure as `?start=` (the first round's start). Once
  the rounds exist, Basics hides the start time and a new date moves the rounds along (`EditCompetition
  moveRoundsWithDate`, only while none has begun or holds a secret puzzle).
- Structure = two forms: the template ("Create the rounds" - writes at once, then the schedule table; behind "Start
  again from a template" later, which deletes every round and the standings left without them) and the schedule table
  (name, day, time, who competes, minutes, remove, spare rows) - both one transaction (`SetUpEventWizardStructure`).
  Read-only once a round has an entry, a linked result or a stopwatch run (`GetEventWizardStructureLock`).
- **Schedule rule**: rounds spread evenly over the event's days - ceil(rounds / days) a day in order, each day's first
  at the start time, then every N minutes (default 120). Names: first stage lettered ("Group A"), later stages numbered
  ("Semifinal 1"), one-round stages keep the stage name; "Solo 1", "Pairs 1", "Teams 1" interleaved or by category.
- Toolkit (W7, `COMPETITION_TOOLKIT_MANAGE`): a stage's "top N of each round go on" → `CreateCompetitionAdvancementStep`
  (`single` for one target round, `balanced` for several, per-round count; mixed categories refused on the field);
  "add up the times per category" → `StandingsPerCategory` (the standings page's "One per category" preset, shared),
  unpublished. Without the toolkit neither field exists.

### 3.6 Step "Puzzles" (several rounds)

One row per round, puzzle picker inline, "Decide later" per row, one switch on top: *Keep every puzzle secret until
its round starts* with the reveal mode chosen once (per-row override behind "change"). Pick-K rounds show "+ another
puzzle" and the K field in the row.

```
| Round       | Puzzle(s)                              | Secret |
| Group A     | [Ravensburger Tropical… ] [+]          |  ✓     |
| Group B     | Decide later                           |  ✓     |
| Solo 3      | [A] [B] [C]  each solves [2] of 3      |  -     |
```

Writes as in 3.4, per round.

**As built (W5):** one form for the grid (`SetUpEventWizardPuzzles` = W1's `SetUpEventWizardRound` per changed row, one
transaction); per row W1's picker (brand + puzzle autocomplete, the brands handed to every picker once by
`event_wizard_puzzles_controller.js`), a new puzzle by name + pieces (its photo/codes on the round's own puzzle form),
"+ another puzzle" (up to 4 pickers per submit through `<template>`s; without JavaScript, or after the fourth, it saves
and comes back to the row), "each competitor solves K" (toolkit or a round already `pick`; empty = all), "Change for
this round" (as above / not secret / a reveal). Checked up front as row errors (422, nothing written): the same puzzle
twice in one category (chosen twice or already in a round), the automatic reveal shown (`automatic_reveal_at[<round>]`)
vs the round's now, K above the round's puzzles; another organiser's secret puzzle refused (`SecretPuzzleAccess`).
Done when every round has a puzzle.

### 3.7 Steps "Dates" + "Each date's round" (series)

```
+--------------------------------------------------------------+
| When does it happen?                                         |
|  (•) Every month on the [third v] [Saturday v]               |
|  ( ) Every week on [Tuesday v]                               |
|  ( ) I'll pick the days   [calendar]                         |
|  Starting [ 01.01.2027 ]   for [ 12 ] dates                  |
|  Starts at [ 19:00 ]                                         |
|                                                              |
|  Each date is called  [ {series} #{n} ]  → "Cup #1" …        |
|                                                              |
|  Each date has a round:                                      |
|   Who competes [Solo][Pairs][Teams]   Time limit [ 60 ] min  |
|   ( ) No rounds - just dates on the calendar                 |
|                                                              |
|  Preview: [x] 16.01. Cup #1  [x] 20.02. Cup #2  … (12)       |
+--------------------------------------------------------------+
```

- Puzzles are *not* asked per date here (monthly cups usually decide later); the checklist and the series page get a
  per-edition "Add puzzle" quick action.
- Writes: `AddCompetitionSeries` (Basics) + `AddEditions` (existing, idempotent ids from the preview) + per new edition
  `AddCompetitionRound` (a composite like 3.4, under the `series-editions-*` lock key `AddEditions` already uses).
- Later "Add the next dates" reuses the same screen from the series page (it *is* today's `add_editions` plus the round
  template).

### 3.8 Step "Extras" - feature toggles mapped to existing features

All off by default. Each row: one sentence, a switch; switching on reveals only that feature's must-ask fields; deeper
setup is linked from the checklist (not done inside the wizard).

| Toggle (user words) | Asked when on | Writes | Flag |
|---|---|---|---|
| Registration on MySpeedPuzzling | capacity, opens/closes, fee text (optional) | `ChangeCompetitionRegistrationSettings` | - |
| Registration / results elsewhere | the link(s) | `EditCompetition` (`registrationLink`, `resultsLink`) | - |
| Judges enter results on their phones | judges (player search) | `AddCompetitionReferee` × n | - |
| Name tags with QR codes | QR mode: per competitor / per table / per competitor per round | `ChangeCompetitorPageSettings(qrTagMode)` | toolkit |
| Competitor pages | live results before publishing? (default no) | `ChangeCompetitorPageSettings(competitorPages, liveResultsBeforePublishing)` | toolkit |
| Competitors can propose their own time | - (needs competitor pages) | `ChangeCompetitorPageSettings(timeProposals)` | toolkit |
| Timer screen for the hall | style preset (thumbnails of the 3 screen presets) | `AddCompetitionDisplay(kind: screen, preset)` | toolkit |
| Overlay for a live stream | preset (3 OBS presets) | `AddCompetitionDisplay(kind: overlay, preset)` | toolkit |
| Overall standings | (pre-filled per category from Structure) | `CreateCompetitionStanding` | toolkit |
| Table numbers | - (seating happens once people are in) | none - becomes a checklist item | - |

Toolkit rows are shown only for whom `COMPETITION_TOOLKIT_MANAGE` grants, exactly like the hub today.

### 3.9 Step "Review"

```
+--------------------------------------------------------------+
| Review                                                       |
|  Minnesota Fall Speed Puzzle            edit                 |
|  14.12.2026 10:00 · St Paul, US · under Minnesota Puzzlers   |
|  Round: Solo · 90 min · Ravensburger Tropical Fish (secret,  |
|         revealed when you start the stopwatch)      edit     |
|  Extras: registration (80 places), judges: 3, timer screen   |
|                                                     edit     |
|                                                              |
|  ⓘ Under an approved organization: public right away.        |
|    (or) An admin checks new events - usually within a day.   |
|                                                              |
|  [Keep as draft]                     [Publish]               |
+--------------------------------------------------------------+
```

- The approval note reuses `approval_note_controller.js` logic (`OrganizationApprovalPolicy`).
- Publish = `PublishCompetition` / `PublishCompetitionSeries` (+ editions' publish if they were created as drafts with
  the series - the series path publishes the drafts it made).
- After Publish: the **event's edit page with the checklist on top** - never the bare form again (pain 1).

### 3.10 After creation: "Get ready" checklist on the event page

```
+--------------------------------------------------------------+
| Get ready for 14 Dec                       3 of 7 done  [–]  |
|  ✓ Event details                                              |
|  ✓ Round and puzzle                                           |
|  ✓ Judges added (3)                                           |
|  ○ Open registration - 0 of 80 places taken        → open     |
|  ○ Assign table numbers (after registration closes)→ seating  |
|  ○ Print name tags                                  → print   |
|  ○ Try the timer screen on the hall's TV           → open     |
|  ○ Test run: start the stopwatch without "Reveal"  → stopwatch|
+--------------------------------------------------------------+
```

- Items derived from data only (like `GettingStartedProgress`): rows exist only for features switched on; done = the
  data exists (round has a puzzle, referees > 0, tables assigned for every entry - `seating/_readiness.html.twig`
  already computes this). "Try" items are done when the organiser opens the link once (cheap per-competition
  timestamps would be new data - v2; v1 shows them as tips without a tick).
- Collapsible; hidden after the event's last round finished.
- The 8-button row of the edit page stays below (existing organisers' muscle memory).

### 3.11 Click counts: before → after (estimates)

| Scenario | Today | Wizard | What disappears |
|---|---|---|---|
| S1 one round, one puzzle | 8 clicks, 5 pages, ~13 fields | **4** (Entry, Basics, Puzzle, Review→Publish; Extras skipped by "Continue") , 1 flow, ~7 fields | round form, puzzles page, add-puzzle page |
| S2 monthly series, 12 dates | ~77 (+12 publishes) | **6** (Entry, Basics, Dates+round, Extras, Review/Publish) + 3 per edition when its puzzle is known (quick add on the series page) | the per-edition round chain |
| S3 WJPC-like | ~67, ~12 page types | **~9** in the wizard (Entry, follow-up, Basics, Structure, Puzzles, Extras, Review) + checklist items later (seating, tags, display editor) ≈ 15 total | 7 round forms, 7 reveal changes, 2 advancement forms, 3 settings pages |
| S4 World-Series-like | ~132 | **~8** + puzzle picking inline (18 rows) ≈ 10 | 18 round forms, 18 puzzle pages, 6 pick edits |

---

## 4. Data written per step (existing messages only)

| Step | Messages | Notes |
|---|---|---|
| Basics | `AddCompetition` / `AddCompetitionSeries` (`isDraft: true`), later `EditCompetition` / `EditCompetitionSeries` | draft = wizard state; no admin e-mail until publish |
| Puzzle & format | `AddCompetitionRoundWithPuzzles` (exists) or composite of `AddCompetitionRound` + `AddPuzzleToCompetitionRound` + `ChangeRoundPuzzleReveal` + `EditCompetitionRound` | composite handler = one transaction like `AddCompetitionRoundWithPuzzles` |
| Structure | `AddCompetitionRound` × n, `CreateCompetitionAdvancementStep` × stages-1, `CreateCompetitionStanding` × categories | re-entering the step after rounds exist edits (`EditCompetitionRound`, `DeleteCompetitionRound`) - only while no entries/results |
| Puzzles | `SetCompetitionRoundPuzzles` (whole list per round, invariant checked first) for plain ones, `AddPuzzleToCompetitionRound` for secret/new ones, `ChangeRoundPuzzleReveal` | `shownAutomaticRevealAt` must be the moment the wizard showed - the existing safety contract |
| Dates + round | `AddEditions` + `AddCompetitionRound` per edition | idempotent ids from the preview |
| Extras | `ChangeCompetitionRegistrationSettings`, `AddCompetitionReferee`, `ChangeCompetitorPageSettings`, `AddCompetitionDisplay`, `CreateCompetitionStanding` | each only when switched on |
| Review | `PublishCompetition` / `PublishCompetitionSeries` | |
| Copy an event | `DuplicateCompetition` with `dateFrom` (+ name) override | the web gains the override the API already has |

No new entity, column or table is required for v1. Resume needs no storage: the step is derived from the draft
(no rounds → round step; rounds without puzzles → puzzles step, and so on).

---

## 5. Risks

| Risk | Mitigation |
|---|---|
| **Existing organisers** (WJPC, WI-JPA, Sarah Does Puzzles, Minnesota, CSP…) know the classic pages | Wizard only *creates*; every management page stays; "Use the classic form" link during rollout; feature flag (new `EVENT_CREATION_WIZARD`, documented in `docs/features/feature_flags.md`), admins first |
| Abandoned drafts pile up | drafts are invisible anyway; "You organize" already lists them; optional later prune/nudge |
| Hidden defaults surprise (round name, zone, reveal delay) | Review step shows every inferred value with "edit"; nothing inferred is invisible |
| **Secret puzzles revealed early** ("would be a disaster") | wizard writes through the same messages with `shownAutomaticRevealAt` / `SecretPuzzleAccess`; draft events are invisible until publish; secret default reveal mode must be shown on Review |
| Re-entering Structure after people registered | Structure/Dates steps read-only once entries or results exist; edits go to the normal pages |
| Toolkit flag mixing | toolkit rows absent for non-granted organisers - wizard renders byte-identical paths otherwise; tests per flag state |
| **Translations ×6**: estimate 150-200 new keys (steps, templates, toggles, checklist, help) | English while building, all 6 locales before the PR (CLAUDE.md); avoid fixed words listed in `.claude/rules/translations.md` (never "rival", say "needs verification"); plural keys tested like `OnlinePluralMessagesTest` |
| **Mobile**: schedule tables and stage builder at 375 px | stack rows into cards on phones, one stage per card, date/time native-feel pickers; browser walk at 375/768/1280 in 6 locales (button overflow recipe from memory) |
| Turbo: multi-step full-page POSTs | each step redirects (303) to the next step URL, 422 on errors; no 200 answers |
| Same-puzzle invariant breaks a template | Structure step validates before writing (`PuzzleAlreadyInCompetitionRoundCategory` checked up front) and explains |
| Series edition dated by its rounds vs edition dates | round start always derived from the edition date + start time, never typed twice |
| Scope creep into a "settings editor" | wizard creates; deep editing (display editor, seating, advancement edge cases) stays on existing pages |

---

## 6. Delivery plan (work packages)

| WP | Content | Depends | Size |
|---|---|---|---|
| **W0** | Validate with 2-3 organisers (§7), adjust this doc | - | 1 week calendar, ~3 h work |
| **W4** (do first, independent) | Web "Duplicate" asks for the new date (+ name) and passes the existing `dateFrom` override → rounds and registration move; editing an event's date offers "move the rounds too" | - | 1 d |
| **W1** | Entry screen + one-round fast path (Basics, Puzzle & format, Review) behind `EVENT_CREATION_WIZARD`; composite round message; redirect to edit page | W0 | 3-4 d |
| **W2** | Resume (step derived from the draft), step rail, "Finish later"; "Get ready" checklist on the edit page (data-derived items for rounds/puzzles/registration/referees/seating/tags) | W1 | 2-3 d |
| **W3** | Series path: Dates + per-date round template (composite of `AddEditions` + rounds), "Add the next dates" from the series page, per-edition quick "Add puzzle" | W1 | 3 d |
| **W5** | Several rounds: Structure (templates "every round counts" / "qualification" / empty), schedule table, Puzzles grid incl. pick-K and secret defaults | W1 | 4-5 d |
| **W6** | Extras toggles (registration, judges, toolkit rows) + checklist items for each | W2, W5 | 2-3 d |
| **W7** | Stage arrows → advancement steps, categories → standings (toolkit flag) | W5, toolkit A6/A8 on main | 2 d |
| **W8** | All 6 locales, 375 px browser walks per path, canary checks (drafts), docs (`docs/features/competitions-management/creation-wizard.md`, events rule file) | all | 2 d |
| **W9** | Flag on for everyone; after a few weeks decide on retiring the classic add form (keep edit pages) | W8 + feedback | - |

Order recommendation: W4 → W1 → W2 → W3 (series is Jan's explicit pain) → W5 → W6 → W7 → W8.

---

## 7. Validation with real organisers

### 7.1 Questions (5-8)

1. **Walk me through how you set up your last event on MySpeedPuzzling** - where did you hesitate, what did you skip,
   what did you do outside MSP (spreadsheet, Google Form)? (baseline, no leading)
2. When you create an event, **do you already know the puzzle?** Is it usually secret? When must it be revealed
   (at the start / when you start the clock / later)? *(decides whether the puzzle belongs in the creation flow or in
   the checklist)*
3. **Do groups/heats solve the same puzzle or different ones?** How do people qualify (top N per group, best times
   overall, best of each country)? *(validates the Structure step and pain 9)*
4. For series (Sarah, WI-JPA monthly nights): **is every date the same format?** Do you plan dates months ahead or
   one at a time? Would "12 dates with a round each" in one go help, or create clutter?
5. **Which of these would you switch on** for your next event: own registration, judges on phones, QR name tags,
   competitor pages, timer screen, stream overlay, overall standings? Which words are unclear?
6. **Who sets up the event** - one person, or several co-organisers in turns? Do you need to stop and continue later?
7. **Phone or computer** when you set up? When you run the event?
8. Show the S1 wireframe: **"What would you expect to happen after Publish?"**

### 7.2 How to run it (lean, one week)

- **Who**: 1 large championship (WJPC team), 1 regular series (Sarah Does Puzzles or Wisconsin WI-JPA), 1 smaller
  regional organiser (Minnesota). Jan contacts them (msp-mailer draft, not sent by an agent).
- **Format**: 30-minute video call each, screen shared. 10 min questions 1-7 (open, no product talk), 15 min clickable
  low-fi prototype (these ASCII wireframes turned into 6-8 grey static HTML screens, or a Figma/pen.dev click-through),
  task: "Set up your next event" - think aloud, observe where they stop; 5 min question 8 + free comments.
- **Async alternative** for whoever cannot call: a 5-question Tally/Google form + a 2-minute Loom of the prototype.
- **Capture**: one note per participant (quote, struggle, wish), then a 1-page synthesis appended to this doc
  (§8 "Findings") - tag each finding to a WP; change the design only where at least 2 of 3 agree or one is a blocker.
- **Success measure for later**: time from "Add event" to a published event with a round and a puzzle (Loki/analytics
  event per step - optional, cheap), and the share of new events that have rounds at the event date.

---

## 8. Findings (to fill after W0)

_Empty until the organiser sessions._
