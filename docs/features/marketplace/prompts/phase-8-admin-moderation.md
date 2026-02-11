# Phase 8: Admin Moderation

Read the feature specification in `docs/features/marketplace/07-admin-moderation.md` and the implementation plan Phase 8 in `docs/features/marketplace/10-implementation-plan.md`.

**Prerequisites**: Phase 5 (messaging core) must be implemented first.

## Task

Implement the full admin moderation suite: users can report conversations, admins can review reports, view conversation logs, and take moderation actions (warn, mute, ban, remove listings).

## Requirements

### 1. Create Value Objects

**`src/Value/ReportStatus.php`**:
```php
enum ReportStatus: string
{
    case Pending = 'pending';
    case Reviewed = 'reviewed';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
```

**`src/Value/ModerationActionType.php`**:
```php
enum ModerationActionType: string
{
    case Warning = 'warning';
    case TemporaryMute = 'temporary_mute';
    case MarketplaceBan = 'marketplace_ban';
    case ListingRemoved = 'listing_removed';
    case MuteLifted = 'mute_lifted';
    case BanLifted = 'ban_lifted';
}
```

### 2. Create Entities

**`src/Entity/ConversationReport.php`**:
- `id` (UUID)
- `conversation` (ManyToOne Conversation, immutable)
- `reporter` (ManyToOne Player, immutable)
- `reason` (text, immutable)
- `status` (ReportStatus enum, mutable)
- `reportedAt` (DateTimeImmutable, immutable)
- `resolvedAt` (DateTimeImmutable, nullable, mutable)
- `resolvedBy` (ManyToOne Player, nullable, mutable) — the admin
- `adminNote` (text, nullable, mutable)
- Methods: `resolve(Player $admin, ReportStatus $status, ?string $note)`, `dismiss(Player $admin, ?string $note)`

**`src/Entity/ModerationAction.php`**:
- `id` (UUID)
- `targetPlayer` (ManyToOne Player, immutable)
- `admin` (ManyToOne Player, immutable)
- `report` (ManyToOne ConversationReport, nullable, immutable) — related report if any
- `actionType` (ModerationActionType enum, immutable)
- `reason` (text, nullable, immutable)
- `performedAt` (DateTimeImmutable, immutable)
- `expiresAt` (DateTimeImmutable, nullable, immutable) — for temporary mutes

### 3. Add Moderation Fields to Player Entity

In `src/Entity/Player.php`, add:
```php
#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
#[Column(type: Types::BOOLEAN, options: ['default' => false])]
public bool $messagingMuted = false,

#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
#[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
public null|DateTimeImmutable $messagingMutedUntil = null,

#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
#[Column(type: Types::BOOLEAN, options: ['default' => false])]
public bool $marketplaceBanned = false,
```

Add methods:
- `muteMessaging(DateTimeImmutable $until): void`
- `unmuteMessaging(): void`
- `banFromMarketplace(): void`
- `liftMarketplaceBan(): void`
- `isMessagingMuted(): bool` — checks both flag and expiry

### 4. Generate Migration

Run `docker compose exec web php bin/console doctrine:migrations:diff`. DO NOT run the migration.

### 5. Create Messages & Handlers

**User-facing:**

**`src/Message/ReportConversation.php`**: `reporterId`, `conversationId`, `reason`

**`src/MessageHandler/ReportConversationHandler.php`**:
1. Load conversation, verify reporter is a participant
2. Create `ConversationReport` with status `Pending`

**Admin actions (all require admin check in handler or controller):**

**`src/Message/ResolveReport.php`**: `reportId`, `adminId`, `status` (ReportStatus value), `adminNote` (nullable)

**`src/MessageHandler/ResolveReportHandler.php`**:
- Load report, change status, set resolvedAt and resolvedBy

**`src/Message/WarnUser.php`**: `targetPlayerId`, `adminId`, `reason`, `reportId` (nullable)
**`src/MessageHandler/WarnUserHandler.php`**:
- Create `ModerationAction` with type `Warning`
- Optionally create a notification for the target player

**`src/Message/MuteUser.php`**: `targetPlayerId`, `adminId`, `days`, `reason`, `reportId` (nullable)
**`src/MessageHandler/MuteUserHandler.php`**:
- Set player `messagingMuted = true`, `messagingMutedUntil = now + days`
- Create `ModerationAction` with type `TemporaryMute`

**`src/Message/UnmuteUser.php`**: `targetPlayerId`, `adminId`
**`src/MessageHandler/UnmuteUserHandler.php`**:
- Call `$player->unmuteMessaging()`
- Create `ModerationAction` with type `MuteLifted`

**`src/Message/BanFromMarketplace.php`**: `targetPlayerId`, `adminId`, `reason`, `reportId` (nullable)
**`src/MessageHandler/BanFromMarketplaceHandler.php`**:
- Set player `marketplaceBanned = true`
- Create `ModerationAction` with type `MarketplaceBan`

**`src/Message/LiftMarketplaceBan.php`**: `targetPlayerId`, `adminId`
**`src/MessageHandler/LiftMarketplaceBanHandler.php`**:
- Call `$player->liftMarketplaceBan()`
- Create `ModerationAction` with type `BanLifted`

**`src/Message/AdminRemoveListing.php`**: `sellSwapListItemId`, `adminId`, `reason`, `reportId` (nullable)
**`src/MessageHandler/AdminRemoveListingHandler.php`**:
- Remove the listing from sell/swap list
- Create `ModerationAction` with type `ListingRemoved`

### 6. Enforce Moderation in Existing Handlers

**Update `src/MessageHandler/SendMessageHandler.php`** (from Phase 5):
- Before sending, check `$sender->isMessagingMuted()` — if true, throw `MessagingMuted` exception

**Update `src/MessageHandler/StartConversationHandler.php`** (from Phase 5):
- Before creating conversation, check `$initiator->isMessagingMuted()` — if true, throw `MessagingMuted`

**Update `src/MessageHandler/AddPuzzleToSellSwapListHandler.php`** (existing):
- Before adding, check `$player->marketplaceBanned` — if true, throw new `MarketplaceBanned` exception

Create `src/Exceptions/MarketplaceBanned.php` with appropriate HTTP status.

### 7. Create Queries

**`src/Query/GetReports.php`**:
- `pending(): array` — all reports with status Pending, ordered by reportedAt ASC
- `all(int $limit = 50, int $offset = 0): array` — paginated all reports, newest first
- `byId(string $reportId): ReportDetail` — single report with full details

**`src/Query/GetModerationActions.php`**:
- `forPlayer(string $playerId): array` — all moderation actions targeting a player
- `activeMute(string $playerId): ?ModerationActionView` — currently active mute (if any)

**`src/Query/GetConversationLog.php`**:
- `fullLog(string $conversationId): array` — all messages in conversation for admin review, including metadata (sent times, read times)

### 8. Create Result DTOs

**`src/Results/ReportOverview.php`**: reportId, reporterName, reporterCode, reportedPlayerName, reportedPlayerCode, conversationId, reason, status, reportedAt, puzzleName (nullable)

**`src/Results/ReportDetail.php`**: extends overview with resolvedAt, resolvedByName, adminNote

**`src/Results/ModerationActionView.php`**: actionId, actionType, targetPlayerName, adminName, reason, performedAt, expiresAt

### 9. Create User-Facing Controller

**`src/Controller/Messaging/ReportConversationController.php`**:
- Route: POST `/en/messages/{conversationId}/report` (name: `report_conversation`)
- Requires auth
- Accept reason as form field or simple textarea
- Dispatch `ReportConversation`, redirect back with success flash

### 10. Create Admin Controllers

All in `src/Controller/Admin/`, all require admin check. Use `#[IsGranted('IS_AUTHENTICATED_FULLY')]` and verify admin status (check existing admin controller patterns — likely uses `AdminAccessVoter`).

**`ModerationDashboardController`**:
- Route: GET `/admin/moderation` (name: `admin_moderation_dashboard`)
- Shows pending reports count and list
- Template with tabs: Pending / All / History

**`ReportDetailController`**:
- Route: GET `/admin/moderation/report/{reportId}` (name: `admin_report_detail`)
- Shows full report with conversation preview and action buttons

**`ViewConversationLogController`**:
- Route: GET `/admin/moderation/conversation/{conversationId}` (name: `admin_conversation_log`)
- Shows complete message history for admin review

**`ResolveReportController`**:
- Route: POST `/admin/moderation/report/{reportId}/resolve` (name: `admin_resolve_report`)
- Dispatch `ResolveReport`

**`WarnUserController`**:
- Route: POST `/admin/moderation/warn/{playerId}` (name: `admin_warn_user`)

**`MuteUserController`**:
- Route: POST `/admin/moderation/mute/{playerId}` (name: `admin_mute_user`)
- Accept `days` parameter

**`UnmuteUserController`**:
- Route: POST `/admin/moderation/unmute/{playerId}` (name: `admin_unmute_user`)

**`BanFromMarketplaceController`**:
- Route: POST `/admin/moderation/ban/{playerId}` (name: `admin_ban_marketplace`)

**`LiftMarketplaceBanController`**:
- Route: POST `/admin/moderation/unban/{playerId}` (name: `admin_lift_ban`)

**`AdminRemoveListingController`**:
- Route: POST `/admin/moderation/remove-listing/{itemId}` (name: `admin_remove_listing`)

**`ModerationHistoryController`**:
- Route: GET `/admin/moderation/history/{playerId}` (name: `admin_moderation_history`)
- Shows all moderation actions for a specific player

### 11. Create Templates

**`templates/admin/moderation/dashboard.html.twig`**:
- Pending reports list with: reporter, reported user, reason preview, time ago
- Each report links to detail page
- Quick action buttons

**`templates/admin/moderation/report_detail.html.twig`**:
- Full report info
- Link to view conversation log
- Action dropdown: Dismiss, Warn user, Mute (7/14/30 days), Ban from marketplace, Remove listing
- Admin notes textarea
- Resolution form

**`templates/admin/moderation/conversation_log.html.twig`**:
- All messages displayed chronologically
- Sender info, timestamps, read status
- Highlighted context about the report

**`templates/admin/moderation/user_history.html.twig`**:
- All moderation actions taken against this user
- Current status (muted? banned?)
- Reports involving this user

### 12. Add Auto-Unmute Logic

Create a console command or add to existing scheduler:

**`src/ConsoleCommands/ExpireMutesCommand.php`**:
```
Command: myspeedpuzzling:moderation:expire-mutes
```
- Query players where `messagingMuted = true` AND `messagingMutedUntil < NOW()`
- Set `messagingMuted = false`, `messagingMutedUntil = null`
- Log count of expired mutes

This should run hourly (can be added to the same scheduler as the unread message notifications in Phase 9).

### 13. Create Test Fixtures

**`tests/DataFixtures/ConversationReportFixture.php`**:
- One pending report
- One resolved report

**`tests/DataFixtures/ModerationActionFixture.php`**:
- One warning action
- One expired mute action

### 14. Write Tests

**`tests/MessageHandler/ReportConversationHandlerTest.php`**:
- Test creating a report
- Test only participant can report

**`tests/MessageHandler/MuteUserHandlerTest.php`**:
- Test muting sets correct fields on player
- Test mute creates ModerationAction

**`tests/MessageHandler/BanFromMarketplaceHandlerTest.php`**:
- Test ban sets flag on player

**`tests/MessageHandler/SendMessageHandlerTest.php`** (update from Phase 5):
- Test muted user cannot send messages

**`tests/MessageHandler/AddPuzzleToSellSwapListHandlerTest.php`** (update existing):
- Test marketplace-banned user cannot add listings

**`tests/Query/GetReportsTest.php`**:
- Test pending reports query
- Test all reports query

**`tests/Controller/Admin/ModerationDashboardControllerTest.php`**:
- Test admin can access dashboard
- Test non-admin is rejected

### 15. Run Checks

```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```
