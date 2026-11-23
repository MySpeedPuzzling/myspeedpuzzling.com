# Puzzles needing improvement

A work list for players who like to help with the puzzle database (asked for by a player on 2026-10-20: "which
brands should I update first?"). Route `puzzles_needing_improvement` (`/en/puzzles-needing-improvement`, all 6
locales), linked from the footer only ("Help improve puzzles", MySpeedPuzzling column), `noindex, follow`.

## What is listed

Approved, visible puzzles (`hide_until` / `hide_image_until` passed) that miss something - one row per puzzle with
every badge that applies:

| Badge | When | Who sees it |
|---|---|---|
| EAN missing | `puzzle.ean` empty and the box not confirmed to have no barcode (`ean_absent_confirmed_at IS NULL`, docs/features/puzzle-approvals.md "No barcode on the box") | everybody |
| Image needs improvement | the puzzle's current `crop_trust` analysis has `resolution` set (`low_resolution_artwork` / `small_original`) | everybody |
| Wrong picture | the current `crop_trust` analysis has `notThePicture = true` | admins + community moderators only (`PUZZLE_MODERATION_ACCESS`) - other viewers never get these rows nor the badge |

- `crop_trust` = the image pipeline's quality marks per picture (`puzzle_image_analysis`, analyzer `crop_trust`,
  payload in `docs/features/insights/image-taxonomy.md`, numbers in `image-pipeline.md` §7). An analysis counts only
  while it describes the picture the puzzle has now: `image_fingerprint = PuzzleImageFingerprint::sql('puzzle.image')`
  (a new picture makes it stale until the pipeline looks again). `pictureKind` and `cropNeedsLook` are ignored on
  purpose. Production, 2026-11-23: 5,443 puzzles with a `resolution` mark (2,538 of the strong ones also lack an EAN),
  171 with `notThePicture`.
- A puzzle whose box a moderator confirmed has no barcode at all (World Series puzzles, small makers, older prints) is
  never "EAN missing" - not in the list, no badge, no hint. An EAN stored later clears the confirmation.
- A pending change request proposing an EAN - or "the box has no barcode" - turns "EAN missing" into "EAN suggested,
  waiting for review"; one proposing
  a photo does the same for the image badges ("Image suggested, waiting for review").
- The most solved first (people scan and search those most), then the id. 48 a page (`CataloguePagination`, `?page=`).

## Filters (GET parameters, kept by the pagination)

- `missing` (`ImprovementNeed`): all (default) / `ean` / `image` (for moderators the wrong pictures too) /
  `wrong-picture` (offered to moderators only; anybody else asking for it gets "all").
- `brand`: brands with at least `GetPuzzlesNeedingImprovement::BRAND_FILTER_MIN_PUZZLES` listed puzzles (plus the
  selected one), counted under the active `missing` and `collection` filters - every brand would be 1 600+ options.
  Within the player's collections every brand counts (a collection holds few puzzles of each).
- `collection=1` "In my collection" (signed-in players only, ignored for guests): puzzles in any of the player's
  collections - the system one (`collection_item.collection_id` NULL) and their own.

The count line names what is listed ("N puzzles needing improvement", "… without an EAN", "… needing a better
image", "… with a wrong picture").

## Row layout (phone first, Vroni's feedback 2026-11)

The name gets the whole middle column with a 2-line clamp (one line cut it to "Curious Ca…" on phones), under it
pieces · brand · solved count, then all badges in one wrapping row (`#improvement-badges-<puzzleId>`). The right side
holds only the icon "Suggest a change" button (`#improvement-action-<puzzleId>`, its text from 992 px up) - always,
also with a suggestion waiting: the proposal form shows the pending proposals itself.

## Action

"Suggest a change" = the usual `puzzle_suggest_change` form in `modal-frame` for signed-in players (guests go to the
form page through sign-in). A submitted proposal answers with `mark_improvement_row` in `puzzle-report/_stream.html.twig`
plus `proposed_ean` / `proposed_image`: the stream replaces the badges by id - `improvement-ean-<id>` (EAN),
`improvement-image-<id>` and `improvement-wrong-picture-<id>` (photo) - with their "suggested" form; a proposal with
neither fills the hidden placeholder `improvement-proposed-<id>` with "Suggested, waiting for review". A replace whose
target is not on the page does nothing, so other pages are unaffected. One partial renders every badge:
`puzzle-report/_improvement_badge.html.twig`.

## Puzzle page hint

Signed-in players see under the puzzle page's action buttons "Help improve this puzzle: EAN missing · better image
wanted → Suggest a change" (only the parts that apply; "wrong picture" only for moderators; never "EAN missing" for a box
confirmed to have no barcode). One statement
(`GetPuzzlesNeedingImprovement::hintForPuzzle()`, primary-key lookups) for signed-in viewers only - pinned in
`PuzzleDetailQueryBudgetTest`. Guests and crawlers get nothing.

## Performance

Three statements (count, page, brands) in `GetPuzzlesNeedingImprovement`:

- The page is cut first (puzzle + statistics, sorted by solved count + id) and only its rows get the brand, the badges
  and the pending-proposal checks. Sorting by the name as well tripled the page query (2026-10-21) - don't add it back.
- The filter joins only the `crop_trust` analyses that mark something, **by the puzzle id alone**, and compares the
  fingerprint inside a CASE for those rows only. The first version joined every analysis on `(puzzle_id, fingerprint)`:
  the planner hashed `md5()` of all ~41 000 pictures (~25 ms) and read every payload - ~70 ms a statement.
- The EAN filter joins no analysis (as before: count ~2 ms, page ~9 ms, brands ~4 ms).

Measured 2026-11-23 on a copy of the dev database (41 282 puzzles, 17 068 without an EAN) with seeded analyses of the
production shape (41 266 `crop_trust` rows, ~485 bytes each, 5 % stale fingerprints, 5 129 `resolution` marks, 260
`notThePicture`; plus 12 other analyzers = 536 000 rows, 918 MB) - the real query class, best of 7 warm runs, no
parallel workers:

| Filter | count | page | brands |
|---|---|---|---|
| all (default) | 22 ms | 30 ms | 26 ms |
| ean | 2 ms | 9 ms | 4 ms |
| image | 23 ms | 28 ms | 24 ms |
| all, moderator | 23 ms | 31 ms | 25 ms |
| all, the biggest brand (6 075 puzzles) | 10 ms | 13 ms | 24 ms |
| all, the biggest collection (1 722 items) | 5 ms | 5 ms | 5 ms |
| puzzle page hint | 0.15 ms | | |

What is left is reading the `crop_trust` payloads of every puzzle. A partial index halves the image filters (all:
9 / 16 / 13 ms, image: 10 / 16 / 12 ms) - **not added** (a hand-written migration needs Jan's OK); when wanted, a
`custom_*` index per `.claude/rules/database.md`, its predicate word for word the subquery's WHERE in
`markingAnalyses()`:

```sql
CREATE INDEX custom_puzzle_image_analysis_crop_trust_marks ON puzzle_image_analysis (puzzle_id)
WHERE analyzer = 'crop_trust' AND (
    (jsonb_typeof(puzzle_image_analysis.result -> 'resolution') = 'array' AND puzzle_image_analysis.result -> 'resolution' <> '[]'::jsonb)
    OR (puzzle_image_analysis.result -> 'notThePicture' = 'true'::jsonb)
);
```

(200 kB on the seeded copy.)

## Tests

`tests/Query/GetPuzzlesNeedingImprovementTest.php` (every filter, moderator vs player, stale fingerprint, collection,
proposals, the hint) and `tests/Controller/PuzzlesNeedingImprovementControllerTest.php` (page per viewer, the stream,
the puzzle page hint); seeded analyses in `tests/SeedsCropTrustAnalyses.php`.
