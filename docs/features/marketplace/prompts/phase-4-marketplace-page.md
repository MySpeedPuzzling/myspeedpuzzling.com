# Phase 4: Marketplace Page

Read the feature specification in `docs/features/marketplace/01-marketplace.md` and the implementation plan Phase 4 in `docs/features/marketplace/10-implementation-plan.md`.

**Prerequisites**: Phase 2 (reserved status) and Phase 3 (shipping settings) must be implemented first.

## Task

Create the centralized marketplace page where all active sell/swap listings are displayed. Implement search, filtering with URL-persisted query parameters (using Symfony UX Live Component), and sorting.

## Requirements

### 1. Create Marketplace Query

**`src/Query/GetMarketplaceListings.php`**:

This is the core query. It joins `sell_swap_list_item`, `puzzle`, `manufacturer`, and `player` tables to get everything needed for the marketplace cards.

Method signature:
```php
/**
 * @return MarketplaceListingItem[]
 */
public function search(
    ?string $searchTerm = null,
    ?string $manufacturerId = null,
    ?int $piecesMin = null,
    ?int $piecesMax = null,
    ?ListingType $listingType = null,
    ?float $priceMin = null,
    ?float $priceMax = null,
    ?PuzzleCondition $condition = null,
    ?string $shipsToCountry = null,
    string $sort = 'newest',
    int $limit = 24,
    int $offset = 0,
): array

public function count(
    // same filter parameters without sort/limit/offset
): int
```

**Search logic** — reuse the same approach as `SearchPuzzle` query:
- Use `immutable_unaccent()` and trigram matching (`%search_term%` with ILIKE) on puzzle `name`, `alternative_name`, `ean`, `identification_number`
- When `$sort === 'relevance'` and search term is present, order by match score (exact match > starts with > contains)
- Leverage existing GIN trigram indexes: `custom_puzzle_name_trgm`, `custom_puzzle_alt_name_trgm`, `custom_puzzle_name_unaccent_trgm`, `custom_puzzle_alt_name_unaccent_trgm`

**Shipping filter** — filter by seller's shipping countries in their JSON settings:
```sql
WHERE player.sell_swap_list_settings->'shippingCountries' @> :countryJson
-- where :countryJson is e.g., '"cz"'
```

**Sort options**:
- `newest` — ORDER BY `sell_swap_list_item.added_at DESC`
- `price_asc` — ORDER BY `sell_swap_list_item.price ASC NULLS LAST`
- `price_desc` — ORDER BY `sell_swap_list_item.price DESC NULLS LAST`
- `relevance` — ORDER BY match score DESC (only meaningful when search term present)

### 2. Create Result DTO

**`src/Results/MarketplaceListingItem.php`**:
```php
readonly final class MarketplaceListingItem
{
    public function __construct(
        public string $itemId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|string $manufacturerName,
        public string $listingType,      // ListingType value
        public null|float $price,
        public string $condition,         // PuzzleCondition value
        public null|string $comment,
        public bool $reserved,
        public string $addedAt,           // ISO datetime string
        public string $sellerId,
        public string $sellerName,
        public null|string $sellerCode,
        public null|string $sellerAvatar,
        public null|string $sellerCountry,
        public null|string $sellerCurrency,
        public null|string $sellerCustomCurrency,
        public null|string $sellerShippingCost,
    ) {
    }
}
```

### 3. Create Live Component

**`src/Component/MarketplaceListing.php`**:

```php
#[AsLiveComponent]
final class MarketplaceListing
{
    use DefaultActionTrait;

    #[LiveProp(writable: true, url: true)]
    public string $search = '';

    #[LiveProp(writable: true, url: true)]
    public string $manufacturer = '';

    #[LiveProp(writable: true, url: true)]
    public ?int $piecesMin = null;

    #[LiveProp(writable: true, url: true)]
    public ?int $piecesMax = null;

    #[LiveProp(writable: true, url: true)]
    public string $listingType = '';

    #[LiveProp(writable: true, url: true)]
    public ?float $priceMin = null;

    #[LiveProp(writable: true, url: true)]
    public ?float $priceMax = null;

    #[LiveProp(writable: true, url: true)]
    public string $condition = '';

    #[LiveProp(writable: true, url: true)]
    public string $shipsTo = '';

    #[LiveProp(writable: true, url: true)]
    public string $sort = 'newest';

    #[LiveProp(writable: true, url: true)]
    public int $page = 1;
}
```

Use `url: true` on LiveProp to automatically sync state with URL query parameters. This is built into Symfony UX Live Components — no custom Stimulus controller needed for URL sync.

The component should:
- Inject `GetMarketplaceListings` query and other needed services
- Have `getItems(): array` method that calls the query with current filter state
- Have `getResultCount(): int` method
- Have `getManufacturers(): array` method that returns manufacturers with active listings (for the filter dropdown)
- Use debounce on the search field (`data-live-debounce="300"`)
- Reset page to 1 when any filter changes

### 4. Create Manufacturer Filter Query

Add method to existing query or create helper:
- Get distinct manufacturers that have at least one active listing in `sell_swap_list_item` — for populating the manufacturer filter dropdown

### 5. Create Controller

**`src/Controller/Marketplace/MarketplaceController.php`**:
- Route: GET `/en/marketplace` (name: `marketplace`)
- Single action, no auth required (public browsing)
- Simply renders the page template that contains the Live Component

### 6. Create Templates

**`templates/marketplace/index.html.twig`**:
- Page title: "Marketplace"
- Renders the `MarketplaceListing` Live Component
- Standard page layout extending `base.html.twig`

**`templates/components/MarketplaceListing.html.twig`**:
- Filter bar section:
  - Search input with `data-model="search"` and `data-live-debounce="300"`
  - Manufacturer select with `data-model="manufacturer"`
  - Pieces min/max inputs
  - Listing type select (All, Sell, Swap, Both, Free)
  - Price min/max inputs
  - Condition select
  - Ships to country select (pre-fill with logged user's country if available)
  - Sort select
- Results count: "Showing X results"
- Grid of listing cards (responsive: 4 columns desktop, 2 mobile)
- Pagination or "Load more" button
- Empty state when no results match filters
- Collapsible filter section on mobile (use Bootstrap collapse)

**`templates/marketplace/_listing_card.html.twig`**:
- Puzzle thumbnail image (use existing image URL patterns)
- Puzzle name (truncate to ~60 chars)
- Pieces count + manufacturer name
- Listing type badge (Sell=green, Swap=blue, Both=purple, Free=info) — match existing badge styles
- Price with seller's currency (show "Swap" or "Free" instead of price when applicable)
- Condition badge
- Seller name + country flag
- "RESERVED" badge overlay (Bootstrap `badge bg-warning`) when reserved
- Link: entire card links to puzzle detail page
- Time ago (added at)

### 7. Add Navigation Link

In `templates/base.html.twig`, add "Marketplace" link to the main navigation bar. Place it prominently — after "Puzzles" or similar.

### 8. Write Tests

**`tests/Query/GetMarketplaceListingsTest.php`**:
- Test basic listing retrieval (returns items)
- Test search by puzzle name
- Test search by EAN
- Test filter by manufacturer
- Test filter by pieces range
- Test filter by listing type
- Test filter by price range
- Test filter by condition
- Test filter by ships-to country
- Test sort by newest
- Test sort by price ascending
- Test sort by price descending
- Test pagination (limit/offset)
- Test count method matches search results
- Test empty result set with non-matching filters
- Test that reserved items are included (not hidden)

**`tests/Controller/Marketplace/MarketplaceControllerTest.php`**:
- Test page loads successfully (200) for anonymous user
- Test page loads for authenticated user
- Test URL query parameters are reflected in the page

### 9. Run Checks

```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```
