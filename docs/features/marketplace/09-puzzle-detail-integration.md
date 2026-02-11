# 09 - Puzzle Detail & List Integration

## Overview

Integrate marketplace offer information into puzzle detail pages and puzzle list views. Show offer counts, link to filtered marketplace views, and add an "Offers" entry to puzzle dropdowns.

## Puzzle Detail Page

### Current Behavior

The puzzle detail page (`PuzzleDetailController`) already shows:
- `offers_count` via `GetSellSwapListItems::countByPuzzleId()`
- A badge linking to `/en/sell-swap-offers/{puzzleId}` (the `PuzzleOffersController`)

### Enhancements

1. **Rename/enhance the offers page**: The existing `PuzzleOffersController` at `/en/sell-swap-offers/{puzzleId}` should become the "Puzzle Offers" page, showing all marketplace listings for that specific puzzle. Keep the existing route but enhance the template.

2. **Enhanced offers section on puzzle detail**:
   - Show offer count badge (existing)
   - When offers exist, show a preview of the first 3 offers inline
   - "View all X offers" link to the puzzle offers page
   - "Contact seller" button on each offer (links to marketplace messaging)

3. **Seller rating display**: On each offer, show the seller's average rating (if available)

### Template Changes

In `templates/puzzle_detail.html.twig`, enhance the offers section:

```twig
{% if offers_count > 0 %}
<div class="card mb-3">
    <div class="card-header">
        <h5>{{ 'puzzle_detail.offers'|trans }} ({{ offers_count }})</h5>
    </div>
    <div class="card-body">
        {% for offer in offers_preview %}
            {# Show seller, price, condition, rating, contact button #}
        {% endfor %}
        {% if offers_count > 3 %}
            <a href="{{ path('puzzle_offers', {puzzleId: puzzle.id}) }}">
                {{ 'puzzle_detail.view_all_offers'|trans({'%count%': offers_count}) }}
            </a>
        {% endif %}
    </div>
</div>
{% endif %}
```

### Controller Changes

`PuzzleDetailController`: Add `offers_preview` variable (first 3 offers) from `GetSellSwapListItems::byPuzzleId()` with a limit.

## Puzzle List "Offers" Dropdown

### Requirement

On pages that show lists of puzzles (e.g., puzzle search results, collection items, solved puzzles), add an "Offers (X)" entry to each puzzle's dropdown menu. This should:
- Show the count of active offers for that puzzle
- Be clickable → links to the puzzle offers page
- Be visually "disabled" (grayed out) if count is 0

### Performance: Cached Offer Counts

Since puzzle lists can show many puzzles per page, querying offer counts individually would be expensive. Instead:

#### Approach: Batch Query in Controller/Component

When rendering a list of puzzles, collect all puzzle IDs and fetch counts in one query:

```sql
SELECT puzzle_id, COUNT(*) as offer_count
FROM sell_swap_list_item
WHERE puzzle_id IN (:puzzleIds)
GROUP BY puzzle_id
```

This returns only puzzles that have offers, which is typically a small subset.

#### New Query Method

Add to `GetSellSwapListItems`:

```php
/**
 * @param string[] $puzzleIds
 * @return array<string, int>  puzzleId => count
 */
public function countByPuzzleIds(array $puzzleIds): array
```

#### Integration with Existing Puzzle Lists

Puzzle lists are rendered in various contexts. The offer counts need to be available in:

1. **Puzzle search results** (`SearchPuzzle` query + controller)
2. **Collection items** (collection detail pages)
3. **Solved puzzles** (player solved puzzles page)
4. **Unsolved puzzles** (player unsolved puzzles page)

For each of these, the controller (or Live Component) should:
1. Get the list of puzzles for the current page
2. Extract puzzle IDs
3. Call `countByPuzzleIds()` with those IDs
4. Pass the counts map to the template

#### Template Integration

In the puzzle card dropdown (shared partial):

```twig
{# In puzzle card dropdown, after existing items #}
{% set offer_count = puzzle_offer_counts[puzzle.id] ?? 0 %}
{% if offer_count > 0 %}
    <a class="dropdown-item" href="{{ path('puzzle_offers', {puzzleId: puzzle.id}) }}">
        {{ 'puzzle.offers'|trans }} ({{ offer_count }})
    </a>
{% else %}
    <span class="dropdown-item disabled text-muted">
        {{ 'puzzle.offers'|trans }} (0)
    </span>
{% endif %}
```

### Alternative: Denormalized Count on Puzzle Entity

For even better performance, consider adding a denormalized `offerCount` field to the Puzzle entity:

```php
#[Column(type: Types::INTEGER, options: ['default' => 0])]
private int $activeOfferCount = 0;
```

Updated via event handlers:
- `AddPuzzleToSellSwapList` → increment
- `RemovePuzzleFromSellSwapList` → decrement
- `MarkPuzzleAsSoldOrSwapped` → decrement

This eliminates the need for any additional query on puzzle lists. The count is always available in the puzzle data that's already loaded.

**Recommendation**: Start with the batch query approach (simpler, no schema change). If performance becomes an issue with large puzzle lists, add the denormalized field later.

## Puzzle Offers Page Enhancement

### Current Route

`/en/sell-swap-offers/{puzzleId}` → `PuzzleOffersController`

### Enhancements

1. **Add "Contact seller" button** for each offer (links to `StartMarketplaceConversationController`)
2. **Show seller ratings** alongside each offer
3. **Show reserved badge** for reserved listings
4. **Show shipping info**: Countries seller ships to, shipping cost
5. **Backlink to marketplace**: "See all offers on marketplace" link with puzzle name pre-filled as search

### Template

```
templates/sell-swap/offers.html.twig (enhance existing)
```

## Navigation

Add to main navigation:
```
┌────────────────────────────────────────────────────┐
│ Logo  │ Puzzles  │ Marketplace  │ Messages  │ ...  │
└────────────────────────────────────────────────────┘
```

- **Marketplace**: Link to `/en/marketplace`
- **Messages**: Link to `/en/messages` with unread count badge (Mercure-powered real-time update)

## Files to Modify

```
src/Controller/PuzzleDetailController.php              # Add offers_preview
src/Query/GetSellSwapListItems.php                     # Add countByPuzzleIds()
templates/puzzle_detail.html.twig                      # Enhanced offers section
templates/sell-swap/offers.html.twig                   # Enhanced with contact/rating
templates/_partials/puzzle_card_dropdown.html.twig     # Add "Offers (X)" item (or equivalent)
templates/base.html.twig                               # Navigation updates
```

## Testing

- Test batch count query with various puzzle ID sets (empty, one, many)
- Test offer count matches actual listings
- Test disabled dropdown item when no offers
- Test offers preview on puzzle detail (limit 3)
- Test navigation links and badges
