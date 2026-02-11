# Phase 7: Transaction Ratings & Reviews

Read the feature specification in `docs/features/marketplace/03-ratings.md` and the implementation plan Phase 7 in `docs/features/marketplace/10-implementation-plan.md`.

**Prerequisites**: Phase 5 (messaging) should be implemented first (for notification delivery), though not strictly blocking.

## Task

Implement mutual ratings and reviews after completed transactions. Both seller and buyer can rate each other with 1-5 stars and an optional text review. Ratings are visible on player profiles and in marketplace.

## Requirements

### 1. Create Value Objects

**`src/Value/TransactionRole.php`**:
```php
enum TransactionRole: string
{
    case Seller = 'seller';
    case Buyer = 'buyer';
}
```

### 2. Create Entity

**`src/Entity/TransactionRating.php`**:
- `id` (UUID, primary key)
- `soldSwappedItem` (ManyToOne SoldSwappedItem, immutable)
- `reviewer` (ManyToOne Player, immutable) — who is leaving the rating
- `reviewedPlayer` (ManyToOne Player, immutable) — who is being rated
- `stars` (smallint, 1-5, immutable)
- `reviewText` (text, nullable, max 500 chars, immutable)
- `ratedAt` (DateTimeImmutable, immutable)
- `reviewerRole` (TransactionRole enum, immutable)
- UniqueConstraint on `['sold_swapped_item_id', 'reviewer_id']` — one rating per person per transaction

### 3. Add Denormalized Rating Fields to Player

In `src/Entity/Player.php`, add:
```php
#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
#[Column(type: Types::INTEGER, options: ['default' => 0])]
public int $ratingCount = 0,

#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
#[Column(type: Types::DECIMAL, precision: 3, scale: 2, nullable: true)]
public null|string $averageRating = null,
```

Add method `updateRatingStats(int $count, ?string $average)`.

### 4. Generate Migration

Run `docker compose exec web php bin/console doctrine:migrations:diff`. DO NOT run the migration.

### 5. Create Notification Type

Add to `src/Value/NotificationType.php`:
```php
case RateYourTransaction = 'rate_your_transaction';
```

### 6. Create Message & Handler

**`src/Message/RateTransaction.php`**:
```php
readonly final class RateTransaction
{
    public function __construct(
        public string $soldSwappedItemId,
        public string $reviewerId,
        public int $stars,
        public null|string $reviewText = null,
    ) {
    }
}
```

**`src/MessageHandler/RateTransactionHandler.php`**:
1. Load `SoldSwappedItem` from repository
2. Determine reviewer role: if `reviewerId == seller.id` → Seller role; if `reviewerId == buyerPlayer.id` → Buyer role; otherwise throw exception
3. Verify `buyerPlayer` is not null (ratings only work with registered buyers)
4. Check no existing rating from this reviewer for this transaction
5. Check transaction `soldAt` is within 30 days
6. Create `TransactionRating`
7. Recalculate and update denormalized rating stats on the `reviewedPlayer`:
   - Query total count and average for the reviewed player
   - Call `$reviewedPlayer->updateRatingStats(count, average)`
8. Save everything

### 7. Create Event Handler for Rating Notification

**`src/MessageHandler/NotifyWhenTransactionCompleted.php`** (or add to existing `MarkPuzzleAsSoldOrSwapped` flow):
- When a puzzle is marked as sold/swapped with a registered buyer (`buyerPlayer` not null):
  - Create `Notification` for the seller with type `RateYourTransaction`
  - Create `Notification` for the buyer with type `RateYourTransaction`
- This requires adding a `targetSoldSwappedItem` relationship to the `Notification` entity (nullable ManyToOne to SoldSwappedItem), OR reusing an existing nullable target field, OR storing the reference differently. Check how existing notification types reference their targets and follow the same pattern.

### 8. Create Queries

**`src/Query/GetTransactionRatings.php`**:
- `forPlayer(string $playerId, int $limit = 20, int $offset = 0): array` — paginated ratings received by a player, newest first
- `averageForPlayer(string $playerId): ?PlayerRatingSummary` — returns average + count (or use denormalized fields)
- `canRate(string $soldSwappedItemId, string $playerId): bool` — checks if player is a participant, hasn't rated yet, and within 30-day window
- `pendingRatings(string $playerId): array` — transactions where this player is a participant but hasn't rated yet (within 30-day window)

### 9. Create Result DTOs

**`src/Results/TransactionRatingView.php`**:
- ratingId, reviewerName, reviewerCode, reviewerAvatar, reviewerCountry, reviewerRole
- stars, reviewText, ratedAt
- puzzleName, puzzlePiecesCount, transactionType (sell/swap)

**`src/Results/PlayerRatingSummary.php`**:
- averageRating (float), ratingCount (int)

### 10. Create Controllers

**`src/Controller/Rating/RateTransactionController.php`**:
- Route: GET+POST `/en/rate-transaction/{soldSwappedItemId}` (name: `rate_transaction`)
- Requires auth
- GET: verify `canRate()`, show form with puzzle context + star selector + text area
- POST: dispatch `RateTransaction`, redirect to sold/swapped history or ratings page with success flash

**`src/Controller/Rating/PlayerRatingsController.php`**:
- Route: GET `/en/player/{playerId}/ratings` (name: `player_ratings`)
- Public access (anyone can see ratings)
- Paginated list of ratings received by this player

### 11. Create Form Type

**`src/FormType/RateTransactionFormType.php`**:
- `stars` — ChoiceType, expanded (radio buttons), choices 1-5
- `reviewText` — TextareaType, required false, max 500 chars

### 12. Create Templates

**`templates/rating/rate_transaction.html.twig`**:
- Puzzle context: image, name, pieces, transaction type
- Star selector (5 radio buttons styled as stars, use CSS or a Stimulus controller for interactive star selection)
- Review text textarea
- Submit button

**`templates/rating/player_ratings.html.twig`**:
- Player header with rating summary (average stars + count)
- List of ratings, each showing: reviewer info, stars, review text, puzzle context, date
- Pagination

**`templates/rating/_rating_summary.html.twig`** (reusable partial):
- "4.7 ★ (23 ratings)" format
- Link to full ratings page
- Use on player profiles, marketplace cards, offers pages

**`templates/rating/_rating_stars.html.twig`** (reusable partial):
- Visual star display from a numeric rating
- Filled/empty stars using CSS or icons

### 13. Integrate with Player Profile

On the player profile page, show the rating summary partial if the player has ratings.

### 14. Integrate with Marketplace (if Phase 4 done)

In `templates/marketplace/_listing_card.html.twig`, show seller's rating summary (small, below seller name).
In `templates/sell-swap/offers.html.twig`, show seller's rating next to each offer.

### 15. Create Test Fixtures

**`tests/DataFixtures/TransactionRatingFixture.php`**:
- Create a few ratings for completed transactions (from `SoldSwappedItemFixture` if it exists, or create test SoldSwappedItems)
- Ratings from both seller and buyer perspectives

### 16. Write Tests

**`tests/MessageHandler/RateTransactionHandlerTest.php`**:
- Test seller can rate buyer
- Test buyer can rate seller
- Test non-participant cannot rate (exception)
- Test ratings only work with registered buyer (buyerPlayer not null)
- Test duplicate rating throws exception
- Test expired rating (>30 days) throws exception
- Test denormalized player stats are updated after rating

**`tests/Query/GetTransactionRatingsTest.php`**:
- Test forPlayer returns ratings received
- Test canRate returns correct boolean
- Test pendingRatings returns unrated transactions

**`tests/Controller/Rating/RateTransactionControllerTest.php`**:
- Test form loads for eligible transaction
- Test form rejects for already-rated transaction
- Test form submission creates rating

**`tests/Controller/Rating/PlayerRatingsControllerTest.php`**:
- Test page loads and shows ratings

### 17. Update Notification Template

If notifications link to the rating form, update the notification template/query to handle the new `RateYourTransaction` type — show puzzle context and link to the rating form.

### 18. Run Checks

```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```
