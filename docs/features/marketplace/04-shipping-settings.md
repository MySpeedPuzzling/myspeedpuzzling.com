# 04 - Seller Shipping Settings

## Overview

Sellers can configure which countries they ship to and provide an informational shipping cost. This enables marketplace filtering by "ships to my country."

## Changes to Player Entity

Extend the existing `SellSwapListSettings` value object (stored as JSON in the Player entity):

### Current Structure
```php
class SellSwapListSettings
{
    public ?string $description;
    public ?string $currency;
    public ?string $customCurrency;
    public ?string $shippingInfo;      // Free text shipping info
    public ?string $contactInfo;
}
```

### New Structure
```php
class SellSwapListSettings
{
    public ?string $description;
    public ?string $currency;
    public ?string $customCurrency;
    public ?string $shippingInfo;          // Free text (kept for backwards compat)
    public ?string $contactInfo;

    /** @var string[] Country codes the seller ships to */
    public array $shippingCountries = [];  // NEW: e.g., ['cz', 'sk', 'de', 'at']

    public ?string $shippingCost = null;   // NEW: Informational text, e.g., "€5 CZ, €8 EU, €12 worldwide"
}
```

## Implementation

### Settings Form

Extend `EditSellSwapListSettingsFormType` with:

1. **Shipping Countries**: Multi-select with checkboxes
   - Grouped by region for easier selection (e.g., "Central Europe", "Western Europe", etc.)
   - "Select all" / "Deselect all" buttons
   - Quick-select buttons for common groups: "EU countries", "Europe", "Worldwide"
   - Pre-populated with seller's own country on first setup

2. **Shipping Cost Info**: Text input
   - Informational only (not used for calculations)
   - Placeholder: "e.g., €5 domestic, €8 EU, €12 worldwide"

### Marketplace Query Integration

The `GetMarketplaceListings` query filters by shipping countries using JSON containment:

```sql
-- Filter: Only show sellers shipping to Czech Republic
WHERE sell_swap_list_settings->>'shippingCountries' IS NOT NULL
  AND sell_swap_list_settings->'shippingCountries' @> '"cz"'
```

Alternatively, since `SellSwapListSettings` is stored as JSON on the Player entity:

```sql
-- Using json_array_elements_text for compatibility
WHERE EXISTS (
    SELECT 1 FROM json_array_elements_text(
        player.sell_swap_list_settings->'shippingCountries'
    ) AS country
    WHERE country = :targetCountry
)
```

### Index Consideration

For performance with many listings, consider a GIN index on the shipping countries:

```sql
CREATE INDEX custom_player_shipping_countries_gin
ON player USING GIN ((sell_swap_list_settings->'shippingCountries') jsonb_path_ops);
```

This would be a custom index (prefix `custom_`) managed outside Doctrine, added to migration and `tests/bootstrap.php`.

## UI on Marketplace

### Filter
- "Ships to" dropdown pre-filled with logged-in user's country
- Shows country name with flag
- Option "All countries" to remove filter

### Listing Card
- Show seller's country flag (already available)
- Optionally show shipping cost info on hover or in detail view

### Seller Profile / Sell-Swap List Detail
- Display list of countries seller ships to (with flags)
- Show shipping cost info text

## Migration

No database migration needed — the `SellSwapListSettings` is stored as JSON in the Player entity's existing column. Adding new fields to the value object is backwards-compatible (existing records will have `null`/`[]` for new fields).

## Templates

Modify existing:
```
templates/sell-swap/edit_settings.html.twig    # Add shipping countries multi-select
templates/sell-swap/detail.html.twig           # Display shipping countries
```

## Testing

- Test saving and loading shipping countries
- Test marketplace filtering by shipping country
- Test backwards compatibility (existing settings without new fields)
- Test "ships to my country" pre-fill for logged-in users
