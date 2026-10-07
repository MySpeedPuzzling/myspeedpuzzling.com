# Seating - table numbers before each round

Part of the official results (design of record: [official-results.md](official-results.md), spec §5b). Decided by Jan
2026-10-07: before every **in-person** round each player / pair / team gets a table number - usually the fastest at
table 1 and the slowest at the highest - and organisers may arrange it by hand. A semifinal and a final are seated
**during** the event, minutes after the previous round, because nobody knew in advance who would qualify (WJPC: 200
semifinalists split into two rounds of 100, seated ten minutes before the start by an organiser on a laptop with others
watching). Optional, but highly recommended: a referee then enters results by table number in seconds.

## Where

| What | Route | Notes |
|---|---|---|
| Seating page of a round | `round_seating` (`/en/round-seating/{roundId}`, cs `/zasedaci-poradek-kola/{roundId}`) | `RoundSeatingController`, `templates/seating/round_seating.html.twig`, `controllers/round_seating_controller.js` (lazy) |
| Printable lists | `round_seating_print` (`/en/print-round-seating/{roundId}`, `?list=tables` / `?list=names`) | `RoundSeatingPrintController`, `templates/seating/print.html.twig` - standalone like the table layout print |
| Auto-assign proposal (JSON) | `official_results_seating_proposal` (GET `/{_locale}/official-results/rounds/{roundId}/seating-proposal`) | `SeatingProposalController` → `SeatingProposer` |
| The seating step ("Tables: x / y assigned") on every page managing a round | round list, results desk, results overview, stopwatch control page, seating page, live entry | `templates/seating/_readiness.html.twig`, `SeatingReadiness::isShown()` - see "The seating step: one rule" |

All of them: `CompetitionEditVoter` on the round's competition, `noindex`, `Cache-Control: private, no-store`. The
JSON endpoint follows `OfficialResultsApi` (401 `sign_in_required`, 403 `forbidden`, never a login redirect).
`?propose=auto|earlier_rounds|msp_times|random|name` on the seating page opens the auto-assign proposal at once - the
"Seat them now" link after advancing qualified entries (`&first=101` pre-fills the first table number, e.g. for the
second of two semifinals in one hall).

## The page

Desk and tablet first, usable on a phone (44 px touch targets). It is drawn by the Stimulus controller from the round's
entries (`GetRoundResultEntries`, embedded in the page - nothing to load) and kept current from the round's private
Mercure topic (`OfficialResultsLiveUpdates`; `official_results.entries` merged, `official_results.refresh` and a tab
coming back after 20 s or the network coming back fetch `official_results_round_state` again) on the page's own stream
with the subscriber token it came with (`data-round-seating-mercure-value`; renewed with every state answer -
official-results.md "Subscribing: a token per page, never the cookie"; online events: none) - and, like the results
desk, the round's state is fetched once a minute while the tab is shown, the last safety net.
A state answer overtaken by a newer one, or older than entries merged while it was on its way, is dropped (and asked for
again), so a slow GET never undoes newer numbers.

Around it the page sits like the results desk and the live entry: breadcrumb Events › event › Results overview › round ›
Seating, the round's tools (Live entry, Results desk - `official_results/_round_tool_links.html.twig`) and a round switch
to the other rounds' seating.

- **Readiness**: "Tables: 180 / 200 assigned - recommended before the round starts" ("Every entry has a table." when
  done) - by the one rule below; a round under way or over shows no line here either.
- **Two lists**: "No table yet" on top (by name), then the seated entries by table number. Each row: drag handle, the
  table number input, name (flag, `#CODE`, members of a named pair/team), Move up / Move down, Swap.
- **Typing a number** (Enter saves and goes to the next row; Escape reverts) is one `official_results_record` change
  `table_number` with `from` = what the input showed when the organiser started typing (`data-seen`, written whenever
  the page fills the input; kept for a retry) - never the entry's number at save time: while an input has the focus a
  live update does not touch it, and taking its number as `from` would overwrite the other organiser silently
  (browser verification BLOCKER, 2026-10-07; `seating.js` `typedNumberWrite()`, pinned by `SeatingJsTest`). A number
  another organiser saved while this one was typing - seen before sending, or answered as a conflict - shows "Somebody
  else saved table 9 meanwhile." with **Keep mine** (written over it knowingly) / **Take theirs**. A number that belongs to another entry is not sent: the row says
  "Table 12 is Anna's." with **Swap them** (the other entry gets this entry's old number, or none). While a number
  input has the focus the rows do not re-sort (no jumping under the cursor); they settle when the focus leaves the list.
- **Swap**: Swap on one row, then "Swap with …" on another (Escape cancels) - one bulk write of the two numbers.
- **Drag and drop** (SortableJS, loaded only here; the "No table yet" drop target that opens above the seated list
  while dragging scrolls the page by its own height, so nothing moves under the pointer - and back when it closes) and
  **Move up / Move down** (keyboard / phone twin, crossing between
  the two lists) change only the page's order: a bar says "nothing is saved until you renumber" with **Renumber
  101…200 in this order** and **Undo the order changes**; leaving the page asks first. Renumbering starts at the
  smallest table among the seated list (a second hall numbered from 101 stays from 101), else 1; entries moved to "No
  table yet" lose their table.
- **Seat them at tables 181…200**: the entries without a table get the tables after the highest one (latecomers).
- **Clear all** (confirm) and **Auto-assign** (below).
- **Find** box: table number, name, member, `#code` (accents ignored); drag and drop is off while filtering.
- Every bulk action (swap, renumber, seat the rest, clear all, apply a proposal) is **one**
  `official_results_assign_table_numbers` write, validated as a whole by `AssignTableNumbers` - all or nothing. Every
  assignment carries `from` = the number this page showed when the action was taken (`seating.js` `withFrom()`; kept for a
  "Try again"), so a number another organiser changed meanwhile refuses the whole write (`changed_meanwhile`): "Somebody
  else changed table numbers meanwhile - nothing was saved", the rows name it and the page fetches the round again. Any
  other refusal (422) names the entries ("Nothing was saved - fix the tables below") and re-fetches too. Each success
  toast has **Undo** (the numbers before, as one more bulk write whose `from` is the number the write set - an entry
  renumbered by somebody else since is not undone over their change). Signed out / offline / server errors keep the
  page and offer "Try again" (+ "Sign in again" in a new tab).
- The order bar, the swap bar and the readiness alert carry `hidden` on a wrapper: Bootstrap's `d-flex` is
  `!important` and wins over `[hidden]` on the same element (`HiddenAttributeDisplayUtilityTest` guards every template).
  The two bars stick right under the site's header (`--header-height`).
- **"This round doesn't use table numbers"** (`official_results_table_numbers_usage`, undoable from the toast): the page
  then says so and offers **Use table numbers**; the readiness line disappears everywhere.
- **Online events**: the page only explains that seating is for in-person events.

The pure half of the page - the order, every write an action sends, the find box - is `assets/seating.js`, run under
node by `tests/SeatingJsTest.php`.

## Auto-assign: a proposal first

`SeatingProposer::propose()` numbers **every** entrant of the round from a first table number (default 1), the entrants
with data first - fastest at the first table, or slowest first when asked - then the entrants without data by name. The
page shows the proposal (table, entrant, "Based on" for earlier rounds, the current table struck through when it
changes, "N entries have no data", "Changes the table of N entries") and **Apply these tables** writes it as one
`AssignTableNumbers` (only the entries whose table changes). If the round's entrants changed since the proposal (somebody
was added or removed) the page asks for a fresh proposal instead of applying.

### Sources (`SeatingSource`)

1. **Results of earlier rounds** (`earlier_rounds`). An entrant's source round is its **latest** earlier round of the
   event in which the same people have a *ranked* result (a time or pieces placed): rounds of the **same kind** (solo /
   pair / team), other than this one, **starting no later** than this one (organisers often give a day's rounds one
   placeholder start), latest first. "The same people" = the same participant for a person, **exactly the same set of
   participants** for a pair/team (`AdvanceQualified` creates the target team with the same people, so advanced teams
   always match). The found entries are then ordered by `AdvancementSeeding` - rank within their own round, ties and
   different rounds interleaved by result ÷ that round's winner, unfinished after finished - over the whole source
   rounds (so each round's winner is its real winner). A final's entrants are seeded by the semifinal, not the groups
   they played before it; an entrant who did not start the semifinal falls back to their group. "Based on: Group A: 3."
2. **MySpeedPuzzling times** (`msp_times`), `GetSeatingSeedTimes` - two set-based statements for any number of
   entrants:
   - piece count: the round's puzzle (several: the most frequent, then the largest); **no puzzle yet: 500** (the event
     page's participants chart compares people by 500-piece times) and the proposal says so;
   - a person: their Puzzle Insights **baseline** for that piece count (`player_baseline` - the weighted median of
     first-attempt solo times, interpolated from their other piece counts when needed); without one, the **median of
     their own solo times** of that piece count from the last 24 months (not suspicious). Chosen over the event page's
     average (500 pieces only, last 3 months, any attempt) because it exists per piece count and is already
     precomputed, and over `GetPlayerPredictions` (per player, per puzzle - N queries);
   - a pair/team: the median time of the **puzzling team of exactly these people** (`puzzling_team.composition_key`,
     `TeamComposition::keyFromMemberKeys`) for that piece count from the last 24 months - only when every member is a
     player visible to the organiser (a guest's name could match anybody's guest); else the **mean of its members'**
     times (members without data left out); nobody with data = no data. Known bias: a pair's own pair time is faster
     than its members' solo times, so pairs that puzzled together come first - the organiser reviews the proposal;
   - **privacy**: a player whose profile is private to the organiser (`PrivateProfileAccess::sqlIsPrivate`, the allow
     list applies) has no data at all - the event page's participants chart leaves them out as well. Nothing but the
     resulting order (and how many had data) reaches the page: no times, no baselines, no members-only insights.
3. **Random draw** (`random`) - `sha256(seed:ref)` order; the proposal shows "Draw no. 48213" and **Draw again**; the
   same number with the same entrants gives the same order (also after changing the first table).
4. **By name** (`name`) - the page language's collation (`Collator`).

**Default** (the page opens with it, marked "best available"): earlier rounds when they place at least half of the
entrants or more of them than MySpeedPuzzling times (the round's entrants came from qualification), else MySpeedPuzzling
times when anybody has data, else by name. Each source shows "n of N with data".

## Printed lists

`?list=both` (default, a page break between them), `tables`, `names`. Large type (table numbers 26 px), names as the
organiser recorded them (never player profiles or codes - the list hangs at the venue), country codes as text.
- **By table**: table, entrant (named pair/team with its members), country; entries without a table last ("–" + "Not
  seated yet - ask at the desk.").
- **Find your table**: every **person** alphabetically (collation of the page language) with their table - each member
  of a pair/team on a line of their own pointing to the pair's/team's table, with the pair's/team's name (unnamed:
  "with Eva Noshow"). Two columns on paper.

## The seating step: one rule

`SeatingReadiness::isShown()` is THE rule - in-person event, the round uses table numbers, has entrants, it has not
started (its stopwatch never ran) and it was due to start less than 12 hours ago or later (a late start keeps it). A
round under way, finished or past never nags - every existing event's pages stay as they were.
`GetRoundResultsOverview` computes it into `RoundResultsOverview::$showsTablesReadiness` (JSON `tablesReadiness`, also in
every Mercure update and state answer), and every page follows it: the round list (compact line), the results desk
(compact, redrawn from the desk's own numbers), the results overview (the Tables column turns warning-coloured only while
it holds), the stopwatch control page (alert with the way to the seating page), the seating page and the live entry
(its recommendation shows only while `tablesReadiness`). One partial (`templates/seating/_readiness.html.twig`, `compact`
or the alert) and one set of texts (`seating.readiness.progress` / `recommended` / `done`).

## Tests

`tests/Services/Seating/SeatingProposerTest.php` (ordering, ties, slowest first, no-data last, random repeatability,
default source, earlier-round source selection incl. pairs, pairs/teams times, composition keys),
`tests/Services/Seating/SeatingProposalIntegrationTest.php` (fixture event "Results Cup": final seeded from both groups,
slowest first from table 101, MySpeedPuzzling baselines / median / private player / no times in the JSON, pairs by
their pair time or members, a no-puzzle round at 500 pieces, pairs final from the pairs round, draw and by name),
`tests/Controller/Seating/*` (page, print, proposal endpoint, stopwatch readiness incl. pages that must not change),
`tests/SeatingJsTest.php` (assets/seating.js under node).
