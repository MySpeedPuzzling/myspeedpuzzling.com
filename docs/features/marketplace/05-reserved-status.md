# 05 - Reserved Listing Status

## Overview

Sellers can mark a listing as "reserved" to indicate that a negotiation is in progress. The puzzle remains visible in the marketplace but with a clear visual indicator.

## Entity Changes

### `SellSwapListItem`

Add a new property:

```php
#[Column(type: Types::BOOLEAN, options: ['default' => false])]
private bool $reserved = false;

#[Column(type: 'datetimetz_immutable', nullable: true)]
private ?DateTimeImmutable $reservedAt = null;

#[Column(type: 'uuid', nullable: true)]
private ?UuidInterface $reservedForPlayerId = null;  // Optional: track who it's reserved for

public function markAsReserved(?UuidInterface $reservedForPlayerId = null): void
{
    $this->reserved = true;
    $this->reservedAt = new DateTimeImmutable();
    $this->reservedForPlayerId = $reservedForPlayerId;
}

public function removeReservation(): void
{
    $this->reserved = false;
    $this->reservedAt = null;
    $this->reservedForPlayerId = null;
}
```

## CQRS Commands

| Command | Properties | Description |
|---------|-----------|-------------|
| `MarkListingAsReserved` | `sellSwapListItemId`, `playerId`, `reservedForPlayerId?` | Mark listing as reserved |
| `RemoveListingReservation` | `sellSwapListItemId`, `playerId` | Remove reserved status |

### Handler Logic

**`MarkListingAsReservedHandler`**:
1. Load `SellSwapListItem`
2. Verify the player is the owner
3. Call `markAsReserved()`
4. Emit `ListingReserved` domain event (for potential Mercure notification)

**`RemoveListingReservationHandler`**:
1. Load `SellSwapListItem`
2. Verify the player is the owner
3. Call `removeReservation()`

## Controllers

```
src/Controller/SellSwap/MarkAsReservedController.php      # POST /en/sell-swap/{itemId}/reserve
src/Controller/SellSwap/RemoveReservationController.php    # POST /en/sell-swap/{itemId}/unreserve
```

Both are single-action POST controllers that dispatch the command and return a Turbo Stream response to update the UI.

## UI

### Marketplace Listing Card

Reserved items show:
- Semi-transparent overlay on the puzzle image
- "RESERVED" badge (Bootstrap `badge bg-warning`) in the top corner
- Item is still visible and clickable, but clearly marked

### Seller's Own List

On the seller's sell/swap list, reserved items show:
- "RESERVED" badge
- "Remove reservation" button in the dropdown menu
- "Mark as sold" button still available

### Sell/Swap Item Dropdown Menu

Add to existing dropdown:
```
- Edit
- Mark as reserved       (if not reserved)
- Remove reservation     (if reserved)
- Mark as sold/swapped
- Remove from list
```

## Migration

Database migration needed to add:
- `reserved` boolean column (default false)
- `reserved_at` timestamp column (nullable)
- `reserved_for_player_id` uuid column (nullable, foreign key to player)

## Marketplace Query

The `GetMarketplaceListings` query includes reserved status in results so the UI can display the badge. Reserved items are NOT hidden from search results — they remain visible but marked.

## Templates

Modify existing:
```
templates/sell-swap/detail.html.twig          # Show reserved badge on items
templates/sell-swap/_stream.html.twig         # Turbo stream for reserve/unreserve
templates/marketplace/_listing_card.html.twig # Reserved badge overlay
```

## Testing

- Test marking as reserved and removing reservation
- Test only owner can reserve
- Test reserved status visible in marketplace and sell/swap list queries
- Test reserved items still appear in search results (not hidden)
- Fixture: Create some reserved items for marketplace display testing
