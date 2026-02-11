# Phase 5: Messaging System (Core)

Read the feature specification in `docs/features/marketplace/02-messaging.md` and the implementation plan Phase 5 in `docs/features/marketplace/10-implementation-plan.md`.

## Task

Implement the complete direct messaging system with first-contact approval flow. This is the largest phase — it includes new entities, migration, commands/handlers, queries, controllers, templates, and Mercure real-time integration.

## Requirements

### 1. Create Entities

All entities follow existing patterns: constructor-based with Doctrine attributes, `UuidInterface` IDs, `readonly` or `Immutable` properties.

**`src/Value/ConversationStatus.php`** (enum):
```php
enum ConversationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Denied = 'denied';
}
```

**`src/Entity/Conversation.php`**:
- `id` (UUID, primary key)
- `initiator` (ManyToOne Player, immutable) — who started the conversation
- `recipient` (ManyToOne Player, immutable) — who receives the request
- `sellSwapListItem` (ManyToOne SellSwapListItem, nullable, immutable) — null for general conversations
- `puzzle` (ManyToOne Puzzle, nullable, immutable) — denormalized from listing for persistence after sale
- `status` (ConversationStatus enum, mutable via methods)
- `createdAt` (DateTimeImmutable, immutable)
- `respondedAt` (DateTimeImmutable, nullable, mutable) — when accepted/denied
- `lastMessageAt` (DateTimeImmutable, nullable, mutable) — for sorting
- Add methods: `accept()`, `deny()`, `updateLastMessageAt()`
- Add UniqueConstraint on `['initiator_id', 'recipient_id', 'sell_swap_list_item_id']` — but note: for general conversations `sell_swap_list_item_id` will be null, so the uniqueness works differently. Consider whether you need a partial unique index or handle uniqueness in the handler logic instead.

**`src/Entity/Message.php`** (note: avoid conflict with Symfony Messenger's Message — use full namespace or name it `ChatMessage`):
- Consider naming this `ChatMessage` to avoid confusion with `src/Message/` (CQRS commands). Check if there's a naming conflict.
- `id` (UUID)
- `conversation` (ManyToOne Conversation)
- `sender` (ManyToOne Player)
- `content` (text, max 2000 characters)
- `sentAt` (DateTimeImmutable)
- `readAt` (DateTimeImmutable, nullable)
- Method: `markAsRead()`

**`src/Entity/UserBlock.php`**:
- `id` (UUID)
- `blocker` (ManyToOne Player)
- `blocked` (ManyToOne Player)
- `blockedAt` (DateTimeImmutable)
- UniqueConstraint on `['blocker_id', 'blocked_id']`

### 2. Add Player Entity Fields

Add to `src/Entity/Player.php`:
```php
#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
#[Column(type: Types::BOOLEAN, options: ['default' => true])]
public bool $allowDirectMessages = true,
```

Add to the constructor with default `true`. Add method `changeAllowDirectMessages(bool $allow)`.

Update the profile edit form (`src/FormType/EditProfileFormType.php`) to include a checkbox "Allow other users to message me directly".

Update `EditProfile` message and handler to handle the new field.

### 3. Generate Migration

Run `docker compose exec web php bin/console doctrine:migrations:diff`. Review the generated migration and add appropriate indexes manually:

```sql
CREATE INDEX idx_conversation_initiator ON conversation (initiator_id);
CREATE INDEX idx_conversation_recipient ON conversation (recipient_id);
CREATE INDEX idx_conversation_status ON conversation (status);
CREATE INDEX idx_conversation_last_message ON conversation (last_message_at DESC);
CREATE INDEX idx_chat_message_conversation ON chat_message (conversation_id, sent_at);
CREATE INDEX idx_chat_message_unread ON chat_message (conversation_id, sender_id, read_at) WHERE read_at IS NULL;
CREATE INDEX idx_user_block_blocker ON user_block (blocker_id);
```

DO NOT run the migration.

### 4. Create Mercure Test Double

If not already present, create `tests/TestDouble/NullMercureHub.php`:

```php
namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Contracts\Service\ResetInterface;

final class NullMercureHub implements HubInterface, ResetInterface
{
    /** @var Update[] */
    private array $publishedUpdates = [];

    public function publish(Update $update): string
    {
        $this->publishedUpdates[] = $update;
        return 'test-id';
    }

    public function getUrl(): string
    {
        return 'http://localhost/.well-known/mercure';
    }

    public function getPublicUrl(): string
    {
        return 'http://localhost/.well-known/mercure';
    }

    public function getProvider(): TokenProviderInterface
    {
        throw new \RuntimeException('Not implemented in test double');
    }

    /** @return Update[] */
    public function getPublishedUpdates(): array
    {
        return $this->publishedUpdates;
    }

    public function reset(): void
    {
        $this->publishedUpdates = [];
    }
}
```

Register in `config/services_test.php`:
```php
$services->set(HubInterface::class, NullMercureHub::class);
```

### 5. Create Messages (Commands)

All in `src/Message/`, all `readonly final class` with public constructor properties:

- **`StartConversation`**: `initiatorId`, `recipientId`, `sellSwapListItemId` (nullable string), `puzzleId` (nullable string), `initialMessage` (string)
- **`AcceptConversation`**: `conversationId`, `playerId`
- **`DenyConversation`**: `conversationId`, `playerId`
- **`SendMessage`**: `conversationId`, `senderId`, `content` (string)
- **`MarkMessagesAsRead`**: `conversationId`, `playerId`
- **`BlockUser`**: `blockerId`, `blockedId`
- **`UnblockUser`**: `blockerId`, `blockedId`

### 6. Create Handlers

All in `src/MessageHandler/`, all `#[AsMessageHandler] readonly final class` with `__invoke()`.

**`StartConversationHandler`**:
1. Load initiator and recipient players from repository
2. Check `UserBlock` — if initiator is blocked by recipient, throw exception
3. If `sellSwapListItemId` is null (general conversation): check recipient's `allowDirectMessages` — if false, throw exception
4. Check if an accepted conversation already exists between these two users (use query)
   - If yes and this is a marketplace conversation (sellSwapListItemId provided), create a new auto-accepted conversation
   - If yes and this is general, reuse existing conversation — just send the message
   - If a pending request already exists, throw `ConversationRequestAlreadyPending`
5. Create `Conversation` with status `pending` (or `accepted` if auto-accepted per above)
6. Create first `ChatMessage`
7. Update `conversation.lastMessageAt`
8. Publish Mercure update to `/conversations/{recipientId}` topic with conversation data

**`AcceptConversationHandler`**:
1. Load conversation, verify recipient matches playerId
2. Verify status is `pending`
3. Call `$conversation->accept()`
4. Publish Mercure update to `/conversations/{initiatorId}`

**`DenyConversationHandler`**:
1. Load conversation, verify recipient matches playerId
2. Verify status is `pending`
3. Call `$conversation->deny()`
4. Publish Mercure update to `/conversations/{initiatorId}`

**`SendMessageHandler`**:
1. Load conversation, verify status is `accepted`
2. Verify sender is a participant (initiator or recipient)
3. Check sender is not blocked by the other participant
4. Check sender is not messaging-muted (for Phase 8, add check but it can be a no-op for now — just leave the check placeholder)
5. Validate content length (max 2000 chars)
6. Create `ChatMessage`
7. Update `conversation.lastMessageAt`
8. Publish Mercure update to `/messages/{conversationId}` with message data
9. Publish Mercure update to `/unread-count/{recipientId}` with new unread count

**`MarkMessagesAsReadHandler`**:
1. Bulk update: set `readAt = now()` on all messages in conversation where `senderId != playerId` and `readAt IS NULL`
2. Publish Mercure update to `/unread-count/{playerId}` with updated count

**`BlockUserHandler`**:
1. Check block doesn't already exist
2. Create `UserBlock`

**`UnblockUserHandler`**:
1. Find and delete the `UserBlock`

### 7. Create Repositories

Create repositories for new entities following existing patterns:
- `ConversationRepository` with `get()`, `save()`, `findByParticipants()` methods
- `ChatMessageRepository` with `get()`, `save()` methods
- `UserBlockRepository` with `save()`, `remove()`, `findByBlockerAndBlocked()` methods

### 8. Create Queries

**`src/Query/GetConversations.php`**:
- `forPlayer(string $playerId, ?ConversationStatus $status = null): array` — returns conversations sorted by lastMessageAt DESC. Each result includes: conversation ID, other participant info (name, avatar, country, code), last message preview (truncated), unread count for this player, conversation status, puzzle context (if marketplace)
- `pendingRequestsForPlayer(string $playerId): array` — returns pending conversations where player is the recipient
- `countUnreadForPlayer(string $playerId): int` — count of accepted conversations with at least one unread message

**`src/Query/GetMessages.php`**:
- `forConversation(string $conversationId, int $limit = 50, int $offset = 0): array` — paginated messages, oldest first

**`src/Query/GetUserBlocks.php`**:
- `forPlayer(string $playerId): array` — list of blocked users
- `isBlocked(string $blockerId, string $blockedId): bool`

**`src/Query/HasExistingConversation.php`**:
- `acceptedBetween(string $playerAId, string $playerBId): bool` — check if accepted conversation exists (in either direction)

### 9. Create Result DTOs

**`src/Results/ConversationOverview.php`**: conversationId, otherPlayerName, otherPlayerCode, otherPlayerId, otherPlayerAvatar, otherPlayerCountry, lastMessagePreview, lastMessageAt, unreadCount, status, puzzleName (nullable), puzzleId (nullable), sellSwapListItemId (nullable)

**`src/Results/MessageView.php`**: messageId, senderId, senderName, senderAvatar, content, sentAt, readAt, isOwnMessage (computed based on viewer)

### 10. Create Controllers

All single-action with `__invoke()`, in `src/Controller/Messaging/`.

**`ConversationsListController`**:
- Route: GET `/en/messages` (name: `conversations_list`)
- Requires auth
- Gets conversations list and pending request count
- Template: `templates/messaging/conversations.html.twig`

**`ConversationDetailController`**:
- Route: GET `/en/messages/{conversationId}` (name: `conversation_detail`)
- Requires auth
- Verify current user is a participant
- Get messages for conversation
- Dispatch `MarkMessagesAsRead` automatically when viewing
- Template: `templates/messaging/conversation_detail.html.twig`

**`StartConversationController`**:
- Route: GET+POST `/en/messages/new/{recipientId}` (name: `start_conversation`)
- Requires auth + membership check
- GET: render form with recipient info
- POST: dispatch `StartConversation`, redirect to conversations list
- Template: `templates/messaging/start_conversation.html.twig`

**`StartMarketplaceConversationController`**:
- Route: GET+POST `/en/messages/new/offer/{sellSwapListItemId}` (name: `start_marketplace_conversation`)
- Requires auth + membership check
- GET: render form with puzzle/listing context
- POST: dispatch `StartConversation` with `sellSwapListItemId` and `puzzleId`
- Template: reuse `start_conversation.html.twig` with additional puzzle context

**`AcceptConversationController`**:
- Route: POST `/en/messages/{conversationId}/accept` (name: `accept_conversation`)
- Dispatch `AcceptConversation`, redirect back

**`DenyConversationController`**:
- Route: POST `/en/messages/{conversationId}/deny` (name: `deny_conversation`)
- Dispatch `DenyConversation`, redirect back

**`SendMessageController`**:
- Route: POST `/en/messages/{conversationId}/send` (name: `send_message`)
- Dispatch `SendMessage`, redirect to conversation detail
- Alternatively, use Turbo Stream response to append the new message without full reload

**`BlockUserController`**:
- Route: POST `/en/block-user/{playerId}` (name: `block_user`)
- Dispatch `BlockUser`, redirect back with flash

**`UnblockUserController`**:
- Route: POST `/en/unblock-user/{playerId}` (name: `unblock_user`)
- Dispatch `UnblockUser`, redirect back with flash

### 11. Create Mercure Publishing Service

Create `src/Services/MercureNotifier.php` (implements `ResetInterface` for FrankenPHP worker mode):
- Inject `HubInterface`
- Methods:
  - `notifyNewConversationRequest(Conversation)` — publishes to `/conversations/{recipientId}`
  - `notifyConversationAccepted(Conversation)` — publishes to `/conversations/{initiatorId}`
  - `notifyConversationDenied(Conversation)` — publishes to `/conversations/{initiatorId}`
  - `notifyNewMessage(ChatMessage)` — publishes to `/messages/{conversationId}`
  - `notifyUnreadCountChanged(string $playerId, int $count)` — publishes to `/unread-count/{playerId}`
- Each method creates an `Update` with JSON-encoded data and publishes via hub

### 12. Create Stimulus Controllers

**`assets/controllers/messaging_controller.js`**:
- Connects to Mercure EventSource for `/messages/{conversationId}` (on conversation detail page)
- On new message event: append message HTML to the messages container
- Auto-scroll to bottom on new message

**`assets/controllers/unread_badge_controller.js`**:
- Connects to Mercure EventSource for `/unread-count/{playerId}` (on all pages, attached to nav)
- On count change event: update the badge number in navigation
- Show/hide badge based on count

### 13. Create Templates

**`templates/messaging/conversations.html.twig`**:
- Page title "Messages"
- Tab: "Conversations" / "Requests (X)" where X is pending request count
- List of conversations, each showing: other user avatar+name+country, last message preview, time ago, unread badge
- Empty state if no conversations
- Link to user profiles

**`templates/messaging/conversation_detail.html.twig`**:
- Header with other user info and puzzle context (if marketplace)
- If pending and user is recipient: show accept/deny buttons, do NOT show messages
- If accepted: show message thread (bubbles, left for other, right for own) and send form at bottom
- If denied: show "This conversation was declined" message
- Message form: textarea + send button
- Block user button in dropdown/menu
- Wire up `messaging_controller` Stimulus controller for real-time updates

**`templates/messaging/start_conversation.html.twig`**:
- Recipient info display
- If marketplace: show puzzle image, name, listing type, price
- Message textarea
- Send button

**`templates/messaging/_conversation_list_item.html.twig`**:
- Avatar, name, country flag
- Last message preview (truncated ~80 chars)
- Time ago
- Unread count badge

**`templates/messaging/_message_bubble.html.twig`**:
- Message content
- Sender name (for received messages)
- Sent time
- Read indicator (checkmark or similar)
- Align left for received, right for sent

**`templates/messaging/_request_card.html.twig`**:
- "Puzzler X wants to message you" or "Puzzler X is interested in your puzzle Y"
- Puzzle thumbnail + details if marketplace
- Accept/Deny buttons (forms with POST)
- Time ago

### 14. Update Navigation

In `templates/base.html.twig`, add "Messages" link to the nav for authenticated users:
- Show unread count badge (fetch via `GetConversations::countUnreadForPlayer()`)
- Attach `unread_badge_controller` Stimulus controller for real-time updates
- Use Mercure `MERCURE_PUBLIC_URL` for EventSource URL — pass it as a data attribute

### 15. Update Player Profile Page

On other players' profile pages, show "Send message" button:
- Only if the player allows direct messages OR if there's already an existing conversation
- Link to `start_conversation` route

### 16. Create Exceptions

- `src/Exceptions/ConversationNotFound.php` — extends appropriate base, with `#[WithHttpStatus(404)]`
- `src/Exceptions/ConversationRequestAlreadyPending.php`
- `src/Exceptions/UserIsBlocked.php`
- `src/Exceptions/DirectMessagesDisabled.php`
- `src/Exceptions/MessagingMuted.php` (for future Phase 8)
- `src/Exceptions/ChatMessageNotFound.php`
- `src/Exceptions/UserBlockNotFound.php`

### 17. Create Test Fixtures

**`tests/DataFixtures/ConversationFixture.php`**:
- `CONVERSATION_ACCEPTED` — accepted conversation between PLAYER_REGULAR and PLAYER_ADMIN
- `CONVERSATION_PENDING` — pending request from PLAYER_WITH_STRIPE to PLAYER_REGULAR
- `CONVERSATION_MARKETPLACE` — marketplace conversation linked to a sell/swap item
- `CONVERSATION_DENIED` — denied conversation

**`tests/DataFixtures/ChatMessageFixture.php`**:
- Messages in the accepted conversation (at least 3-4 messages back and forth)
- Initial message in the pending conversation (not visible until accepted)
- Some messages with `readAt` set, some without (unread)

**`tests/DataFixtures/UserBlockFixture.php`**:
- `BLOCK_REGULAR_BLOCKS_PRIVATE` — PLAYER_REGULAR blocks PLAYER_PRIVATE

### 18. Write Tests

**`tests/MessageHandler/StartConversationHandlerTest.php`**:
- Test starting a general conversation creates pending conversation + message
- Test starting a marketplace conversation with sellSwapListItemId
- Test that starting when blocked throws exception
- Test that starting general conversation when recipient has `allowDirectMessages=false` throws exception
- Test that starting conversation with user who already has accepted conversation auto-accepts (for marketplace)
- Test that duplicate pending request throws exception

**`tests/MessageHandler/AcceptConversationHandlerTest.php`**:
- Test accepting changes status to accepted and sets respondedAt
- Test only recipient can accept

**`tests/MessageHandler/DenyConversationHandlerTest.php`**:
- Test denying changes status to denied
- Test only recipient can deny

**`tests/MessageHandler/SendMessageHandlerTest.php`**:
- Test sending message in accepted conversation creates message
- Test sending updates lastMessageAt
- Test cannot send in pending conversation
- Test cannot send when blocked

**`tests/MessageHandler/MarkMessagesAsReadHandlerTest.php`**:
- Test marks all unread messages from other participant as read
- Test does not mark own messages

**`tests/MessageHandler/BlockUserHandlerTest.php`**:
- Test blocking creates UserBlock
- Test duplicate block throws or is idempotent

**`tests/MessageHandler/UnblockUserHandlerTest.php`**:
- Test unblocking removes the block

**`tests/Query/GetConversationsTest.php`**:
- Test forPlayer returns conversations sorted by lastMessageAt
- Test pendingRequestsForPlayer returns only pending where player is recipient
- Test countUnreadForPlayer returns correct count

**`tests/Query/GetMessagesTest.php`**:
- Test returns messages for conversation in order
- Test pagination works

**`tests/Controller/Messaging/ConversationsListControllerTest.php`**:
- Test page loads for authenticated user
- Test redirects for anonymous user

**`tests/Controller/Messaging/ConversationDetailControllerTest.php`**:
- Test participant can view
- Test non-participant gets 403

### 19. Update tests/bootstrap.php

If the new entities require any custom indexes, add them to the `createCustomIndexes()` function. The partial index on `chat_message.read_at IS NULL` should be added there.

### 20. Run Checks

```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```
