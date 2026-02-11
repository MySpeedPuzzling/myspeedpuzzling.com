# 06 - Unread Message Email Notifications

## Overview

A scheduled task (cron) that sends email notifications about unread messages. Designed to be non-intrusive: notifies only once per unread batch, with a 12-hour delay, and resets the timer when the user responds.

## Notification Logic

### Rules

1. **12-hour delay**: Only notify if messages have been unread for more than 12 hours
2. **No duplicate notifications**: Once an email is sent for a set of unread messages, don't send again for those same messages
3. **Reset on response**: If the user reads messages or sends a reply, the 12-hour timer resets
4. **Group by sender**: Email shows count of unread messages per sender (e.g., "3 messages from Jane, 1 message from John")
5. **Skip if no email**: Don't attempt to send if player has no email address
6. **Respect opt-out**: Players can opt out of email notifications entirely (new setting)

### Timer Logic (Detailed)

For each player:
1. Find all conversations where the player has unread messages
2. For each conversation, find the **oldest unread message** timestamp
3. If oldest unread message is > 12 hours old AND no notification email has been sent since that message was created → include in notification
4. After sending the notification, record the timestamp
5. If the player then reads messages or replies → `lastNotifiedAt` is effectively reset because the "oldest unread" check starts fresh from new unread messages

## Entity

### `MessageNotificationLog`

```php
#[ORM\Entity]
#[ORM\Table(name: 'message_notification_log')]
class MessageNotificationLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private UuidInterface $id;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    private Player $player;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $sentAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $oldestUnreadMessageAt;  // The oldest unread message at time of notification
}
```

### Player Settings

Add to Player entity:

```php
#[Column(type: Types::BOOLEAN, options: ['default' => true])]
private bool $emailNotificationsEnabled = true;
```

## Console Command

### `SendUnreadMessageNotificationsCommand`

```php
#[AsCommand(
    name: 'myspeedpuzzling:messages:notify-unread',
    description: 'Send email notifications for unread messages older than 12 hours',
)]
class SendUnreadMessageNotificationsCommand extends Command
```

### Algorithm

```
1. Query all players who:
   - Have an email address
   - Have emailNotificationsEnabled = true
   - Have conversations with unread messages
   - Oldest unread message is > 12 hours old

2. For each player:
   a. Get last notification log entry for this player
   b. If last notification exists AND last notification's oldestUnreadMessageAt >= current oldest unread → SKIP (already notified)
   c. Otherwise, gather unread message summary:
      - Count unread messages per conversation
      - Get sender name for each conversation
      - Get conversation context (puzzle name if marketplace)
   d. Send email
   e. Create MessageNotificationLog entry

3. Log summary: "Sent X notifications, skipped Y players"
```

### SQL Query for Finding Players to Notify

```sql
SELECT DISTINCT
    p.id as player_id,
    p.email,
    p.locale,
    MIN(m.sent_at) as oldest_unread_at
FROM player p
JOIN conversation c ON (c.initiator_id = p.id OR c.recipient_id = p.id)
JOIN message m ON m.conversation_id = c.id
WHERE p.email IS NOT NULL
  AND p.email_notifications_enabled = true
  AND c.status = 'accepted'
  AND m.sender_id != p.id          -- Not sent by this player
  AND m.read_at IS NULL            -- Unread
  AND m.sent_at < NOW() - INTERVAL '12 hours'  -- Older than 12h
GROUP BY p.id, p.email, p.locale
HAVING MIN(m.sent_at) > COALESCE(
    (SELECT MAX(mnl.oldest_unread_message_at) FROM message_notification_log mnl WHERE mnl.player_id = p.id),
    '1970-01-01'::timestamptz
)
```

## Email Template

### `templates/emails/unread_messages.html.twig`

Content:
```
Subject: You have unread messages on MySpeedPuzzling

Hi {playerName},

You have unread messages waiting for you:

- 3 messages from Jane Doe (about: Ravensburger Sunset 1000pc)
- 1 message from John Smith

[View your messages →]

--
You can turn off these notifications in your profile settings.
```

## Scheduler Configuration

### Option A: Symfony Scheduler (Recommended)

```php
// src/Scheduler/UnreadMessagesSchedule.php
#[AsSchedule('unread-messages')]
class UnreadMessagesScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::every('1 hour', new SendUnreadMessageNotifications())
        );
    }
}
```

Run the scheduler worker: `php bin/console messenger:consume scheduler_unread-messages`

### Option B: System Cron

```cron
0 * * * * docker compose exec web php bin/console myspeedpuzzling:messages:notify-unread
```

Runs every hour. The command itself handles the 12-hour delay logic, so running it more frequently is safe (idempotent).

## Profile Settings Integration

Add to profile edit page:
- Checkbox: "Send me email notifications about unread messages" (default: on)

## Testing

- Test that notification is sent after 12h of unread messages
- Test that no duplicate notification is sent for the same unread batch
- Test that replying resets the timer (new unread messages start fresh)
- Test that reading all messages prevents notification
- Test opt-out setting respected
- Test email content includes correct message counts per sender
- Test no email sent when player has no email address
- Test the SQL query edge cases (multiple conversations, mixed read/unread)
