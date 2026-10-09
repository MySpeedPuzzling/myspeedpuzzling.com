# High-frequency series

The design of record for **high-frequency series**: a series is **one entry** in the add-time event picker and
MySpeedPuzzling **finds the edition**; the events pages stay usable with 200+ editions; API v1 links times to a
series or an edition. Decisions by Jan (2026-10-09, H1-H13 below). The build contract is
[high-frequency-series-plan.md](high-frequency-series-plan.md); where the build differs, see [As built](#as-built).

It builds on the events page ([README.md](README.md)), the series, edition and event pages
([detail-pages.md](detail-pages.md)), organizations and drafts ([../organizations/README.md](../organizations/README.md))
and round results ([../competitions-management/round-results.md](../competitions-management/round-results.md)).

**This repository is public: every organiser, series, puzzle and person below is made up** ("Lantern Weekly Jam",
"Starling Puzzle Collective", "Copper Lighthouse").

## Goal

The Starling Puzzle Collective runs the **Lantern Weekly Jam** online: 2-4 standalone contests ("jams") a week, about
200 a year, each with its own registration, prizes and official results on the organiser's website, usually one
500-piece puzzle per jam, solo, pairs or teams. On MySpeedPuzzling it is **one undated one-time online event holding
thousands of results** - nobody can see which jam a result was from, and the events pages show one perpetual entry.

Turning every jam into an edition of a series fixes the data, but today it breaks the rest:

- the add-time picker bakes **every edition** into every add form - 200 more options a year, and the player must find
  "Jam No. 154" among them;
- the events page rolls a month of jams into one row with a chip per jam (15 chips), the embedded search index grows by
  ~80 KB per 200 editions, the series page is a wall of 200 lines;
- API v1 links a time to an event only through a round id.

Jan's acceptance criteria: **(1)** convenient to fill - pick the series once, everything else is automatic, never a
list of 200 jams in the form; **(2)** well arranged on the events pages - a jam is found by date or by puzzle;
**(3)** it works through the API too.

## Decisions

### Jan's decisions and the orchestrator's (binding)

| # | Topic | Decision |
|---|---|---|
| H1 | Data model | `puzzle_solving_time.competition_series_id` (FK, `ON DELETE SET NULL`, indexed) = **"the player picked the series - MySpeedPuzzling finds the edition"** (a *series pick*), plus `puzzle_solving_time.series_edition_match` (`puzzle` \| `date` \| NULL) = how an automatic link was made. **Series-level** (edition not identified) is a **normal, permanent, supported state** shown everywhere as the series' result. Every existing row stays explicit; no backfill. |
| H2 | One matching rule | `SeriesEditionMatch` - one SQL fragment for every path: (1) **puzzle**: the edition with a revealed round of the time's category holding the puzzle, nearest to the solve day; (2) else **date**: exactly one edition whose day span ±1 day contains the solve day and whose rounds (if any) accept the category; (3) else series-level. Secret round puzzles never count for (1) before their reveal. **Sticky**: the reconciler re-evaluates only automatic links and series-level times; a still-valid automatic link stays, except a date link moves when a puzzle match appears. |
| H3 | Where it runs | The add/edit handlers resolve before persist (fresh answer); a set-based reconciler runs after flush on every change of a series' editions, rounds, round puzzles or reveals, and for all series in the existing 15-minute `myspeedpuzzling:reconcile-round-results` cron - **no new cron**. |
| H4 | Messages | `AddPuzzleSolvingTime` / `EditPuzzleSolvingTime` gain `seriesId`. Precedence `roundId` > `competitionId` (explicit one-time event or edition) > `seriesId` (series pick). A series pick needs a publicly visible series, or (edit) the time's current one. |
| H5 | The form | The field holds `<uuid>` (one-time event), `series:<uuid>` (series pick) or `edition:<uuid>` (explicit edition). The default list has one-time events and series only. **S1**: typing ≥ 2 characters also finds editions by name (fetched, never baked in). **S2**: when the edition cannot be worked out, the short edition list is shown openly - optional, nothing preselected. A preview line says what was matched. |
| H6 | API v1 | Create/update accept `competition_id` and `series_id` besides `round_id`; responses carry `competition_id`, `series_id`, `round_id` read from the stored row. Additive, no BC break. |
| H7 | Readers + guard | Every reader that shows a time's event, counts a series' results or exports the event handles series picks; `SeriesPickQueryCoverageTest` guards it. First tries, duplicates and suspicious times verified. |
| H8 | Events pages | Agenda roll-up ≤ 6 chips + "+N more"; index weight measured and kept ≤ ~40 KB per 200 editions; events search finds past editions by round puzzle names; series page with filters, months and a date jump; puzzle page "Used at" lines; round results clearly labelled unofficial next to the official link. |
| H9 | Conversion tool | `ConvertCompetitionToSeries` gains `keepAsEdition` (web unchanged = `true`); `false` turns an umbrella event into the series: its times become series-level, the competition row goes, its old URL leads to the series. Internal API endpoint. |
| H10 | Docs, texts | This doc + the plan; English keys during the build, translators add cs, de, es, fr, ja. |
| H11 | Out of scope | Importing upcoming jams; e-mails to the organiser; a series-level results link field; moving a one-time event into an existing series; format chips. |
| H12 | Scenario matrix | 14 scenarios, each covered by tests - [Scenario matrix](#scenario-matrix). |
| H13 | Parents without children | A series without editions, an event or edition without rounds, an organization without series or events are first class - [Parents without children](#parents-without-children). |

### Decided while planning (can be revisited)

| # | Decision | Why |
|---|---|---|
| P1 | **No new fixture rows.** A test helper `tests/SeriesEditionScenario.php` (like `FirstTryScenario`) builds made-up series, editions, rounds and puzzles inside each test (DAMA rolls back). | Fixture rows would change counts in dozens of existing tests (events page, picker, sitemap, internal API lists). |
| P2 | `CompetitionPick` (`src/Value/`) is the one parser of the field value. A bare uuid of an **edition** is read as an explicit edition. | A form rendered by the old release during a blue-green deploy still posts edition ids. |
| P3 | `Competition` and `CompetitionSeries` become `EntityWithEvents`; `SeriesEditionsChanged` is recorded **inside their methods**. Round-side changes the reconcile needs and that record nothing today (round created, start/zone changed, round deleted, reveal changed) record `CompetitionRoundsChanged`. | `DomainEventsSubscriber` only collects events from entities persisted/updated/removed in the flush; handlers never dispatch on their own. |
| P4 | Events implementing a new marker `DeduplicatedDomainEvent` (`SeriesEditionsChanged`, `CompetitionRoundsChanged`) are dispatched **once per equal event per flush**. | "Add several dates" creates 24 editions in one flush - one reconcile, not 24. Both handlers are idempotent. |
| P5 | A rule-1 **tie** (two editions equally near hold the puzzle) is series-level; rule 2 is not tried. | The date rule could only pick a third edition that does not hold the puzzle. |
| P6 | An edition dated by one field only spans that day (`COALESCE(date_from, date_to)` / `COALESCE(date_to, date_from)`), like `OccurrenceDates`. | "Only what exists" without losing one-field editions. |
| P7 | An image-only hidden round puzzle counts as hidden for rule 1 (`NOT RoundPuzzleReveal::sqlHidden()`, the rule of `GetPuzzleSummary`). | One conservative definition of "revealed" for matching, preview, "Used at" and the index. |
| P8 | After a **date** match the round follows `SolvingTimeRoundResolver` / `RoundResultsReconciler` unchanged (they ignore secrecy, as for an explicit pick today). Matching, the preview, "Used at" and the index never use a hidden round puzzle. | An explicit pick of the same edition links the same round today; nothing new is revealed. |
| P9 | Undoing an automatic duplicate removal restores a series pick and **re-resolves** it (fresh answer); an explicit link is restored as it was. | The editions may have changed since. |
| P10 | `takeOverFrom()` takes a twin's whole link (competition + series pick + match kind) only when the kept copy has **no event link at all**. | Never mixes an explicit edition with another series' pick. |
| P11 | `ConvertCompetitionToSeries` takes the event's participants lock (`SerializedByLock`, `CompetitionParticipantsLock::key()`); "already in a series" becomes `CompetitionAlreadyInSeries` (409) instead of a `LogicException` (500). | `keepAsEdition: false` deletes participants (`SerializedByLockMessagesTest`); the internal API needs a 409. |
| P12 | `keepAsEdition: false` moves the times in **one commented bulk UPDATE** after the handler's existing flush and records **no** reconcile event. | A brand-new series has no editions - every moved time is series-level by construction; the first `AddEdition` reconciles. |
| P13 | `ConvertCompetitionForeignKeyCoverageTest` lists every FK to `competition` with what `keepAsEdition: false` does with it (moved, refused, deleted, repointed). Redirect rows **pointing at** the event are repointed to the series. | The conversion deletes a competition row; nothing may be lost silently (like `PuzzleMergeForeignKeyCoverageTest`). |
| P14 | The preview's category is **what the save would store** - the number of co-puzzlers in `group_players[]` (0 solo, 1 pair, 2+ team), not the Solo/Pair/Team chip. | The handler derives `puzzling_type` from the group; a chip with nobody added saves solo. |
| P15 | The two picker endpoints use the single-path `/{_locale}/…` style of `first_try_check` / `my_co_puzzlers`. | Helper endpoints, never linked or indexed. |
| P16 | API v1 gets `GET /api/v1/series` (publicly visible series). | The competition list holds one-time events only; without it no client can discover a series id. |
| P17 | API `PUT` cannot remove an event link (both fields omitted/null keep it) - unchanged; a way to clear it is a TODO. | Additive only. |
| P18 | API responses read the **stored** row - this also fixes `PUT` answering `round_id: null` always. | H6. |
| P19 | Internal API: the competition answer gains `seriesPickResultsCount` (automatic links to this edition), the series answer `resultsCount` (editions' results + series picks, each time once) and `resultsWithoutEditionCount`. | The production conversion is verified through these numbers. |
| P20 | Unpublish/delete blockers of a competition are **unchanged** (every linked time counts, automatic ones too - H12.10 "today's rules"); a **series'** blockers add its series picks. | Series-level times live on the series page's numbers. |
| P21 | The series delete confirmation gets one static sentence: results linked to it stay on the players' profiles, without the event (no count, no statement). | H7 "say it"; the modal is rendered without a query today. |
| P22 | The results export gains four columns at the end: `event_id`, `event_name`, `event_series_id`, `event_series_name`. | The export has no event column at all today; columns are only ever appended. |
| P23 | Duplicate detection compares the **event identity** = the series pick when set, else the competition (+ round only for explicit links). | Two copies of one series pick are the same result even when one was matched later. |
| P24 | Puzzle page "Used at" rides on `GetPuzzleSummary` (no new statement); guests see the lines in "About this puzzle", signed-in players in the header's "Details" collapse. | It is computed for everyone already (meta description). |
| P25 | Events index: an edition entry carries only what differs from its series' entry (`sid` → name, place, scope, country, organization; URL rebuilt from the series URL + edition slug when the paths agree in all 6 locales; `cm` only for competitions with 2+ sessions); target ≤ 200 B raw per past edition including puzzle names. | ~400 B per edition today = ~80 KB per 200 editions. |
| P26 | Series `EventSeries` JSON-LD: at most 50 `subEvent`s (every upcoming session, then the newest past ones). | 200+ sessions would add ~60 KB of JSON-LD to one page. |
| P27 | The series page header gets **"Add my time"** (`puzzle_add?series=<id>`) for signed-in viewers of a publicly visible series with an edition that has started. | The series pick's natural entry point; the edition pages keep their explicit links. |
| P28 | A series' reconcile also runs after a puzzle merge (with the global round reconcile it already triggers). | A merge changes the puzzle of series picks. |
| P29 | `MoveRoundToCompetition` moves **explicit** times only; series picks of the round's edition are re-matched by the reconcile both competitions get. | A series pick's edition is derived - it follows the rule, not the round. |
| P30 | One-round editions: the edition header's facts line also shows the round's start time in the event's zone (missing today). | H8 "date and time in its zone"; the round row has it, the header did not. |
| P31 | The old hint `forms.competition_hint_series` ("pick the specific edition") is no longer rendered; removed from all 6 locales by the translators' step. | It says the opposite of the new behaviour. |

## Data model

Two new columns on `puzzle_solving_time` (one generated migration, the only mapping change):

| Column | Type | Null | Notes |
|---|---|---|---|
| `competition_series_id` | uuid FK `competition_series` | yes | `ON DELETE SET NULL`, indexed. Set = a series pick. Entity `PuzzleSolvingTime::$competitionSeries`. |
| `series_edition_match` | varchar enum `SeriesEditionMatchKind` (`puzzle`, `date`) | yes | How the automatic link was made. Entity `$seriesEditionMatch`. |

| State | `competition_id` | `competition_series_id` | `series_edition_match` | `competition_round_id` |
|---|---|---|---|---|
| No event | NULL | NULL | NULL | NULL |
| **Explicit** (a one-time event or an edition picked by the player / API; **every existing row**) | X | NULL | NULL | derived as today |
| **Automatic by puzzle** | edition E of S | S | `puzzle` | the matched round |
| **Automatic by date** | edition E of S | S | `date` | `SolvingTimeRoundResolver` (normally none) |
| **Series-level** ("edition not identified") | NULL | S | NULL | NULL |

**Invariants** (kept by the resolver, the reconciler and the entity methods): `competition_series_id` set ⇒
`competition_id` is NULL or an edition of that series; `series_edition_match` is set ⇔ a series pick with an edition;
an explicit link (`competition_series_id` NULL) is never touched by anything series-related.

**Entity API** (no public property writes from handlers):

- constructor gains `null|CompetitionSeries $competitionSeries = null` (last, named);
- `modify(…, null|CompetitionSeries $competitionSeries = null)` - sets the pick, clears the match kind (the caller
  resolves afterwards);
- `seriesEditionResolved(null|Competition $edition, null|SeriesEditionMatchKind $match)` - a series pick's edition;
  `LogicException` for an explicit time, an edition of another series, or a kind without an edition; no domain event;
- `restore()` and `RemovedResultSnapshot` carry `competitionSeriesId` + `seriesEditionMatch` (old snapshots: null);
  `takeOverFrom()` follows P10.

**Deleting an edition** (web, internal API): its explicit times lose the event as today; its automatic times become
series-level (`competition_id` and `series_edition_match` nulled together) and are re-matched in the same flush.
**Deleting a series**: its editions go as today (their explicit times lose the event); its series picks lose the pick
(`competition_series_id` and `series_edition_match` nulled by the handler before the FK would do it) - the results stay
on the players' profiles without an event (P21).

## The matching rule

**Input per time**: series S, puzzle P, category C = `puzzling_type` (`solo` / `duo` / `team`, the values of
`RoundCategory`), solve day D = the date of `COALESCE(finished_at, tracked_at)`.

**Candidates**: competitions with `series_id = S` passing `IsCompetitionPubliclyVisible::SQL_CONDITION` - never a draft,
never pending or rejected, never an edition of a hidden series.

**Day span of a candidate**: from = LEAST(`COALESCE(date_from, date_to)`, first round's local day), to =
GREATEST(`COALESCE(date_to, date_from)`, last round's local day) - NULLs ignored (P6). A round's local day is its start
in its own zone, `COALESCE(cr.timezone, 'Europe/Prague')` (every production round has a saved zone since 2026-10). A
candidate with neither dates nor rounds has **no span**: only rule 1 can pick it.

**Revealed round puzzle**: `NOT RoundPuzzleReveal::sqlHidden('crp', 'cr', now)` and `puzzle.hide_until` not in the
future (P7) - the rule of `GetPuzzleSummary`, `GetEditionRounds` and the events page.

1. **Puzzle.** Candidates with a revealed round of category C holding P (one per competition - the
   one-round-per-category invariant). One → it. Several → the one whose span is nearest to D (0 inside the span); a
   tie → **series-level** (P5).
2. **Date** (only when rule 1 has no candidate at all). Candidates whose span widened by one day each side contains D
   and that have no rounds or a round of category C (hidden puzzles included - the round exists). Exactly one → it.
3. Otherwise **series-level**.

The round: rule 1 → the matched round; rule 2 → whatever `SolvingTimeRoundResolver` finds (P8).

### Examples (series "Lantern Weekly Jam", Central European Time)

| Edition | Day | Rounds |
|---|---|---|
| Jam No. 153 | Mon 5 Oct 2026 | solo, "Copper Lighthouse" (revealed) |
| Jam No. 154 | Wed 7 Oct | pairs, "Starry Harbor" (revealed) |
| Jam No. 155 | Thu 8 Oct | solo, puzzle secret until 19:10 |
| Jam No. 156 | Sat 10 Oct | none yet |
| Summer Special | no date | none |

| Time | Rule | Result |
|---|---|---|
| solo, Copper Lighthouse, Tue 6 Oct | 1 | No. 153 + its round (`puzzle`) |
| solo, Copper Lighthouse, logged on Fri 30 Oct | 1 | No. 153 (the only edition holding it - distance does not matter) |
| pair, Starry Harbor, Wed 7 Oct | 1 | No. 154 (`puzzle`) |
| solo, another puzzle, Thu 8 Oct | 2 | No. 154 (6-8 Oct) accepts pairs only; No. 155 (7-9 Oct) accepts solo; No. 156 (9-11 Oct) no → No. 155 (`date`) |
| solo, No. 155's puzzle before its reveal (a public catalogue puzzle the round keeps secret on its pages) | 2 | No. 155 (`date`); after the reveal the 15-minute reconcile turns it into `puzzle` |
| pair, another puzzle, Fri 9 Oct | 2 | No. 156 (no rounds accept anything) - when No. 156 gets a solo round the link no longer holds → series-level |
| solo, another puzzle, Sun 25 Oct | 3 | series-level |
| solo, Wed 14 Oct, two editions that day ("Flex" and "Live", solo, puzzles not announced) | 3 | series-level, the form shows both; once the Live puzzle is attached and revealed, a time on it → `puzzle` |
| any time on "Summer Special" | - | never automatic (no span) - only an explicit pick |

### Stickiness (Jan: "automatic links may be corrected when a stronger match appears; when it can't, leave them alone")

| Current link | When | Result |
|---|---|---|
| series-level | the rule finds an edition | linked (`puzzle` / `date`) |
| series-level | the rule finds nothing | left alone |
| `puzzle` to E | E is still a candidate with a revealed round of C holding P | kept - also when another edition became nearer |
| `date` to E | E still a candidate, its widened span holds D, C accepted - and the rule finds no puzzle match | kept |
| `date` to E | the rule now finds a puzzle match (E or another edition) | the puzzle match - puzzle beats date |
| any automatic | no longer holds (edition deleted, moved, unpublished, rejected, its dates or rounds changed, the puzzle left its round, the time's puzzle/category/day edited) | the rule's current answer, else series-level |
| explicit | anything | never touched |

The add and edit handlers always take the rule's **current** answer for a series pick (a save is a fresh evaluation).

## Where the rule runs and when it reconciles

- **The time's own writes** (`AddPuzzleSolvingTimeHandler`, `EditPuzzleSolvingTimeHandler`, `UndoAutoRemovalHandler`):
  `SeriesEditionResolver` answers in PHP before persist, after the group is assembled (the category is final), then the
  round as today. One statement (+ loading the edition entity when found).
- **Everything else**: `SeriesEditionReconciler::reconcile(?seriesId)` - one set-based UPDATE of the series picks in
  scope (a commented genuine bulk operation, like `RoundResultsReconciler`), then `RoundResultsReconciler` for the
  series' editions. Only after flush: a sync postFlush domain-event handler, or the console command.

| Change | Recorded by | Event (routed `sync`) | Reconciles |
|---|---|---|---|
| edition created (Add edition, Add several dates, internal API editions) | `Competition` constructor | `SeriesEditionsChanged(S)` | S |
| edition moved to another series | `Competition::moveToSeries()` | both series | old + new |
| edition's dates changed (edit form, internal API `PATCH`) | `Competition::edit()` when `dateFrom`/`dateTo` differ | `SeriesEditionsChanged(S)` | S |
| edition published / unpublished / rejected | `Competition::publish()` / `unpublish()` / `reject()` | `SeriesEditionsChanged(S)` | S |
| edition deleted | `Competition::recordRemoval()`, called by `DeleteCompetitionHandler` before `remove()` | `SeriesEditionsChanged(S)` | S |
| series approved / rejected / published / unpublished (also `OrganizationApprovalPolicy`) | `CompetitionSeries` methods | `SeriesEditionsChanged(S)` | S |
| round created, category / start / zone changed, deleted; round puzzle attached / removed; reveal changed or revealed now | `CompetitionRound` (constructor, `edit()`, `recordRemoval()`), `CompetitionRoundPuzzle` (constructor, `recordRemoval()`, reveal methods) | `CompetitionRoundsChanged(C)` | C's series when C is an edition (then its rounds), else C's rounds as today |
| puzzle merge approved | `PuzzleMergeApproved` | - | every series, then every round (P28) |
| automatic reveal by time; SQL changes | - | cron `myspeedpuzzling:reconcile-round-results` (every 15 min on lily) | every series first, then every round |

`SeriesEditionsChanged` and `CompetitionRoundsChanged` are deduplicated per flush (P4). The console command reports
"Series picks: N linked, N moved, N back to series level" next to the round counts.

## The form (add time, edit time, stopwatch finish)

This applies to **every** series (Jan); one-time events and times already linked to an edition behave as today.

### The field

`competition` holds one of three kinds (`CompetitionPick`, like `FollowTarget`'s `organization:<uuid>`):

| Value | Meaning | Sent to the handler as |
|---|---|---|
| `<uuid>` | a one-time event (a bare edition uuid from an old form = explicit edition, P2) | `competitionId` |
| `series:<uuid>` | a series pick | `seriesId` |
| `edition:<uuid>` | an explicit edition | `competitionId` |

### The default list (empty search)

Every publicly visible **one-time event** and every publicly visible **series**, one option each - **no edition, and
no edition list baked into the page**. One statement (`GetSelectableCompetitions`).

- **Series card**: series logo, name, "Online" or the place (flag + city), "Next: Tue 13 Oct" / "Last: Wed 7 Oct" /
  "No dates yet" (H13), a "live" badge while an edition is live. Keywords: series name and shortcut, the organization's
  name and short name (publicly visible organizations), the place.
- **Order** (one SQL `ORDER BY`): live (a live one-time event, or a series with a live edition) → undated one-time
  events ("perpetual") → recent past, newest first (a series by its latest past edition's day) → upcoming, soonest
  first → series without any dated edition. TomSelect `maxOptions: null` stays.
- **Include-current** (edit form): the time's current one-time event, its series pick (`series:<uuid>`) or its linked
  edition (`edition:<uuid>`, under its series' optgroup) is offered even when it is no longer public.

### S1 - typing finds editions

From 2 typed characters TomSelect also asks `competition_picker_editions` (`GET /{_locale}/competition-picker/editions?q=`,
signed in, `private, no-store`): at most 20 publicly visible editions whose name, series name or series shortcut
contains every typed word, nearest to today first (undated last). They appear under their series (optgroup = series,
its logo in the header) as today's edition cards - "Lantern Weekly Jam · Jam No. 154 · 7 Oct 2026". Picking one =
`edition:<uuid>`. Fetched editions leave the list again when the search is cleared (except the chosen one). The fetch
is patched into TomSelect's `load` in the existing `autocomplete:pre-connect` hook (ux-autocomplete 3.2 sets
`shouldLoad = () => false` for local pickers - the hook overrides it).

### The preview line and S2

Once the value is `series:S`, a line under the picker says what MySpeedPuzzling will do. It comes from
`competition_picker_series_preview` (`GET /{_locale}/competition-picker/series-preview`, signed in, no state change, an
HTML fragment) - the same rule (`SeriesEditionResolver::preview()`) with the form's inputs: the series, the puzzle (when
an existing puzzle is chosen), the date (empty = today, the handler's normalising of mistyped years applied), the
category (P14). It refreshes on any change of the series, puzzle, date or co-puzzlers (debounced, like
`first-try-check`).

```
┌ 🏆 Competition result ─────────────────────────────────────────────┐
│ Competition / event                                                 │
│ [ ▣ Lantern Weekly Jam · Online · Last: Wed 7 Oct            ▾ ]    │
│ ✓ Lantern Weekly Jam · Jam No. 154 · Wed 7 Oct 2026 · Pair  [change]│  ← matched
└─────────────────────────────────────────────────────────────────────┘

┌ 🏆 Competition result ─────────────────────────────────────────────┐
│ [ ▣ Lantern Weekly Jam · Online · Last: Wed 7 Oct            ▾ ]    │
│ Pick the date if you know it - or just save, and we'll match it     │  ← S2: not identified
│ later.                                                              │
│ [ Search dates or puzzles…                     ]                    │
│ ○ Jam No. 155 · Thu 8 Oct · Solo                                    │
│ ○ Jam No. 154 · Wed 7 Oct · Pair · Starry Harbor                    │
│ ○ Jam No. 156 · Sat 10 Oct                                          │
│ …  (at most 10, closest to the solve date)                          │
└─────────────────────────────────────────────────────────────────────┘
```

- **Matched**: one line "<series> · <edition> · <date> · <Solo / Pair / Team>" and a small **change** link that opens
  the short edition list.
- **S2 - not identified** (no edition by puzzle or date, a tie, two by date; a series whose editions have no dates and
  no round puzzles): the short edition list is shown **openly at once** - optional, **nothing preselected**, closest
  first - with a neutral line (wording in `series_picker.*`, generic, never "jam"). A series without publicly visible
  editions shows only the neutral line. Saving without a choice = series-level, matched later by the reconcile.
- **The short list** (both cases): that series' publicly visible editions, at most 10, closest to the solve date first
  (dated ones by day distance to their span, then undated ones newest first), each with its date, its rounds' categories
  and its **revealed** round puzzles' names; a search field (edition names + revealed round puzzle names, server-side,
  ≤ 10 results). Picking one sets the field to `edition:<uuid>` (the option is added to TomSelect) and shows
  **"Let MySpeedPuzzling match it"**, which sets it back to `series:S`.
- **An explicit edition** (`edition:E`, picked or prefilled): the line "<series> · <edition> · <date>" with
  "Let MySpeedPuzzling match it" when the series is offered.
- Texts come with the server's fragment (translations); the controller's own texts via data attributes. A new lazy
  Stimulus controller (`series_edition_preview_controller.js`).

### Validation, submit, prefill

- **Validation** (`POST_SUBMIT`): a one-time id or `series:` must be offered (or current); `edition:<uuid>` (or a bare
  edition uuid) must be a publicly visible edition or the time's current edition; anything else, or a malformed value,
  gets the generic `forms.competition_not_selectable` (never echoes names). One statement only when an edition value
  is submitted.
- **Submit**: see the field table. The handler is authoritative: a series that stopped being public between render and
  submit saves the time without a link + a warning log (like `CompetitionNotFound` today).
- **Edit form prefill**: explicit edition → `edition:E`; series pick → `series:S` (its current automatic edition is
  only shown by the preview); one-time → its id. Changing the puzzle, date or co-puzzlers of a series pick re-resolves
  it on save; an explicit link stays explicit until the player changes the field.
- **Deep links**: `?competition=<one-time id>` as today; `?competition=<edition id>` → `edition:<id>` (explicit) when
  public; new `?series=<id>` → `series:<id>` when public (`?competition` wins when both are sent). The edition and
  round pages' "Add my time" links stay explicit editions; the series page gets "Add my time" with `?series=` (P27).
  "Add to my profile" of official results (`official_entry`) reads the edition from the parsed value.
- **Without JavaScript**: as today - the field is ux-autocomplete's plain input; a submitted `series:` value is valid,
  there is no preview.
- **The 97 % path** (nobody opens the competition card): the add page runs the same statements as before (the picker is
  one statement, the preview controller is lazy and idle without a series value); the page gets lighter - editions are
  no longer baked into every add form.

### How the picker behaves for three kinds of series

| | (a) a weekly online series, each contest one edition with one round puzzle | (b) a series with dated editions, no round puzzles | (c) a series with undated editions |
|---|---|---|---|
| Default list | the series once | the series once | the series once ("No dates yet" when nothing is dated) |
| Logging a contest's puzzle | preview: matched by puzzle - "· Jam No. 154 · Wed 7 Oct · Pair" | matched by date when exactly one edition is within a day of the solve day (weekly: always) | S2: the list (newest first), nothing chosen |
| Logging another puzzle on a contest day | matched by date when exactly one contest that day ±1 accepts the category, else S2 | as before | S2 |
| Two contests a day apart, puzzles not announced yet | S2 (both listed); matched by puzzle once revealed | S2 (both listed) | - |
| Saving without choosing | series-level, matched later (reveal, puzzle attached, dates added) | series-level | series-level for good - unless the organiser dates the editions |
| Typing "No. 154" | S1 finds the edition by name → explicit | S1 | S1 |

## Scenario matrix

| # | Scenario | Expected | Covered by |
|---|---|---|---|
| 1 | One-time event, no series | Picker entry and linking exactly as today; round derived as today | foundation (handler), WS-A (form) |
| 2 | Editions with dates + rounds + puzzles | Rule 1 → edition + round | foundation (resolver, reconciler, handler), WS-A (preview), WS-B (API) |
| 3 | Editions with dates only | Rule 2 → edition | foundation (resolver, reconciler) |
| 4 | Rounds without puzzles yet / secret until reveal | Rule 2 by round days + category; a puzzle match once attached / revealed; never a leak | foundation (resolver, reconciler: attach, reveal now, cron), WS-A (preview), WS-C (index), WS-D ("Used at") |
| 5 | Undated placeholder editions without rounds | No automatic match; the open optional choice; explicit pick works; else series-level | foundation (resolver), WS-A (preview, explicit submit) |
| 6 | Series without editions | Series-level, permanent; re-matched when the first edition appears | foundation (reconciler on `AddEdition`), WS-A (card + save), WS-C (series page, directory) |
| 7 | A single undated edition | Not guessed; the choice is shown | foundation (resolver), WS-A (preview) |
| 8 | Two editions on the same day and category | Series-level + the open choice; a later puzzle attach resolves it | foundation (resolver, reconciler), WS-A (preview) |
| 9 | Draft / pending / rejected editions or series | Never a target; re-matched after publish / approval | foundation (resolver, reconciler), WS-A (S1 + canary) |
| 10 | Edition's dates change / moved / deleted | Automatic links re-evaluated; explicit stay; deleting an edition holding results follows today's rules | foundation (triggers) |
| 11 | Converted with the existing tool | `keepAsEdition: true`: times stay on the first edition (explicit); `false`: times series-level, nothing lost | foundation (handler, internal API) |
| 12 | Edit form of an existing time | Shows its current link (include-current); puzzle/date changes re-resolve only series picks | foundation (handler), WS-A (form) |
| 13 | API | `competition_id` explicit, `series_id` automatic or series-level, `round_id` as today; precedence; 404 unknown / non-public | WS-B |
| 14 | First tries, duplicates, suspicious scans, round results, exports | Work in every state | foundation (round results, twin net), WS-D (exports, duplicates, first try, suspicious) |

## API v1

`POST /api/v1/me/solving-times` and `PUT /api/v1/me/solving-times/{timeId}` gain two optional fields (JSON snake_case,
DTOs camelCase):

| Field | Meaning |
|---|---|
| `round_id` (exists, POST) | as today: the round's competition, explicit |
| `competition_id` | explicit: a one-time event or an edition |
| `series_id` | a series pick: MySpeedPuzzling finds the edition, else series-level |

- **Create precedence**: `round_id` > `competition_id` > `series_id`. Sent together they must agree - `competition_id` =
  the round's competition, `competition_id` an edition of `series_id`, the round's competition an edition of
  `series_id` - else `422` (problem+json violation on the field). When they agree the time is explicit (the most
  specific wins).
- Unknown, malformed or not publicly visible id → `404` before dispatch (`CompetitionNotFound`,
  `CompetitionSeriesNotFound`, `CompetitionRoundNotFound` - all `NotFoundHttpException`), like `round_id` today.
- **Update**: both omitted or null → the current link is kept exactly (an explicit link stays; a series pick stays a
  series pick and is re-resolved like every edit); `competition_id` or `series_id` given → the link changes (same
  rules; the time's current competition or series is accepted even when no longer public). There is no way to remove
  a link (P17).
- **Responses** (`SolvingTimeResponse`: create, update, the `Idempotency-Key` replay) read the stored row after the
  handler: `competition_id` = the linked one-time event or edition (explicit or matched) or null; `series_id` = the
  series pick, else the linked edition's series, else null; `round_id` = the stored round (P18).
- **Discovering series**: new `GET /api/v1/series` (P16) - publicly visible series, one statement: `id`, `name`,
  `shortcut`, `slug`, `logo`, `is_online`, `location`, `country_code`, `link`, `organization_name`, `editions_count`,
  `next_date`, `last_date`. The competition detail already carries `series: {id, name, slug}`. No membership gate
  (events are public); any authenticated token, like the competition endpoints.

```http
POST /api/v1/me/solving-times
{"puzzle_id": "…", "time": "1:12:09", "finished_at": "2026-10-07T21:40:00+02:00", "series_id": "<Lantern Weekly Jam>"}

201
{"time_id": "…", "puzzle_id": "…", "time_seconds": 4329, "competition_id": "<Jam No. 154>",
 "series_id": "<Lantern Weekly Jam>", "round_id": "<its pairs round>", "prediction": null, …}
```

Not identified: `"competition_id": null, "series_id": "<Lantern Weekly Jam>", "round_id": null`. The API never
offers a choice - series-level is a valid answer (H6).

## Events pages

### Events page agenda

- **Month roll-up**: a group row shows at most **6 date chips**, then a "+N more" chip linking the series page
  (`EventsPageBuilder::MAX_SESSION_CHIPS = 6`). The row still carries every session's index id, so a search hit on a
  hidden chip shows the row. Live editions are never rolled up (unchanged). The **calendar** keeps a dot per day (it
  reads the index, not the chips).

```
OCTOBER 2026
[TUE 13] Lantern Weekly Jam · 11 sessions  [Tue 13] [Thu 15] [Sat 17] [Tue 20] [Thu 22] [Sat 24] [+5 more →]
         Online · Recurring
```

- **Archive**: one line per series and year stays ("Lantern Weekly Jam · 98 editions in 2026"), verified with 200
  editions (server and client roll-up).

### The embedded index

Today an edition entry is ~400 B (keys, a 36-character id, an escaped URL, the series name, place and a search text
repeating the series'), so 200 editions add ~80-90 KB raw to `/en/events`. Target: **≤ 40 KB raw for 200 editions**,
measured by a test with a synthetic 200-edition series (raw and gzip, numbers in [As built](#as-built)). Plan (P25):

- an edition entry keeps only `id`, `k`, `en` (edition name), `sid`, the edition slug (URL rebuilt from the series'
  `u`), `f`/`t`, `st`, `r`, `sl` when set, and its own search text (edition name, session label, year, revealed round
  puzzle names); everything else (series name, place, scope, country, organization) is read from the series entry
  through `sid`; a query matches an edition when every word is in the edition's or its series' search text;
- `cm` only for competitions with 2+ sessions (the archive counts editions by `cm ?? id`);
- keys left out when empty (already the rule for `lr`, `w`).

`EventsIndexScriptTest` keeps server and browser writing the same labels; the calendar, archive and search read the
looked-up fields through one helper.

### Events search by puzzle

A past (or revealed) edition is found by the names of its **revealed** round puzzles: the rounds JSON of
`GetEventOccurrences` (`OccurrenceRounds`) gains each round's revealed puzzle names and its category - inside the
same statement, so the events page's statement count is unchanged. A hidden puzzle's name never reaches the HTML.

### Series page for 200+ editions

```
Events › Starling Puzzle Collective › Lantern Weekly Jam
[logo] Lantern Weekly Jam                         [☆ Follow series] [Add my time] [Website ↗] [⋯]
       Organized by Starling Puzzle Collective · Online
218 editions · since Apr 2024 · 3 coming dates · next Tue 13 Oct
┌ Next ──────────────────────────────────────────────────────────────┐
│ [TUE 13 OCT] Jam No. 219 · Online · 19:00 Central European Time    │
│              In 4 days                    [Registration ↗]          │
└─────────────────────────────────────────────────────────────────────┘
[All] [Solo] [Pairs] [Teams]   [ Search dates or puzzles… ]   [ Jump to ▾ October 2026 ]
Upcoming · 3                    (month headers, one row per session)
page sections
Past · 215     [2026 · 98] [2025 · 102] [2024 · 15]
  ▾ October 2026 · 6            (the newest month of the year open)
      WED 7 OCT  Jam No. 154 · Pair · Starry Harbor · Results
      MON 5 OCT  Jam No. 153 · Solo · Copper Lighthouse · Results
  ▸ September 2026 · 12
  ▸ August 2026 · 11
```

- **Past**: year chips as today; each year split into **month sections** (`<details>`), only the newest month of the
  open year unfolded; lines show the edition's category pills and revealed round puzzle names.
- **Filter bar** (client-side, hidden without JavaScript - then everything shows): category chips All / Solo / Pairs /
  Teams (only categories that occur), a search field over edition names + revealed round puzzle names (the events
  page's fold, `SearchText::fold()` / `foldSearchText()`), a month select that jumps to (and opens) that month. Rows and
  lines carry `data-categories`, `data-search`, `data-month`. Empty result: "No date matches - show all".
- **One statement** for the occurrences (`forSeries()`, rounds JSON with categories and revealed puzzle names);
  budget unchanged and pinned: guest 3, signed in 9, sections +1; a series without editions guest 2, signed in 8.
- **JSON-LD**: at most 50 `subEvent`s (P26).
- **"Add my time"** in the header (P27).

### Edition page and round results

- **Edition page**: the header facts show the date, the round's start time in the event's zone for a one-round
  edition (P30), the round's category pill (timeline), "Registration ↗" and "Official results ↗" (existing rules).
- **Round results page**: above the list of times a label **"Times logged on MySpeedPuzzling - not the official
  results"** with **"Official results ↗"** right next to it (`officialResultsLink` = the round's results link, else the
  edition's, both with `utm_source=myspeedpuzzling`); shown whenever the list is shown, not only after the start. With
  published official results the page is unchanged (they lead, the times fold below).

### Puzzle page "Used at" (P24)

Lines instead of badges, from round puzzles of publicly visible competitions, newest first, at most 10 + "and N more":

```
Used at
  Lantern Weekly Jam · Jam No. 154 · Wed 7 Oct 2026 · Pair      → /en/series/lantern-weekly-jam/jam-no-154#round-…
  Riverside Puzzle Open · Sat 14 Mar 2026 · Solo                 → /en/events/riverside-puzzle-open#round-…
  Valley Speed Puzzle Cup 2025                                   (competition tag, no round)
  and 3 more
```

- A round line: "<series> · <edition> · <date in the round's zone> · <category>" (one-time: "<event> · <date> ·
  <category>"), linking `edition_detail` / `event_detail` with `#round-<id>`.
- Competition and series tags of publicly visible items not already covered by a round line: "<event>" / "<series>",
  linking its page (today's badges).
- **Secret rules**: a round row that still hides the puzzle (`RoundPuzzleReveal::sqlHidden()`, `hide_until`) is left
  out; drafts, pending and rejected items never appear. One statement - the existing `GetPuzzleSummary` query.

## The conversion tool (H9)

`ConvertCompetitionToSeries(competitionId, seriesId, keepAsEdition = true, dropParticipants = false)`:

- `keepAsEdition: true` - **unchanged** (the web button): the series is created from the event, the competition row
  becomes its first edition, its times stay explicit on it, its old URL 301s to the edition (`EventDetailController`).
- `keepAsEdition: false` - **the event becomes the series**: the series is created exactly as today (organization, tag,
  maintainers, approval, eligibility, draft state, followers moved); every solving time of the event becomes a
  series-level pick of the new series (one commented bulk UPDATE, P12); `event_url_redirect` rows pointing at the event
  are repointed to the series and a new row maps the old one-time path (`('', old slug, '')`) to the series (P13); the
  competition row is deleted (its maintainer links with it). The series slug = the event slug when free (as today).
- **Refused** (409 `CompetitionNotConvertible` naming the reasons, nothing changes) for `keepAsEdition: false` when the
  event has rounds, official results, referees, page sections or marketplace marks, or participants (unless
  `dropParticipants: true` - then they and their participant-sheet receipts are deleted). 409
  `CompetitionAlreadyInSeries` for an edition. Takes the event's participants lock (P11).

**Internal API** `POST /internal-api/competitions/{competitionId}/convert-to-series`, body
`{"keepAsEdition"?: true, "dropParticipants"?: false}` → `201` with the series answer (`GetAdminSeries::detail()`);
`404` unknown competition, `409` refusals (JSON, logged at info), `400` unknown fields; audit-logged (`createdId` = the
series). No reviewer player needed (the series takes the event's creator and approval).

**Production conversion** (the orchestrator, after deploy): snapshot (`pg_dump`); `POST …/convert-to-series` with
`keepAsEdition: false` (and `dropParticipants` if the umbrella event holds joins); check the old URL answers 301 to
the series page and the series answer's `resultsWithoutEditionCount` equals the event's former `resultsCount`; backfill
past contests through the internal API only (`POST /internal-api/series/{id}/editions` with the contest's day, then
`POST /internal-api/competitions/{id}/rounds` with the round's start, zone, category and `puzzleIds`) - every call
reconciles; verify `resultsWithoutEditionCount` falls and spot-check profiles; the remaining series-level times are
the outliers and stay. No upcoming contests are imported (H11), nothing is mailed.

## Readers and the guard

Every reader of a time's event decides series picks; the full per-file checklist is the plan's WS-D section.

| Kind | Rule |
|---|---|
| Badges (profile results, puzzle leaderboards, ladders, recent activity, the result modal) | `LEFT JOIN competition_series cs ON cs.id = COALESCE(competition.series_id, pst.competition_series_id)`; `_competition_badge.html.twig` shows the series label (shortcut ?? name) linking `competition_series_detail` for a series-level time, "series · edition" for an automatic link (exactly like an explicit edition) |
| Review and admin lists (duplicate review copies, suspicious case cards) | the series' name for a series-level time |
| Per-competition counts (event/edition "Results" titles, round results, "puzzles people logged", the events page "Results" tag) | unchanged - an automatic link is linked to its edition and counts there; a series-level time belongs to no edition |
| Per-series counts and blockers (series unpublish blockers, internal API series answer) | include series picks |
| Exports | four new columns at the end (P22) |
| Write side (twin net, snapshots, keep-a-copy, undo, round moves) | foundation |

**`SeriesPickQueryCoverageTest`**: every PHP file under `src/` whose SQL (comments stripped) reads `puzzle_solving_time`
together with `competition_id` must mention `competition_series_id` or be listed with a one-line reason (round-level,
per-competition count, participants, …). It is per file - the canary `SeriesPickCanaryTest` (WS-D) proves the badge on
every surface.

## First tries, duplicates, suspicious times

- **First tries** (`FirstTryAssessor`, `GetFirstTryTimes`) and the **suspicious-time scan** read no competition column
  (verified): a series pick behaves like any other time.
- **Duplicates**: `DuplicateCandidate::differences()` compares the event identity (P23) - two copies of one series pick
  never differ by their derived edition; an explicit edition and a series pick of its series differ (the player
  decides). The 10-second twin net (`GetRecentIdenticalSolvingTime`) compares the series pick for a series pick and the
  competition for an explicit link.
- **Round results**: an automatic link has a round exactly like an explicit one; the round page lists it (unofficial
  times); series-level times are on no round page.

## Performance

| Page / endpoint | Statements |
|---|---|
| Add time (GET, every mode) | unchanged - pinned by a new budget assertion |
| Add / edit time (POST) | unchanged without a series; a series pick + ≤ 3 (the series, the match, the edition entity when found); an `edition:` value + 1 (validation) |
| `competition_picker_editions` | site overhead + 1 |
| `competition_picker_series_preview` | site overhead + ≤ 3 (series, match, short list) |
| Events page, archive | unchanged (`EventsPageQueryBudgetTest`) |
| Series page | unchanged: guest 3, signed in 9 (no editions 2 / 8), sections + 1 |
| Edition / event / round results pages | unchanged (`DetailPagesQueryBudgetTest`) |
| Puzzle page | unchanged - a new `PuzzleDetailQueryBudgetTest` pins today's numbers first |
| API create / update with `series_id` | + 1 match + 1 edition + 1 for the response's series |
| `GET /api/v1/series` | 1 (+ authentication) |
| Reconcile of one series (~5,000 picks, ~200 editions) | one UPDATE + the round reconcile of its editions; measured by the foundation |

## Privacy, secret puzzles, drafts

- **Secret puzzles**: matching, the preview, the short list's puzzle names and search, the events index, the series
  page filter text and "Used at" use **revealed** round puzzles only (P7). A date match never says which edition holds
  a hidden puzzle; the round link after a date match is today's explicit behaviour (P8). The preview ignores a puzzle
  the viewer may not see.
- **Drafts**: never a candidate, never in the picker, S1, the short list, the API series list or "Used at"
  (`IsCompetitionPubliclyVisible`, `IsSeriesPubliclyVisible`). New surfaces are added to `DraftCanaryTest` and every
  new reader is decided in `DraftVisibilityCoverageTest`.
- **Player identity**: nothing new shows other players; the preview and the short list show events only.

## Every kind of event (additions)

| Kind | Add-time picker | A time linked to it | Events pages |
|---|---|---|---|
| One-time event | one option (unchanged) | explicit | unchanged |
| Series with editions | one option; editions by typing (S1) or the short list | series pick (automatic or series-level) or explicit edition | roll-up ≤ 6 chips; series page filters and months |
| Series without editions | one option, "No dates yet" | series-level | directory "No dates yet"; series page "No editions yet." |
| Edition (public) | only by typing, the short list, a deep link or include-current | explicit (picked) or automatic (matched) | unchanged |
| Edition without rounds | as above | explicit, or automatic by date | unchanged |
| Undated edition | as above (the short list's end) | explicit only | series page "Date not set" |
| Draft / pending / rejected edition or series | never (include-current on edit only) | never a match target | nowhere (unchanged) |
| Umbrella one-time event converted with `keepAsEdition: false` | gone - its series instead | series-level, matched as editions appear | its old URL → the series page |

## Parents without children (H13)

| Parent | Behaviour | Test |
|---|---|---|
| Series without editions | In the picker ("No dates yet"); times link series-level, permanently; its first edition reconciles them; its series page shows "No editions yet." and the events page directory "No dates yet" | foundation (reconciler), WS-A (card + save), WS-C (series page + directory) |
| Event or edition without rounds | Explicit pick works; an edition without rounds is matched by date (rule 2, any category) | foundation (resolver), WS-A (explicit submit) |
| Organization without series or events | Its page keeps the clean empty state ("Nothing planned yet.") | WS-C (organization page) |

## Later (docs/TODO.md)

- Removing a time's event link through the API (P17).
- An organiser view of their series' series-level times ("N results not matched to a date") with a way to assign them.
- Notifications for followed series (new edition) - unchanged TODO.
- Importing upcoming contests; a series-level results link; format chips; moving a one-time event into an existing
  series (H11).
- If the reconcile of a very large series gets slow: scope it to the picks whose solve day is near a changed edition.

## Conflicts with the decisions (resolved here, flagged to the orchestrator)

1. **H3b / H9 - `ConvertCompetitionToSeries` "records `SeriesEditionsChanged`"**: a new series has no editions, so the
   reconcile it would trigger changes nothing; with `keepAsEdition: false` the handler's existing mid-handler flush would
   even dispatch it before the times move. Fix: record nothing (P12); every later `AddEdition` reconciles. Same outcome.
2. **H3 - "recorded when an edition is created, moved, …"** needs a mechanism: `Competition` / `CompetitionSeries` are
   not `EntityWithEvents` and several listed triggers (round start/zone change, round creation and deletion, reveal
   changes, series approval) record nothing today. Fix: P3 + P4 - the substance is unchanged.
3. **H8 - "Puzzle page … budget test updated"**: there is no puzzle page budget test. Fix: WS-D creates
   `PuzzleDetailQueryBudgetTest`, measured on the foundation commit before its change.
4. **H8 - index ≤ ~40 KB per 200 editions**: today's entry shape gives ~80-90 KB. Fix: the compact edition entry (P25),
   measured; numbers recorded here.
5. **H7 - "results export gets the series name"**: the export has no event column at all. Fix: four columns (P22).
6. **H6 - "add `seriesId` where a series summary lacks an id"**: the competition detail's `series` has its id; the list
   has one-time events only, so series were not discoverable. Fix: `GET /api/v1/series` (P16).
7. **H2 - a round's local day `COALESCE(cr.timezone, 'Europe/Prague')`** differs from `RoundTimezone::resolve()` (round
   zone, else the event's or series' country zone) for rounds without a saved zone; production has none since the
   2026-10-07 backfill. Kept as decided (one SQL rule); new rounds always save their zone.
8. **H5 - "Without JS: series pick only (no list), still valid"**: without JavaScript the field is a plain text input
   today already; a submitted `series:` value is valid, there is no picker either. Clarification only.

## As built

(Filled at integration: the foundation's and the workstreams' deviations, the measured index weight and reconcile
time, the pinned budgets.)

### Events pages (WS-C)

- **Index weight** (`EventsIndexWeightTest`, `EventsIndexExamples::weeklySeriesPage()`: one online series, 200 past
  editions of one solo round with one revealed puzzle each, encoded with `json_ld` like the page): the compact entries
  add **27,661 B raw / 3,264 B gzip** for 200 editions (138 B an edition, P25's ≤ 200 B met); the same editions as
  full entries would add 60,861 B raw / 3,597 B gzip (400 editions: 55,878 / 6,382 compact, 122,278 / 7,029 full).
  Budget pinned at 40,000 B raw. A past edition entry ships `id`, `k`, `en`, `sid`, `es`, `f`, `st`, `r`, `x` -
  e.g. `{"id":100,"k":"d","en":"Jam No. 101","sid":400,"es":"jam-no-101","f":"2023-11-26","st":"past","r":true,"x":"no. 101 2023 starry meadow"}`.
- **The compact index** is a whole format, not only for editions: `EventsIndexFactory::compact()` leaves out every key
  holding its default (`DEFAULTS`); an edition takes `n`, `sc`, `c`, `p` from its series entry unless its own differ
  (an edition held elsewhere than its series keeps its place), its link is `es` = its path after its series' path +
  "/" (any session `#round-` included), and its `x` holds only the words its series' `x` does not. The full entries
  stay in `EventsPage::$index` (the server's `?q=` search and search results read them); the page ships
  `EventsPage::$shippedIndex`; `expandEventsIndex()` (browser, used by `readEventsIndex()`) and
  `EventsIndexFactory::expand()` (PHP, tests) rebuild exactly `$index` - pinned by `EventsIndexScriptTest`. An
  edition's full `x` is therefore its own words + its series' text: a series' city finds its editions held elsewhere
  too (accepted, server and browser agree). `cm` is set only for a competition with 2+ sessions.
- **Puzzle names in the search** for every occurrence (one-time events too), not only editions.
- **Archive with 200 editions**: one line per series and year on both sides (`EventsIndexScriptTest`), the
  client roll-up moved into `archiveLinesOf()` of `assets/events_index.js` (tested under node).
- **Series page**: the filter bar and the month sections show from **13 dated sessions** on
  (`SeriesPageBuilder::FILTER_FROM_SESSIONS`); smaller series keep the flat years with the 5-line preview. In month
  mode the newest month of **each** year is open (one year shows at a time with JavaScript). Category pills on rows
  and lines (and the chips) only when the series has two or more categories; revealed puzzle names on every series
  page. The Next card is never filtered; the month select's options are `upcoming-Y-m` / `past-Y-m` (a month can be
  in both lists) and the select only scrolls - focus stays on it (a closed select fires `change` per arrow key).
  "Add my time" reuses `event_rounds.add_my_time`.
- **Round results**: the label replaces the old "Times added by puzzlers…" note on round pages without published
  official results and shows whenever the round has puzzles (also before the start and with no times yet);
  `round_results.see_official_results` is no longer rendered (translators can drop it). The competition's results
  link already carried `utm_source` (`CompetitionEvent`).
- **Budgets** unchanged and pinned: series page guest 3 / signed in 9 for 2 and for 203 editions
  (`DetailPagesQueryBudgetTest::testTwoHundredEditionsCostWhatTwoCost`), events page as before
  (`EventsPageQueryBudgetTest`).
