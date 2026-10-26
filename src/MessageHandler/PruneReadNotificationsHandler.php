<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\PruneReadNotifications;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes one batch of read "a puzzler you follow added a time" notifications past the retention window - 99.7 % of
 * the table (production 2026-10-26: 4.5M rows, 2.95M of them read and older than 90 days). A feed item seen once is
 * not looked for again; the results stay on the puzzler's profile. Unread ones and every other type (lending,
 * moderation, requests - 18k rows in all) are kept. Returns how many rows went; the command repeats until a batch
 * comes back short (docs/features/notifications.md).
 */
#[AsMessageHandler]
readonly final class PruneReadNotificationsHandler
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(PruneReadNotifications $message): int
    {
        $before = $this->clock->now()->modify("-{$message->retentionDays} days");

        return $this->notificationRepository->deleteReadOlderThan(
            NotificationType::SubscribedPlayerAddedTime,
            $before,
            $message->batchSize,
        );
    }
}
