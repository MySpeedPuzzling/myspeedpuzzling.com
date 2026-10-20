# Puzzles needing improvement

A work list for players who like to help with the puzzle database (asked for by a player on 2026-10-20: "which
brands should I update first?"). Route `puzzles_needing_improvement` (`/en/puzzles-needing-improvement`, all 6
locales), linked from the footer only ("Help improve puzzles", MySpeedPuzzling column), `noindex, follow`.

- **What is listed:** approved, visible puzzles (`hide_until` / `hide_image_until` passed) without an EAN, badge
  "EAN missing". The most solved first (people scan and search those most), then the id. Brand filter = brands with at
  least `GetPuzzlesNeedingImprovement::BRAND_FILTER_MIN_PUZZLES` such puzzles (plus the selected one) - every brand
  would be 1 600+ options. 48 a page (`CataloguePagination`, `?page=`).
- **Action:** "Suggest a change" = the usual `puzzle_suggest_change` form in `modal-frame` for signed-in players (guests
  go to the form page through sign-in). A submitted proposal answers with `mark_improvement_row` in
  `puzzle-report/_stream.html.twig`, which swaps the row's button for "Suggested, waiting for review"
  (`#improvement-action-<puzzleId>`; a no-op on every other page). A puzzle with a pending change request proposing an
  EAN shows that badge from the start.
- **Performance:** 3 statements (count, page, brands). The page is cut first (puzzle + statistics, sorted by solved
  count + id) and only its rows get the brand and the pending-proposal check: ~13 ms warm on a production-sized copy
  (17 000 puzzles without an EAN of 40 000), count ~4 ms, brands ~6 ms. Sorting by the name as well tripled the page
  query - don't add it back. No index needed at this size.
- **Later:** puzzles whose picture needs a better one (`docs/TODO.md`).
