# Phase 10: Puzzle Detail & List Integration

Read the feature specification in `docs/features/marketplace/09-puzzle-detail-integration.md` and the implementation plan Phase 10 in `docs/features/marketplace/10-implementation-plan.md`.

**Prerequisites**: Phase 4 (marketplace page) must be implemented.

## Task

Integrate offer counts into puzzle detail pages and puzzle list dropdowns. Show offer previews on puzzle detail, and add "Offers (X)" to puzzle card dropdown menus.

## Requirements

### 1. Add Batch Count Query

Add to `src/Query/GetSellSwapListItems.php`:

```php
/**
 * Returns offer counts for multiple puzzles in a single query.
 *
 * @param string[] $puzzleIds
 * @return array<string, int>  puzzleId => count
 */
public function countByPuzzleIds(array $puzzleIds): array
```

Implementation:
```sql
SELECT puzzle_id, COUNT(*) as offer_count
FROM sell_swap_list_item
WHERE puzzle_id IN (:puzzleIds)
GROUP BY puzzle_id
```

Returns only puzzles that have offers (no zero-count entries). Calling code should use `$counts[$puzzleId] ?? 0` for puzzles without offers.

Handle edge case: if `$puzzleIds` is empty, return empty array immediately (don't run query).

### 2. Enhance Puzzle Detail Page

In `src/Controller/PuzzleDetailController.php`:
- Already has `offers_count` — keep it
- Add `offers_preview` — the first 3 offers, fetched from `GetSellSwapListItems::byPuzzleId()` with a limit
- Pass both to the template

In `templates/puzzle_detail.html.twig`:
- When `offers_count > 0`, show an "Offers" card section:
  - Show the first 3 offers inline (seller name+avatar+country, listing type badge, price, condition)
  - Each offer has a "Contact seller" link (if messaging is implemented, link to `start_marketplace_conversation`; otherwise link to seller's sell/swap list)
  - If `offers_count > 3`, show "View all X offers →" link to the puzzle offers page

### 3. Add "Offers (X)" to Puzzle Card Dropdowns

Puzzle cards appear in many contexts (search results, collections, solved/unsolved lists). Find the shared puzzle card template or the dropdown partial used across these pages.

Explore existing templates to find where puzzle card dropdowns are rendered. Common locations:
- `templates/_partials/` or similar shared directory
- Look for dropdowns containing "Add solving time", "Add to collection", "Add to sell/swap list" — those are the puzzle action dropdowns

In each context that shows puzzle cards with dropdowns:
- The controller (or Live Component) should fetch offer counts for all visible puzzles using `countByPuzzleIds()`
- Pass the counts map to the template
- In the dropdown, add an "Offers (X)" link:
  - If count > 0: clickable link to the puzzle offers page
  - If count == 0: disabled/grayed out "Offers (0)" text

### 4. Enhance Puzzle Offers Page

In `templates/sell-swap/offers.html.twig`:
- Show reserved badge on reserved listings
- Show seller's shipping info (countries, cost) if configured
- Show seller rating if ratings are implemented (Phase 7) — or leave placeholder
- Add "View on marketplace" backlink (link to marketplace page with puzzle name pre-filled as search)

### 5. Write Tests

**`tests/Query/GetSellSwapListItemsTest.php`** (update existing):
- Test `countByPuzzleIds()` with multiple puzzle IDs
- Test returns correct counts per puzzle
- Test puzzles without offers are not in result
- Test with empty puzzle IDs array returns empty array
- Test with single puzzle ID

**`tests/Controller/PuzzleDetailControllerTest.php`** (update existing if exists):
- Test puzzle detail page shows offers section when offers exist
- Test puzzle detail page hides offers section when no offers

### 6. Run Checks

```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```
