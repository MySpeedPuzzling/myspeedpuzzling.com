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
"Results", "Seating" + "Results overview" at the top), the event edit page and the series' editions list (one
"Results overview" button), the overview's rows, and the desk itself (round switcher, the other tools of the round).

`templates/official_results/_round_tool_links.html.twig` is the **one** place a round's three tools are linked
(`live_results`, `results_desk`, `round_seating` - Seating only for in-person events, marked "not used" while the
round goes without table numbers). `_tables_readiness.html.twig` is the "Tables: x / y assigned" line
(`OfficialResultsRound::showsTablesReadiness()`): in-person rounds that use table numbers and have entries - not a
past round that was never seated (history, not a to-do). It is a recommendation, never a block.

`OfficialResultsRounds` (service) joins the round list's rounds (`GetCompetitionRoundsForManagement`: badge colours,
the zone a start is shown in) with their progress (`GetRoundResultsOverview`) and the public round page
(`OfficialRoundPageUrl`) into `OfficialResultsRound` - its JSON is the overview's JSON plus `color`, `textColor`,
`timezone`, `publicUrl`. The round list pays one more statement for it.

## The desk (`results_desk_controller.js`)

The page bootstraps exactly what `official_results_round_state` answers (plus the colours and the public URL) and
authorises the round's private Mercure topic. Columns: rank, table (hidden for online events and rounds without
table numbers), entrant (team: members with flags and #CODE), country flags, result, entered by · at, Qualified.
Entries without a result and did-not-start are listed (organisers only). Search by table number (exact), names,
member names and #CODE (accent-insensitive); filter all / without a result / qualified / not saved yet.

- **Ranking in the browser** (`assets/official_results_ranking.js`) - the same rules and order as
  `OfficialResultsRanking` + `GetRoundResultEntries`, pinned by `OfficialResultsRankingParityTest` (node runs the
  module against the PHP implementation). Ranks follow what the desk shows, i.e. unsaved values too (they are
  marked).
- **Inline edits**: result (Finished = the shared time parser `official_results_time.js` with a live preview,
  Didn't finish = pieces placed 1..pieces−1, Did not start, No result) and table number (1..9999, empty = none).
  Enter saves, Escape cancels; while an editor is open the row order is frozen and that row is never re-rendered
  (typing is never wiped); focus is restored after every re-render.
- **Unsaved changes** (`assets/official_results_pending_changes.js`, `PendingChanges`): one cell per entry field;
  `from` = the server value the organiser saw when they changed it, so a value somebody else saved meanwhile comes
  back as a conflict. A cell is saved only when the server answered `applied`/`unchanged` for exactly that value.
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
  while the tab is shown - see "Mercure cookie" below. When our own answer and a live update disagree about an entry
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
their members), that linked players get a notification once - on the first publication only - and warns about
entries without a result and about this page's unsaved changes. The card shows the state and links the public
round page (when the round has an address).

### Export

`RoundResultsExporter`: one row per entry in ranking order - rank, table, entrant, members, country codes, result as
the pages show it ("1:23:45", "479 / 500 pcs", "Did not start"), time in seconds, pieces placed, qualified, entered
by, entered at (round's zone). Headers in the organiser's language. CSV = one UTF-8 file with a BOM (Excel), XLSX =
one sheet `results`, numbers typed. **Every cell through `SpreadsheetSafeValue`** (names are typed by people).
Deliberately not the data export's sectioned writer: one table is one file, not a ZIP.

## Advance the qualified (`advance_qualified_controller.js`)

From the desk (this round preselected as the source) and from the overview. Choose the source round(s) - once one
is chosen, rounds of another category are disabled - and the target round(s) (same category, not a source), then the
distribution: **all into one round** (`single`, exactly one target), **spread evenly by results** (`balanced`,
serpentine by the advancement seed), **by the round they come from** (`by_source`, a target per source).

"Show the plan" = the dry run: who goes where (seed, entrant, from round + rank, result), skipped with the reason,
entries before → after per target. "Add N entries" applies with the plan's `planHash`; on 409 `plan_changed`
(somebody re-marked, corrected a result or changed a target meanwhile) nothing was added - the new plan is fetched
and shown with "Something changed meanwhile - review the plan again". A lost answer is safe to repeat: the server
re-plans, finds everybody in already, and answers 409 with the new (empty) plan.

**Seat them now** (in-person rounds that use table numbers): per target the round's state is fetched, and everybody
who qualified into it - the new entries and those skipped as "already in the round" (matched by their people) - get
the lowest free table numbers in seed order (fastest at table 1, or slowest), in one `official_results_assign_table_numbers`
write. Whoever has a number already keeps it. "Skip" closes; "Seating" opens the round's seating page for changes by
hand.

## Results overview (`results_overview_controller.js`)

A row per round: badge + category, start (round's zone) + stopwatch running/stopped, entries, results entered
(x / y + bar), qualified, tables (x / y or "not used"; no column for online events), published (+ public page),
Live entry / Results / Seating. The counters follow every round's private topic (each update carries the round's
progress; a refresh signal fetches it). After a minute in a background tab the page reloads when it comes back
(never while a dialog is open).

## Mercure cookie (known limitation)

`MercureSubscribeCookieListener` writes the subscribe cookie on **every** signed-in response with the topics of that
request only - also on the JSON endpoints and on any page opened in another tab. An open desk keeps receiving
updates on its established connection, but after a reconnect (a Wi-Fi drop, the hub's write timeout) the cookie may
no longer authorise the round's topic and updates stop silently. The desk therefore re-fetches the state once a
minute while shown (2 statements + 1 for the rounds) - eventual consistency within a minute even without Mercure.
Follow-up in `docs/TODO.md`.

## Tests

`ResultsDeskControllerTest`, `ExportRoundResultsControllerTest`, `CompetitionResultsOverviewControllerTest`,
`RoundToolLinksTest` (hrefs compared with the router - independent of which branch owns `live_results` /
`round_seating`), `ManageCompetitionRoundsControllerTest` (tools + readiness per round; a past never-seated round and
a round without entries show none), node: `OfficialResultsRankingParityTest`, `OfficialResultsDeskHelpersTest`.
