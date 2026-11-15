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

"How the import works". Public, indexable, all 6 locales with their own copy and address, canonical + hreflang from
the base layout, in `sitemap-static.xml`; `noindex, follow` with any query string (the way back from the flow).
Design of record: [guided-flow.md](guided-flow.md) §13. `ImportGuideController` reads nothing: the sample is
`Value\ImportGuideSample` (fixed data), `keep_days` / `daily_limit` are `DataImportLimits`' constants - the page
costs a guest exactly what the platform guide costs.

| Locale | Address |
|---|---|
| en | `/en/import-your-spreadsheet` |
| cs | `/import-tabulky` |
| de | `/de/tabelle-importieren` |
| es | `/es/importar-hoja-de-calculo` |
| fr | `/fr/importer-un-tableur` |
| ja | `/ja/スプレッドシートをインポート` |

**One sample spreadsheet, followed through the five steps.** Six rows of invented puzzles and one invented person
(no catalogue name, no player), the same in every language (puzzle names are texts): a clean row, a time written
"1h 05", a date that knows only its month, a pair with Anna, a puzzle without a brand, a hand-cut puzzle we do not
have.

**The examples are real HTML, not pictures** - `templates/data_import/guide/` (`index`, `_figure_open`, `_sample`,
`_puzzle`). Each is a `<figure class="guide-example" inert>` labelled "Example": no form, no input, no link, no id,
no Turbo frame. They are dedicated partials in the look of the screens (the flow's own partials take the import's
result objects and carry forms and ids), but **their words are the screens' own keys** - step names, "Matched",
"Looks like a match", "Not in our database", "Keep it just for me", "Don't import", "Add N to my profile", "Undo",
"Who is Anna?" and every explanation (`data_import.explain.*` through `_explain.html.twig`) - so a renamed button
renames itself in the guide, in six languages. Only the narration and the questions are the guide's own texts
(`import_guide.*`). No `|raw` anywhere (the flow's guard covers the directory).

| Anchor | Section | The example | Texts |
|---|---|---|---|
| (top) | "How the import works", the lead, the start button, "Free for everyone." | the sample as a small spreadsheet (grey header, row numbers, scrolls inside its card) | `to_guide`, `lead`, `sample.*` |
| `#step-file` | 1 Your file | the step dots, the file's name, "Rows read: 6" + what happens to the file | `steps.file`, `data_import.explain.file` |
| `#step-check` | 2 Check | the question "How should we read "3:26"?" with both readings, the sheet's one-sentence summary, the month-only line, the button "Looks right - continue"; a question is asked once per file | `steps.check.*`, `data_import.flow.looks_right` |
| `#step-people` | 3 People | "Who is Anna?" with the screen's two answers + guest / player explanations | `steps.people`, `data_import.people.*`, `data_import.explain.guest`, `.player_partner` |
| `#step-puzzles` | 4 Puzzles | Matched (4) with a card, Looks like a match (1) with its tick, Not in our database (1) with "Keep it just for me" / "Don't import it" + the unlisted explanation | `steps.puzzles.*`, `data_import.puzzles.*`, `data_import.explain.unlisted` + `_more` |
| `#step-add` | 5 Check and add | "5 results ready to add · 1 puzzle kept just for you", three rows (one not imported), the button "Add 5 to my profile" (a `<span>`, `aria-disabled`) + "Don't import" and the daily limit | `steps.add.*`, `data_import.add.button`, `data_import.explain.dont_import`, `.daily_limit` |
| `#on-your-profile` | What ends up on your profile | the six rows with what each became + what an imported result is | `profile.*`, `data_import.explain.add` |
| `#take-back` | Undo - also several rows at once ("Select several" on "What this import added") | an added row with its Undo button | `data_import.undo.*`, `data_import.explain.undo` + `_more`, `several` |
| `#questions` | Questions people ask - eleven, `<details open>` (no script), also `FAQPage` structured data through `json_ld` | - | `questions.q1..11`, `.a1..11` |
| `#what`, `#your-file`, `#never-twice`, `#imported-result`, `#unlisted`, `#not-yet` | what the steps did not say: formats and limits, known exports, the file (discard / finish), never twice, an imported result, unlisted puzzles (the screens' explanation), "Not yet" | - | `what.*`, `file.*`, `never_twice.*`, `result.*`, `not_yet.*` |
| `#start` | the button again | - | `start.title` |

All anchors are pinned by `ImportGuideControllerTest::ANCHORS`, every example's root by `::EXAMPLES`.

**From the flow:** `_flow_footer.html.twig` ends every screen of an import with "How this step works" → the guide's
section of the screen's step (`#step-check`, `#step-people`, `#step-puzzles`, `#step-add`, `#take-back` for the undo
screens; "Where you are" → the top) with `?return=` = the screen. The step is read from the screen's route (the
footer is included without the page's context, so no screen had to change). The guide then starts with "Back to
your import" - only for a return that is an import's address.

**Not built of §13:** the six illustrations of the invented puzzles (a neutral puzzle icon stands in their place),
the `og:image` screenshot script, the profile's own result partial in `#on-your-profile` (it needs a result object;
the outcome is a plain list), "over 40,000" in step 4 (no number is claimed).

The start button: a player → `data_imports`; a guest → `register` (and `login`) with `?return=` = the import's own
path, validated where it is used (`docs/features/return-url.md`). **Sign-in lands on the import. Sign-up lands on
the welcome screen, as always** - with the import as its first, highlighted choice: the registration form posts the
return as a hidden field (and keeps it on its action, so a refused form still knows it), `RegisterController` passes
a `ReturnUrl::tryFrom()`-valid one on as `/welcome?return=…`, and the welcome template highlights the import only
for a return that is exactly the import's address (`safe_return_url()` + a pattern); any other return changes
nothing there. Nothing else of the registration flow changed. **Social sign-up does not carry it**: the parked
sign-up (`SocialRegisterConfirmController`) holds no return path, so it lands on the plain welcome screen - where
the import is one of the four choices anyway.

**Wording:** the vocabulary of guided-flow.md §8 (matching, "Keep it just for me", "Don't import", "Undo", "Check
and add" - never pairing, catalogue, take back); the guide promises only what is built - `#not-yet` is the docs'
"left for later" lists; a feature that ships moves its line out of there. Czech and French address the reader
formally, like the import's screens.

## Every place that links

| Place | File | What | Leads to | Keys |
|---|---|---|---|---|
| User menu | `templates/base.html.twig` | "My data", "Import a spreadsheet" above "Export data" | `my_data`, `data_imports` | `my_data.title`, `data_import.home.import_button` |
| Own profile's ⋯ menu (every page of the own profile: results, collections …) | `templates/player/_header_actions_menu.html.twig` | the same two, above "Export data" | `my_data`, `data_imports` | the same |
| Footer, column Track | `templates/_footer.html.twig` | "Import a spreadsheet" | the guide (a guest can read it) | `data_import.home.import_button` |
| FAQ | `templates/faq.html.twig` (`faq_items`, anchor `#import-spreadsheet`, in the FAQ's JSON-LD like every item) | "I keep my times or my collection in a spreadsheet. Can I bring it in?" | the guide | `faq.question_import_spreadsheet`, `faq.answer_import_spreadsheet` |
| Data export page | `templates/export-puzzler-data.html.twig` | card "Bring data in" above the downloads; "See your data as a table" stays | `data_imports`, the guide | `import_guide.export_card.*`, `import_guide.to_guide` |
| Welcome screen | `templates/registration_welcome.html.twig` | **one of the big choices**, equal to the others: "Import your data from elsewhere" + "A spreadsheet or another app's export - results, collection, wish list" (the owner's call); last of four - first and the highlighted one after a sign-up from the guide | `data_imports` | `import_guide.newcomer.title`, `.hint` |
| Getting started card (Hub + own profile) | `templates/onboarding/_getting_started_card.html.twig` | one quiet line in the card's footer: "Already tracking in a spreadsheet? Bring it over"; its five derived steps are untouched | the guide | `import_guide.entry.question`, `.bring` |
| Platform guide | `templates/onboarding/getting_started.html.twig` | an **item of its own** after the six steps, anchor `#import` (pinned by `GettingStartedControllerTest`), the same title as on the welcome screen, three sentences, where to find it; no number and no tick - it is the other way to begin, not a seventh step | the guide (+ `data_imports` signed in) | `import_guide.newcomer.*`, `import_guide.to_guide` |
| Add-result page | `templates/puzzle_add.html.twig` | "Have many results in a spreadsheet? Import them" after the form | the guide | `import_guide.entry.many_results`, `.import_them` |
| Own statistics without a result | `templates/components/PlayerStatistics.html.twig` | the line under "Log your first puzzle" | the guide | `import_guide.entry.question`, `.bring` |
| Own empty collection | `templates/collections/_empty_state.html.twig` (only with `own_collection`, passed by `collections/detail.html.twig`; the Turbo streams do not pass it) | "Kept your puzzles in a spreadsheet? Bring it over" | the guide | `import_guide.entry.puzzles_question`, `.bring` |
| Own empty wish list | `templates/wishlist/_empty_state.html.twig` (the owner's branch) | the same | the guide | the same |
| Own library overview | `templates/puzzle_library.html.twig` | buttons "My data" (its Puzzles tab) and "Import a spreadsheet" beside "Export"; the line in the "no collections" state | `my_data?tab=puzzles`, `data_imports`, the guide | `my_data.title`, `data_import.home.import_button` |
| My data | `templates/my_data/index.html.twig` | one line: "Unlisted puzzles" · "Import a spreadsheet", with the unlisted explanation folded under it | `unlisted_puzzles`, `data_imports` | `unlisted_puzzles.title`, `data_import.home.import_button` |
| Imports home | `templates/data_import/index.html.twig` | "How the import works" beside the intro; the first visit below | the guide | `import_guide.to_guide` |
| Every screen of an import | `templates/data_import/_flow_footer.html.twig` | "How this step works" | the guide's section of the step, with the way back | `data_import.flow.how_step_works` |

The checklist's steps are derived exactly as before (`GetGettingStartedProgress` is not touched): a result or a
collection puzzle added by an import is a result or a collection puzzle, so it ticks "Log your first puzzle" /
"Add a puzzle you own" like a typed one.

## Imports home, the first visit

A player without an import reads guided-flow.md §5.1's empty state (`templates/data_import/_first_visit.html.twig`,
a small include so the home's own template stays the flow's; `data-testid="no-imports"` stays): "No imports yet.
Have your results or your collection in a spreadsheet? Bring the file - we read it, you check it, and it is on your
profile.", the three ways to bring it - a file, a Google Sheets link, "Coming from Puzzle Tracker?" (export a CSV
in the app, its columns are known) - and the file's explanation with §12's exact text plus "Nothing is added until
you … pressed Add". No button of its own: "Import a spreadsheet" above is the page's one primary. Keys
`import_guide.first.*`, `data_import.after.home.empty` (the home's own sentence), `data_import.explain.file`,
`data_import.nothing_until_add`. The
page's statements are unchanged.

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

- `ImportGuideControllerTest`: 6 locales (every anchor, the six sample rows, every example's root - `inert`,
  captioned, nothing interactive in it - the eleven questions open and as `FAQPage`, `index, follow`, canonical,
  every hreflang, own address), the sitemap, the flow's words and none of the engine's, the way back only for an
  import's address (and `noindex` with a query), the guest's and the player's start button, no statement of its
  own, and every `import_guide.*` + FAQ key in every catalogue (present, not empty, same placeholders, not the
  English sentence).
- `ImportFlowScreensTest::testEveryScreensFooterLeadsToTheGuidesSectionOfItsStep()`; its guards (one primary a
  screen, no engine word in `data_import.*`, six locales, no `|raw` under `templates/data_import/`) hold for what
  this stage added.
- `ImportEntryPointsTest`: every place above for a player and, where public, a guest; somebody else's profile,
  library and empty lists show none of it; a newcomer with nothing (the welcome screen's four choices, Hub card with
  its five derived steps, profile, statistics, collection, wish list); a sign-up from the guide (the hidden return,
  `/welcome?return=/en/import`, the import first and the one highlighted choice); any other return is ignored by the
  welcome screen; Imports home's first visit; the add page still holds its form and submit button.
- Budgets unchanged: `PuzzleAddPageQueryBudgetTest`, `PlayerProfileQueryBudgetTest`.

## For CLAUDE.md

Ready to paste under "Feature Planning & Brainstorming":

- **Data import of spreadsheets + My data**: `docs/features/data-import/` (README = design + decisions, `implementation-plan.md` = stages, **`STATUS.md` = the living record**, a doc per part: `upload.md`, `google-sheets.md`, `reading.md`, `columns.md` + `columns-screen.md`, `layouts.md`, `pairing.md` + `pairing-screens.md`, `adding.md` + `adding-screens.md`, `writer.md`, `group-answers.md`, `date-precision.md`, `unlisted-puzzles.md`, `entry-points.md`) + `docs/features/my-data.md` + `docs/features/custom-fields.md` — free for everybody: a player brings an `.xlsx` / CSV / TSV file or a Google Sheets link (`data_imports` `/{_locale}/import`, owner-only, `noindex`; public guide `import_guide` in all 6 locales, anchors pinned by `ImportGuideControllerTest`), the worker reads it (`imports` transport; safety net `myspeedpuzzling:read-waiting-imports` every 5 min), the columns are recognised (known exports = `KnownExportLayout`: Puzzle Tracker's CSV, our own results export; a confirmed mapping is remembered as a layout), every puzzle is looked up in the catalogue (sure matches by themselves, the others chosen by the player from pictures), and after a review **"Add"** writes results, collection puzzles and wish-list puzzles in batches (`myspeedpuzzling:continue-import-batches` every 5 min). **The add-result form's controllers, messages, handlers and form types are never touched by any of it**: an imported result is written by `ImportedResultWriter` next to them (`created_via = import`, partners always guests, no photo, a day or only a month / year - `date-precision.md`), and `ImportWritePathParityTest` saves the same input through the form and through the import and compares what was stored - a change to either path must keep it green. **Never twice** (`ImportNeverTwice`, `ImportNeverTwiceTest`): a row's `source_key`, state and links to what it made are kept for ever, so the same file again adds nothing and new rows are added; the player's typed results are recognised too. **Everything can be taken back**: every "Add" is a batch with an append-only log, Undo per row / batch / import keeps a result the player edited since. Unusual times are asked about before "Add" (one answer for a group - `group-answers.md`), never marked quietly. **The pause switch** (`data_import_settings`, flipped on `/admin/imports`, asked before every row) stops adding without losing anything; Undo is never paused. The file is kept `DataImportLimits::KEEP_FILE_DAYS` (30) days (`myspeedpuzzling:prune-data-imports` daily), ≤ 2,000 results made public per player a day. **Unlisted puzzles** (`unlisted-puzzles.md`: a puzzle of the file that is not in the catalogue, with its results and ownership, on the owner-only `unlisted_puzzles`; `myspeedpuzzling:pair-unlisted-puzzles` daily only suggests, the player pairs): **the unlisted tables are never part of a shared read** - no public page, list, search, statistic or API - guarded by `UnlistedDataCoverageTest` (**add every new reader there**). **My data** (`my_data`, `/{_locale}/my-data`, owner-only): the player's results and puzzles as a table with their own fields (`custom-fields.md`) as columns - reads through the viewer-bound readers only, never a shared one. Where all of it is linked from, and what is deliberately not: `entry-points.md` - **an entry point is a link, never a statement more on a page**; the add form's page carries one static line outside the form
