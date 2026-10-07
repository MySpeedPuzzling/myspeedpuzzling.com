# Results desk, results overview and advancing the qualified

The organiser's desk-side tools for official round results (design of record of the data and the write path:
[official-results.md](official-results.md)). Desktop/tablet-first; the phone-first live entry and the seating page are
their own tools, linked from here. Every write goes through the official results JSON endpoints - these pages add no
write path of their own.

Scenario 3 of the official results decision: **who qualified between rounds** - the WJPC rush: four group rounds of
50-100 people finish, the best 50 of each advance into two semifinals, which have to be seated minutes later, with
several organisers clicking at once. Correctness first (every change carries the value the organiser saw; advancing
applies only the plan the organiser reviewed), then the speed of the common path.

## Pages

| Page | Route | What |
|---|---|---|
| Results desk (per round) | `results_desk` (`/en/manage-round-results/{roundId}`) | ranked table, inline edits, qualified marks + helpers, advance, publish, export |
| Export | `results_desk_export` (`…/{roundId}/export/{csv\|xlsx}`) | the round as one CSV file / one XLSX sheet |
| Results overview (per event) | `competition_results_overview` (`/en/manage-event-results/{competitionId}`) | the control room: every round's progress + its tools, advance across rounds |

All three: `IS_AUTHENTICATED_REMEMBERED` + `CompetitionEditVoter` on the round's competition, `noindex, nofollow`,
`Cache-Control: private, no-store`. Linked from: the round list (`manage_competition_rounds`: per round "Live entry",
"Results desk", "Seating" + "Results overview" at the top), the event edit page and the series' editions list (one
"Results overview" button), the overview's rows, the desk itself (round switcher, the other tools of the round), the
seating page and the stopwatch control page (the round's tools), the live entry ("Results desk"), and - for the event's
organisers only - the public round page once its results are published (`official_results/_organiser_round_links.html.twig`,
included by that page; an untouched round page asks nobody's permissions).
One name everywhere: **Results desk**.

`templates/official_results/_round_tool_links.html.twig` is the **one** place a round's tools are linked
(`live_results`, `results_desk`, `round_seating` - Seating only for in-person events, marked "not used" while the
round goes without table numbers; `with_stopwatch` adds the stopwatch control page, on the overview). The "Tables: x / y
assigned" line is `seating/_readiness.html.twig` (`compact` here) and shows by the one rule of
[seating.md](seating.md#the-seating-step-one-rule) (`SeatingReadiness`, `overview.showsTablesReadiness` / JSON
`tablesReadiness`): a round under way or over never nags. It is a recommendation, never a block.

`OfficialResultsRounds` (service) joins the round list's rounds (`GetCompetitionRoundsForManagement`: badge colours,
the zone a start is shown in) with their progress (`GetRoundResultsOverview`) and the public round page
(`OfficialRoundPageUrl`) into `OfficialResultsRound` - its JSON is the overview's JSON plus `color`, `textColor`,
`timezone`, `publicUrl`. The round list pays one more statement for it.

## The desk (`results_desk_controller.js`)

The page bootstraps exactly what `official_results_round_state` answers (plus the colours and the public URL) - its
live updates token included (see "Live updates" below). Columns: rank, table (hidden for online events and rounds without
table numbers), entrant (team: members with flags and #CODE), country flags, result, entered by · at, Qualified.
Entries without a result and did-not-start are listed (organisers only). Search by table number (exact), names,
member names and #CODE (accent-insensitive); filter all / without a result / qualified / not saved yet. "Entered at"
and "published since" are shown in the round's zone (like the export), not the device's.

- **Swap two tables**: a table number held by another entry comes back refused (`table_number_taken`) with "Table 6 is
  Ben's · Swap them": the holder gets this entry's old number and both changes go in **one** request - the server
  checks the numbers after the whole set, so 5 ↔ 6 works (one at a time it never could).
- **Take out of this round** (an entry without a result or a qualified mark - a mistaken advance, the wrong group):
  confirmation, then `official_results_take_out` (`TakeEntryOutOfRound`): a person leaves the round (not the event), a
  pair/team leaves with its members' places in the round. The other pages fetch the round again
  (`official_results.refresh`). Refused for official data on the server too.

- **Ranking in the browser** (`assets/official_results_ranking.js`) - the same rules and order as
  `OfficialResultsRanking` + `GetRoundResultEntries`, pinned by `OfficialResultsRankingParityTest` (node runs the
  module against the PHP implementation). Ranks follow what the desk shows, i.e. unsaved values too (they are
  marked).
- **Inline edits**: result (Finished = the shared time parser `official_results_time.js` with a live preview,
  Didn't finish = pieces placed 1..pieces−1, Did not start, No result) and table number (1..9999, empty = none).
  Enter saves, Escape cancels; while an editor is open the row order is frozen and that row is never re-rendered
  (typing is never wiped); focus is restored after every re-render.
- **Unsaved changes** (`assets/official_results_pending_changes.js`, `PendingChanges`): one cell per entry field;
  `from` = the value the organiser saw when they started changing it, so a value somebody else saved meanwhile comes
  back as a conflict. For an inline editor that is the value shown when it **opened** (`openEditor()` - never the
  server's value at save time: a live update reaching a frozen row would otherwise become the `from` and be
  overwritten silently - browser verification BLOCKER, 2026-10-07). A value somebody else saved while the editor is
  open (a live update, or a conflict answer to the organiser's earlier value) is shown **next to the editor**: "Saved
  meanwhile by Eva: 1:20:00 · Keep mine / Take theirs" (`savedMeanwhile()`); Save/Enter does not go through until the
  organiser chose (the same value typed = nothing to decide); Keep mine saves over it knowingly
  (`keepMineInEditor()`), Take theirs closes the editor. A qualified tick sends the mark the organiser saw before
  the click, a helper the marks its diff showed, "Swap them" the number the refusal named. Pinned by
  `OfficialResultsDeskHelpersTest::testAnInlineEditorSendsWhatTheOrganiserSawWhenItOpened`. A cell is saved only when the server answered `applied`/`unchanged` for exactly that value.
  One request at a time, in order (max 500 changes); a value changed while the previous one is on its way follows
  it (chained `from`). Conflict = "Saved meanwhile by Eva: 1:20:00 · Keep mine / Take theirs" (+ "keep all mine /
  take all theirs" when there are several); refused = the reason + Try again / Discard.
- **Transport** (`official_results_api.js`): offline / 5xx = kept and retried (2, 5, 10, 20, 30 s; at once when the
  browser is back online), "N waiting - offline"; signed out (401 / redirect / a 2xx without JSON) = nothing is sent
  until the organiser signed in again (link opens the login in a new tab, "Try again"), "Sign in again"; 403 =
  "Not allowed - reload". Leaving the page with unsaved changes asks first (`beforeunload` + `turbo:before-visit`).
  The desk is not offline-first: unsaved changes live in the page only.
- **Live updates**: `official_results.entries` merges the entries (an older result never replaces a newer one -
  `enteredAt`), `official_results.refresh` fetches the state, `official_results.round` updates publication/table
  usage. The state is fetched again when the tab returns after 10 s, when the connection returns, and once a minute
  while the tab is shown - see "Live updates" below. When our own answer and a live update disagree about an entry
  we just saved, the desk asks the server once more.

### Qualification helpers - always the organiser's decision

They only **pre-select** for review (`assets/official_results_qualification.js`, pinned by
`OfficialResultsDeskHelpersTest`); "Apply" turns the reviewed selection into ordinary `qualified` changes (from →
to), so a mark another organiser changed meanwhile surfaces as a conflict.

- **Top N** by the computed rank; entries sharing the last qualifying place (taking the list past N) are proposed
  but highlighted with a checkbox each - the organiser decides.
- **Best of each country** (K per country, default 1) among entries with a ranked result (finished or pieces
  placed; never did not start / no result). A pair/team counts for each of its members' countries (`countries`).
  Ties at the K-th place are highlighted like Top N; entries without a country are listed apart, unticked.
- **Clear all**.
- "Keep the other current marks" (default off for Top N, on for the country rule - "best 50 plus the best of every
  country" is Top N, then the country helper). The dialog shows the diff: "Will mark 12, unmark 3 - 50 qualified
  afterwards", with the names behind each number.

### Publish / unpublish

`official_results_publish` / `unpublish` behind a confirmation that says what becomes public (pair/team names and
their members), that linked players with a finished result get a notification - each once, also for a result added
after publishing (`official-results.md`); a republished round tells only who was not told yet - that the event is not
public yet when it is not (nobody is told until it is approved), and warns about entries without a result and about
this page's unsaved changes. The card shows the state and links the public
round page (when the round has an address).

### Export

`RoundResultsExporter`: one row per entry in ranking order - rank, table, entrant, members, country codes, result as
the pages show it ("1:23:45", "479 / 500 pcs", "Did not start"), time in seconds, pieces placed, qualified, entered
by, entered at (round's zone). Headers in the organiser's language. CSV = one UTF-8 file with a BOM (Excel), XLSX =
one sheet named in the organiser's language (`results_desk.export.sheet`, cleaned of the characters a sheet name may
not have), numbers typed. **Every cell through `SpreadsheetSafeValue`** (names are typed by people).
Deliberately not the data export's sectioned writer: one table is one file, not a ZIP.

## Advance the qualified (`advance_qualified_controller.js`)

From the desk (this round preselected as the source) and from the overview. Choose the source round(s) - once one
is chosen, rounds of another category are disabled - and the target round(s) (same category, not a source), then the
distribution: **all into one round** (`single`, exactly one target), **spread evenly by results** (`balanced`,
serpentine by the advancement seed), **by the round they come from** (`by_source`, a target per source).

**Country rule** - "Also the best of each country" + how many per country: over ALL the chosen source rounds, by the
advancement seed (official-results.md, `bestOfEachCountry`). The plan marks whom the rule takes ("country rule" badge,
"N entries are taken by the country rule and will be marked qualified in their rounds") and folds the ranked entries
without a country away for the organiser to mark by hand; "Add" marks them qualified in their own round and advances
them in the same write. The per-round helper on the desk stays for one round's own rule.

**Unsaved marks**: the dialog asks the desk on the same page (event `official-results:unsaved-marks`) and warns while
qualified marks are not saved yet - the plan uses the saved marks only (if they land before "Add", the plan hash no
longer matches and the new plan is shown).

"Show the plan" = the dry run: who goes where (seed, entrant, from round + rank, result), skipped with the reason,
entries before → after per target. "Add N entries" applies with the plan's `planHash`; on 409 `plan_changed`
(somebody re-marked, corrected a result or changed a target meanwhile) nothing was added - the new plan is fetched
and shown with "Something changed meanwhile - review the plan again". A lost answer is safe to repeat: the server
re-plans, finds everybody in already, and answers 409 with the new (empty) plan.

**Seat them now** (in-person rounds that use table numbers): per target the round's state is fetched, and everybody
who qualified into it - the new entries and those skipped as "already in the round" (matched by their people) - get
the lowest free table numbers in seed order (fastest at table 1, or slowest), in one `official_results_assign_table_numbers`
write with `from` = no table (if somebody seated them meanwhile, nothing is written and the reason is shown). Whoever has
a number already keeps it. "Skip" closes; "Seating" opens the round's seating page for changes by
hand.

## Results overview (`results_overview_controller.js`)

A row per round: badge + category, start (round's zone) + stopwatch running/stopped, entries, results entered
(x / y + bar), qualified, tables (x / y or "not used"; no column for online events; warning colour only while the
seating step is recommended), published (+ public page), Live entry / Results desk / Seating / Stopwatch. At the top:
Advance the qualified, the round list, **Name tags** (in-person events) and the **Referees** page
(`official_results/_referees_link.html.twig`). The counters follow every round's private topic on one stream (the page's token lists every round;
each update carries the round's progress). A refresh signal, the stream opening again, the tab coming back after 10 s
and every minute while it is shown fetch `official_results_competition_state` (`GET /{_locale}/official-results/
competitions/{competitionId}`, organisers only: every round's progress in one statement + a fresh token) and rewrite
every row - no page reload any more (it used to reload after a minute in the background because the subscription may
have lapsed).

## Live updates

Every page follows its rounds on a stream of its own with the subscriber token its state carries
(`OfficialResultsEvents`, official-results.md "Subscribing: a token per page, never the cookie") - not the
`mercureAuthorization` cookie, which every signed-in response rewrote with its own topics, so a reconnect silently lost
the round (review 2 M1). The desk, the seating page and the overview still fetch the state once a minute while shown
(2 statements + 1 for the rounds; the overview 1) as the last safety net, and every write carries the value the page
saw (`from`), so a stale page gets a conflict, never overwrites.

## Tests

`ResultsDeskControllerTest`, `ExportRoundResultsControllerTest`, `CompetitionResultsOverviewControllerTest`,
`RoundToolLinksTest` (hrefs compared with the router - independent of which branch owns `live_results` /
`round_seating`), `ManageCompetitionRoundsControllerTest` (tools + readiness per round; a past never-seated round and
a round without entries show none), node: `OfficialResultsRankingParityTest`, `OfficialResultsDeskHelpersTest`.
