# 02 - Messaging System

## Overview

A direct messaging system between users with first-contact approval (similar to Instagram's message requests). Works both within the marketplace context (buyer contacts seller about a specific puzzle) and as general user-to-user messaging.

## Core Concepts

### Conversation

A conversation is a thread between exactly two users. It can be:
- **Marketplace-initiated**: Linked to a specific `SellSwapListItem` (buyer interested in a puzzle)
- **General**: User-to-user conversation not linked to any listing

### First Contact Approval

When User A messages User B for the first time:
1. User B sees a **message request** (not the actual messages)
2. The request shows: "Puzzler X is interested in your puzzle Y" (marketplace) or "Puzzler X wants to message you" (general)
3. User B can **Accept** or **Deny** the request
4. If accepted: conversation opens, all messages become visible, both users can chat freely going forward
5. If denied: conversation is rejected, User A is notified their request was denied
6. Once two users have an accepted conversation, future marketplace conversations between them are auto-accepted

### Contactability Setting

Users can configure whether they can be contacted outside of marketplace context:
- **Setting**: `allowDirectMessages` (boolean, default: `true`) on Player entity
- When disabled (`false`): Other users cannot initiate general conversations with this user
- Marketplace conversations always work regardless of this setting (sellers must be contactable for their listings)
- If User A has contacted User B first (regardless of setting), User B can always reply

## Entities

### `Conversation`

```php
#[ORM\Entity]
#[ORM\Table(name: 'conversation')]
#[ORM\UniqueConstraint(columns: ['initiator_id', 'recipient_id', 'sell_swap_list_item_id'])]
class Conversation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    private Player $initiator;           // User who started the conversation

    #[ORM\ManyToOne(targetEntity: Player::class)]
    private Player $recipient;           // User who receives the request

    #[ORM\ManyToOne(targetEntity: SellSwapListItem::class)]
    private ?SellSwapListItem $sellSwapListItem = null;  // Nullable: null for general conversations

    #[ORM\ManyToOne(targetEntity: Puzzle::class)]
    private ?Puzzle $puzzle = null;      // Denormalized from sellSwapListItem for queries after item is sold

    #[ORM\Column(type: 'string', enumType: ConversationStatus::class)]
    private ConversationStatus $status;  // pending, accepted, denied

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $respondedAt = null;  // When accepted/denied

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $lastMessageAt = null;  // For sorting conversations
}
```

### `ConversationStatus` enum

```php
enum ConversationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Denied = 'denied';
}
```

### `Message`

```php
#[ORM\Entity]
#[ORM\Table(name: 'message')]
class Message
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    private Conversation $conversation;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    private Player $sender;

    #[ORM\Column(type: 'text')]
    private string $content;            // Max 2000 characters

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $sentAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $readAt = null;  // When recipient read the message
}
```

### `UserBlock`

```php
#[ORM\Entity]
#[ORM\Table(name: 'user_block')]
#[ORM\UniqueConstraint(columns: ['blocker_id', 'blocked_id'])]
class UserBlock
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    private Player $blocker;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    private Player $blocked;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $blockedAt;
}
```

## CQRS Commands & Handlers

### Commands (src/Message/)

| Command | Properties | Description |
|---------|-----------|-------------|
| `StartConversation` | `initiatorId`, `recipientId`, `sellSwapListItemId?`, `puzzleId?`, `initialMessage` | Start a new conversation (creates pending request) |
| `AcceptConversation` | `conversationId`, `playerId` | Accept a message request |
| `DenyConversation` | `conversationId`, `playerId` | Deny a message request |
| `SendMessage` | `conversationId`, `senderId`, `content` | Send a message in accepted conversation |
| `MarkMessagesAsRead` | `conversationId`, `playerId` | Mark all messages as read for a user |
| `BlockUser` | `blockerId`, `blockedId` | Block a user |
| `UnblockUser` | `blockerId`, `blockedId` | Unblock a user |
| `ReportConversation` | `reporterId`, `conversationId`, `reason` | Report a conversation for moderation |

### Handler Logic

**`StartConversationHandler`**:
1. Check if initiator is not blocked by recipient
2. Check if general messaging is allowed (if not marketplace-initiated, check recipient's `allowDirectMessages`)
3. Check if an existing accepted conversation between these two users exists → if marketplace, create new one auto-accepted; if general, reuse existing
4. Check if a pending request already exists → throw `ConversationRequestAlreadyPending`
5. Create `Conversation` with status `pending`
6. Create first `Message`
7. Emit `ConversationRequested` domain event
8. Publish Mercure update to recipient (real-time notification)

**`AcceptConversationHandler`**:
1. Verify recipient is the accepting player
2. Change status to `accepted`
3. Emit `ConversationAccepted` domain event
4. Publish Mercure update to initiator

**`SendMessageHandler`**:
1. Verify conversation is accepted
2. Verify sender is a participant
3. Verify sender is not blocked
4. Create `Message`
5. Update `conversation.lastMessageAt`
6. Publish Mercure update to recipient (real-time message delivery)

## Queries (src/Query/)

| Query | Method | Returns |
|-------|--------|---------|
| `GetConversations` | `forPlayer(playerId, status?)` | List of conversations with last message preview |
| `GetConversations` | `pendingRequestsForPlayer(playerId)` | Incoming message requests |
| `GetConversations` | `countUnreadForPlayer(playerId)` | Count of conversations with unread messages |
| `GetMessages` | `forConversation(conversationId, limit, offset)` | Paginated messages |
| `GetMessages` | `countUnreadForConversation(conversationId, playerId)` | Unread count in conversation |
| `GetUserBlocks` | `forPlayer(playerId)` | List of blocked users |
| `GetUserBlocks` | `isBlocked(blockerId, blockedId)` | Check if user is blocked |
| `HasExistingConversation` | `between(playerAId, playerBId)` | Check if accepted conversation exists |

## Controllers

```
src/Controller/Messaging/ConversationsListController.php      # GET /en/messages
src/Controller/Messaging/ConversationDetailController.php     # GET /en/messages/{conversationId}
src/Controller/Messaging/StartConversationController.php      # GET+POST /en/messages/new/{recipientId}
src/Controller/Messaging/StartMarketplaceConversationController.php  # GET+POST /en/messages/new/offer/{sellSwapListItemId}
src/Controller/Messaging/AcceptConversationController.php     # POST /en/messages/{conversationId}/accept
src/Controller/Messaging/DenyConversationController.php       # POST /en/messages/{conversationId}/deny
src/Controller/Messaging/SendMessageController.php            # POST /en/messages/{conversationId}/send
src/Controller/Messaging/BlockUserController.php              # POST /en/block-user/{playerId}
src/Controller/Messaging/UnblockUserController.php            # POST /en/unblock-user/{playerId}
src/Controller/Messaging/ReportConversationController.php     # POST /en/messages/{conversationId}/report
```

## Real-Time Updates (Mercure)

### Topics

```
/conversations/{playerId}          # New conversation requests, conversation status changes
/messages/{conversationId}         # New messages in a conversation
/unread-count/{playerId}           # Unread message count updates (for navbar badge)
```

### Frontend Integration

**Stimulus controller**: `messaging_controller.js`
- Subscribes to Mercure topics for the logged-in user
- Updates conversation list in real-time
- Appends new messages to conversation view
- Updates unread badge in navigation
- Plays notification sound (optional, user preference)

**Stimulus controller**: `unread_badge_controller.js`
- Subscribes to `/unread-count/{playerId}`
- Updates the badge counter in the navigation bar
- Shared across all pages (attached to nav element)

## UI Flow

### Marketplace → Contact Seller

1. User browses marketplace, finds interesting puzzle
2. Clicks "Contact seller" button on listing card or puzzle detail offers
3. Modal or new page: pre-filled context ("Regarding: [Puzzle Name] - [Listing Type]")
4. Types message, clicks Send
5. Seller sees message request with context: "Puzzler X is interested in buying your puzzle Y"
6. Seller accepts → conversation opens for both

### General Messaging

1. User visits another user's profile
2. Clicks "Send message" button (only shown if recipient allows direct messages or they already have a conversation)
3. Types message, clicks Send
4. Recipient sees message request: "Puzzler X wants to message you"
5. Recipient accepts/denies

### Conversations List Page

```
┌─────────────────────────────────────────┐
│ Messages                          [New] │
├────────────┬────────────────────────────┤
│ Requests(2)│ All conversations          │
├────────────┤                            │
│            │ ┌────────────────────────┐ │
│ Conv list  │ │ Jane Doe         2m ago│ │
│            │ │ Re: Ravensburger 1000  │ │
│ - Jane Doe │ │ "Sounds good, I can... │ │
│ - John S.  │ └────────────────────────┘ │
│ - Maria K. │ ┌────────────────────────┐ │
│            │ │ John Smith      1h ago │ │
│            │ │ General conversation   │ │
│            │ │ "Hey, are you going... │ │
│            │ └────────────────────────┘ │
└────────────┴────────────────────────────┘
```

### Message Request View

```
┌──────────────────────────────────────┐
│ Message Request from Puzzler X       │
│                                      │
│ 🧩 Regarding: Ravensburger Sunset   │
│    1000 pieces - Sell - €25          │
│                                      │
│ [Accept]  [Deny]                     │
│                                      │
│ (Message content hidden until        │
│  accepted)                           │
└──────────────────────────────────────┘
```

## Templates

```
templates/messaging/conversations.html.twig          # Conversations list
templates/messaging/conversation_detail.html.twig     # Single conversation view
templates/messaging/start_conversation.html.twig      # New conversation form
templates/messaging/_conversation_list_item.html.twig # Conversation list item partial
templates/messaging/_message.html.twig                # Single message partial
templates/messaging/_request_card.html.twig           # Message request card
templates/messaging/_send_form.html.twig              # Message input form
```

## Player Entity Changes

Add to Player entity:
```php
#[Column(type: Types::BOOLEAN, options: ['default' => true])]
private bool $allowDirectMessages = true;
```

Add to profile edit form: checkbox "Allow other users to message me directly"

## Testing Considerations

- Mock Mercure HubInterface in tests (inject test double)
- Test conversation lifecycle: request → accept → message → read
- Test deny flow and re-request behavior
- Test blocking: cannot start conversation with blocker
- Test `allowDirectMessages` setting enforcement
- Test auto-accept for users with existing accepted conversation
- Test marketplace conversation context preservation
- Fixture: Create test conversations in various states
