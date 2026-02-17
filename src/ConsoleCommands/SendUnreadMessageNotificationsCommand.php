<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\SendUnreadNotificationEmail;
use SpeedPuzzling\Web\Query\GetPlayersWithUnreadMessages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'myspeedpuzzling:messages:notify-unread',
    description: 'Send email notifications for unread messages older than X hours',
)]
final class SendUnreadMessageNotificationsCommand extends Command
{
    public function __construct(
        readonly private GetPlayersWithUnreadMessages $getPlayersWithUnreadMessages,
        readonly private MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $playersToNotify = $this->getPlayersWithUnreadMessages->findPlayersToNotify(36);
        $pendingRequestNotifications = $this->getPlayersWithUnreadMessages->findPlayersWithPendingRequestsToNotify(36);

        // Index pending requests by player ID for easy lookup
        $pendingByPlayer = [];
        foreach ($pendingRequestNotifications as $pendingNotification) {
            $pendingByPlayer[$pendingNotification->playerId] = $pendingNotification;
        }

        // Collect all player IDs that need notification (unread messages or pending requests)
        $allPlayerIds = array_unique(array_merge(
            array_map(static fn ($n) => $n->playerId, $playersToNotify),
            array_keys($pendingByPlayer),
        ));

        if (count($allPlayerIds) === 0) {
            $io->success('No players to notify.');
            return self::SUCCESS;
        }

        // Index unread message notifications by player ID
        $unreadByPlayer = [];
        foreach ($playersToNotify as $notification) {
            $unreadByPlayer[$notification->playerId] = $notification;
        }

        $sentCount = 0;
        $skippedCount = 0;
        $notifiedEmails = [];

        foreach ($allPlayerIds as $playerId) {
            $unreadNotification = $unreadByPlayer[$playerId] ?? null;
            $pendingNotification = $pendingByPlayer[$playerId] ?? null;

            // Get player info from whichever notification is available
            if ($unreadNotification !== null) {
                $playerEmail = $unreadNotification->playerEmail;
                $playerName = $unreadNotification->playerName;
                $playerLocale = $unreadNotification->playerLocale;
            } else {
                assert($pendingNotification !== null);
                $playerEmail = $pendingNotification->playerEmail;
                $playerName = $pendingNotification->playerName;
                $playerLocale = $pendingNotification->playerLocale;
            }

            // Prevent sending multiple emails to the same email address
            $normalizedEmail = mb_strtolower($playerEmail);
            if (isset($notifiedEmails[$normalizedEmail])) {
                $skippedCount++;
                continue;
            }

            $summaries = [];
            if ($unreadNotification !== null) {
                $summaries = $this->getPlayersWithUnreadMessages->getUnreadSummaryForPlayer($playerId);
            }

            $pendingRequestCount = $pendingNotification !== null ? $pendingNotification->pendingCount : 0;

            if (count($summaries) === 0 && $pendingRequestCount === 0) {
                $skippedCount++;
                continue;
            }

            $this->commandBus->dispatch(new SendUnreadNotificationEmail(
                playerId: $playerId,
                playerEmail: $playerEmail,
                playerName: $playerName,
                playerLocale: $playerLocale,
                summaries: $summaries,
                pendingRequestCount: $pendingRequestCount,
                oldestUnreadAt: $unreadNotification?->oldestUnreadAt,
                oldestPendingAt: $pendingNotification?->oldestPendingAt,
            ));

            $notifiedEmails[$normalizedEmail] = true;
            $sentCount++;
        }

        $io->success("Sent {$sentCount} notification emails, skipped {$skippedCount} players.");

        return self::SUCCESS;
    }
}
