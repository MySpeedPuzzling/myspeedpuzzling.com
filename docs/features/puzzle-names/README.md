# Puzzle names, EANs and brand codes

One puzzle is sold under several names: an English (international) title plus Czech, German, Japanese… boxes, and
newer boxes of the same puzzle bring a new EAN and a new brand code. This document is the design of record for
storing, searching and showing all of them. The step-by-step build is in [implementation-plan.md](implementation-plan.md).
The interactive proposal with demos and the full review lives at https://claude.ai/artifact/2Jw17VQ8xrEPYa6cZ9mdNG
(private to Jan).

Status: **in delivery** (decisions taken 2026-10-04; phase 0 shipped 2026-10-04). Decisions taken during delivery are
listed at the end under "Delivery decisions".

## Decisions (Jan, 2026-10-04)

1. **The main title is always the English title** when the box has one, otherwise the title printed on the box. It is
   the first line everywhere: lists, puzzle page, pickers, `<title>`, e-mails, share images, exports.
2. **A name in the viewer's language is a smaller second line** under the main title, only in lists, on the puzzle
   page, in pickers and in search results. Language = the page language; on English pages, a signed-in player's
   country language (a Czech player on `/en/` sees the Czech name). Guests get the page language.
3. **New names are moderated.** Moderators and admins apply their own at once (with an audit row). Every admin
   editor (change-request review, approval queue, moderator edit, merge review) is the full names editor: the main
   title in its own slot, "Make main title" on every other name, a language per name, edit and remove, and a warning
   when the main title does not look English. Players can add a name in another language through a change request or
   a merge request; the moderator can edit all of it before approving.
4. **Migration tagging:** existing alternative names with Czech letters get `cs`; the rest stay without a language
   until a change proposal tags them.
5. **SEO follows multilingual best practice** (locale copies are "crawled/discovered - not indexed" because only the
   interface differs, see `docs/features/seo/research-2026-09.md` §4.1).
6. **Codes:** one input per code in every form; canonical lists in their columns; a search key for lookups.
7. **The add form stays compact and non-disturbing.** Extras are quiet links on label lines; nothing opens until tapped.
8. **No database triggers, functions or generated columns.** Everything that derives data lives in the app.
9. **Best match first** when a search term is typed; global search gets "Show all N results".
10. **Unapproved puzzles are first-class:** listable everywhere, including the add-form picker. "Waiting for approval"
    only says the puzzle is fresh.

## Data model

| Column | Type | Meaning |
|---|---|---|
| `puzzle.name` | varchar(255) | Main title. **Private-write**: changed only by `Puzzle::changeNames()` |
| `puzzle.name_language` | varchar(16), null | BCP 47 language of the main title when it is not English (a Czech-only brand). Null = English or not known |
| `puzzle.alternative_names` | jsonb, default `[]` | Ordered list `[{"name": "…", "language": "cs" \| null}]`. Within one language the first entry is the one shown |
| `puzzle.names_changed_at` | timestamp, null | Last change of any name: feeds the sitemap `lastmod` |
| `puzzle.search_names` | text, null | Folded search key of every name, built by the entity (below) |
| `puzzle.search_codes` | text, null | Folded search key of every EAN and brand code, built by the entity |
| `puzzle.ean` | varchar, null | Canonical list `"4005556147090, 4005555001997"`: digits, leading zeros stripped, `", "` separator |
| `puzzle.identification_number` | varchar, null | Canonical list of brand codes: trimmed, upper case, `", "` separator |

`alternative_names` is a plain `Types::JSONB` array on the entity (no custom Doctrine type): arrays are compared by
value, so an unchanged list never causes an UPDATE. The entity exposes it as a value object:

```php
final readonly class PuzzleName { public function __construct(public string $name, public null|string $language) {} }

final readonly class PuzzleNames // ordered list
{
    public static function fromJson(null|string $json): self;   // raw SQL reads
    public function shownFor(string $language): null|PuzzleName; // base-language match, never a name without language
    public function union(self $other): self;                   // merges: keeps tagged + accented variants, survivor first
    public function diff(self $before): PuzzleNamesDiff;        // change requests apply as diffs
    public function matching(SearchQuery $query): null|PuzzleName; // "Matched: …" line
    public function legacyAlternativeName(): null|string;      // API v1 alternative_name: first cs name, else first
}
```

`Puzzle::changeNames(string $name, null|string $nameLanguage, PuzzleNames $alternatives, DateTimeImmutable $now)`
is the only way names change: trims, collapses whitespace, strips control characters, drops anything that folds equal
to the main title or to another name (keeping the accented and the language-tagged variant), caps form input at 20
names of 255 characters (a merge never throws on the cap), sets `names_changed_at`, rebuilds `search_names`.
`updateProductIdentifiers(EanList, BrandCodeList)` writes the canonical code lists and rebuilds `search_codes`.

## Search

**One fold, in PHP.** `SearchText::fold()` = apostrophes removed (`` ' ’ ‘ ´ ` ʼ ʹ ′ ‛ ＇ ``, before and after NFKC:
"Where's", "Where´s", "Wheres" are one name) + ICU `NFKC; [:Latin:] Latin-ASCII; Lower(); NFC` + control characters
removed + a combining mark with no letter before it removed (NFKC makes `˘ ¨ ¸` a space and a mark) + whitespace
collapsed. NFKC turns full-width `％＿＼４` into ASCII *before* escaping, Latin-ASCII handles
`Łódź → lodz`, `Straße → strasse`, `Ørsted → orsted`, non-Latin scripts stay as they are (half-width katakana are
normalised by NFKC). Postgres never folds puzzle text for search, so stored keys and queries cannot disagree.
`SearchText::VERSION` is bumped whenever the fold changes; then `myspeedpuzzling:rebuild-puzzle-search-keys` runs.

**Keys** (stored with a leading and trailing newline so every whole-line match is a plain `LIKE`):

```
search_names = "\n" + fold(name) + "\n" + fold(alt1) + "\n" + … + "\n"
search_codes = "\ne:4005556147090\ne:4005555001997\nc:14709\nc:12000199\n"
```

EAN lines (`e:`) hold digits without leading zeros; brand-code lines (`c:`) hold letters and digits only. The tags
matter: 167 brand codes equal some stored EAN. A code that is not an EAN ("X002ROECA7") is a `c:` line, never folded
to digits.

**Query.** `PuzzleSearchQuery::fromUserInput()` folds, then escapes `\ % _`, then builds the patterns. An empty query after
folding means no text filter at all (browsing). Indexes: `custom_puzzle_search_names_trgm` and
`custom_puzzle_search_codes_trgm` (GIN `gin_trgm_ops`), created in the migration, mirrored in
`tests/bootstrap.php`, registered in `docs/database-indexes.md`.

| Rank | Match | Pattern |
|---|---|---|
| 6 | exact EAN or brand code | `search_codes LIKE '%e:4005556147090\n%'` (or `c:…\n`; a tag always starts a line) |
| 5 | a whole name | `search_names LIKE '%\nq\n%'` |
| 4 | a name starts with it | `search_names LIKE '%\nq%'` |
| 3 | a word starts with it | `search_names LIKE '% q%'` |
| 2 | a name contains it | `search_names LIKE '%q%'` |
| 1 | part of a code (5+ letters/digits only) | `search_codes LIKE '%q%'` |

One SQL fragment class next to `HiddenPlayers` (`PuzzleTextSearch::condition('p')` / `::score('p')` + params) is used
by `SearchPuzzle` (page, count, EAN lookup), `GetMarketplaceListings`, `FindPuzzlesByExactEan` and
`GetMultiscanCandidates`. Ordering: `best-match` (rank, then most solved) is the default whenever a term is typed;
other sorts stay selectable. Global search shows the best 15 and links "Show all N results" to `/puzzle?search=…`.
API v1 gets `sort=best-match` as an addition; its default is unchanged.

Barcode scans are exact (`e:` whole line): today's loose substring hits (192 codes on dev, mostly wrong puzzles) end.

Benchmarks (dev copy of production, 62,501 names incl. 20,000 synthetic, median of 5, full catalogue page query):
"a" 333 → 49 ms, "cat" 33 → 12 ms, browsing 201 → 45 ms, "van haasteren" 15 → 8 ms, selective terms ≤ 1 ms.
Barcode exact 0.3 ms, brand code 0.16 ms, partial EAN 3.8 ms.

## Display

- First line: `puzzle.name`, always.
- Second line: `PuzzleNames::shownFor($language)` where `$language` comes from `PuzzleNameLanguage` (page language;
  on English pages a signed-in player's country language from a small country → language map). English pages for
  guests have no second line. Rendered by one partial `puzzle/_name.html.twig` (with `lang` attribute).
- Surfaces with the second line: catalogue/search cards, library-like lists (`_puzzle_library_item`, sell/swap), global
  search, multiscan rows, pickers, the add recap, the puzzle page header. Everything else (≈50 queries, 83 templates)
  shows the main title, which is correct under decision 1.
- Search results add "Matched: …" when the match came from a name that is not shown (`PuzzleNames::matching()`).
- Puzzle page: "Also known as" (every name, language named by Symfony Intl in the page language) in the **Details block
  that signed-in players see** and in the guests' "About this puzzle"; "Suggest another name" in the actions menu.
- E-mails, share images, data export: main title only (e-mails render async without a page language; the share image
  font has no CJK glyphs).

## SEO

Hreflang and canonical are already right and stay: self-canonical per locale, reciprocal alternates, English
x-default, never noindex a locale copy. Localized names are the local content these copies lack.

| Where | Czech page of a puzzle with a Czech name |
|---|---|
| `<title>` | `Ravensburger Circle of Colors: Seashells (Kruh barev: Mušle) – puzzle 500 dílků`; the ` – MySpeedPuzzling` suffix is dropped on puzzle pages (Google shows the site name separately) |
| H1 + second line | main title, then `<span lang="cs">Kruh barev: Mušle</span>` |
| Meta description | Czech sentence naming both titles |
| "Also known as" | every name on every language version |
| Product JSON-LD | `alternateName` = other names; valid EANs only, padded (block renders only with marketplace offers today) |
| Sitemap | `lastmod` = latest of added, approved, last solve, `names_changed_at` |

Unapproved puzzles are treated like any other (decision 10). This only differentiates puzzles that have a name in
that language (1,229 today, growing); the "locale experiment" in the SEO research remains the lever for the rest.
Measure index coverage per language in Search Console for 6–8 weeks after the display phase.

## Writing names and codes

Every writer goes through the entity methods (nine paths): `AddPuzzleHandler` (add form, multiscan quick-add),
`Puzzle::correctNewlyAdded()`, `AddPuzzleToCompetitionRoundHandler`, `ApprovePuzzleHandler` (approval queue),
`PuzzleRecordUpdater` (change-request approval + moderator edit page), `ApprovePuzzleMergeRequestHandler`,
`LinkEanToPuzzleHandler`. Raw SQL never writes names or codes (the Ravensburger Puzzle Month runbook `INSERT` leaves
keys empty on purpose - a hidden placeholder must not be searchable; the reveal goes through a change request).

- **Merge requests** ("Report duplicate", the approval queue's "merge into duplicate", internal API): the reporter
  may say which language each reported puzzle's name is in ("this record is the Czech box"). Optional, one language
  per reported puzzle, stored on the merge request as `reported_name_languages` (jsonb map puzzle id → language).
- **Merge review** (admin page and internal API approve): the full names editor, prefilled with the union: the
  survivor's main title, then every other name of all puzzles, with each merged puzzle's main title as an other name
  in the language the reporter gave. The moderator picks the main title, sets or corrects languages, edits or removes
  names, then approves. Without edits the automatic union applies (tagged and accented variants win). Codes union as
  today. The audit snapshot holds the list. Internal API approve accepts `mergedName`, `mergedNameLanguage` and
  `mergedAlternativeNames` (optional; default = the automatic union with the reporter's languages).
- **Change requests**: a player can add a name in any language, re-tag, edit or remove names, or propose a different
  main title. Stored as `proposed_alternative_names` / `original_alternative_names` (+ `proposed_name_language`);
  the moderator edits any of it in the review, and approval applies the result as a **diff** against the original,
  never as a replacement.
- **Stale forms:** every full-record form (moderator edit, change-request review, approval detail, merge detail)
  carries a record version (hash of names, codes, pieces, brand, image) and refuses a stale save. Messages that change
  a puzzle implement `SerializedByLock` keyed by puzzle id.
- **History and audit logs** (`puzzle_moderation_decision`, `puzzle_merge_audit`) keep old `alternativeName` strings
  forever; readers handle both shapes.
- **"Suggest another name"**: names-only change request; rate limited per player; kill switch
  `PUZZLE_NAME_SUGGESTIONS_PUBLIC` (`docs/features/feature_flags.md`); moderators and admins apply their name at once.

## Considered and rejected

- **`puzzle_name` table:** slow on short and broad searches (GROUP BY over many hits; "a" 592 ms), needs a join or a
  second query in every list.
- **Trigram expression index over the JSON / generated columns / triggers:** expression recheck is slow on broad
  searches; generated columns freeze the types of the columns they read and rewrite the table under lock; triggers and
  SQL functions are non-app surfaces (decision 8).
- **JSON or array columns for codes:** would change every read query for no search gain.
- **Editions** (pairing a name, EAN and brand code per box): players rarely know the pairing and nothing needs it yet;
  the JSON shape leaves room for an `edition` key.

## Delivery decisions

Taken by the delivering agent where the plan left room (2026-10-04 onwards).

- **Phase 0 - picker option HTML is escaped in PHP** (`PuzzleChoicesBuilder`, `htmlspecialchars` like
  `BrandChoicesBuilder`), not by a Twig partial per option: one render per option cost ~25 ms on Ravensburger's 6,000
  puzzles. The picker's Tom Select renderer escapes every option that does not come from the server (typed new
  brands/puzzles, also when a refused form comes back). Organisers adding puzzles to a round get their own
  competition's secret puzzles (`?competition=`, `COMPETITION_EDIT`); nobody else does. Code matching: a part of a
  code only from 5 letters/digits, shorter terms match a whole code only (`PuzzleCodeSearch`).
- **Phase 0 - two stored XSS sinks outside the plan fixed with it:** stopwatch milestone labels (player names) and the
  toast body (multiscan puts a puzzle name into it) are rendered as text.
- **Phase 1b - the puzzle query object is `PuzzleSearchQuery`** (`SearchQuery` already exists for the players search).
- **Phase 1b - code patterns without the newline before the tag** (`%e:…\n%`, `%c:…\n%`): a line holds no colon after
  its tag, so the tag always starts a line and the patterns match the same rows - but the trigram index no longer reads
  the posting lists of the tag letter, which is in nearly every key (exact EAN 67 → 43 index pages).
- **Phase 1b - the barcode lookups** (`allByEan`, `FindPuzzlesByExactEan`, so multiscan) ask the index for a code line
  ending with the number (`search_codes LIKE '%4005556147090\n%'`) and check the whole `\ne:…\n` line on the rows it
  finds (`strpos`): the same rows as `LIKE '%e:…\n%'`, 34 index pages instead of 43 - as cheap as the old substring
  lookup on `ean`, which was not exact.
- **Phase 1b - only the tests a query has**: `PuzzleTextSearch` leaves out the test of a pattern the query has none of
  (instead of binding NULL), and a part of a code (5+) covers the whole-code tests in the WHERE condition, so those are
  left out there (one bitmap index scan less; the score keeps them for tier 6).
- **Phase 1b - sort state**: the catalogue's `sortBy` URL/live prop is what the visitor picked (null = nothing). Without
  a pick the best match applies while a term is typed, the most solved otherwise; a pick stays until another one (also
  when the term is cleared); "Best match" is offered only while a term is typed and is no pick without one. The shared
  first-page cache still serves only the term-less most-solved view (unchanged key).
- **Phase 1b - "Show all N results"** in the header search is shown whenever it found a puzzle (also when all of them
  are among the 15): it leads to the catalogue with its filters.
- **Phase 1b - the browser fold** (`assets/search_fold.js`) carries ICU Latin-ASCII as a table of the Latin letters it
  does not reduce by dropping accents (Ł, ß, ø, æ, đ, þ... 351 letters, generated against PHP's ICU 72: all 286,719
  defined code points fold alike); `tests/SearchFoldParityTest.php` pins every Latin letter and a corpus. A typed
  text also matches a code without its separators ("RB-1000" finds `c:rb1000001`, only on code lines) besides the
  barcode without leading zeros. Dead `puzzle_filter_controller.js` and `wjpc_filter_controller.js` went with the
  attributes they read.
- **Phase 1b - fold version 2** (production data: `´` in 25 names became a space and a stray accent, curly
  apostrophes kept "peggy’s" apart from a typed "peggy's"): apostrophe look-alikes are removed before and after NFKC,
  and a combining mark at the start or after a space goes. Names differing only by an apostrophe now fold equal and
  `PuzzleNames` keeps one of them - intended, they are one name. The browser fold mirrors it (all 286,719 code points
  alone, between two letters and after a space fold alike; PCRE still counts U+180E as a space, so the browser does).
  `myspeedpuzzling:rebuild-puzzle-search-keys` runs after the deploy.
- **Phase 1b - picker fields:** options carry `name` (main title), `names` (the other names), `codes` (EANs + brand
  codes as stored) and `piecesCount`, searched with weights 3 / 2 / 1 / 1 (`PuzzleChoicesBuilder::SEARCH_FIELDS`).
  Tom Select's scoring (@orchidjs/sifter) divides a typed word's length by the field's length, so one field of every
  name ranked a five-name puzzle below a one-name puzzle of the same title; separate fields rank them alike
  (`PuzzlePickerRankingTest` runs the real library). `name` marks a server-built option for the escaping renderer,
  as it already did for brands. Blue-green: options keep the old joined `search` text for one more release (not in
  `SEARCH_FIELDS`) and the renderer trusts `search` too, so a page of either release works with options of the
  other; phase 1c drops both.
- **Phase 1b - approval queue duplicates** compare barcodes through the search keys (leading zeros aside; junk in the
  EAN column no longer matches junk) and every name against every name; the statement got faster (28-48 → ~19 ms).
- **Phase 1b - library lists** select `search_names` + `search_codes` in place of `ean` + `identification_number`
  (nothing else read those two there); lend/borrow lists have no filter and render no `data-search`.
- **Phase 1b - the wishlist list query** reads the two key columns and is ~4 % slower on the heaviest wishlist
  (655 items, 9.7 → 10.1 ms, same plan); accepted - folding the names per item in PHP would cost far more.
- **Phase 4 - filing in waves, high confidence only:** the research (2026-10-05, production read-only) found 407
  high-confidence language tags of the 554 untagged names, 3 main-title swaps (the English title is the other name),
  54 jammed-name splits and 45 language-duplicate merges. They are filed through the internal API in waves of about
  100 (merges, splits and swaps first, then tags) so the moderation queue (120-250 items a month normally) is not
  flooded; medium/low-confidence rows (111 names spelled the same in Czech and Slovak, 22 without a clear language) are
  not filed in the first round. Every filing re-reads the puzzle first; puzzles with a pending request are skipped and
  listed. Nothing is approved by the delivering agent.
- **Phase 1c-1 - the old column is unmapped, not kept mapped:** `doctrine:schema:validate` (CI's migrations job) fails
  on a column the mapping does not know, and the contract's fallback (keep it mapped, never written) would have made
  the 1c-2 drop break the 1c-1 containers still serving (every puzzle SELECT and INSERT lists mapped columns). So
  `CustomIndexFilteringPostgreSQLSchemaManager` leaves `puzzle.alternative_name` out of the introspected columns
  (`UNMAPPED_COLUMNS_AWAITING_DROP`), like it leaves out `custom_` indexes: validate passes, `migrations:diff` does
  not want the column gone, and 1c-2 writes its `DROP` by hand and removes the entry.
- **Phase 1c-1 - overlap copy:** appended at the end of the list (the list's order and what it shows stay as they
  are), compared like the 1a backfill; a copied row keeps its old search key until
  `myspeedpuzzling:rebuild-puzzle-search-keys` runs (Postgres never folds). `GetPuzzleOverview::byEan()` (only tests
  called it, `ean LIKE`) was removed. The picker options lost the transitional `search` key, the picker trusts `name`
  only.

- **Phase 2 - two languages, one service** (`PuzzleNameLanguage`): `forViewer()` = the page language, on `/en/` the
  signed-in player's country language (`Value\CountryLanguage`, only countries with one clear language; English and
  multilingual countries have none); `forPage()` = the page language only, for `<title>`, meta description and
  JSON-LD, so every guest of one URL gets the same HTML. The profile is the one `UserLocaleListener` loaded already.
- **Phase 2 - Norwegian:** `LanguageTag::base()` reads `no` as `nb` (Norway maps to `nb`), the stored tag stays as
  given (`normalize('no')` = `no`), so "Also known as" still names what the moderator picked.
- **Phase 2 - the partial `puzzle/_name.html.twig` renders only the lines under the main title** (second line,
  "Matched: …"); every surface keeps its own markup for the main title. Second line = `PuzzleNames::shownUnder()`
  (shownFor, unless it folds equal to the main title). Lines are `span` by default (valid inside links and buttons);
  multiscan rows truncate them like the title. "Matched" highlights the typed words like the main title does.
- **Phase 2 - puzzle page:** the second line sits inside the H1, right under the main title span (the H1 stays
  inline with "500 pieces · Brand" after it), so a Czech page's H1 holds the Czech name too. "Also known as" lists
  every name as "name · language" (`LanguageTag::displayName()`: Symfony Intl, a tag Intl has no locale for = base
  language + region/script/variant in brackets; untagged = "language not set" with `lang=""`) in the Details block
  for signed-in players and in the guests' "About this puzzle" - once per page. The ` – MySpeedPuzzling` suffix is a
  `title_suffix` block in `base.html.twig` (`og:title` / `twitter:title` reuse it); only the puzzle page empties it.
- **Phase 2 - meta description:** no new variants - `%name%` becomes
  `puzzle_detail.meta.name_with_local_name_description` ("Main / Local", Japanese "Main／Local"), so all four sentences
  and the Product description get it. Not "Main (Local)" like the title: two sentences put "(500 pieces…)" right
  after the name, and a dash would meet the " – 500-piece jigsaw puzzle" of the other two.
- **Phase 2 - structured data escaping:** every JSON-LD value in every template goes through the `json_ld` filter
  (`JsonLdTwigExtension`: `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`). Names are player-typed, and
  `json_encode` left `<!--<script>` alone - the HTML parser then swallowed the rest of the page into the script element.
  Ordinary data comes out the same, `& ' "` as `\u` escapes.
- **Phase 2 - GTINs** (`EanList::gtins()`): every stored code with a right check digit, EAN-13 / UPC-A as `gtin13`
  (13 digits, a UPC-A with its preceding zero as schema.org's `gtin13` says), EAN-8 as `gtin8`; one value as a string,
  several as an array. Only 8 and 11-13 significant digits: the field also holds ISBN-like and catalogue numbers
  (171 ten-digit values on production), and padding those would pass every tenth by chance; an EAN-8 starting with 0
  (7 digits stored) is GS1's restricted circulation range, never a GTIN.
- **Phase 2 - cache:** `initial_puzzles_v5` (`PuzzleSearch`) - `PuzzleOverview` gained `nameLanguage`, and an old
  container reading a new entry (Redis is shared across blue-green) fails on the unknown property.
- **Phase 2 - picker label:** `main <small lang="xx">(local)</small>` in every locale from the viewer language
  (replaces the Czech pages' rule that put the other name first). Puzzles without a name in that language cost no
  fold, so Ravensburger's 6,000 options stay as cheap as before.
- **Phase 1c-2 - the column drop** (`Version20261005001318`, hand-written: the column was hidden from the schema
  tools, so `migrations:diff` could not see it) rides with the phase 2 release; the transitional column filter in
  `CustomIndexFilteringPostgreSQLSchemaManager` went with it. The same two-release pattern (unmap + hide, then drop)
  is the way to remove any column under blue-green deploys.

- **Phase 3A - the names editor** (`templates/puzzle/_names_editor.html.twig`, `names_editor_controller.js`,
  `PuzzleNamesType`): rows are renumbered 0, 1, 2... after every add / remove / swap / split, so the list is saved in the
  order shown (Symfony's collection would otherwise keep the old keys' order). The "does not look English" warning
  (a letter outside ASCII) shows only while the main title has no language - choosing "The box has no English title"
  answers it. "Make main title": the old main title takes the row's place in the main title's language (a "Set its
  language" note when it has none), the promoted name's language becomes the main title's unless it is English. A
  language tag outside the curated list (`pt-BR`) is offered on the name that has it and moves with "Make main title".
- **Phase 3A - stale forms:** `PuzzleRecordVersion` = the first 16 hex of sha256 over the whole record (names with
  languages and order, brand id, pieces, codes, stored image); `null` = not checked (internal API, a form rendered by
  the release before). The change-request read model computes it from the stored image, not the embargo-masked one.
  `PuzzleChangedMeanwhile` extends `UnprocessableEntityHttpException` instead of `#[WithHttpStatus]`:
  `UnwrapHttpExceptionMiddleware` hands only HTTP exceptions to the caller as themselves.
- **Phase 3A - locks:** `EditPuzzle`, `ApprovePuzzle`, `ApprovePuzzleChangeRequest` (gains `puzzleId`, checked by the
  handler, looked up by the internal API), `ApprovePuzzleMergeRequest` (the survivor only - the middleware takes one
  key; the merged puzzles are deleted by the merge), `LinkEanToPuzzle` and `AddPuzzle` (corrects the puzzle it added).
- **Phase 3A - transition:** `PuzzleRecordFormType` option `names_editor` (moderator edit on, change-request review
  still on the single fields until 3B switches it); `PuzzleNames::withLegacyAlternativeName()` goes with the last
  single-field form.
- **Phase 3B - a change request proposes the names as one part:** `proposed_alternative_names` (the whole list, cleaned
  like the puzzle stores it next to the proposed main title) and `proposed_name_language` are proposed together; null
  list = the names are no part of the proposal (then the language is not either). `original_*` are snapshotted on every
  new request (also EAN links). A list differing only in order is no change.
- **Phase 3B - the review applies the reviewer's list as a diff** against the puzzle's names read in the same request as
  the form (`ApprovePuzzleChangeRequest::$reviewedFrom`); the record version the form carries refuses the save when they
  changed since the page was rendered, so the base equals the rendered list whenever a save goes through - no extra
  hidden field. The internal API approve corrects a proposal with `alternativeNames` / `nameLanguage` (selected fields
  only), applied as a diff against the list when filed, like the proposal itself.
- **Phase 3B - "Suggest another name":** a fold-equal name the puzzle has without a language is tagged instead of added;
  a fold-equal main title or tagged name is refused ("already has this name"). A moderator's or an admin's name is their
  direct edit (`puzzle_edited` decision, history shows it) and the page refreshes; the rate limit (10 a day) counts
  players only and every valid submit (also a refused known name). Allowed while other proposals are pending - names
  apply as diffs. The modal lists the names the puzzle has, so nobody suggests one again.
- **Phase 3B - the legacy single name fields of the record form are gone** (`PuzzleRecordFormType` always has the names
  editor; option `names_editor` removed). `PuzzleNames::withLegacyAlternativeName()` went with the approval queue's single
  field (release R3) - every moderator form, the approval detail included, saves the whole list from the names editor.
  `PuzzleNames::legacyAlternativeName()` stays only for the reads of the one name of old: API v1 `alternative_name` and
  the internal API merge queue's `alternativeName`.
- **Phase 3C - the merge review is a Symfony form** (`PuzzleMergeReviewFormType`) posting back to the detail page, so a
  refused one (record version, invalid names) comes back with what was typed; the raw-field admin `/approve` route is
  gone. Its names start from `PuzzleMergeNames::forReview()`, the same rules as the automatic union. The editor's list
  is final: a main title typed over is gone unless moved to the other names first; the internal API without
  `mergedAlternativeNames` keeps the union.
- **Phase 3C - reported languages** are stored as base languages keyed by the lower-case puzzle id. The reporter's
  language of the survivor's own title prefills its `nameLanguage`; a language the merge derives for the main title is
  never `en` (null = English).
- **Phase 3C - where the reporter says it**: "Report duplicate" has both selects behind a quiet "Are they boxes in
  different languages?" link (the duplicate's language only when the report names one duplicate); the approval
  queue's merge asks for the new puzzle's language next to each merge button (Twig `puzzle_name_language_choices()`).
- **Phase 3C - add form**: "+ name in another language" and its rows show only while a new puzzle is typed
  (`newPuzzleExtra` targets of `time_form_autocomplete_controller.js`); rows left under an existing puzzle are ignored
  and never block a save. The stopwatch's save page is the same form, so it has the link too; the competition-round form
  and multiscan keep one name.
- **Phase 3 review - one proposal at a time, names aside:** a pending merge request or change request of more than the
  names holds up another such proposal ("Suggest a change", "Report duplicate", the internal API's 409); a change request
  of the names only (main title, its language, other names - nothing else differs) counts in neither direction, so
  several may wait at once next to a full one (`GetPendingPuzzleProposals::blocksNewProposal()`; the puzzle page's
  "pending" badge still shows every one). A "Suggest a change" opened before a full proposal was filed still files names.
