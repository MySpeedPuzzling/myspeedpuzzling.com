# 01 - Marketplace Browsing & Search

## Overview

A centralized marketplace page where all active sell/swap listings are displayed. Users can search, filter, and sort to find puzzles they want to buy, swap, or get for free.

## Access Rules

- **Browsing/searching**: Available to all users (including anonymous)
- **Creating listings**: Requires active membership (existing behavior)
- **Contacting sellers (chat)**: Available to all registered users (no membership required)
- **Viewing seller profiles**: Public (existing behavior)

## URL Structure

```
/en/marketplace                          # Main marketplace page
/en/marketplace?search=ravensburger&pieces_min=1000  # With filters in query string
/en/puzzle/{puzzleId}/offers             # All offers for a specific puzzle (existing route, currently /en/sell-swap-offers/{puzzleId})
```

## Search & Filtering

### Filter Fields

All filters are persisted in the URL query string so users don't lose their search when refreshing.

| Filter | Type | Description |
|--------|------|-------------|
| `search` | Text input | Free-text search across puzzle name, alternative name, EAN, identification number (reuse `SearchPuzzle` scoring logic) |
| `manufacturer` | Select dropdown | Filter by manufacturer/brand (populated from manufacturers that have active listings) |
| `pieces_min` | Number input | Minimum piece count |
| `pieces_max` | Number input | Maximum piece count |
| `listing_type` | Select | Sell / Swap / Both / Free / All |
| `price_min` | Number input | Minimum price |
| `price_max` | Number input | Maximum price |
| `condition` | Select | Like New / Normal / Not So Good / Missing Pieces / All |
| `ships_to` | Select (country) | Only show sellers who ship to selected country. Pre-filled with logged-in user's country |
| `sort` | Select | Newest first (default), Price low→high, Price high→low, Search relevance (when search term present) |
| `difficulty[]` | Chips (members) | Difficulty tiers, `0` = not rated yet - members only, ignored for anybody else; members also see the tier on each card's image (2026-10-03, `docs/features/list-difficulty-and-my-list-filter.md`) |

### Implementation: Symfony UX Live Component

**Component**: `MarketplaceListing` (extends `AbstractController`, uses `DefaultActionTrait`)

```
src/Component/MarketplaceListing.php
templates/components/MarketplaceListing.html.twig
```

**Behavior**:
- All `#[LiveProp]` properties map to URL query parameters
- On mount, read query parameters from the request to restore filter state
- URL is updated via Stimulus controller that syncs LiveProp changes to `history.replaceState()`
- Debounced text search (300ms) to avoid excessive re-renders
- Pagination with "Load more" button or traditional pagination (consistent with existing patterns)

**Query**: New `GetMarketplaceListings` query class

```
src/Query/GetMarketplaceListings.php
src/Results/MarketplaceListingItem.php
```

The query joins:
- `sell_swap_list_item` (active listings)
- `puzzle` (puzzle details)
- `manufacturer` (brand name)
- `player` (seller info, country, avatar)
- Player's `sell_swap_list_settings` JSON (currency, shipping countries)

Filtering by `ships_to` uses a JSON containment check on the seller's shipping countries setting (new field, see [04-shipping-settings.md](04-shipping-settings.md)).

### Performance Considerations

- Index on `sell_swap_list_item.listing_type` for type filtering
- Reuse existing trigram GIN indexes on puzzle name for text search
- The query should use the same `immutable_unaccent()` + trigram matching as `SearchPuzzle`
- Pagination: 24 items per page (grid of 4x6 or 3x8 depending on screen)
- Count query separate for pagination header ("Showing X of Y results")

## UI Design

### Marketplace Page Layout

```
┌─────────────────────────────────────────────────────┐
│ Marketplace                                    [🔍] │
├─────────────────────────────────────────────────────┤
│ Filters bar (collapsible on mobile):                │
│ [Search...] [Brand ▼] [Pieces: min-max]            │
│ [Type ▼] [Price: min-max] [Condition ▼]            │
│ [Ships to ▼] [Sort: Newest ▼]                      │
├─────────────────────────────────────────────────────┤
│ Showing 142 results                                 │
├─────────────────────────────────────────────────────┤
│ ┌──────┐ ┌──────┐ ┌──────┐ ┌──────┐               │
│ │Puzzle│ │Puzzle│ │Puzzle│ │Puzzle│               │
│ │Image │ │Image │ │Image │ │Image │               │
│ │      │ │      │ │      │ │      │               │
│ │Name  │ │Name  │ │Name  │ │Name  │               │
│ │1000pc│ │500pc │ │2000pc│ │1500pc│               │
│ │€25   │ │Swap  │ │€15   │ │FREE  │               │
│ │Seller│ │Seller│ │Seller│ │Seller│ RESERVED      │
│ │🇨🇿   │ │🇩🇪   │ │🇬🇧   │ │🇫🇷   │ badge         │
│ └──────┘ └──────┘ └──────┘ └──────┘               │
│ ...                                                 │
│           [Load more] or [1 2 3 ... 6]             │
└─────────────────────────────────────────────────────┘
```

### Listing Card Content

Each card shows:
- Puzzle image (thumbnail)
- Puzzle name (truncated if long)
- Piece count + manufacturer badge
- Listing type badge (Sell / Swap / Both / Free) — use existing badge styles
- Price (if selling) with seller's currency
- Condition badge
- Seller name + country flag
- **"RESERVED"** badge overlay if marked as reserved (see [05-reserved-status.md](05-reserved-status.md))
- Click → goes to puzzle detail page (with offers section highlighted) or directly to the offer detail

### Navigation Integration

Add "Marketplace" link to the main navigation menu, prominently placed.

## Controller

**`MarketplaceController`** (`src/Controller/Marketplace/MarketplaceController.php`)
- Route: `/en/marketplace`
- Single action controller with `__invoke()`
- Renders the Live Component with initial filter state from query parameters

## Related Files to Create

```
src/Controller/Marketplace/MarketplaceController.php
src/Component/MarketplaceListing.php
src/Query/GetMarketplaceListings.php
src/Results/MarketplaceListingItem.php
templates/marketplace/index.html.twig
templates/components/MarketplaceListing.html.twig
templates/marketplace/_listing_card.html.twig
assets/controllers/marketplace_filter_controller.js  (URL sync)
```
