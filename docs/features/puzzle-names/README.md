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

**Query.** `SearchQuery::fromUserInput()` folds, then escapes `\ % _`, then builds the patterns. An empty query after
folding means no text filter at all (browsing). Indexes: `custom_puzzle_search_names_trgm` and
`custom_puzzle_search_codes_trgm` (GIN `gin_trgm_ops`), created in the migration, mirrored in
`tests/bootstrap.php`, registered in `docs/database-indexes.md`.

| Rank | Match | Pattern |
|---|---|---|
| 6 | exact EAN or brand code | `search_codes LIKE '%\ne:4005556147090\n%'` (or `\nc:…\n`) |
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
  `PUZZLE_NAME_SUGGESTIONS_PUBLIC` (see `docs/features/feature_flags.md` when added).

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
  (`PuzzlePickerRankingTest` runs the real library). No `search` key any more: `name` marks a server-built option
  for the escaping renderer, as it already did for brands.
- **Phase 1b - approval queue duplicates** compare barcodes through the search keys (leading zeros aside; junk in the
  EAN column no longer matches junk) and every name against every name; the statement got faster (28-48 → ~19 ms).
- **Phase 1b - library lists** select `search_names` + `search_codes` in place of `ean` + `identification_number`
  (nothing else read those two there); lend/borrow lists have no filter and render no `data-search`.

