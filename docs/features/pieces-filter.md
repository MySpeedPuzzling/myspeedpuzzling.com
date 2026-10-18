# Piece-count filter

One filter, the same everywhere: quick-pick chips + a custom from–to.
Feature request: https://myspeedpuzzling.com/en/feature-requests/019fa436-565d-7177-9fef-e14c2067e471 (#219)

## Chips

`All · 99/100 · 200 · 300 · 500 · 750 · 1000 · 1500 · 2000+` — `PiecesRange::PRESETS`, the only definition.
Picked from production data (2026-10-01, 530k solving times): 500 = 401k, 1000 = 39k, 300 = 34k,
99 = 15k, 200 = 13k, 100 = 7k, 150/750 ≈ 1.5k, 1500 1k, 2000 0.7k. 99 and 100 are one chip.
Everything else goes through from–to, so the chip row stays short.

**A count chip is a class: the count ± 1 %** (`PiecesRange::CLASSES` = 200, 250, 300, 350, 500, 750, 1000,
1500, 2000, 3000; `CLASS_TOLERANCE_PERCENT`; since 2026-10-18): 200 = 198–202, 500 = 495–505, 750 = 743–757,
1000 = 990–1010, 1500 = 1485–1515. Below 200 a count stays exact (99 and 100 have pages of their own; the
99/100 chip is unchanged), "2000+" stays at least 2000. Why: some boxes print a few pieces more than their class -
JIGZUP (World's Fastest Puzzle) says 504 and its maker asked for it to be in the 500 category. The puzzle keeps its
printed count (what players see on the box, so nobody reports it as wrong); the class decides where it is listed:
the chips (also the puzzle picker's, `piecesChipValues()`), the pieces hubs `/{N}-pieces` and their stats
(`GetPiecesHub`), the brand × pieces pages, the brand hub's medians per piece count, the sitemap of those pages,
the difficulty lists per piece count and the puzzle page's links to them (`PiecesRange::ofClass()` / `classOf()`;
SQL `sqlWithinClasses()` to filter - index-friendly BETWEENs - and `sqlClassOf()` only to group). The chip's URL
param is the range (`495-505`); an old `?pieces=500` link still filters exactly 500.
**Rankings, the MSP rating, ladders, skill tiers, predictions and every insight keep the exact count** - a
504-piece time is not compared with 500-piece times there (the "Rating grind" picker preset stays exact 500 too).
Measured on the dev copy of prod data (2026-10-18): see "Performance" below.

## Where

| Page | How it filters |
|---|---|
| `/{_locale}/puzzle` (`PuzzleSearch`) | SQL (`SearchPuzzle`, `:minPieces`/`:maxPieces`), one URL param `pieces` |
| Profile results (`PlayerSolvedPuzzles`) | PHP over the loaded results; only chips the player has results for (+ the active one) |
| Collections, wishlist, solved, unsolved, sell/swap | client-side, `collection_filter_controller.js` + `_pieces_filter_client.html.twig` |
| Marketplace (`MarketplaceListing`) | SQL, URL params `piecesMin`/`piecesMax` stay; chips just set them |

The puzzle picker and API v1 parse through `PiecesRange` too (their UI/params unchanged).

## Rules

- **URL grammar** (`PiecesRange::parse()` / `toParam()`): `N` exact, `A-B`, `A-` at least, `-B` at most, 1–100 000.
  The old `/puzzle` buckets (`1-499`, `501-999`, `1000`, `1001+`) still parse to the same puzzles — indexed links keep working.
- Swapped bounds are put in order, an invalid bound is ignored, unparsable input = no filter.
- **Live components:** `pieces` (or `piecesCountRange`) is the source of truth; the from/to inputs are real
  props (`piecesMin`/`piecesMax`) derived from it on every render and composing it via `onUpdated`.
  They must stay real props: Live Components writes each model's value back onto its input after a render,
  a transient null prop would blank the inputs. Marketplace is the mirror image (bounds are the URL props, `pieces` is the chip prop).
- **Performance:** the btree on `puzzle.pieces_count` serves every chip (prod `EXPLAIN ANALYZE` 2026-10-01: 2–6 ms
  for 300 / 750 / 99–100 / 2000+, a full-width range = the unfiltered count ~40 ms). No extra index. Only the
  unfiltered `/puzzle` view uses the shared first-page cache, as before.
- **Classes (± 1 %) cost nothing measurable** (dev copy of prod data, 2026-10-18, warm): a chip is the same btree
  range scan (catalogue count 1000 vs 990–1010: ~1 ms both); the 500 hub's solves 177 → 134 ms (noise, 393k rows
  either way); Ravensburger's brand hub grouping 379 → 223 ms; the brand directory's combinations 60–85 ms both.
  Filters use `sqlWithinClasses()` (BETWEENs, index-friendly), the CASE of `sqlClassOf()` only groups.
