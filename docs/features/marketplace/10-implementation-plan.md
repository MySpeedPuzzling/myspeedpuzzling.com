# 10 - Implementation Plan (Step-by-Step)

## Overview

This document provides a detailed, ordered implementation guide. Each phase is independently deployable and testable. Dependencies between phases are noted.

---

## Phase 1: Infrastructure — Separate Mercure Container ✅ DONE

Mercure has already been separated from the FrankenPHP web container into its own dedicated Docker service. See `08-infrastructure.md` for reference documentation.

**Remaining task**: Create `NullMercureHub` test double if it doesn't exist yet (needed for Phase 5+ testing).

### Files (still needed if not present)
```
tests/TestDouble/NullMercureHub.php
config/services_test.yaml (or config/services_test.php)
```

---

## Phase 2: Reserved Status for Listings

**Goal**: Allow sellers to mark listings as "reserved."

**Why early**: Small, self-contained change. No dependencies on other phases. Adds immediate value to existing sell/swap lists.

### Steps

#### 2.1 Update Entity
- Add `reserved` (bool), `reservedAt` (datetime), `reservedForPlayerId` (uuid, nullable) to `SellSwapListItem`
- Add `markAsReserved()` and `removeReservation()` methods

#### 2.2 Generate Migration
- `docker compose exec web php bin/console doctrine:migrations:diff`
- Review generated migration, add columns with defaults

#### 2.3 Create Commands & Handlers
- `MarkListingAsReserved` message + handler
- `RemoveListingReservation` message + handler

#### 2.4 Create Controllers
- `MarkAsReservedController` — POST, dispatches command, returns Turbo Stream
- `RemoveReservationController` — POST, dispatches command, returns Turbo Stream

#### 2.5 Update Templates
- Add "Mark as reserved" / "Remove reservation" to sell/swap item dropdown
- Add "RESERVED" badge to listing card partial
- Update Turbo Stream responses

#### 2.6 Update Queries
- Include `reserved` status in `GetSellSwapListItems` result DTOs
- Update `SellSwapListItemOverview` and `PuzzlerOffer` with reserved field

#### 2.7 Add Test Fixtures
- Add reserved items to `SellSwapListItemFixture`

### Files
```
src/Entity/SellSwapListItem.php
src/Message/MarkListingAsReserved.php
src/MessageHandler/MarkListingAsReservedHandler.php
src/Message/RemoveListingReservation.php
src/MessageHandler/RemoveListingReservationHandler.php
src/Controller/SellSwap/MarkAsReservedController.php
src/Controller/SellSwap/RemoveReservationController.php
src/Query/GetSellSwapListItems.php
src/Results/SellSwapListItemOverview.php
src/Results/PuzzlerOffer.php
templates/sell-swap/detail.html.twig
templates/sell-swap/_stream.html.twig
tests/DataFixtures/SellSwapListItemFixture.php
migrations/VersionXXXX.php (generated)
```

### Tests
```
tests/MessageHandler/MarkListingAsReservedHandlerTest.php
tests/MessageHandler/RemoveListingReservationHandlerTest.php
tests/Controller/SellSwap/MarkAsReservedControllerTest.php
tests/Query/GetSellSwapListItemsTest.php (update existing)
```

---

## Phase 3: Shipping Settings

**Goal**: Allow sellers to configure shipping countries and cost information.

### Steps

#### 3.1 Extend SellSwapListSettings Value Object
- Add `shippingCountries` (array of country codes) and `shippingCost` (string) properties
- JSON-serializable, backwards-compatible (new fields default to `[]` / `null`)

#### 3.2 Update Settings Form
- Add multi-select for shipping countries to `EditSellSwapListSettingsFormType`
- Add shipping cost text input
- Use `CountryCode` enum for options, grouped by region
- Add Stimulus controller for "Select all EU" / "Select all" buttons

#### 3.3 Update Settings Template
- Display shipping countries and cost in sell/swap list detail page
- Add country grouping UI with quick-select buttons

#### 3.4 Update EditSellSwapListSettings Command & Handler
- Add new fields to the message
- Handler saves them in the JSON settings

#### 3.5 No Migration Needed
- `SellSwapListSettings` is stored as JSON — new fields are automatically handled

### Files
```
src/Value/SellSwapListSettings.php
src/FormType/EditSellSwapListSettingsFormType.php
src/Message/EditSellSwapListSettings.php
src/MessageHandler/EditSellSwapListSettingsHandler.php
templates/sell-swap/edit_settings.html.twig
templates/sell-swap/detail.html.twig
assets/controllers/shipping_countries_controller.js
```

### Tests
```
tests/MessageHandler/EditSellSwapListSettingsHandlerTest.php
tests/Value/SellSwapListSettingsTest.php (serialization/deserialization)
```

---

## Phase 4: Marketplace Page

**Goal**: Central marketplace browsing page with search, filtering, and sorting.

**Depends on**: Phase 2 (reserved status), Phase 3 (shipping settings/countries)

### Steps

#### 4.1 Create Marketplace Query
- `GetMarketplaceListings` with comprehensive filtering:
  - Text search (reuse trigram + unaccent logic from `SearchPuzzle`)
  - Manufacturer, piece count range, listing type, price range, condition
  - Ships-to country filter (JSON containment on seller settings)
  - Sorting: newest, price asc/desc, relevance
  - Pagination
- `MarketplaceListingItem` result DTO with all card data

#### 4.2 Create Live Component
- `MarketplaceListing` component
- `LiveProp` for each filter field
- Mount method reads from request query parameters
- Component re-renders on filter change
- Debounced search input

#### 4.3 Create URL Sync Stimulus Controller
- `marketplace_filter_controller.js`
- Syncs Live Component prop changes to `history.replaceState()`
- Reads URL params on page load to restore filter state
- Preserves filter state across page refreshes

#### 4.4 Create Controller
- `MarketplaceController` at `/en/marketplace`
- Renders the Live Component page

#### 4.5 Create Templates
- `templates/marketplace/index.html.twig` — page wrapper
- `templates/components/MarketplaceListing.html.twig` — Live Component template
- `templates/marketplace/_listing_card.html.twig` — individual card partial
- Filter bar with collapsible sections on mobile

#### 4.6 Add Navigation Link
- Add "Marketplace" to main nav in `base.html.twig`

### Files
```
src/Controller/Marketplace/MarketplaceController.php
src/Component/MarketplaceListing.php
src/Query/GetMarketplaceListings.php
src/Results/MarketplaceListingItem.php
templates/marketplace/index.html.twig
templates/components/MarketplaceListing.html.twig
templates/marketplace/_listing_card.html.twig
assets/controllers/marketplace_filter_controller.js
templates/base.html.twig (nav update)
```

### Tests
```
tests/Query/GetMarketplaceListingsTest.php
tests/Controller/Marketplace/MarketplaceControllerTest.php
tests/Component/MarketplaceListingTest.php
```

---

## Phase 5: Messaging System (Core)

**Goal**: Direct messaging between users with first-contact approval.

**Depends on**: Phase 1 (Mercure for real-time)

### Steps

#### 5.1 Create Entities
- `Conversation` entity with status (pending/accepted/denied)
- `Message` entity
- `UserBlock` entity
- `ConversationStatus` enum

#### 5.2 Generate Migration
- `docker compose exec web php bin/console doctrine:migrations:diff`
- Review and add appropriate indexes:
  - Index on `conversation(initiator_id, recipient_id)`
  - Index on `conversation(status)`
  - Index on `message(conversation_id, sent_at)`
  - Index on `message(read_at)` for unread queries
  - Index on `user_block(blocker_id, blocked_id)`

#### 5.3 Add Player Entity Fields
- `allowDirectMessages` (bool, default true)
- Extend profile edit form with new checkbox

#### 5.4 Create Commands & Handlers
- `StartConversation` + handler (with first-contact approval logic)
- `AcceptConversation` + handler
- `DenyConversation` + handler
- `SendMessage` + handler (with Mercure publishing)
- `MarkMessagesAsRead` + handler
- `BlockUser` + handler
- `UnblockUser` + handler

#### 5.5 Create Queries
- `GetConversations` (list, pending requests, unread counts)
- `GetMessages` (paginated messages for a conversation)
- `GetUserBlocks` (blocked users list, is-blocked check)
- `HasExistingConversation` (check between two users)

#### 5.6 Create Controllers
- `ConversationsListController` — list all conversations + pending requests
- `ConversationDetailController` — view a single conversation
- `StartConversationController` — general new conversation
- `StartMarketplaceConversationController` — marketplace-context new conversation
- `AcceptConversationController` — accept request (POST)
- `DenyConversationController` — deny request (POST)
- `SendMessageController` — send message (POST)
- `BlockUserController` — block user (POST)
- `UnblockUserController` — unblock user (POST)

#### 5.7 Create Templates
- Conversations list with tabs (all / requests)
- Conversation detail with message thread
- New conversation form
- Message request card (accept/deny)
- Message send form (input + submit)
- Message bubble partial

#### 5.8 Create Stimulus Controllers
- `messaging_controller.js` — Mercure SSE subscription for real-time messages
- `unread_badge_controller.js` — nav badge update via Mercure

#### 5.9 Mercure Integration
- Publish to `/conversations/{playerId}` on new request/status change
- Publish to `/messages/{conversationId}` on new message
- Publish to `/unread-count/{playerId}` on unread count change

#### 5.10 Add Navigation
- "Messages" link in nav with unread count badge
- Badge updates in real-time via Mercure

#### 5.11 Create Test Fixtures
- Test conversations in various states (pending, accepted, denied)
- Test messages in conversations
- Test blocked users

### Files
```
src/Entity/Conversation.php
src/Entity/Message.php
src/Entity/UserBlock.php
src/Value/ConversationStatus.php
src/Message/StartConversation.php (+ handler)
src/Message/AcceptConversation.php (+ handler)
src/Message/DenyConversation.php (+ handler)
src/Message/SendMessage.php (+ handler)
src/Message/MarkMessagesAsRead.php (+ handler)
src/Message/BlockUser.php (+ handler)
src/Message/UnblockUser.php (+ handler)
src/Query/GetConversations.php
src/Query/GetMessages.php
src/Query/GetUserBlocks.php
src/Query/HasExistingConversation.php
src/Results/ConversationOverview.php
src/Results/MessageView.php
src/Controller/Messaging/*.php (9 controllers)
templates/messaging/*.html.twig (8 templates)
assets/controllers/messaging_controller.js
assets/controllers/unread_badge_controller.js
tests/DataFixtures/ConversationFixture.php
tests/DataFixtures/MessageFixture.php
tests/DataFixtures/UserBlockFixture.php
migrations/VersionXXXX.php (generated)
```

### Tests
```
tests/MessageHandler/StartConversationHandlerTest.php
tests/MessageHandler/AcceptConversationHandlerTest.php
tests/MessageHandler/DenyConversationHandlerTest.php
tests/MessageHandler/SendMessageHandlerTest.php
tests/MessageHandler/BlockUserHandlerTest.php
tests/Query/GetConversationsTest.php
tests/Query/GetMessagesTest.php
tests/Query/GetUserBlocksTest.php
tests/Controller/Messaging/ConversationsListControllerTest.php
tests/Controller/Messaging/ConversationDetailControllerTest.php
tests/Controller/Messaging/StartConversationControllerTest.php
tests/Controller/Messaging/SendMessageControllerTest.php
```

---

## Phase 6: Marketplace ↔ Messaging Integration

**Goal**: Connect marketplace listings with the messaging system ("Contact seller" flow).

**Depends on**: Phase 4 (marketplace), Phase 5 (messaging)

### Steps

#### 6.1 Add "Contact Seller" Button
- On marketplace listing cards
- On puzzle offers page (each offer row)
- On puzzle detail page (offers preview section)
- Button links to `StartMarketplaceConversationController` with `sellSwapListItemId`

#### 6.2 Conversation Context Display
- When conversation is marketplace-initiated, show puzzle info in conversation header
- "Regarding: [Puzzle Image] [Puzzle Name] - [Listing Type] - [Price]"
- Link back to puzzle detail page

#### 6.3 Message Request Context
- For marketplace requests: "Puzzler X is interested in buying your puzzle Y"
- Show puzzle thumbnail and listing details in the request card
- Accept/deny buttons

#### 6.4 Seller Profile Integration
- On seller's profile/sell-swap list page, add "Send message" button
- Respects `allowDirectMessages` setting for non-marketplace messages

### Files
```
templates/marketplace/_listing_card.html.twig (add contact button)
templates/sell-swap/offers.html.twig (add contact button)
templates/puzzle_detail.html.twig (add contact button in offers preview)
templates/messaging/_request_card.html.twig (marketplace context)
templates/messaging/conversation_detail.html.twig (puzzle context header)
src/Controller/Messaging/StartMarketplaceConversationController.php (enhance)
```

### Tests
```
tests/Controller/Messaging/StartMarketplaceConversationControllerTest.php
```

---

## Phase 7: Transaction Ratings & Reviews

**Goal**: Mutual ratings after completed transactions.

**Depends on**: Phase 5 (messaging — for notification delivery, though not strictly required)

### Steps

#### 7.1 Create Entity
- `TransactionRating` with stars, review text, reviewer role, link to `SoldSwappedItem`
- `TransactionRole` enum (seller/buyer)

#### 7.2 Generate Migration

#### 7.3 Add Denormalized Fields to Player
- `ratingCount` (int, default 0)
- `averageRating` (decimal, nullable)

#### 7.4 Create Command & Handler
- `RateTransaction` + handler
- Handler validates: participant, no duplicate, within 30 days, buyer is registered player
- On rating: update denormalized averages on reviewed player

#### 7.5 Create Notification Type
- Add `RateYourTransaction` to `NotificationType` enum
- Create event handler to generate notification when transaction is completed

#### 7.6 Create Queries
- `GetTransactionRatings` — for player, average, can-rate check, pending ratings

#### 7.7 Create Controllers
- `RateTransactionController` — rating form
- `PlayerRatingsController` — view all ratings for a player

#### 7.8 Create Templates
- Rating form (star selector + text)
- Player ratings page
- Rating summary partial (for profile/marketplace)
- Star display partial (reusable)

#### 7.9 Integrate with Marketplace
- Show seller rating in marketplace listing cards
- Show seller rating on puzzle offers page
- Show rating on seller's profile

### Files
```
src/Entity/TransactionRating.php
src/Value/TransactionRole.php
src/Entity/Player.php (add rating fields)
src/Message/RateTransaction.php (+ handler)
src/Value/NotificationType.php (add new type)
src/MessageHandler/NotifyWhenTransactionCompleted.php
src/Query/GetTransactionRatings.php
src/Results/TransactionRatingView.php
src/Results/PlayerRatingSummary.php
src/Controller/Rating/RateTransactionController.php
src/Controller/Rating/PlayerRatingsController.php
templates/rating/*.html.twig
templates/marketplace/_listing_card.html.twig (add rating)
templates/sell-swap/offers.html.twig (add rating)
migrations/VersionXXXX.php (generated)
```

### Tests
```
tests/MessageHandler/RateTransactionHandlerTest.php
tests/Query/GetTransactionRatingsTest.php
tests/Controller/Rating/RateTransactionControllerTest.php
tests/Controller/Rating/PlayerRatingsControllerTest.php
```

---

## Phase 8: Admin Moderation

**Goal**: Full admin moderation suite for chat reports.

**Depends on**: Phase 5 (messaging)

### Steps

#### 8.1 Create Entities
- `ConversationReport` with status, reason, admin notes
- `ModerationAction` with action types and expiry
- `ReportStatus` enum
- `ModerationActionType` enum

#### 8.2 Add Player Moderation Fields
- `messagingMuted` (bool), `messagingMutedUntil` (datetime)
- `marketplaceBanned` (bool)

#### 8.3 Generate Migration

#### 8.4 Create Commands & Handlers
- `ReportConversation` + handler
- `ResolveReport` + handler
- `WarnUser` + handler
- `MuteUser` + handler
- `UnmuteUser` + handler
- `BanFromMarketplace` + handler
- `LiftMarketplaceBan` + handler
- `AdminRemoveListing` + handler

#### 8.5 Enforce Moderation in Existing Handlers
- `SendMessageHandler`: Check mute status before allowing
- `StartConversationHandler`: Check mute status
- `AddPuzzleToSellSwapListHandler`: Check marketplace ban

#### 8.6 Create Report Controller (User-Facing)
- `ReportConversationController` — POST, accessible to all authenticated users

#### 8.7 Create Admin Controllers
- Dashboard, report detail, conversation log, actions, history
- All require admin access

#### 8.8 Create Admin Templates
- Moderation dashboard with pending/all/history tabs
- Report detail with conversation log and action buttons
- User moderation history page

#### 8.9 Auto-Unmute Logic
- Add to the unread message cron or separate scheduler
- Expires mutes where `messagingMutedUntil < NOW()`

### Files
```
src/Entity/ConversationReport.php
src/Entity/ModerationAction.php
src/Value/ReportStatus.php
src/Value/ModerationActionType.php
src/Entity/Player.php (moderation fields)
src/Message/ReportConversation.php (+ handler)
src/Message/ResolveReport.php (+ handler)
src/Message/WarnUser.php (+ handler)
src/Message/MuteUser.php (+ handler)
src/Message/UnmuteUser.php (+ handler)
src/Message/BanFromMarketplace.php (+ handler)
src/Message/LiftMarketplaceBan.php (+ handler)
src/Message/AdminRemoveListing.php (+ handler)
src/Query/GetReports.php
src/Query/GetModerationActions.php
src/Query/GetConversationLog.php
src/Controller/Messaging/ReportConversationController.php
src/Controller/Admin/Moderation*.php (8 controllers)
templates/admin/moderation/*.html.twig
migrations/VersionXXXX.php (generated)
```

### Tests
```
tests/MessageHandler/ReportConversationHandlerTest.php
tests/MessageHandler/MuteUserHandlerTest.php
tests/MessageHandler/BanFromMarketplaceHandlerTest.php
tests/MessageHandler/SendMessageHandlerTest.php (update: test mute enforcement)
tests/Query/GetReportsTest.php
tests/Controller/Admin/ModerationDashboardControllerTest.php
```

---

## Phase 9: Email Notification Cron

**Goal**: Send email notifications about unread messages (12h delay, no duplicates).

**Depends on**: Phase 5 (messaging)

### Steps

#### 9.1 Create Entity
- `MessageNotificationLog` — tracks sent notifications per player

#### 9.2 Add Player Setting
- `emailNotificationsEnabled` (bool, default true)
- Add to profile edit form

#### 9.3 Generate Migration

#### 9.4 Create Console Command
- `SendUnreadMessageNotificationsCommand`
- Finds players with unread messages > 12h
- Checks notification log to avoid duplicates
- Sends grouped email (count per sender)
- Logs sent notifications

#### 9.5 Create Email Template
- `templates/emails/unread_messages.html.twig`
- Subject: "You have unread messages on MySpeedPuzzling"
- Body: count per sender, link to messages page
- Unsubscribe link (points to profile settings)

#### 9.6 Configure Scheduler
- Either Symfony Scheduler or cron entry
- Run every hour (command handles 12h delay internally)

#### 9.7 Integrate Auto-Unmute
- Add expired mute cleanup to the same command or a separate one

### Files
```
src/Entity/MessageNotificationLog.php
src/Entity/Player.php (add emailNotificationsEnabled)
src/ConsoleCommands/SendUnreadMessageNotificationsCommand.php
src/Query/GetPlayersWithUnreadMessages.php
templates/emails/unread_messages.html.twig
migrations/VersionXXXX.php (generated)
```

### Tests
```
tests/ConsoleCommands/SendUnreadMessageNotificationsCommandTest.php
tests/Query/GetPlayersWithUnreadMessagesTest.php
```

---

## Phase 10: Puzzle Detail & List Integration

**Goal**: Integrate offer counts into puzzle detail pages and puzzle list dropdowns.

**Depends on**: Phase 4 (marketplace)

### Steps

#### 10.1 Enhance Puzzle Detail Page
- Add `offers_preview` (first 3 offers) to `PuzzleDetailController`
- Add "Contact seller" buttons to each offer
- Show seller ratings (if Phase 7 complete)

#### 10.2 Add Batch Count Query
- `GetSellSwapListItems::countByPuzzleIds(array $puzzleIds): array`
- Single query for all puzzle IDs on a page

#### 10.3 Update Puzzle List Controllers/Components
- Pass offer counts to templates in puzzle search results, collection items, etc.
- Add "Offers (X)" to puzzle card dropdowns

#### 10.4 Enhance Puzzle Offers Page
- Add "Contact seller" button per offer
- Show reserved badges
- Show seller ratings
- Show shipping info
- Add "View on marketplace" backlink

### Files
```
src/Controller/PuzzleDetailController.php
src/Query/GetSellSwapListItems.php (add countByPuzzleIds)
templates/puzzle_detail.html.twig
templates/sell-swap/offers.html.twig
templates/_partials/puzzle_card_dropdown.html.twig (or equivalent shared partial)
```

### Tests
```
tests/Query/GetSellSwapListItemsTest.php (update: test countByPuzzleIds)
tests/Controller/PuzzleDetailControllerTest.php (update: test offers preview)
```

---

## Phase Summary & Dependencies

```
Phase 1: Infrastructure (Mercure separation)     ─── ✅ DONE
Phase 2: Reserved Status                          ─── no dependencies
Phase 3: Shipping Settings                        ─── no dependencies
Phase 4: Marketplace Page                         ─── depends on Phase 2, 3
Phase 5: Messaging System (Core)                  ─── no dependencies (Mercure already separated)
Phase 6: Marketplace ↔ Messaging Integration      ─── depends on Phase 4, 5
Phase 7: Transaction Ratings                      ─── depends on Phase 5 (loosely)
Phase 8: Admin Moderation                         ─── depends on Phase 5
Phase 9: Email Notification Cron                  ─── depends on Phase 5
Phase 10: Puzzle Detail Integration               ─── depends on Phase 4
```

### Parallel Tracks

These phases can proceed in parallel:

**Track A (Marketplace)**: Phase 2 → Phase 3 → Phase 4 → Phase 10
**Track B (Messaging)**: Phase 5 → Phase 6, Phase 7, Phase 8, Phase 9 (after Phase 5, these can be parallel)

### Suggested Implementation Order

1. **Phase 2** (Reserved) — Quick win, small scope
2. **Phase 3** (Shipping) — Small scope, needed for marketplace
3. **Phase 5** (Messaging Core) — Large phase, start early
4. **Phase 4** (Marketplace Page) — Depends on 2 & 3
5. **Phase 6** (Marketplace ↔ Messaging) — Connects 4 & 5
6. **Phase 10** (Puzzle Integration) — Enhances existing pages
7. **Phase 7** (Ratings) — Nice-to-have, adds trust
8. **Phase 8** (Admin Moderation) — Important but can follow messaging
9. **Phase 9** (Email Cron) — Final piece, polishes the experience

---

## Quality Checklist (per phase)

After each phase, run:
```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```

And verify:
- [ ] All new entities have proper Doctrine mapping
- [ ] All new controllers are single-action with `__invoke()`
- [ ] All new IDs use `Uuid::uuid7()`
- [ ] All services with cached state implement `ResetInterface`
- [ ] All new features have corresponding tests
- [ ] All new user-facing text uses only English (translations added later)
- [ ] Mercure publishers use the NullMercureHub in tests
- [ ] Database schema validates
- [ ] PHPStan passes at max level
- [ ] Coding standards pass
