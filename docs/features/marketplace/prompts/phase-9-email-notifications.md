# Phase 9: Email Notification Cron

Read the feature specification in `docs/features/marketplace/06-email-notifications.md` and the implementation plan Phase 9 in `docs/features/marketplace/10-implementation-plan.md`.

**Prerequisites**: Phase 5 (messaging core) must be implemented first.

## Task

Implement a scheduled console command that sends email notifications about unread messages. The command must be non-intrusive: notify only after 12 hours of unread messages, never duplicate notifications for the same batch, and reset when the user reads or responds.

## Requirements

### 1. Create Entity

**`src/Entity/MessageNotificationLog.php`**:
- `id` (UUID)
- `player` (ManyToOne Player, immutable)
- `sentAt` (DateTimeImmutable, immutable)
- `oldestUnreadMessageAt` (DateTimeImmutable, immutable) — the oldest unread message timestamp at the time of notification (used to prevent duplicates)

### 2. Add Player Setting

In `src/Entity/Player.php`, add:
```php
#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
#[Column(type: Types::BOOLEAN, options: ['default' => true])]
public bool $emailNotificationsEnabled = true,
```

Add method `changeEmailNotificationsEnabled(bool $enabled)`.

Update `EditProfile` message, handler, and form to include the new checkbox: "Send me email notifications about unread messages".

### 3. Generate Migration

Run `docker compose exec web php bin/console doctrine:migrations:diff`. DO NOT run the migration.

### 4. Create Query for Players with Unread Messages

**`src/Query/GetPlayersWithUnreadMessages.php`**:

```php
/**
 * Returns players who have unread messages older than the given threshold
 * AND have not already been notified about those specific messages.
 *
 * @return UnreadMessageNotification[]
 */
public function findPlayersToNotify(int $hoursThreshold = 12): array
```

The SQL logic:

```sql
SELECT
    p.id as player_id,
    p.email,
    p.name as player_name,
    p.locale,
    MIN(cm.sent_at) as oldest_unread_at,
    COUNT(cm.id) as unread_count
FROM player p
JOIN conversation c ON (c.initiator_id = p.id OR c.recipient_id = p.id)
JOIN chat_message cm ON cm.conversation_id = c.id
WHERE p.email IS NOT NULL
  AND p.email_notifications_enabled = true
  AND c.status = 'accepted'
  AND cm.sender_id != p.id
  AND cm.read_at IS NULL
  AND cm.sent_at < NOW() - INTERVAL ':hours hours'
GROUP BY p.id, p.email, p.name, p.locale
HAVING MIN(cm.sent_at) > COALESCE(
    (SELECT MAX(mnl.oldest_unread_message_at)
     FROM message_notification_log mnl
     WHERE mnl.player_id = p.id),
    '1970-01-01'::timestamptz
)
```

The HAVING clause ensures we only notify if the oldest unread message is newer than the last notification we sent. This prevents duplicate notifications.

Also create a method that returns per-sender breakdown for the email body:

```php
/**
 * @return UnreadMessageSummary[]  grouped by sender
 */
public function getUnreadSummaryForPlayer(string $playerId): array
```

Returns: senderName, senderCode, unreadCount, puzzleName (nullable, from conversation context), conversationId.

### 5. Create Result DTOs

**`src/Results/UnreadMessageNotification.php`**:
- playerId, playerEmail, playerName, playerLocale, oldestUnreadAt, totalUnreadCount

**`src/Results/UnreadMessageSummary.php`**:
- senderName, senderCode, unreadCount, puzzleName (nullable), conversationId

### 6. Create Console Command

**`src/ConsoleCommands/SendUnreadMessageNotificationsCommand.php`**:

```php
#[AsCommand(
    name: 'myspeedpuzzling:messages:notify-unread',
    description: 'Send email notifications for unread messages older than 12 hours',
)]
```

Algorithm:
1. Find all players to notify using `GetPlayersWithUnreadMessages::findPlayersToNotify(12)`
2. For each player:
   a. Get unread message summary using `getUnreadSummaryForPlayer()`
   b. Build and send email (using Symfony Mailer `TemplatedEmail`)
   c. Create `MessageNotificationLog` entry with current timestamp and `oldestUnreadAt`
3. Output summary: "Sent X notification emails, skipped Y players"

The command must implement `ResetInterface` if it caches any state (FrankenPHP worker mode safety).

### 7. Create Email Template

**`templates/emails/unread_messages.html.twig`**:

Follow the existing email template pattern (extend base email layout, use Inky for responsive design).

Content:
```
Subject: You have unread messages on MySpeedPuzzling

Hi {playerName},

You have unread messages waiting for you:

- {count} message(s) from {senderName} {regarding: puzzleName}
- {count} message(s) from {senderName}

[View your messages →]  (link to /en/messages)

--
You can turn off these notifications in your profile settings.
(link to /en/edit-profile)
```

Use the player's locale for translations. Subject should also be translated.

### 8. Create Repository

**`src/Repository/MessageNotificationLogRepository.php`**:
- `save(MessageNotificationLog $log)` method

### 9. Optional: Integrate Mute Expiry

If Phase 8 (admin moderation) is implemented, add the expired mute cleanup to this command or reference the separate `ExpireMutesCommand`. Both can run on the same hourly schedule.

### 10. Create Test Fixtures

Update `tests/DataFixtures/ChatMessageFixture.php` (from Phase 5):
- Ensure some messages have `sentAt` timestamps > 12 hours ago and `readAt = null` for testing the notification logic

### 11. Write Tests

**`tests/Query/GetPlayersWithUnreadMessagesTest.php`**:
- Test finds players with unread messages older than threshold
- Test does NOT find players whose messages are all read
- Test does NOT find players who were already notified about the same unread batch
- Test does NOT find players without email
- Test does NOT find players with emailNotificationsEnabled = false
- Test the per-sender summary returns correct groupings

**`tests/ConsoleCommands/SendUnreadMessageNotificationsCommandTest.php`**:
- Test command runs successfully and sends emails (check Mailer assertions — Symfony provides test email transport)
- Test command creates notification log entries
- Test command is idempotent (running twice doesn't send duplicate emails)
- Test command handles zero players to notify gracefully

### 12. Scheduler Configuration

Document in the command output or in a comment: this command should run hourly via cron or Symfony Scheduler:

```
# Cron entry (option A)
0 * * * * docker compose exec web php bin/console myspeedpuzzling:messages:notify-unread

# Or Symfony Scheduler (option B) - create schedule provider if desired
```

The 12-hour delay logic is internal to the command, so running every hour is safe and ensures timely notifications.

### 13. Run Checks

```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```
