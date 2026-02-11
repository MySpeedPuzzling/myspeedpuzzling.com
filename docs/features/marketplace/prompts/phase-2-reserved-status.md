# Phase 2: Reserved Status for Listings

Read the feature specification in `docs/features/marketplace/05-reserved-status.md` and the implementation plan Phase 2 in `docs/features/marketplace/10-implementation-plan.md`.

## Task

Implement the "reserved" status for sell/swap listings. Sellers should be able to mark a listing as "reserved" (to indicate negotiation in progress) and remove the reservation. Reserved items remain visible in lists but with a clear visual badge.

## Requirements

### 1. Update `SellSwapListItem` Entity

Add three new properties to `src/Entity/SellSwapListItem.php`:

```php
#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
#[Column(type: Types::BOOLEAN, options: ['default' => false])]
public bool $reserved = false,

#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
#[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
public null|DateTimeImmutable $reservedAt = null,

#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
#[Column(type: UuidType::NAME, nullable: true)]
public null|UuidInterface $reservedForPlayerId = null,
```

Add these to the constructor with default values. Add methods:

```php
public function markAsReserved(null|UuidInterface $reservedForPlayerId = null): void
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

### 2. Generate Migration

Run `docker compose exec web php bin/console doctrine:migrations:diff` to generate the migration for the new columns. DO NOT run the migration — leave that to me.

### 3. Create Messages

**`src/Message/MarkListingAsReserved.php`**:
```php
readonly final class MarkListingAsReserved
{
    public function __construct(
        public string $sellSwapListItemId,
        public string $playerId,
        public null|string $reservedForPlayerId = null,
    ) {
    }
}
```

**`src/Message/RemoveListingReservation.php`**:
```php
readonly final class RemoveListingReservation
{
    public function __construct(
        public string $sellSwapListItemId,
        public string $playerId,
    ) {
    }
}
```

### 4. Create Handlers

**`src/MessageHandler/MarkListingAsReservedHandler.php`**:
- Load `SellSwapListItem` from repository, throw `SellSwapListItemNotFound` if not found
- Verify `$message->playerId` matches the item's player ID — throw exception if not owner
- Call `$item->markAsReserved()`
- If `reservedForPlayerId` provided, pass it as UUID

**`src/MessageHandler/RemoveListingReservationHandler.php`**:
- Load `SellSwapListItem`, verify ownership
- Call `$item->removeReservation()`

### 5. Create Controllers

Both are single-action controllers with `__invoke()`, require `IS_AUTHENTICATED_FULLY`.

**`src/Controller/SellSwap/MarkAsReservedController.php`**:
- Route: `POST /en/sell-swap/{itemId}/reserve` (name: `sell_swap_mark_reserved`)
- Only English route is fine for now
- Dispatch `MarkListingAsReserved` with the current user's player ID
- Return a redirect back to the referrer or the sell/swap list

**`src/Controller/SellSwap/RemoveReservationController.php`**:
- Route: `POST /en/sell-swap/{itemId}/unreserve` (name: `sell_swap_remove_reservation`)
- Dispatch `RemoveListingReservation`
- Return a redirect back

### 6. Update Queries & Result DTOs

**`src/Query/GetSellSwapListItems.php`**: Add `reserved` (bool) and `reserved_at` to the SELECT in all relevant methods (`byPlayerId`, `byPuzzleId`).

**`src/Results/SellSwapListItemOverview.php`**: Add `public bool $reserved` property.

**`src/Results/PuzzlerOffer.php`**: Add `public bool $reserved` property.

### 7. Update Templates

**`templates/sell-swap/detail.html.twig`**: On each listing card, show a `<span class="badge bg-warning">RESERVED</span>` badge when the item is reserved. Add "Mark as reserved" and "Remove reservation" to the dropdown menu (conditionally based on current reserved state). Use a small `<form>` with POST method for each action.

### 8. Update Test Fixtures

In `tests/DataFixtures/SellSwapListItemFixture.php`, mark one or two existing items as reserved so tests and the UI can display them.

### 9. Write Tests

**`tests/MessageHandler/MarkListingAsReservedHandlerTest.php`**:
- Test marking an item as reserved succeeds (assert `reserved = true`, `reservedAt` is set)
- Test marking with `reservedForPlayerId`
- Test that non-owner cannot mark as reserved (expect exception)

**`tests/MessageHandler/RemoveListingReservationHandlerTest.php`**:
- Test removing reservation succeeds (assert `reserved = false`, `reservedAt = null`)
- Test that non-owner cannot remove reservation

**`tests/Query/GetSellSwapListItemsTest.php`** (update existing or create):
- Test that reserved status is included in query results

### 10. Run Checks

After all changes, run:
```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```

Fix any issues that arise.
