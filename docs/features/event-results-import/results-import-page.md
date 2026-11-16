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
  own). Kill switch flag hides the page; console command + internal API stay for admins.

## Changes to today's code (nothing breaks)

| Today | Becomes |
|---|---|
| `event_results_sheet` | the results source + `format` column (migration fills `sarah_does_puzzles`) |
| `event_results_sheet_tab` keyed by gid | keyed by the format's stable key (Sarah's = gid, nothing re-imports) |
| `SarahDoesPuzzlesTabParser` | `SarahDoesPuzzlesFormat implements ResultsFormat` |
| `myspeedpuzzling:sync-sarah-does-puzzles-results` | `myspeedpuzzling:sync-results-source <id>` (old name = alias) |
| `/internal-api/event-results-sheets` | + `format` when registering |
| `ApplyEventResultsTab`, `SyncEventResultsSheet` | same behaviour, input through the format |
| — | `results_import_check` (snapshot) + `results_import_log` (who, when, which events, counts) |

## Delivery

1. **Generalise the core** - format interface, Sarah's parser behind it, `format` column, stable keys. No visible
   change; a test proves the current import produces identical results.
2. **Read-only page** - check, statuses, preview, differences, Sarah's guide.
3. **Import from the page** - tick + import from the snapshot, the log, removed-team handling (P4), kill switch.
4. **Sandbox for Sarah** - her account copied, her organization and source registered on sandbox.
5. **The second organizer** - their format class + guide, an admin registers their sheet.
