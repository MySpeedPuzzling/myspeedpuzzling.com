# Entry points and the guide (stage 8)

The spreadsheet import, "My data", the unlisted puzzles and the player's own fields were reachable by address only.
This stage links them - for the people the import was built for: puzzlers who already keep their times or their
collection in a spreadsheet and should not have to type it again.

Two rules held everything here:

- **An entry point is a link.** Nothing is read for one: no page got a statement more. Where a link would be right
  only for some players (e.g. "Unlisted puzzles (3)"), the fact is not on the viewer's profile row, so the link is
  shown to everybody on a page that is the player's own (My data, Imports home) and nowhere else.
- **The add-result form is not touched** - no controller, message, handler or form type. Its page template
  (`templates/puzzle_add.html.twig`) got one static line with one link, after and outside the form
  (`data-testid="add-form-import"`; `ImportEntryPointsTest` checks it is inside no `<form>` and holds no input).

## The guide - `import_guide`

Public, indexable, all 6 locales with their own copy and address, canonical + hreflang from the base layout, in
`sitemap-static.xml`. No screenshots (the platform guide's rule). No JSON-LD of its own - the platform guide has none.
`ImportGuideController` reads nothing (`keep_days` is `DataImportLimits::KEEP_FILE_DAYS`); the page costs a guest
exactly what the platform guide costs.

| Locale | Address |
|---|---|
| en | `/en/import-your-spreadsheet` |
| cs | `/import-tabulky` |
| de | `/de/tabelle-importieren` |
| es | `/es/importar-hoja-de-calculo` |
| fr | `/fr/importer-un-tableur` |
| ja | `/ja/スプレッドシートをインポート` |

Template `templates/data_import/guide.html.twig`, texts `import_guide.*`. Sections and their anchors (pinned by
`ImportGuideControllerTest::ANCHORS` - they are linked from elsewhere):

| Anchor | Section | Keys |
|---|---|---|
| (top) | title, lead, the start button | `title`, `lead`, `free`, `start_guest`, `sign_in` |
| `#what` | What you can bring: results, puzzles you own, wish list; Excel / CSV / TSV / Google Sheets; Puzzle Tracker's CSV and our own results export; ready-made private fields | `what.*` |
| `#how` | Six steps: bring the file → we read it → check the columns → we find your puzzles → review → add | `how.step1..6.*` |
| `#your-file` | Only you see it; the file is kept 30 days, what was read stays with the import; Google sharing can be switched off again; discard, account deletion | `file.*` |
| `#never-twice` | The same file again adds nothing; new rows are added; typed results are recognised | `never_twice.*` |
| `#take-back` | One row, one "Add" or the whole import; shown first what goes; an edited result is kept | `take_back.*` |
| `#imported-result` | Counts like a typed one; its day, or only the month / year; no photo; partners are guests; unusual times are asked about | `result.*` |
| `#unlisted` | A puzzle we do not have is kept on a page only the player sees, and paired later | `unlisted.*` |
| `#not-yet` | `.xls` / `.ods`, photos, lending and sell/swap lists, the event of a result, columns outside the ready-made fields, editing / proposing an unlisted puzzle, watching a Google sheet | `not_yet.*` |
| `#start` | The button again | `start.*` |

The start button: a player → `data_imports`; a guest → `register` (and `login`) with `?return=` = the import's own
path, validated where it is used (`docs/features/return-url.md`).

**Wording:** the guide promises only what is built - `#not-yet` is the docs' "left for later" lists; a feature that
ships moves its line out of there. Czech and French address the reader formally, like the import's screens; the
catalogue is "databáze" in Czech and an unlisted puzzle is each locale's `unlisted_puzzles.title`.

## Every place that links

| Place | File | What | Leads to | Keys |
|---|---|---|---|---|
| User menu | `templates/base.html.twig` | "My data", "Import a spreadsheet" above "Export data" | `my_data`, `data_imports` | `my_data.title`, `data_import.home.import_button` |
| Own profile's ⋯ menu (every page of the own profile: results, collections …) | `templates/player/_header_actions_menu.html.twig` | the same two, above "Export data" | `my_data`, `data_imports` | the same |
| Footer, column Track | `templates/_footer.html.twig` | "Import a spreadsheet" | the guide (a guest can read it) | `data_import.home.import_button` |
| FAQ | `templates/faq.html.twig` (`faq_items`, anchor `#import-spreadsheet`, in the FAQ's JSON-LD like every item) | "I keep my times or my collection in a spreadsheet. Can I bring it in?" | the guide | `faq.question_import_spreadsheet`, `faq.answer_import_spreadsheet` |
| Data export page | `templates/export-puzzler-data.html.twig` | card "Bring data in" above the downloads; "See your data as a table" stays | `data_imports`, the guide | `import_guide.export_card.*`, `import_guide.to_guide` |
| Welcome screen | `templates/registration_welcome.html.twig` | "Already tracking in a spreadsheet? Bring it over" under the guide link | the guide | `import_guide.entry.question`, `.bring` |
| Getting started card (Hub + own profile) | `templates/onboarding/_getting_started_card.html.twig` | the same line in the card's footer | the guide | the same |
| Platform guide, step "Track" | `templates/onboarding/getting_started.html.twig` | the same line under the two buttons | the guide | the same |
| Add-result page | `templates/puzzle_add.html.twig` | "Have many results in a spreadsheet? Import them" after the form | the guide | `import_guide.entry.many_results`, `.import_them` |
| Own statistics without a result | `templates/components/PlayerStatistics.html.twig` | the line under "Log your first puzzle" | the guide | `import_guide.entry.question`, `.bring` |
| Own empty collection | `templates/collections/_empty_state.html.twig` (only with `own_collection`, passed by `collections/detail.html.twig`; the Turbo streams do not pass it) | "Kept your puzzles in a spreadsheet? Bring it over" | the guide | `import_guide.entry.puzzles_question`, `.bring` |
| Own empty wish list | `templates/wishlist/_empty_state.html.twig` (the owner's branch) | the same | the guide | the same |
| Own library overview | `templates/puzzle_library.html.twig` | buttons "My data" (its Puzzles tab) and "Import a spreadsheet" beside "Export"; the line in the "no collections" state | `my_data?tab=puzzles`, `data_imports`, the guide | `my_data.title`, `data_import.home.import_button` |
| My data | `templates/my_data/index.html.twig` | one line: "Unlisted puzzles" · "Import a spreadsheet" | `unlisted_puzzles`, `data_imports` | `unlisted_puzzles.title`, `data_import.home.import_button` |
| Imports home | `templates/data_import/index.html.twig` | "How the import works" beside the intro; the first visit below | the guide | `import_guide.to_guide` |

The checklist's steps are derived exactly as before (`GetGettingStartedProgress` is not touched): a result or a
collection puzzle added by an import is a result or a collection puzzle, so it ticks "Log your first puzzle" /
"Add a puzzle you own" like a typed one.

## Imports home, the first visit

A player without an import no longer reads a bare "No imports yet" (`data-testid="no-imports"` stays): a card
"Three ways to start" - a file, a Google Sheets link, "Coming from Puzzle Tracker?" (export a CSV in the app, its
columns are known) - and the privacy line: only you see your file, nothing is added until you press Add, the file is
kept 30 days. Keys `import_guide.first.*`, `import_guide.file.only_you`, and the existing
`data_import.nothing_until_add` / `data_import.new.kept_note`. The page's two statements are unchanged.

## Deliberately not linked, not built

- **The Hub** beyond the Getting started card, and **no hint banner / announcement modal** for existing players: the
  design asks for neither. Existing players find it in the user menu, the profile's ⋯ menu, the library and the footer.
- **"Unlisted puzzles" on the collections overview / the profile for a player who has any**: the fact is not on the
  profile row and a statement per page view is not worth a link. It is on My data and on Imports home for everybody.
- **An empty state on the profile's results list**: the list has none (its tabs are disabled) and it is a Live
  component under a characterization test; a newcomer's profile shows the Getting started card with the line, and
  the statistics page without a result has it.
- **The membership page**: the import and My data are free for everybody - nothing in `_benefits.html.twig`.
- **"Start from our template"** (README "1. Bring the file", plan stage 8): the template `.xlsx` does not exist yet,
  so nothing offers it.
- **A line in the newsletter** (plan stage 8) - a campaign text, not code.
- **The look after two weeks of real imports** (plan stage 8) - an operations task.

## Tests

- `ImportGuideControllerTest`: 6 locales (anchors, six steps, `index, follow`, canonical, every hreflang, own
  address), the sitemap, the guest's and the player's start button (a `?return=` `ReturnUrl::tryFrom()` accepts),
  no statement of its own, and every `import_guide.*` + FAQ key in every catalogue (present, not empty, same
  placeholders, not the English sentence).
- `ImportEntryPointsTest`: every place above for a player and, where public, a guest; somebody else's profile,
  library and empty lists show none of it; a newcomer with nothing (welcome, Hub card with its five derived steps,
  profile, statistics, collection, wish list); Imports home's first visit.
- Budgets unchanged: `PuzzleAddPageQueryBudgetTest`, `PlayerProfileQueryBudgetTest`.

## For CLAUDE.md

Ready to paste under "Feature Planning & Brainstorming":

- **Data import of spreadsheets + My data**: `docs/features/data-import/` (README = design + decisions, `implementation-plan.md` = stages, **`STATUS.md` = the living record**, a doc per part: `upload.md`, `google-sheets.md`, `reading.md`, `columns.md` + `columns-screen.md`, `layouts.md`, `pairing.md` + `pairing-screens.md`, `adding.md` + `adding-screens.md`, `writer.md`, `group-answers.md`, `date-precision.md`, `unlisted-puzzles.md`, `entry-points.md`) + `docs/features/my-data.md` + `docs/features/custom-fields.md` — free for everybody: a player brings an `.xlsx` / CSV / TSV file or a Google Sheets link (`data_imports` `/{_locale}/import`, owner-only, `noindex`; public guide `import_guide` in all 6 locales, anchors pinned by `ImportGuideControllerTest`), the worker reads it (`imports` transport; safety net `myspeedpuzzling:read-waiting-imports` every 5 min), the columns are recognised (known exports = `KnownExportLayout`: Puzzle Tracker's CSV, our own results export; a confirmed mapping is remembered as a layout), every puzzle is looked up in the catalogue (sure matches by themselves, the others chosen by the player from pictures), and after a review **"Add"** writes results, collection puzzles and wish-list puzzles in batches (`myspeedpuzzling:continue-import-batches` every 5 min). **The add-result form's controllers, messages, handlers and form types are never touched by any of it**: an imported result is written by `ImportedResultWriter` next to them (`created_via = import`, partners always guests, no photo, a day or only a month / year - `date-precision.md`), and `ImportWritePathParityTest` saves the same input through the form and through the import and compares what was stored - a change to either path must keep it green. **Never twice** (`ImportNeverTwice`, `ImportNeverTwiceTest`): a row's `source_key`, state and links to what it made are kept for ever, so the same file again adds nothing and new rows are added; the player's typed results are recognised too. **Everything can be taken back**: every "Add" is a batch with an append-only log, Undo per row / batch / import keeps a result the player edited since. Unusual times are asked about before "Add" (one answer for a group - `group-answers.md`), never marked quietly. **The pause switch** (`data_import_settings`, flipped on `/admin/imports`, asked before every row) stops adding without losing anything; Undo is never paused. The file is kept `DataImportLimits::KEEP_FILE_DAYS` (30) days (`myspeedpuzzling:prune-data-imports` daily), ≤ 2,000 results made public per player a day. **Unlisted puzzles** (`unlisted-puzzles.md`: a puzzle of the file that is not in the catalogue, with its results and ownership, on the owner-only `unlisted_puzzles`; `myspeedpuzzling:pair-unlisted-puzzles` daily only suggests, the player pairs): **the unlisted tables are never part of a shared read** - no public page, list, search, statistic or API - guarded by `UnlistedDataCoverageTest` (**add every new reader there**). **My data** (`my_data`, `/{_locale}/my-data`, owner-only): the player's results and puzzles as a table with their own fields (`custom-fields.md`) as columns - reads through the viewer-bound readers only, never a shared one. Where all of it is linked from, and what is deliberately not: `entry-points.md` - **an entry point is a link, never a statement more on a page**; the add form's page carries one static line outside the form
