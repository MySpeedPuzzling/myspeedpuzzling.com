# Codes on the add form: "What is it?" help, "Brand code", the check digit

Status: shipped 2026-10-05 in four commits (rename, help modal, rebuild `--dry-run`/`--alert-on-drift`, aliases);
the production rebuild runbook (4a) is the last step. Rehearsed on the dev copy of production: 8,734 of 41,282 code
keys gain an alias (only added lines, no name key changed), the run takes ~80 s, a second dry run reports 0. Part of the puzzle-names work (codes = phase 5,
see `README.md`).

## Why

Whoever adds a puzzle sees two code inputs - "EAN code" and "Manufacturer's code" - with no hint what either is or
where it is on the box. And Ravensburger prints one more digit after its article number (`12 002 028 8`): that digit
is the **EAN's check digit**, not part of the code. Players type it, and then:

- the stored code drifts (`120020288` instead of `12002028` - 115 such codes were trimmed on 2026-09-19, new ones come
  in through the add form), and
- a search for the code **as printed** finds nothing: `120020288` is neither a whole code (`c:12002028`) nor a part of
  one, and not a part of the EAN `4005555020288` either - not in the server search, not in the add form's picker.

## Verified facts

- Ravensburger's article number is 8 digits (`12000199`), its own URLs and retailers list it so
  (`…/at-harry-potter-1500p-12001222`, Rarewaves `12000199` ↔ `4005555001997`).
- New-format EAN = `4005555` + last 5 digits of the article + check digit: `12002028` ↔ `4005555 02028 8`.
  Old format: 5-digit code, EAN `4005556` + code + check digit: `16907` ↔ `4005556169078`. Other brands build their
  EAN the same way (Trefl `37441` ↔ `5900511374414`).
- Old-format code + check digit (`169078`) is already found today - as a *part* of the EAN (rank 1). New format is not.
  In the add form's picker (Tom Select, filters in the browser) `120020288` finds nothing, `12 002 028 8` does (each
  word is matched separately).
- No single word for the code in the puzzle world: Ravensburger "Art.-Nr." / article number, shops "Item #" / "MPN" /
  "SKU", tracker apps "box number". We pick **Brand code**: it pairs with the "Brand" field right above it, it is our
  word everywhere else already (admin pages, `forms.puzzle_help` "search by name, brand code, pieces", the "Suggest a
  change" form, the docs), every locale has it translated already, and it is short (the label line also carries the
  "What is it?" link on a 375 px phone).

## Decisions

| # | Decision | Status |
|---|---|---|
| D1 | Label "Manufacturer's code" → **"Brand code"** in all 6 locales | Jan 2026-10-05 |
| D2 | Czech: the add form's brand field and its texts say "Značka" instead of "Výrobce" (the rest of the Czech UI says Značka; "Výrobce" + "Kód značky" would read oddly) | Jan 2026-10-05 |
| D3 | "What is it?" on **both** labels (EAN and Brand code), one modal, one image | Jan 2026-10-05 |
| D4 | One image per locale (the tab text is translated), `public/img/puzzle-codes/box-side.{locale}.webp`, 800×495, ~32 KB | yes |
| D5 | The image boxes the code **without** the check digit (nobody can say we got it wrong) | Jan 2026-10-05 |
| D6 | Check digit in search: **one PHP rule, stored as extra `c:` lines** (see 4.) instead of an extra SQL `OR` at query time - application side only, no trigger, backfilled by the existing reconcile command (see 4a.) | Jan 2026-10-05 |
| D7 | Stored codes are not trimmed on save - the help asks for the code without the digit, search tolerates both | yes |

## 1. Rename the label (6 locales)

| key | en | cs | de | es | fr | ja |
|---|---|---|---|---|---|---|
| `forms.puzzle_identification_number` (add form) | Brand code | Kód značky | Markencode | Código de marca | Code de marque | ブランドコード |
| `forms.identification_number` (add puzzle to a round) | same | same | same | same | same | same |
| `puzzle_report.form.identification_number` (Suggest a change) - drops " / SKU" | same | same | same | same | same | same |

French "Code de marque" (not "Code marque") - matches `forms.puzzle_help` "code de marque".
`puzzle_report.form.identification_number_help` stays ("The manufacturer's product code (e.g., 19432 …)" - describes,
does not name). Admin templates already say "Brand code" (hardcoded English) - untouched.

**Czech (D2)**, all in `messages.cs.yml`, changed together so the brand field is not half converted:

| key | today | new |
|---|---|---|
| `forms.brand` | Výrobce | Značka |
| `forms.brand_help` | …výrobce… | …značka… (reworded) |
| `forms.puzzle_choose_brand_placeholder` | …výrobce… | …značku… |
| `forms.puzzle_help` | …kódu výrobce… | …kódu značky… |

`forms.brand` is also the label in `RoundPuzzleFormType`, `EditPuzzleSolvingTimeFormType` and the brand filter's empty
option in `PuzzleSearch` - Značka fits all of them. `filters.manufacturer_placeholder` "- Výrobce -" (other filters)
is left alone.

## 2. The images

- `public/img/puzzle-codes/box-side.{en,cs,de,es,fr,ja}.webp` - the box side of `12002028` (Ravensburger, 2026), crop
  of the label + address, 800×495, WebP q72, paper texture smoothed behind the print (halves the bytes, text and bars
  untouched), no metadata (the iPhone photo carried GPS). Rectangles + tabs in `$primary` `#fe696a`, Rubik SemiBold
  (Noto Sans JP Bold for ja). EAN box = bars + digits, tab "EAN" below; Brand code box = `12 002 028` without the check
  digit, tab above with the locale's label from the table in 1.
- Generator kept in the repo for a future locale or a better photo: `docs/features/puzzle-names/codes-help/` =
  `make_images.py` (Pillow; downloads Rubik / Noto Sans JP from google/fonts) + `box-side-source.jpg` (the box side
  only, ~2000 px, EXIF stripped).
- New files, so no service-worker `CACHE_VERSION` bump. **A changed image gets a new file name** (`box-side-2.…`) -
  `/img/*` is not content-hashed and the worker serves images stale-while-revalidate.

## 3. "What is it?" link + modal (add form only)

`templates/_solving_time_form.html.twig`, the new-puzzle section (`{% if solving_time_form.puzzlePiecesCount is
defined %}`, ~line 156 - never on edit-time, whose form has no `puzzlePiecesCount`):

- Both labels move into the existing **`.label-row`** (`assets/styles/_optional-rows.scss`: flex, `space-between`,
  wraps) next to a `btn btn-link label-row__link` - the same quiet link as "+ name in another language". The link sits
  at the right end of the label line, right above the input's top-right corner, and on a narrow screen it wraps under
  the label instead of overlapping it (absolute positioning would overlap "Código de marca" + link at 375 px). No JS
  depends on the label: `eanInput` / `eanErrors` targets sit on the input and the errors div.

  ```twig
  <div class="label-row">
      <label class="form-label" for="{{ first_ean.vars.id }}">{{ 'forms.ean'|trans }}</label>
      <button type="button" class="btn btn-link label-row__link"
              data-bs-toggle="modal" data-bs-target="#puzzleCodesHelpModal">
          {{- 'puzzle_codes.help.link'|trans -}}<span class="visually-hidden">: {{ 'forms.ean'|trans }}</span>
      </button>
  </div>
  ```

  `type="button"`: never submits. No JS of our own, no request - Bootstrap's data API, like `#membersExclusiveModal`
  in the same template.
- New partial `templates/puzzle/_codes_help_modal.html.twig`, included **inside the same `puzzlePiecesCount is defined`
  branch, right after the `newPuzzle` div** (~line 207) - not next to the barcode-scanner modal, which renders only
  when `active_puzzle is null`: with `puzzle_change` the new-puzzle block can show while a puzzle is active, and the
  link would open nothing. It holds no input, so being inside the form is harmless.
  - `modal fade`, `modal-dialog modal-dialog-centered modal-lg` (800 px = the image 1:1 on desktop, full width on
    phones), `aria-labelledby` on the title, close button `forms.close`.
  - Title: "Where to find the codes".
  - `<img src="{{ asset('img/puzzle-codes/box-side.' ~ locale ~ '.webp') }}" width="800" height="495"
    loading="lazy" class="img-fluid rounded" alt="…">` - `locale` = `app.request.locale` when it is one of the six,
    else `en`. **Lazy inside a hidden modal = 0 bytes until opened** (a `display: none` image never intersects the
    viewport).
  - Two short blocks:
    - **EAN** - "The number under the barcode, usually 13 digits. Quickest: the scan button next to the field."
    - **Brand code** - "The brand's own number for this puzzle, usually printed near the barcode. Some brands
      (Ravensburger, for example) print one more digit after it - that digit belongs to the barcode, so leave it
      out. Searching finds the puzzle either way."
    - One line: "Both are optional, but they let others find the puzzle by scanning or searching."
- Translations: `puzzle_codes.help.{link, title, ean, brand_code, optional, image_alt}` in all 6 locales - under the
  existing `puzzle_codes:` namespace (the code inputs' strings), since the help is meant to reach
  `puzzle/_code_inputs.html.twig` later.
- Not in this step: the same help on "add puzzle to a round" and "Suggest a change" (both render
  `puzzle/_code_inputs.html.twig`) → `docs/TODO.md`.

## 4. Search: the code with or without the check digit

### One rule, in PHP

`Value\BrandCodeCheckDigit::aliases(null|string $storedEans, null|string $storedBrandCodes): list<string>` - works on
the stored strings like `PuzzleSearchKeys::codes()` does, returns the aliases already in `c:` form (fold, then letters
and digits only - `PuzzleSearchKeys.php:43`; the normalisation moves to one shared private/static helper so the line
and the alias cannot disagree).

Inputs:

- **EANs = real barcodes only**: `EanList::fromStored($storedEans)->gtins()['gtin13']` - 13 digits (UPC-A padded),
  check digit verified. Not `searchTokens()['numbers']`: that also holds catalogue numbers typed into the EAN field
  (`6000-5468` → `60005468`), which have no check digit.
- **Codes**: `BrandCodeList::tokens($storedBrandCodes)`, normalised as above, taken only when **digits only and 5-10
  digits long**. 11+ digits is a barcode typed into the code field (the README counts 167 brand codes equal to some
  stored EAN; `4005556147090` in both fields would otherwise alias to `400555614709`).

For every such code `c` and EAN `e` (`body` = `e` minus its last digit, `check` = its last digit):

- **stored without the digit** - `body` ends with the last 5 digits of `c` → alias `c . check`
  (`12002028` + `4005555020288` → `120020288`; `16907` + `4005556169078` → `169078`);
- **stored with the digit** - `c` has 6+ digits, its last digit is `check`, and `body` ends with the last 5 digits of
  `c` minus its last digit → alias `c` minus its last digit (`120020288` → `12002028`). The 6+ keeps every alias at
  5+ digits: a 4-digit `c:` line would make short searches like "1000" hit at rank 6.

Aliases equal to a code the puzzle already has are dropped (the key de-duplicates lines anyway). Only fires when the
digit really is that puzzle's check digit - no near-miss codes. Generic: any brand building its EAN from its code gets
it (Trefl does too), nothing names Ravensburger.

### Used twice

1. **`PuzzleSearchKeys::codes()`** writes each alias as one more `c:` line. The exact whole-code match (rank 6) then
   works in all four cases - stored with/without × typed with/without, also typed with spaces (`12 002 028 8` folds to
   `120020288`) - through the existing `codeExact` pattern; with 5+ characters `codePart` matches it too. No SQL
   change in `PuzzleSearchQuery` / `PuzzleTextSearch`, no new index path. Barcode lookups are untouched:
   `barcodeCondition` needs a whole `\ne:…\n` line, so `FindPuzzlesByExactEan`, `GetMultiscanCandidates` and the
   approvals queue (`e:` lines only) stay exact.
   Everything reading `search_codes` benefits after the rebuild: `SearchPuzzle` (catalogue, global search, API v1
   `/puzzles`), `GetMarketplaceListings`, and the client-side list filters (`data-search="{{ item.searchNames ~
   item.searchCodes }}"` in `_puzzle_library_item.html.twig:96` and `sell-swap/_item.html.twig:21`, matched by
   `searchKeyMatcher` in `assets/search_fold.js`). Nothing outputs `search_codes`.
2. **`PuzzleChoicesBuilder`** appends the aliases to the hidden `codes` search field (`PuzzleChoicesBuilder.php:102`),
   never to the shown `text`. The builder serves four pickers - add form, edit-time, add-to-round, report duplicate -
   all of them gain it.

**Rebuild:** `SearchText::VERSION` is informational (printed by the command, never stored or compared). Bump it 2 → 3
anyway as the marker, and reword its docblock and README "Search" to "bumped whenever the fold **or the key format**
changes". Every write path goes through `PuzzleSearchKeys::codes()`; the backfill and its safety net are in 4a.
The picker side needs no backfill - `PuzzleChoicesBuilder` computes the aliases on every read.

### 4a. Rollout and safety of the stored aliases

The keys are **derived data, maintained by the application** - no trigger, no SQL function, no SQL folding (the
puzzle-names rule): `Puzzle` writes both keys through `PuzzleSearchKeys` on every name/code change, and the source
columns (`ean`, `identification_number`) are never touched by this work. So nothing here is irreversible: revert the
code and run the rebuild again, and the aliases are gone - no undo CSV, no migration, no schema change.

**The backfill is the existing reconcile command**, which already has the properties a backfill needs:

- keyset batches of 500 by id, each its own transaction (`doctrine_transaction`), `clear()` between batches - flat
  memory, the same cost at the end of the table as at its start;
- the batch rows are locked `FOR UPDATE` (`findByIdsForUpdate`) - a moderator's edit or a merge committing meanwhile
  is never overwritten by a key built from stale names;
- writes only the rows whose key changed - idempotent, safe to interrupt, cheap to run again (~0.15 s per 500 on a
  production copy, ~20-30 s for the catalogue);
- `refreshSearchKeys()` sets the two key columns and nothing else - no timestamp, no domain event, no notification,
  no cache or sitemap side effect.

**Added for this rollout:**

1. **`--dry-run`** on `myspeedpuzzling:rebuild-puzzle-search-keys`: builds every key in PHP, compares, writes nothing;
   prints how many keys would change and, with `--report=<csv>`, each puzzle id + old and new `search_codes` - the
   same shape as `canonicalize-puzzle-codes --report`. Reads go through a query service, no handler, no flush.
2. **Drift alert:** the command logs the number of changed keys; with `--alert-on-drift` (the cron variant) a run
   that changed any key logs a **warning** (→ Sentry) naming the count and the first ids. Outside a release that
   changes the key format, a changed key means some write bypassed the entity - a bug worth seeing. After such a
   release, the one warning is the reminder that the rebuild was not run.
3. **Self-healing cron** on lily (I add the row to `~/www/lily.srv`): `rebuild-puzzle-search-keys --alert-on-drift`
   daily at night. It heals the blue-green window (the old container writes keys without aliases until it is gone),
   puzzles inserted by SQL (Ravensburger Puzzle Month runbook) and any future rule change - without anyone
   remembering to run it.
4. **Parity guard test:** for every fixture puzzle, every alias the picker gets (`PuzzleChoicesBuilder` `codes`) is a
   `c:` line of its stored key - the read side and the write side cannot drift apart.

**Release runbook** (in this doc and the commit message):

1. Deploy. On its own the deploy only adds aliases to puzzles saved from then on - nothing is rewritten in bulk.
2. On lily: `rebuild-puzzle-search-keys --dry-run --report=/root/aliases.csv` → the count should match the estimate
   (a read-only SQL count of puzzles with a 5-10-digit numeric brand code and a valid EAN whose body ends with the
   code's last 5 digits); spot-check ~20 rows of the CSV. Only then `rebuild-puzzle-search-keys` (writes). Once the
   old container is gone, run it again (or let the nightly cron do it - its warning in this window is expected).
3. Verify on production: search `120020288`, `12 002 028 8` and `12002028` - the same puzzle first; scan its EAN -
   exact as before; the add form's picker finds it by `120020288`.
4. Next morning: the cron run reports 0 changed keys.

**Performance:** no query changes; each affected key grows by one short line. On the dev copy the code index is
1.8 MB after `REINDEX` with the aliases, an exact alias lookup 0.2 ms. A GIN index does not give back the pages of the
8.7k updated rows on `VACUUM` - harmless at this size; `REINDEX INDEX CONCURRENTLY custom_puzzle_search_codes_trgm`
reclaims them if ever wanted.

### Why not the extra `OR` at query time (the first idea)

It works for the server search, but: the picker needs the same rule in PHP anyway (two implementations of one rule),
the client-side list filters would not get it at all, it covers only three of the four cases at rank 6 (stored
`120020288`, typed `12002028` stays a rank-1 part match), and it adds a two-`LIKE` branch to every numeric search. The
stored alias costs one rebuild run.

### Known gaps

- A puzzle without a valid EAN gets no alias (nothing to check the digit against): `120020288` does not find a stored
  `12002028` there. An unchecked fallback would also hit near-miss codes. → `docs/TODO.md`.
- Global search highlights the shown codes (`components/GlobalSearch.html.twig:106`); when an alias matched, nothing
  is highlighted (the shown code differs by a digit). Acceptable.

## 5. Tests

New:

- `tests/Value/BrandCodeCheckDigitTest.php` - new format both directions, old format, several EANs/codes, a code with
  letters, a digit that is not the check digit, a 4-digit code, a 5-digit code stored with its digit (6 → no 4-digit
  alias), an 11+-digit code / a code equal to the EAN, a catalogue number in the EAN field (`6000-5468`), a UPC-A EAN
  (padded) - each with or without an alias as expected.
- `tests/Query/SearchPuzzleCodesTest::testBrandCodeIsFoundWithOrWithoutTheCheckDigit` via `ChangesPuzzleRecords`
  (`changePuzzleEan` / `changePuzzleBrandCode`: `4005555020288` + `12002028`): `12002028`, `120020288`,
  `12 002 028 8` find it first; stored `120020288` found first by `12002028`; `120020281` finds nothing.
- `tests/Services/PuzzleChoicesBuilderTest.php` (none exists) - `codes` carries the alias, `text` does not.
- Rebuild command: `--dry-run` writes nothing and counts what would change; `--alert-on-drift` logs a warning only when
  a key changed (test the handler/query services, not the command - project rule).
- Parity guard: every picker alias is a `c:` line of the stored key, for every fixture puzzle.
- `tests/Controller/PuzzleAddControllerTest` - two "What is it?" buttons, the modal, `img/puzzle-codes/box-side.cs.webp`
  on `/cs/…`, the "Brand code" label.

Existing exact-key assertions that gain alias lines - update them:

- `tests/PuzzleFixtureSearchKeysTest.php:51-53` - PUZZLE_1000_05 gets `c:174812` (`17481` + `4005556174812`).
- `tests/Entity/PuzzleTest.php:46` and `:232` - `14709` + `4005556147090` → `c:147090`.
- `tests/MessageHandler/ApprovePuzzleMergeRequestHandlerTest.php:513` - Trefl `37441` + `5900511374414` → `c:374414`.
- `tests/Value/PuzzleSearchKeysTest.php:72` (the README example) - gains `c:147090` and `c:120001997`;
  `:86` (EAN typed into both fields) must stay without an alias - the 11+-digit guard.

## 6. Docs

- `README.md` "Search" - the example key with its alias lines, the rule in two sentences, VERSION wording.
- `SearchText` docblock - VERSION wording.
- `CLAUDE.md` puzzle-names bullet - one clause: the add form's "What is it?" help + check-digit aliases.
- `docs/TODO.md` - help on the other two code forms; the EAN-less gap.

## 7. Delivery

Four commits on `main` (Jan allows direct commits; the working tree holds another session's co-puzzler changes -
stage only these files, `git commit -- <paths>`):

1. Rename to "Brand code" (+ the Czech brand texts) - translations only.
2. Images + generator + "What is it?" modal + translations.
3. Rebuild command `--dry-run` / `--report` / `--alert-on-drift` + its tests + the lily cron row - no behaviour
   change on its own (the cron finds 0 drift), so it is proven in production before the rule lands.
4. `BrandCodeCheckDigit` + search keys + picker + VERSION + tests + docs.

Before each: `phpstan`, `cs-fix`, `paratest --testsuite "Project Test Suite"`, `cache:warmup`; for 2: the label rows
at 375 px in all 6 locales (button-overflow audit recipe) and the modal opened on a phone width. Release of 4: the runbook in 4a - Jan runs it or asks me to.
