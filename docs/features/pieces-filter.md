# Piece-count filter

One filter, the same everywhere: quick-pick chips + a custom from–to.
Feature request: https://myspeedpuzzling.com/en/feature-requests/019fa436-565d-7177-9fef-e14c2067e471 (#219)

## Chips

`All · 99/100 · 200 · 300 · 500 · 750 · 1000 · 1500 · 2000+` — `PiecesRange::PRESETS`, the only definition.
Picked from production data (2026-10-01, 530k solving times): 500 = 401k, 1000 = 39k, 300 = 34k,
99 = 15k, 200 = 13k, 100 = 7k, 150/750 ≈ 1.5k, 1500 1k, 2000 0.7k. 99 and 100 are one chip.
Everything else goes through from–to, so the chip row stays short.

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
