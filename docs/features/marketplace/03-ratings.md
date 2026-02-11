# 03 - Transaction Ratings & Reviews

## Overview

After a transaction is completed (puzzle marked as sold/swapped), both the seller and the buyer can rate each other with a star rating and optional text review. Ratings build trust and credibility in the marketplace.

## Rating Flow

1. Seller marks a puzzle as sold/swapped (existing `MarkPuzzleAsSoldOrSwapped` flow)
2. If the buyer is a registered player (identified by player code `#xxx`), both parties can leave a rating
3. Each party receives a notification: "Rate your transaction with Puzzler X for puzzle Y"
4. Rating window: 30 days after the transaction is marked as completed
5. Ratings are visible on user profiles

## Entity

### `TransactionRating`

```php
#[ORM\Entity]
#[ORM\Table(name: 'transaction_rating')]
#[ORM\UniqueConstraint(columns: ['sold_swapped_item_id', 'reviewer_id'])]
class TransactionRating
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: SoldSwappedItem::class)]
    private SoldSwappedItem $soldSwappedItem;  // The completed transaction

    #[ORM\ManyToOne(targetEntity: Player::class)]
    private Player $reviewer;                   // Who is leaving the rating

    #[ORM\ManyToOne(targetEntity: Player::class)]
    private Player $reviewedPlayer;             // Who is being rated

    #[ORM\Column(type: 'smallint')]
    private int $stars;                          // 1-5

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reviewText = null;          // Optional text review (max 500 chars)

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $ratedAt;

    #[ORM\Column(type: 'string', enumType: TransactionRole::class)]
    private TransactionRole $reviewerRole;       // buyer or seller
}
```

### `TransactionRole` enum

```php
enum TransactionRole: string
{
    case Seller = 'seller';
    case Buyer = 'buyer';
}
```

## Changes to Existing Entities

### `SoldSwappedItem`

The existing entity needs a reference to the buyer as a Player (currently has `buyerPlayer` - already exists!). No changes needed to the entity, it already tracks:
- `seller` (Player)
- `buyerPlayer` (nullable Player) — set when buyer identified by player code
- `buyerName` (nullable string) — free text fallback

Ratings are only possible when `buyerPlayer` is not null (both parties are registered users).

## CQRS Commands

| Command | Properties | Description |
|---------|-----------|-------------|
| `RateTransaction` | `soldSwappedItemId`, `reviewerId`, `stars`, `reviewText?` | Leave a rating for a transaction |

### `RateTransactionHandler` Logic

1. Load `SoldSwappedItem` and verify it exists
2. Determine reviewer role (seller or buyer) based on `reviewerId`
3. Verify reviewer is a participant (either seller or buyer)
4. Verify `buyerPlayer` is not null (both must be registered)
5. Verify no existing rating from this reviewer for this transaction
6. Verify transaction is not older than 30 days
7. Create `TransactionRating`
8. Emit `TransactionRated` domain event

## Queries

| Query | Method | Returns |
|-------|--------|---------|
| `GetTransactionRatings` | `forPlayer(playerId, limit, offset)` | Paginated ratings received by a player |
| `GetTransactionRatings` | `averageForPlayer(playerId)` | Average star rating + total count |
| `GetTransactionRatings` | `canRate(soldSwappedItemId, playerId)` | Whether player can still rate this transaction |
| `GetTransactionRatings` | `pendingRatings(playerId)` | Transactions the player hasn't rated yet |

## Average Rating Calculation

Display format: "4.7 ★ (23 ratings)"

The average is computed from all received ratings. Consider caching this on the Player entity or in a materialized query for performance (player profile pages will query this frequently).

Option: Add denormalized fields to Player:
```php
#[Column(type: 'integer', options: ['default' => 0])]
private int $ratingCount = 0;

#[Column(type: 'decimal', precision: 3, scale: 2, nullable: true)]
private ?string $averageRating = null;
```

Update via event handler when `TransactionRated` event fires.

## Controllers

```
src/Controller/Rating/RateTransactionController.php     # GET+POST /en/rate-transaction/{soldSwappedItemId}
src/Controller/Rating/PlayerRatingsController.php       # GET /en/player/{playerId}/ratings
```

## UI

### Rating Form (modal)

Appears after marking as sold, or accessible from sold history and notifications.

```
┌──────────────────────────────────────┐
│ Rate your transaction                │
│                                      │
│ 🧩 Ravensburger Sunset 1000pc       │
│ Sold to: Puzzler Jane               │
│                                      │
│ Rating: ★★★★☆  (click to select)    │
│                                      │
│ Review (optional):                   │
│ ┌──────────────────────────────────┐ │
│ │ Great communication, fast        │ │
│ │ shipping, puzzle in perfect...   │ │
│ └──────────────────────────────────┘ │
│                                      │
│ [Submit Rating]                      │
└──────────────────────────────────────┘
```

### Profile Rating Display

On player profile, show:
- Average rating with star visualization
- Total number of ratings
- Link to "View all ratings" page

### Ratings Page

List of all ratings received, showing:
- Reviewer name + avatar
- Star rating
- Review text (if provided)
- Transaction context (puzzle name, type)
- Date

## Marketplace Integration

In marketplace listing cards and seller info, show:
- Seller's average rating (if they have ratings)
- Number of completed transactions

## Notifications

When a transaction is completed with a registered buyer:
- Both parties get a notification (use existing `Notification` entity with new `NotificationType`)
- New notification types: `RateYourTransaction`
- Notification links to the rating form

## Templates

```
templates/rating/rate_transaction.html.twig    # Rating form
templates/rating/player_ratings.html.twig      # All ratings for a player
templates/rating/_rating_card.html.twig        # Single rating display
templates/rating/_rating_stars.html.twig       # Star display partial (reusable)
templates/rating/_rating_summary.html.twig     # Average + count summary (for profile/marketplace)
```

## Testing

- Test rating creation (both seller and buyer perspectives)
- Test duplicate rating prevention
- Test 30-day window enforcement
- Test that ratings only work with registered buyer (`buyerPlayer` not null)
- Test average calculation accuracy
- Test denormalized counter updates
- Fixture: Create completed transactions with and without ratings
