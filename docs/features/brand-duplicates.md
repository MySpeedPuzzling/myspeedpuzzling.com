# Duplicate brands

The same brand (`manufacturer`) exists several times: "PusselParet" twice, both approved, "SPA-TIME Puzzles" eight
times. Players then split their puzzles between the copies, the same puzzle gets added under each copy, and the brand
hub pages show half a brand each.

## How it happens - the "PusselParet" case

| When | Who | What |
|---|---|---|
| 2025-06-17 | Tobias P | Adds "Öland" 500 (EAN 7300009077465), types the brand "PusselParet" → brand #1 `pusselparet`, unapproved |
| 2025-06-26 | Elin J | Adds **the same puzzle**. Brand #1 is unapproved and not hers, so it is not in her brand picker → types the name → brand #2 `pusselparet-2` |
| before 2026-09-17 | SQL bulk approval (not recorded) | Both brands approved |
| since | four more players | Both copies look identical in the picker ("PusselParet (3)"): Österlen + Lund went to #2, Funäsfjällen + Göteborg to #1 |

The gaps behind it:

1. **The add form de-duplicates only against brands it can see.** Since 2025-01-11 (`ad2f225c`) the brand field selects an
   existing option instead of creating a new one when the typed text matches it
   (`time_form_autocomplete_controller.js`), but the options (`GetManufacturers::onlyApprovedOrAddedByPlayer`) leave out
   other players' unapproved brands. *Fixed 2026-10-03, see Hardening.*
2. **The server never checks.** `AddPuzzleHandler::manufacturer()` and `AddPuzzleToCompetitionRoundHandler` create a new
   `Manufacturer` for any brand value that is not an id. No name comparison, no unique constraint. *Name comparison
   since 2026-10-03 (`ManufacturerResolver`), still no unique constraint.*
3. **Approval never checks for twins.** The SQL bulk approvals did not; the approval queue's "approve" choice does not
   either, and its suggestions list only approved brands, so two unapproved copies never meet.
4. **New brands get stuck unapproved.** The approval queue shows a brand only together with a pending puzzle. 158 of the
   213 unapproved brands (2026-10-03) have only approved puzzles, so nobody is ever asked about them - and they stay
   hidden from every other player's picker, which breeds the next copy.

The 2026-10-03 survey (below) adds: 171 of 173 certain/likely duplicates were typed into the add-puzzle form, and 97 of them
copy a brand that had existed for over 30 days - approved brands get re-typed too, with other spacing or a typo
("Puzzle bug", "LaLaLand", "Workshoppe", "Treff"). So hiding unapproved brands is not the only cause; any free-text brand
that does not happen to match an option is a new brand.

## Survey 2026-10-03

Read-only survey of production, full results in `~/Downloads/msp-brand-duplicates-2026-10-03/` on Jan's Mac
(`brand-duplicates.md` report, `brand-duplicates.json` groups with ids, SQL of every signal in the report's appendix).
It covers every brand, approved ones included, and supersedes `/root/brand-merge-proposal-2026-09-17.json` (all 86 of
those groups were still open and are included).

| | |
|---|---|
| Brands | 2,277 (2,064 approved, 213 unapproved) |
| Groups | **99 certain**, 44 likely, 44 your call |
| Merging the certain groups | 126 brands disappear (39 approved), 196 puzzles move |
| Same puzzle under two brands of a group | 50 in certain groups - each needs a puzzle merge afterwards |
| Trend | since July 2026 roughly every 4th-5th new brand is a copy; 29 copies created in the 30 days before the survey |

Signals, strongest first: identical normalised name (case, accents, spacing, punctuation, "&"/"and", a "puzzle(s)" or
legal-form suffix); GS1 company prefix of the puzzles' EANs (variable length, UPC = EAN-13 with a leading 0; beware
generic codes - every PusselParet puzzle carries the same EAN); the same puzzle under both brands. Lines, licences,
artists and distributors typed as the brand (Hinkler's Mindbogglers, Jumbo's Wasgij, Sure-Lox artists) are "your call",
never "certain".

## Cleanup 2026-10-03

Driven through the internal API, credited to Jan, report in `cleanup-report-2026-10-03.md` next to the survey:
2,277 → 2,057 brands (217 merged, 3 empty ones deleted, 35 real brands approved), unapproved 213 → 57, and no two brands
share a name ignoring case and spacing any more. 137 puzzle merge requests were filed for the puzzles that were twice
under the merged brands - left to community review, not approved.

Policy used for the researched groups (web + EAN prefix per group): a line, series, artist, licence, retailer or
imprint typed as the brand is merged into the publisher when the EANs confirm it (Mindbogglers → Hinkler, Anne Geddes →
Sure-Lox, Windows Spotlight → Spin Master, placeholders like "none", "???", "Unbekannt" → Unknown). Kept apart or left to
Jan: box brands of their own with a history (Big Ben), series that moved publisher (Boynton), medium-evidence pairs and
the display name of renamed companies (D-Toys / Roovi) - listed in the report.

## Merging

`ManufacturerMerger` is the one brand merge - the approval queue's `merge_into` brand choice and the internal API both use
it. For each duplicate:

- its puzzles and the change requests proposing it move to the survivor (`puzzle.manufacturer_id` and
  `puzzle_change_request.proposed_manufacturer_id` are the only references; a new one must be moved there too),
- the survivor keeps what only the duplicate had: logo, EAN prefixes (union of the comma-separated lists, never reduced)
  and approval (an approved duplicate makes the survivor approved),
- the duplicate's slug becomes a `manufacturer_slug_redirect` row, and redirect rows that pointed at the duplicate are
  repointed to the survivor (a redirect never chains),
- the duplicate is deleted and the catalogue caches of the slugs involved are dropped (`CatalogueStatsProvider::forgetBrands()`).

The survivor keeps its own slug - it is the address that stays - so pick the copy without a `-2` suffix where you can.

**Redirects.** Brand hubs are public and in the sitemap. `ManufacturerSlugRedirectSubscriber` turns a 404 on any brand
route (`brand_puzzles[_page]`, `brand_pieces_puzzles[_page]`, `brand_hardest_puzzles`, `brand_easiest_puzzles`) whose slug
is in `manufacturer_slug_redirect` into a 301 to the same page of the survivor, query string kept. It runs on the 404 only,
so existing brand pages pay nothing. `GenerateManufacturerSlug` never hands a redirected slug to a new brand.

**Decision log.** Every merge writes one `brand_merged` row per merged brand to `puzzle_moderation_decision` (the merged
brand's id, name, slug and approval, what moved, the redirected slugs, confidence and note) - the only trace of the
deleted brand. Approving a brand on its own writes `brand_approved`, deleting an empty one `brand_deleted` (its name,
slug, approval and `added_at`).

## Internal API (Claude-driven cleanup)

Full reference in [`internal-api.md`](./internal-api.md#brands).

| Endpoint | Use |
|---|---|
| `POST /internal-api/manufacturers/{survivorId}/merge` | Fold `mergedManufacturerIds` into the survivor, optionally renaming it (`name`) |
| `POST /internal-api/manufacturers/{id}/approve` | Approve a genuinely new brand stuck outside the queue (409 if an approved brand has its name) |
| `POST /internal-api/manufacturers/{id}/delete` | Delete an empty brand (409 while a puzzle, a change request proposal or a merged slug's redirect points at it) |
| `POST /internal-api/puzzle-merge-requests` | File a duplicate report for puzzles left twice after a brand merge |
| `POST /internal-api/puzzle-merge-requests/{id}/approve` | Merge those puzzles (existing endpoint, audited in `puzzle_merge_audit`) |

Workflow: survey JSON → Jan picks the groups → merge each group (`decisionConfidence` + `decisionNote` from the
survey's evidence) → for every "same puzzle under two brands" pair now under one brand: compare the images, file + approve
a puzzle merge → approve the remaining genuine new brands.

## Hardening

### Built (2026-10-03)

1. **Every brand picker lists every brand** - approved or not, whoever added it (`GetManufacturers::allIncludingUnapproved()`):
   the add-time / add-puzzle form, the edit-time form and the competition round puzzle form (`BrandChoicesBuilder`), the
   multiscan quick-add (`MultiscanTray::brandOptions()` - it listed approved brands only), the change request ("suggest a
   change" - a puzzle under someone else's unapproved brand could not keep its brand there) and the duplicate report's
   brand filter (the duplicate often sits under an unapproved copy; `PuzzleByBrandAutocompleteController` lists every
   puzzle of any brand id). The brand name in the picker's option HTML is escaped now - those names are typed by players
   and no longer only the moderated or the player's own. The `active_puzzle` form option, which only added the edited
   result's brand to the picker, is gone.
   Left as they were (`onlyApprovedOrAddedByPlayer()`, approved only): the public search filter (`PuzzleFilterOptions`),
   the approval queue's brand suggestions and the admin puzzle merge page.
2. **The server resolves a typed brand name** (`ManufacturerResolver`, the one place for `AddPuzzleHandler` incl. its
   `correct()`, the multiscan quick-add through it, and `AddPuzzleToCompetitionRoundHandler`): an id is that brand; a
   name is the existing brand whose name is equal after trimming, collapsing inner whitespace and lowercasing both sides
   (`ManufacturerRepository::findByNameIgnoringCase()` - one SQL expression for both sides, so they always agree; ASCII
   whitespace only). Nothing else is normalised: "Puzzle bug" is not "Puzzlebug", "Treff" is not "Trefl". Several
   matches: approved first, then the most puzzles, then the oldest. Only without a match a new unapproved brand is
   created, its name trimmed and whitespace-collapsed. Not race-safe: two players typing the same new brand within the
   same second still create two (no unique index yet - see below).
3. **The picker's client-side match** (`time_form_autocomplete_controller.js`) compares the typed text with the option's
   plain `name` (no longer its HTML with `includes()`), diacritics, case and spacing ignored: an exact name selects that
   brand; otherwise the only brand whose name starts with the text ("ravensbur" → Ravensburger); otherwise a new brand.
   Tom Select searches `name` + `eanPrefix` instead of the HTML. The multiscan quick-add has no client-side matching (a
   plain select + a text field); the typed name goes to the server as it is.
4. **Save once** (`docs/features/duplicate-results.md`): a resent add form carries the same new-puzzle id, waits on its
   lock, finds the puzzle and its brand through the resolver - covered (`ResultSavedOnceTest`, `AddPuzzleBrandTest`).

### Still open

1. **A unique key** on the normalised name (race-safe insert like `PuzzlingTeamResolver`) - needs the identical-key
   duplicates merged first.
2. **Approval checks for twins**: the queue's brand suggestions include unapproved brands, "approve" is refused while a
   same-key approved brand exists (the internal API's approve already refuses a case-insensitive name match).
3. **Stuck unapproved brands**: they no longer breed copies (every picker shows them), but nobody is ever asked about a
   brand without a pending puzzle - approve or merge them through the API, or list them in the approval queue.

### Multiscan check (2026-10-03)

The multiscan quick-add (members, since 2026-09-22) is not a source of duplicates. Of the 30 brands created between
2026-09-22 and 2026-10-03, 23 came from the add form with a time, 6 from the add form in collection mode, 1 most likely
from the add form whose time save failed, 0 from multiscan or a competition round. Of 820 puzzles created in that time,
21 look like multiscan quick-adds, none with a new brand. Only 1 of the 30 brands repeats an older brand by the
case-and-spacing rule ("SPA-Time Puzzles", whose copies were all unapproved and someone else's - gap 1). By that rule July
had 12 such copies (of 118 new brands), August 16 (of 130), September 17 (of 137), all from the add form; the higher
survey numbers use the broader normalisation (accents, punctuation, suffixes) the resolver deliberately does not.
Method: a brand's first puzzle shares its `added_at` (±2 s); add form = the creator's time within 120 s or a collection
row within 10 s of it; multiscan = member, EAN, and a collection/wishlist/lending row only after a later "apply".

## Files

- `src/Services/ManufacturerMerger.php` - the merge
- `src/Services/ManufacturerResolver.php`, `ManufacturerRepository::findByNameIgnoringCase()` - a typed brand name
- `src/Query/GetManufacturers.php` (`allIncludingUnapproved()`), `src/Services/BrandChoicesBuilder.php` - the pickers
- `src/MessageHandler/MergeManufacturersHandler.php`, `ApproveManufacturerHandler.php`, `DeleteManufacturerHandler.php` - API decisions
- `src/Entity/ManufacturerSlugRedirect.php`, `src/EventSubscriber/ManufacturerSlugRedirectSubscriber.php`,
  `src/Query/GetManufacturerSlugRedirect.php` - redirects
- `src/Controller/InternalApi/MergeManufacturersController.php`, `ApproveManufacturerController.php`,
  `DeleteManufacturerController.php`, `SubmitPuzzleMergeRequestController.php`
- Tests: `tests/MessageHandler/MergeManufacturersHandlerTest.php`, `ApproveManufacturerHandlerTest.php`,
  `DeleteManufacturerHandlerTest.php`, `AddPuzzleBrandTest.php`, `tests/Services/BrandChoicesBuilderTest.php`,
  `tests/EventSubscriber/ManufacturerSlugRedirectSubscriberTest.php`, `tests/Controller/InternalApi/*`
