# 07 - Admin Moderation Dashboard

## Overview

Full moderation suite for admins to review reported conversations, warn/mute/ban users, remove listings, and view conversation logs.

## Entity

### `ConversationReport`

```php
#[ORM\Entity]
#[ORM\Table(name: 'conversation_report')]
class ConversationReport
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    private Conversation $conversation;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    private Player $reporter;

    #[ORM\Column(type: 'text')]
    private string $reason;               // Reporter's explanation

    #[ORM\Column(type: 'string', enumType: ReportStatus::class)]
    private ReportStatus $status;          // pending, reviewed, resolved, dismissed

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $reportedAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    private ?Player $resolvedBy = null;    // Admin who handled it

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminNote = null;     // Admin's internal notes
}
```

### `ReportStatus` enum

```php
enum ReportStatus: string
{
    case Pending = 'pending';
    case Reviewed = 'reviewed';    // Admin looked at it, but no action yet
    case Resolved = 'resolved';    // Action taken
    case Dismissed = 'dismissed';  // No action needed
}
```

### `ModerationAction`

Log of all moderation actions taken:

```php
#[ORM\Entity]
#[ORM\Table(name: 'moderation_action')]
class ModerationAction
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    private Player $targetPlayer;           // Who is being moderated

    #[ORM\ManyToOne(targetEntity: Player::class)]
    private Player $admin;                  // Admin performing the action

    #[ORM\ManyToOne(targetEntity: ConversationReport::class)]
    private ?ConversationReport $report;    // Related report (if any)

    #[ORM\Column(type: 'string', enumType: ModerationActionType::class)]
    private ModerationActionType $actionType;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;         // Admin's explanation

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $performedAt;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;  // For temporary actions (mute)
}
```

### `ModerationActionType` enum

```php
enum ModerationActionType: string
{
    case Warning = 'warning';
    case TemporaryMute = 'temporary_mute';       // Cannot send messages for X days
    case MarketplaceBan = 'marketplace_ban';      // Cannot list or use marketplace
    case ListingRemoved = 'listing_removed';      // Specific listing removed by admin
    case MuteLifted = 'mute_lifted';              // Manual lift of mute
    case BanLifted = 'ban_lifted';                // Manual lift of ban
}
```

### Player Entity - Moderation Fields

```php
#[Column(type: Types::BOOLEAN, options: ['default' => false])]
private bool $messagingMuted = false;

#[Column(type: 'datetimetz_immutable', nullable: true)]
private ?DateTimeImmutable $messagingMutedUntil = null;

#[Column(type: Types::BOOLEAN, options: ['default' => false])]
private bool $marketplaceBanned = false;
```

## CQRS Commands

| Command | Properties | Description |
|---------|-----------|-------------|
| `ResolveReport` | `reportId`, `adminId`, `status`, `adminNote?` | Mark report as resolved/dismissed |
| `WarnUser` | `targetPlayerId`, `adminId`, `reason`, `reportId?` | Send warning to user |
| `MuteUser` | `targetPlayerId`, `adminId`, `days`, `reason`, `reportId?` | Temporarily mute messaging |
| `UnmuteUser` | `targetPlayerId`, `adminId` | Lift mute early |
| `BanFromMarketplace` | `targetPlayerId`, `adminId`, `reason`, `reportId?` | Ban from marketplace |
| `LiftMarketplaceBan` | `targetPlayerId`, `adminId` | Lift marketplace ban |
| `AdminRemoveListing` | `sellSwapListItemId`, `adminId`, `reason`, `reportId?` | Force-remove a listing |

## Enforcement

### Messaging Mute

When `messagingMuted = true` and `messagingMutedUntil > now()`:
- `SendMessageHandler` throws `MessagingMuted` exception
- `StartConversationHandler` throws `MessagingMuted` exception
- UI shows: "Your messaging is temporarily suspended until {date}. Reason: {reason}"

### Marketplace Ban

When `marketplaceBanned = true`:
- `AddPuzzleToSellSwapListHandler` throws `MarketplaceBanned` exception
- Marketplace listing creation blocked in controller
- Existing listings remain visible but marked (optional: hide them)
- UI shows: "Your marketplace access has been suspended. Reason: {reason}"

### Auto-unmute

The unread message notification cron (or a separate scheduler) checks for expired mutes:
```php
// Run hourly alongside notification cron
UPDATE player SET messaging_muted = false, messaging_muted_until = NULL
WHERE messaging_muted = true AND messaging_muted_until < NOW()
```

## Admin Controllers

```
src/Controller/Admin/ModerationDashboardController.php    # GET /admin/moderation
src/Controller/Admin/ReportDetailController.php           # GET /admin/moderation/report/{reportId}
src/Controller/Admin/ViewConversationLogController.php    # GET /admin/moderation/conversation/{conversationId}
src/Controller/Admin/ResolveReportController.php          # POST /admin/moderation/report/{reportId}/resolve
src/Controller/Admin/WarnUserController.php               # POST /admin/moderation/warn/{playerId}
src/Controller/Admin/MuteUserController.php               # POST /admin/moderation/mute/{playerId}
src/Controller/Admin/UnmuteUserController.php             # POST /admin/moderation/unmute/{playerId}
src/Controller/Admin/BanFromMarketplaceController.php     # POST /admin/moderation/ban/{playerId}
src/Controller/Admin/LiftMarketplaceBanController.php     # POST /admin/moderation/unban/{playerId}
src/Controller/Admin/AdminRemoveListingController.php     # POST /admin/moderation/remove-listing/{itemId}
src/Controller/Admin/ModerationHistoryController.php      # GET /admin/moderation/history/{playerId}
```

All admin controllers require `IS_AUTHENTICATED_FULLY` + admin check (existing `AdminAccessVoter`).

## Admin UI

### Moderation Dashboard

```
┌─────────────────────────────────────────────────────┐
│ Moderation Dashboard                                │
├─────────────────────────────────────────────────────┤
│ Pending Reports (5)  │  All Reports  │  History     │
├─────────────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────────────┐ │
│ │ Report #1234                      2h ago        │ │
│ │ Reporter: Jane Doe                              │ │
│ │ Reported: John Smith                            │ │
│ │ Conversation about: Ravensburger 1000           │ │
│ │ Reason: "Harassing messages, refuses to stop"   │ │
│ │ [View conversation] [Take action ▼]             │ │
│ └─────────────────────────────────────────────────┘ │
│ ┌─────────────────────────────────────────────────┐ │
│ │ Report #1235                      5h ago        │ │
│ │ ...                                             │ │
│ └─────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

### Conversation Log View

Admin can view full conversation history for reported conversations:
- All messages with timestamps
- Message read status
- Highlighted messages that triggered the report (if identifiable)
- User info for both participants

### Action Menu

From report detail, admin can:
- Dismiss report (no action needed)
- Warn user (sends notification)
- Mute user (7 / 14 / 30 days or custom)
- Ban from marketplace
- Remove specific listing
- View user's moderation history

### Moderation History

Per-user view showing:
- All past moderation actions
- Reports involving this user
- Current status (muted? banned?)

## Queries

| Query | Method | Returns |
|-------|--------|---------|
| `GetReports` | `pending()` | All pending reports |
| `GetReports` | `all(limit, offset)` | Paginated all reports |
| `GetReports` | `byId(reportId)` | Single report detail |
| `GetModerationActions` | `forPlayer(playerId)` | Moderation history for a player |
| `GetModerationActions` | `activeMute(playerId)` | Current active mute (if any) |
| `GetConversationLog` | `fullLog(conversationId)` | All messages for admin review |

## Templates

```
templates/admin/moderation/dashboard.html.twig
templates/admin/moderation/report_detail.html.twig
templates/admin/moderation/conversation_log.html.twig
templates/admin/moderation/user_history.html.twig
templates/admin/moderation/_report_card.html.twig
templates/admin/moderation/_action_form.html.twig
```

## Testing

- Test report creation and retrieval
- Test all moderation actions (warn, mute, ban, remove listing)
- Test mute enforcement (cannot send messages when muted)
- Test marketplace ban enforcement (cannot create listings when banned)
- Test auto-unmute on expiry
- Test admin authorization (non-admins cannot access)
- Test moderation action logging
- Fixture: Create reports and moderation actions for dashboard testing
