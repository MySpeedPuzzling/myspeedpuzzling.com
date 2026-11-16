# Results import page - reusable per organization and format (plan of record, 2026-11-16)

An organizer imports their own results from their sheet on MySpeedPuzzling: **Check sheet → every event with its status
→ preview → tick → Import**. Nothing on a schedule, nothing written before they confirm. Built for Sarah Does Puzzles
first; the next organizer differs only in how their sheet is read (their **format**) - everything else is shared.

Builds on [README.md](README.md) (the import, claims, unfinished results D9) and the existing one-event write path.

## Jan's decisions (2026-11-16)

| # | Decision |
|---|---|
| P1 | A **results source** (a sheet + its format) belongs to an organization; an organization may have several, each with its own format. Sources are set up **by admins only** - internal API / by hand, no UI now |
| P2 | The organization's team (and admins) import **without Jan's approval** - the preview + confirm is the approval |
| P3 | **No undo** - the preview is where they confirm what is correct; corrections go through the sheet |
| P4 | A team that disappears from the sheet: **deleted when nobody claimed it** (proposed; Jan asks Sarah in his mail). A claimed one is kept and shown as "no longer in the sheet - kept, claimed" |
| P5 | The page is called **"Results import"** for every organization |
| P6 | Everything ships to production (it touches nobody else); Sarah tests on sandbox first with a copy of her account and organization |

## Three layers

```
Organization ──< Results source (sheet + format) ──< Source item (a tab today) ──> Event + its results
                        │
                        └── ResultsFormat (code): sheet snapshot → neutral events
```

1. **Format - the only per-organizer code.** `ResultsFormat` (tagged services by `key()`): `key()`, `name()`,
   `guideTemplate()` (how the sheet must look, with an example), `parse(SheetSnapshot): list<ParsedEvent>`. The format
   decides how tabs map to events (today one tab = one event) and gives each event a **stable key** (today the tab's gid)
   - re-imports match by it. Sarah's parser becomes `SarahDoesPuzzlesFormat`, same rules.
2. **Neutral model - the contract.** The event (name, venue, city, country, date, start time?, time limit?, pairs /
   teams / later solo), the puzzle (or "waiting for puzzle"), rows (team name, time *or* pieces placed, team size) and
   problems with a cell reference in plain words. Shared checks after the format: platform rules only (pieces, limit,
   puzzle exists).
3. **Shared flow.** Check (read + parse + store a **check snapshot**) → compare with what is stored (new / changed /
   unchanged / waiting for puzzle / needs fixing, per-team differences) → preview → import the ticked events **from the
   stored snapshot** (never re-read: what they saw is what gets published; edit the sheet → check again) → log. Claims,
   unfinished results and display are already format-agnostic.

## Pages

- `/{_locale}/organizations/{slug}/results-import` - the organization's sources (one → straight to it).
- `/{_locale}/organizations/{slug}/results-import/{sourceId}` - the flow. Organization team + admins
  (`GetCompetitionPermissions` organization legs); linked from the organization page's ⋯ menu and "You organize" only
  where a source exists. All 6 locales. Plain server-rendered pages + forms; shared partials (status list, event card,
  ranked preview, difference table, problems), only the guide differs per format.
- Statuses: **New** / **Changed** (ticked by default), **Unchanged**, **Waiting for puzzle**, **Needs fixing** (cannot
  be ticked). Soft warnings never block: puzzle not approved yet, rows out of time order, a team name twice, a time over
  the limit without pieces placed.
- Reading Google takes seconds: outside any transaction (`HandledOutsideTransaction`, writes through a message of its
  own). No kill switch (Jan, PR 4): the page exists where an admin registered a source; the generic console command +
  internal API stay for admins.

## Changes to today's code (nothing breaks)

| Today | Becomes |
|---|---|
| `event_results_sheet` | the results source + `format` column (migration fills `sarah_does_puzzles`) |
| `event_results_sheet_tab` keyed by gid | keyed by the format's stable key (Sarah's = gid, nothing re-imports) |
| `SarahDoesPuzzlesTabParser` | `SarahDoesPuzzlesFormat implements ResultsFormat` |
| `myspeedpuzzling:sync-sarah-does-puzzles-results` | removed (Jan: no dedicated console command any more) - a generic `myspeedpuzzling:sync-results-source <id> [--dry-run]` stays for admins/ops |
| `/internal-api/event-results-sheets` | + `format` when registering |
| `ApplyEventResultsTab`, `SyncEventResultsSheet` | same behaviour, input through the format |
| — | `results_import_check` (snapshot) + `results_import_log` (who, when, which events, counts) |

## Delivery

1. **Generalise the core** - format interface, Sarah's parser behind it, `format` column, stable keys. No visible
   change; a test proves the current import produces identical results. **Built (2026-11-16, branch
   `results-import-1-core`)** - see "As built: PR 1" below.
2. **Read-only page** - check, statuses, preview, differences, Sarah's guide.
3. **Import from the page** - tick + import from the snapshot, the log, removed-team handling (P4), kill switch.
4. **Sandbox for Sarah** - her account copied, her organization and source registered on sandbox.
5. **The second organizer** - their format class + guide, an admin registers their sheet.

## As built: PR 1 - the core behind formats (2026-11-16)

- **Format**: `Services\EventResultsImport\Format\ResultsFormat` (`key()`, `name()`, `guideTemplate()`,
  `parse(SheetSnapshot): list<ParsedEvent>`), tagged by `#[AutoconfigureTag]`; registry `ResultsFormats` (`get()` throws
  `UnknownResultsFormat` naming the known keys, `has()`, `keys()`, `all()`). `SarahDoesPuzzlesFormat` (key
  `sarah_does_puzzles`) = the old parser moved, its rules untouched; `parseTab()` reads one tab.
- **Neutral model**: `ParsedEvent` (was `ParsedEventTab`) + `key` (the stable key, ≤ 20 characters) and `title`.
  `SheetSnapshot` = what `GoogleSheetReader::snapshot()` fetched: `SheetSnapshotTab` (gid, title, CSV) per tab, and the
  tabs whose CSV did not come aside as `UnreadableSheetTab` (never parsed as empty). `ResultsSourceReader::read()` =
  snapshot + the source's format → `ResultsSourceRead` - the one read of the sync and the dry run (PR 2's check reuses it).
- **The content hash is the format's** and computed exactly as before - never of the key or the title (a renamed tab is
  unchanged). `EventResultsImportParityTest` holds the hashes, problems, stored tabs and results captured from the old
  code before the change, plus a tab stored by the old code that the new path must skip.
- **Stable keys kept in `event_results_sheet_tab.gid`** - no column rename: Sarah's key is the gid, so every stored tab
  (production: 4 tabs, 1 imported) matches as it did; the internal API keeps the field name `gid`. A later format with
  keys longer than 20 characters widens the column (a generated migration).
- **`event_results_sheet.format`** `VARCHAR(50) NOT NULL DEFAULT 'sarah_does_puzzles'` (one generated statement; the
  constant default fills the existing rows without a table rewrite). `ApplyEventResultsTab(sheetId, ParsedEvent)` - lock
  key unchanged (`event-results-tab-<sheet>-<key>`). Per-tab read failures give the same `last_fetch_error` text.
- **Console**: `myspeedpuzzling:sync-results-source [<sourceId>] [--dry-run]` (no id = every source); the Sarah command is
  gone. **Internal API**: `POST /internal-api/event-results-sheets` takes an optional `format` (default
  `sarah_does_puzzles`, unknown = 400), every answer carries `format`.
- **Guide**: `templates/results_import/format_guide/sarah_does_puzzles.html.twig` (`results_import.format_guide.*` in all
  6 locales, rendered by `ResultsFormatsTest`), not linked anywhere until PR 2.

## As built: PR 2 - the read-only page (2026-11-16)

- **Routes** (single-action controllers in `src/Controller/ResultsImport/`, all `noindex`, `Cache-Control: private,
  no-store`): `results_import_sources` `GET /{_locale}/organizations/{slug}/results-import` (one source → 302 to it, none
  → 404, several → a list), `results_import` `GET …/results-import/{sourceId}` (the flow), `results_import_event`
  `GET …/results-import/{sourceId}/events/{eventKey}?check=<id>` (the preview; without `check` the latest),
  `results_import_check` `POST …/results-import/{sourceId}/check` (session CSRF `results_import_check_<sourceId>`;
  always a redirect - a stale token too, with a flash).
- **Access**: `ResultsImportPage::organization()` - the kill switch (404), the organization by slug (404),
  `ORGANIZATION_EDIT` (its creator, maintainers and admins - the organization legs of `GetCompetitionPermissions`):
  another player 403, a guest is sent to sign in, like the organization's edit page. `sourceOf()` = 404 for a source of
  another organization. Linked from the organization's ⋯ menu and "You organize" (`events/_manage_items.html.twig`,
  "Results import") only when `OrganizedEvent::$resultsSourceCount > 0` - a subquery of the existing statement, no
  statement more.
- **Check**: `CheckResultsSource` (`HandledOutsideTransaction`) reads `GoogleSheetReader::snapshot()` - never parses -
  and writes through `RecordResultsImportCheck`: `results_import_check` (`source_id` CASCADE, `checked_by_id` SET NULL,
  `checked_at`, `snapshot` jsonb `{tabs: [{gid, title, csv}], unreadable: [{gid, title, reason}]}`, `read_error`). A
  failed read is a check too (the page says it, logged at `info` - the organizer fixes the sharing). The source keeps its
  newest 10 checks (`RecordResultsImportCheckHandler::KEEP`). The stored CSV round-trips byte for byte, so a stored check
  parses to the same events and content hashes as the sheet (`ResultsImportComparisonTest`).
- **Statuses** (`ResultsImportComparison`, three statements for any number of events: stored tabs + their results,
  puzzles with merge redirects): decided **in `ApplyEventResultsTabHandler`'s order** - Unchanged (imported, same hash),
  Event deleted (detached), Needs fixing (a tab problem), Waiting for puzzle (no link / puzzle not found), Needs fixing
  (pieces placed ≥ the puzzle's, round category or puzzle changed after import), else Changed (imported) or New.
  `ResultsImportComparisonTest` imports every predicted event and compares what the import recorded. Row-level problems
  (a team twice, an unreadable time…) and the soft warnings (puzzle not approved, times out of order, a time over the
  limit without pieces) never block; every note is a sentence (`results_import.note.<code>`, the cell value and the
  row).
- **Preview**: the event card (the sheet's wall clock shown as written), the puzzle with its picture and approval, links
  to the stored event and its results; the differences against what is stored - the event's fields, then team by team:
  added, removed ("No longer in the sheet - removed (nobody claimed it)"), kept ("No longer in the sheet - kept,
  claimed", P4), time / pieces / size changed (a claimed team's size stays) - also for an Unchanged event that keeps a
  claimed team; the teams ranked by `ResultsImportComparison::rankedRows()` = `GetRoundResults::order()` for the
  organizer's results (pinned against it) - with a claimed team the sheet dropped ranked too, marked "No longer in the
  sheet - kept, claimed" (it stays on the round page, P4; `rankedRows($event, $teamChanges)`). "Claimed" = tracker or a registered member in the group snapshot =
  `isClaimedByAnybody()` (pinned, also after the first claimer left). Claimers are never named - the only name on the
  page is the team member (or admin) who pressed "Check sheet".
- **Guide**: the format's `guideTemplate()` in a `<details>` help box. Kill switch `RESULTS_IMPORT_PAGE` (removed in PR 4)
  (`docs/features/feature_flags.md`).
- **Not in the canaries on purpose**: the page is for the organization's team and admins only and names no player of a
  result (no claimer; the only name is the teammate who pressed "Check sheet"), so `DraftCanaryTest`, `BlocklistCanaryTest`
  and `PrivateProfileCanaryTest` do not list it; its queries are classified in the coverage tests.
- **Every locale** renders the list and the previews (`ResultsImportPageTest`); claim words follow `event_result_claim.*`
  (fr "réclamé", ja "申告").

## As built: PR 3 - import from the page (2026-11-16)

- **Tick**: the list is a GET form - New and Changed events carry a ticked box, every other status a disabled one.
  "Import ticked events" → `results_import_confirm` `GET …/results-import/{sourceId}/import?check=<id>&events[]=…`:
  "Import 2 events, 5 teams?", each event with its status, teams and changes, the no-undo note (P3), and a form carrying
  a **new import id** (UUIDv7). Only the source's **latest** check is imported from - an older one (somebody checked
  again meanwhile) goes back to the page with "Your sheet was checked again meanwhile"; nothing ticked, likewise.
- **Import**: `results_import_import` `POST …/import` (session CSRF `results_import_import_<sourceId>`, always a
  redirect to `…?import=<id>#import-result`) dispatches `ImportResultsFromCheck` (`HandledOutsideTransaction` +
  `SerializedByLock` on `results-import-<sourceId>`): the stored check's sheet parsed again through the source's format -
  **Google is never read** - and every ticked event that can be imported goes through `ApplyEventResultsTab` with
  `readAt` = the check's read time, each in a transaction of its own like the sync. One failing event is logged
  (warning) and recorded as failed; the entity manager is cleared (or reset when closed) and the next event goes on.
  The outcome comes from the apply handler's return value (`AppliedEventResultsTab`: status, competition, teams
  added / updated / removed, `unchanged`, `stale`) - nothing is predicted.
- **Staleness**: the apply refuses an event whose tab was applied **after** `readAt` (after its unchanged early return,
  under the tab's lock) - an older check never overwrites what a newer one published. The sync passes no `readAt`.
  Judgement call: an admin's sync run after a check makes that check's tabs stale too ("check again") - over-refusing
  is the safe side.
- **Idempotency**: the import id is the primary key of `results_import_log`; the handler does nothing when it exists,
  the POST redirects straight to its result, and the source's lock makes a double submit wait for the first one.
- **`results_import_log`** (generated migration): `id` (the import id), `source_id` CASCADE, `check_id` (copied, no
  foreign key - checks are pruned), `imported_by_id` SET NULL, `imported_at`, `events` jsonb (`key`, `title`,
  `outcome` = `ResultsImportOutcome`: imported / unchanged / stale / not_imported / not_in_check / failed,
  `competition_id` copied, `added`, `updated`, `removed`), `teams_added` / `teams_updated` / `teams_removed`. Logged at
  `info` too (no approval, P2 - nobody is notified).
- **After the import**: the page shows the result (each event's outcome and team counts, links to its results page and
  its event page - `GetResultsImportEventLinks`), the latest 10 imports as history (who, when, events, teams).
- **P4** was already the import's behaviour (`upsertResults()`): a team gone from the sheet is deleted when nobody
  claimed it, kept with `claimed_team_removed_from_sheet` when somebody did; claims are never touched. Now counted
  (`teams_removed`) and tested through the page (`ResultsImportFlowTest`).
- Tests: `ResultsImportFlowTest` (ticks, confirmation, import from the stored check with Google switched off, result +
  links, log, double submit, P4, older check, nothing ticked, stale token, access), `ImportResultsFromCheckHandlerTest`
  (stale refusal, a failing event among others, same import id twice, not importable / not in the check).
- **Browser walk** (headless Chromium, desktop and 390 px, the dev app on a fixture database with a stored check of a
  made-up sheet): list → previews → tick → confirmation → import → result with links → round page → history, and a
  failed "Check sheet". It found that the preview left out a claimed team the sheet dropped (it stays on the round page)
  and that the status counts did not wrap on phones - both fixed in PR 2; no page overflows at 390 px.

## As built: PR 4 - the organizer changes the sheet, no duplicates, no kill switch (2026-11-16)

Jan's decisions of 2026-11-16: no kill switch; the organizer may change the spreadsheet any time; a change never
duplicates an event.

- **No kill switch.** `RESULTS_IMPORT_PAGE` is gone (parameter, `ResultsImportPage::isEnabled()`, the Twig function
  `results_import_enabled()` and its extension, the flag's tests and its row in `docs/features/feature_flags.md`).
  The toggle is whether an admin registered a results source (with a format) for the organization: none = every route
  404 and no link (`OrganizedEvent::$resultsSourceCount`), as before.
- **"Your sheet"** on the source's page (`#your-sheet`): a link opening the current spreadsheet in Google and, folded,
  **Change sheet** - paste the sheet's link (or its id). `results_import_sheet` `POST
  …/results-import/{sourceId}/sheet` (`ChangeResultsSourceSheetController`, session CSRF `results_import_sheet_<id>`,
  the team + admins like every route of the page) cuts the id out with `GoogleSheetLink` (the data import's rule:
  any docs.google.com spreadsheet address, a bare id too; a "Publish to the web" link gets its own sentence) and
  dispatches `ChangeResultsSourceSheet` (`HandledOutsideTransaction` + `SerializedByLock` on the source's import lock
  `results-import-<sourceId>` - never in the middle of an import). Its handler only reads: the same sheet → "This is
  your sheet already", another source's sheet (`spreadsheet_id` is unique) → refused, then
  `GoogleSheetReader::snapshot()` once - unreadable (not shared "Anyone with the link: Viewer") → refused (info log,
  like a failed check). A readable one goes to `RecordResultsSourceSheetChange` (its own transaction): the named
  entity method `EventResultsSheet::changeSpreadsheet()`, **every older check dropped** (they were of the previous
  sheet - an import is made only from the latest check) and the read stored as the new check, so the page shows the new
  sheet's events at once. Answered as `ResultsSourceSheetChange` (the handler's result); always a redirect + flash -
  success to the page, a refusal back to `#your-sheet`. A refusal writes nothing, not even a check.
- **Who and when, no schema change**: the check written by the change keeps `checked_by` / `checked_at` ("Last checked
  … by …" on the page until the next check); the change itself is logged at `info` with the previous and the new id
  (`Results source …: spreadsheet changed by …`). No line in the import history - that would need a column or a log
  row type of its own; ask if wanted.
- **No duplicates after a change** - `PreviousSheetEvents::match()` (pure, format-agnostic, reads only the neutral
  model): the new sheet's tabs may have other gids (a *duplicated* Google spreadsheet usually keeps them - then nothing
  here is needed). An event with **no imported item under its own key** is the event imported before when exactly one
  imported item of the source, stored under a key the sheet no longer has, has its **date** (the day of
  `localStartsAt` = the competition's `date_from`) **and name** (`SearchText::fold()` of the trimmed name = the
  competition's: case, accents, whitespace folded). The page shows it as Changed / Unchanged **against that event**
  with "From your previous sheet …" (list, preview, confirmation; `ResultsImportEvent::$previousSheetKey`), and the
  import passes `ApplyEventResultsTab::$replacesKey`: the stored item moves to the new key
  (`EventResultsSheetTab::movedToKey()`, info log) and the same event is updated - its results follow (they hang on the
  item's id). Uncertain = **Needs fixing, never imported**: two imported events with its date and name
  (`matches_several_imported_events`, "rename one"), or two events of the sheet matching one imported event
  (`shares_imported_event`). A never-imported item under the new key (it waited for its puzzle) is removed first by
  `RemoveUnimportedResultsSourceItem` in its own transaction (the unique `(sheet_id, gid)` - Doctrine updates before
  it deletes). Decided again at import time from what is stored then (`ImportResultsFromCheckHandler`), never taken
  from the page, and the admins' sync does the same (an uncertain one is skipped and named in `last_fetch_error`).
  An event whose MySpeedPuzzling event was deleted (detached) has no name to match by and is not matched. An
  Unchanged match is not tickable, so its item stays under the old key and is matched again on every check - harmless.
- Tests: `PreviousSheetEventsTest` (the rule: folded name, another day / name, a key still in the sheet, imported under
  its own key, never-imported items, both uncertain cases), `PreviousSheetEventsImportTest` (predicted statuses, import
  moves the item and updates the same event - one competition, same item id, new key, time updated; both uncertain
  cases import nothing; the never-imported item makes way; the sync moves too and skips an uncertain one),
  `ResultsSourceSheetChangeTest` (change → new link, the only check with its changer, the event Changed "from your
  previous sheet"; every refusal changes nothing and writes no check; guest / another player; every locale).
- **Not run locally**: Docker was off the day it was built - written and verified on CI only; no browser walk yet.
