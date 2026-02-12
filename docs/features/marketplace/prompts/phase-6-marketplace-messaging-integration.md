# Phase 6: Marketplace ↔ Messaging Integration

Read the feature specification in `docs/features/marketplace/02-messaging.md` (marketplace-initiated conversation flow) and the implementation plan Phase 6 in `docs/features/marketplace/10-implementation-plan.md`.

**Prerequisites**: Phase 4 (marketplace page) and Phase 5 (messaging core) must be implemented first.

## Task

Wire up the marketplace and messaging systems: add "Contact seller" buttons, show puzzle context in conversations, and add "Send message" to player profiles.

## Requirements

### 1. Add "Contact Seller" Button to Marketplace Cards

In `templates/marketplace/_listing_card.html.twig`:
- Add a "Contact seller" button/link on each card
- Links to `start_marketplace_conversation` route with `sellSwapListItemId`
- Only show for authenticated users (no membership required — any registered user can contact sellers)
- Don't show on own listings
- Small button or icon, doesn't dominate the card

### 2. Add "Contact Seller" to Puzzle Offers Page

In `templates/sell-swap/offers.html.twig`:
- Add "Contact seller" button in each offer row
- Same route and conditions as above

### 3. Add "Contact Seller" to Puzzle Detail Page

In `templates/puzzle_detail.html.twig`, in the offers section:
- If offers are shown, add a "Contact" button next to each offer preview
- Links to `start_marketplace_conversation` route

### 4. Enhance Marketplace Conversation Context

In `templates/messaging/conversation_detail.html.twig`:
- When conversation has puzzle context (`puzzleId` and `puzzleName` are set):
  - Show a header card with puzzle image thumbnail, name, piece count, listing type, price
  - Link the puzzle name to the puzzle detail page
  - Show "Regarding:" label

In `templates/messaging/_request_card.html.twig`:
- For marketplace requests, show: "Puzzler {name} is interested in buying your puzzle {puzzleName}"
- Display puzzle thumbnail, listing type badge, price
- For general requests: "Puzzler {name} wants to message you"

### 5. Enhance StartMarketplaceConversationController

In `src/Controller/Messaging/StartMarketplaceConversationController.php`:
- On GET: load the `SellSwapListItem` and related puzzle data
- Pass puzzle context (name, image, pieces, listing type, price, seller name) to the template
- Pre-fill a message context like "Hi, I'm interested in your puzzle [name]"
- Prevent contacting yourself (redirect with flash if seller == current user)

### 6. Add "Send Message" to Player Profiles

On the player profile page template:
- Add "Send message" button for authenticated users
- Only show if:
  - The profile user allows direct messages (`allowDirectMessages = true`), OR
  - There's already an accepted conversation between the two users
- Links to `start_conversation` route with `recipientId`
- Don't show on own profile

To check if conversation exists, add a lightweight query call in the profile controller or pass a flag.

### 7. Update Conversation Queries for Puzzle Context

Ensure `GetConversations::forPlayer()` includes puzzle data (name, image, id) in the result DTO so conversation list items can show "Re: Puzzle Name" context.

### 8. Write Tests

**`tests/Controller/Messaging/StartMarketplaceConversationControllerTest.php`**:
- Test page loads with sell/swap item context
- Test cannot contact yourself
- Test requires authentication
- Test submitting creates conversation with puzzle context

### 9. Run Checks

```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```
