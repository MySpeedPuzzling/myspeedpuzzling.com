# Data import: remembered layouts (stage 4c)

What stage 4c built of [the design](README.md) ("Remembered: the layout is saved for the player"): the columns and
answers a player confirmed for a sheet are applied by themselves the next time a sheet of the same shape arrives -
in a later import, and at once on the other sheets of the same workbook. Built on [columns.md](columns.md) (the
mapping, the options, `ConfirmImportMapping`, `ProposeImportMappings`) and the screen of
[columns-screen.md](columns-screen.md).

Code: `src/Entity/ImportLayout.php`, `src/Services/DataImport/ImportLayoutMatcher.php` (pure - the signature and
every rule below), `src/Services/DataImport/ImportLayouts.php` (saving and sharing), `src/Query/GetImportLayouts.php`,
`src/Value/DataImport/{ImportLayoutContent,SavedImportLayout,RememberedLayout}.php`, the messages below.
Tests: `tests/Services/DataImport/ImportLayoutMatcherTest.php`, `tests/Controller/DataImport/ImportLayoutsTest.php`.

## The flow

```
ConfirmImportMapping (the player's word on a sheet; validation and versions as before)
  └─ ImportLayouts::confirmed()          same transaction, under the import's lock
       1. the layout of the sheet's header row is saved for the player (new, or the newer word over the old one)
       2. sheets of the same workbook with the very same header row that the player has not confirmed themselves
          get that mapping and those answers as a new version each → ParseImportSheet

ProposeImportMappings (a later import)
  per sheet: ImportSheetAnalyser → ImportLayoutMatcher::apply()      the player's saved layout over the proposal
  across the workbook: SheetLookupFactory::link() → ImportLayoutMatcher::withRememberedLookups()
  per sheet: ImportLayoutMatcher::settle()                           the layout answers what the sheet still asks
  └─ RecordImportMappings                                            stores it; counts the layout as used once
```

## The signature

`ImportLayoutMatcher::signature()`: **`L1:` + sha256 of the sheet's header keys, left to right**.

- A **header key** is the header as it is compared (`HeaderMatcher::words()`: folded, punctuation one space) plus its
  number among equal headers: `time#1`, `notes#1`, `notes#2`. A column without a header has no key.
- Taken from the columns of the table the mapping names (`mapping.region`) - not the sheet's name, not its rows, not
  where the table stands.
- **A sheet without a header row has no signature and is never remembered** ("Column N" says nothing about the next
  file).
- It is not `data_import_sheet.header_signature`: that one is written once, for the first table found when the import
  proposed. A layout is known by the table the player confirmed, so the signature is worked out from the mapping each
  time.

`import_layout.headers` keeps the keys themselves (folded header texts, in order) for the "most of the headers" rule.

## When a layout is applied

`ImportLayoutMatcher::matching()` - among the player's own layouts (50 at most):

| | When | What happens | `mapping.remembered.how` |
|---|---|---|---|
| 1 | the same signature | every column that has a key is what the player said, `certainty: certain`, `reason: remembered`; the sheet is what they said it is (kind, row meaning, "every row is a pair result") | `remembered` |
| 2 | the same headers in another order | the same - columns are matched **by header, never by place**: a moved column still maps | `remembered` |
| 3 | at least **80 %** of the headers in common (`common / max(headers of the sheet, headers of the layout)`) | the columns both have are what the player said; the others are proposed as usual; a missing column is nobody's problem; what the sheet is is worked out again from all columns (`SheetKindGuesser`) | `remembered_partly` |
| - | less | nothing of the layout is applied | - |

Of two that fit: the same signature first, then the larger share, then the one used last.

- **Never two columns on one single-valued field:** what the player set up stays; a column proposed beside it that
  wants the same field is left unknown (`reason: duplicate`, what it would have been on offer).
- A column with a **role** (a known export's row id, puzzle id, picture) is never a layout's to give or take.
- What is applied must pass `ImportMapping::fromArray()` like any stored mapping - otherwise the sheet keeps its own
  proposal.
- The mark is `mapping.remembered = {how, id, name}` (`RememberedLayout`). **Not `mapping.layout`**: that key names a
  known export and the row parser reads it; both can be set at once (below). The key is only written where a layout
  was applied, so every other stored mapping keeps its shape. The name in it is a copy - it still reads well after the
  layout was renamed or forgotten.
- The mark stays while the player answers questions; it goes when they change the mapping itself (then it is their
  set-up of this sheet) or start over.

### Known exports

A known export (`KnownExportLayout`) wins over a player's layout: it is recognised every time and nothing is saved
when the player confirms it as it is. **Unless the player changed that export's mapping** (a column, what the sheet
is): then `import_layout.changes_known_export` is set, and their layout is applied over the export the next time -
the sheet is still that export (`mapping.layout`, its roles, its tags and status words).

## Answers

Remembered - only what the **player** answered (`by: player`), never what the rows settled:

| Remembered | Not remembered |
|---|---|
| how a shape of time is read (`durations`, per `DurationShape`) | **the year** of dates written without one - the sheet's own |
| day or month first (`dateOrder`) | "were the typed dates turned around?" - the file's |
| what `Jan 23` means (`shortDates`) | which collection the sheet's puzzles are filed under - the sheet's |
| "every row is solo / pair / team" (`solvingType`) | where the table stands, sample values, questions, counts |

How they are given (`ImportLayoutMatcher::settle()` / `answers()`): the sheet's times and dates are settled from its
own rows first, exactly as for anybody. Then the layout answers **only a question the sheet really asks**, and only

1. with an answer the question offers (a reading the sheet's rows rule out is not among the choices), and
2. when the rows do not point another way (`question.suggested` is empty or the same answer).

So **a remembered answer never hides a question the new sheet's own values contradict** - it is asked again. What
the rows settle by themselves is the rows'. An answer given this way is stored like the player's own (`by: player`),
so confirming the sheet keeps it in the layout.

Known limit: a player who answered against what their rows point to (500 pieces in 45 hours) is asked again in the
next file with such rows - the cost of never assuming a time sixty times off.

## The lookup sheet

A results sheet that looks brand and pieces up in a puzzle list remembers that list as
`content.lookup = {signature, title}` - **never an id of another import**. In the next workbook, a sheet with that
title and that header row that is a list of puzzles becomes the list again - only where the workbook's own names
(`SheetLookupFactory::link()`) found none.

## One workbook

When the player confirms a sheet, the other sheets of the same import with **the very same signature** that the
player has not confirmed themselves follow at once: the confirmed mapping, the answers (each sheet is settled from its
own rows - the year of "2021" stays 2021's), a new `mapping_version`, read again (`ParseImportSheet`), and so are the
sheets that look puzzles up in them.

- `data_import_sheet.confirmed_by_player` is set by `ConfirmImportMapping` and by "Start over". **A sheet with it is
  never overwritten.** A sheet without it follows every later confirmation of a sheet with its header row (the newest
  word).
- A sheet whose mapping and options would not change gets no new version.
- Bounded: 30 sheets per confirmation (`ImportLayouts::MAX_SHEETS_SHARED`), each settled from at most 5,000 rows,
  inside the confirming transaction.
- A sheet the player marks "not puzzles" or "somebody else's" is not remembered and nothing follows it - two sheets
  with the same headers may well be two people's.
- Sheets mapped before this stage shipped have `confirmed_by_player = false` even where the player confirmed them.

## The screen

- A sheet's page and its card: "We used what you set up last time (*name*) - change anything that is different." (or
  the "most of these headers" text), with **Start over for this sheet** - `POST …/sheets/{sheetId}/start-over`
  (`StartImportSheetOver`): proposed again from its own rows without any layout, a new version, counted as the
  player's word; the lookup sheet and "is the puzzle list" are kept; the saved layout is not changed.
- "Sheets and columns": a link **Your saved layouts (N)** (one more statement on that page) →
  `/{_locale}/import/layouts`: name, columns, last used, used again N× - **Rename** (`RenameImportLayout`) and
  **Forget** (`ForgetImportLayout`, deletes it; sheets already set up keep their mapping). POST + CSRF, PRG; another
  player's layout is a 404.

## The table

`import_layout`: `id`, `player_id` (cascade - a player's layouts go with the player), `signature` (unique with the
player), `name` (the file's and sheet's name when saved; '' = "Unnamed layout"), `headers` (jsonb, the keys),
`content` (jsonb, `ImportLayoutContent`: `shape`, `kind`, `rowMeaning`, `columns` by key → `field` / `readyMade`,
`durations`, `dateOrder`, `shortDates`, `solvingType`, `lookup`), `changes_known_export`, `times_used` (once per later
import it was applied in), `last_used_at` (saved or applied), `created_at`. 50 per player - one more replaces the one
used longest ago. Plus `data_import_sheet.confirmed_by_player`. One generated migration.

A layout holds folded header texts and field names - no cell value, no row, no id of an import. Private: read only
for its own player (`GetImportLayouts`), never in a URL, never logged.

## Messages

| Handled at once | |
|---|---|
| `StartImportSheetOver` | under the import's lock, like `ConfirmImportMapping` |
| `RenameImportLayout`, `ForgetImportLayout` | the player's own layout or `ImportLayoutNotFound` (404) |

## Not built here

Sharing layouts between players · editing a layout by hand (the way to change one is to confirm a sheet of its
shape) · "one screen per layout" of the README (sheets are still set up one by one - they follow each other instead)
· saving on merely leaving the screen: a layout is saved by `ConfirmImportMapping`, and the screen sends none when
Save is pressed with nothing changed (4b-2: "nothing is read again") - an untouched proposal holds nothing the import
would not propose again · remembering what a column's words mean (`value_answers` - the screen can not
edit them yet).
