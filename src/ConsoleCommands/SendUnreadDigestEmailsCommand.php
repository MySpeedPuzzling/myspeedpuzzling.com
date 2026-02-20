<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\PrepareDigestEmailForPlayer;
use SpeedPuzzling\Web\Query\GetPlayersWithUnreadMessages;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'myspeedpuzzling:send-unread-digest-emails',
    description: 'Send digest emails about unread messages and pending message requests',
)]
final class SendUnreadDigestEmailsCommand extends Command
{
    public function __construct(
        readonly private GetPlayersWithUnreadMessages $getPlayersWithUnreadMessages,
        readonly private MessageBusInterface $commandBus,
        private readonly int $maxEmailsPerRun = 10,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $playersWithUnreadMessages = $this->getPlayersWithUnreadMessages->findPlayersToNotify($this->maxEmailsPerRun);
        $playersWithPendingRequests = $this->getPlayersWithUnreadMessages->findPlayersWithPendingRequestsToNotify($this->maxEmailsPerRun);

        // Collect all unique player IDs
        $allPlayerIds = array_unique(array_merge(
            array_map(static fn ($n) => $n->playerId, $playersWithUnreadMessages),
            array_map(static fn ($n) => $n->playerId, $playersWithPendingRequests),
        ));

        if (count($allPlayerIds) === 0) {
            $io->success('No players to notify.');
            return self::SUCCESS;
        }

        // Deduplicate by email address
        $emailIndex = [];
        foreach ($playersWithUnreadMessages as $notification) {
            $normalizedEmail = mb_strtolower($notification->playerEmail);
            $emailIndex[$normalizedEmail] = $notification->playerId;
        }
        foreach ($playersWithPendingRequests as $notification) {
            $normalizedEmail = mb_strtolower($notification->playerEmail);
            if (!isset($emailIndex[$normalizedEmail])) {
                $emailIndex[$normalizedEmail] = $notification->playerId;
            }
        }

        $playerIdsToDispatch = array_unique(array_values($emailIndex));

        $dispatchedCount = 0;
        foreach ($playerIdsToDispatch as $playerId) {
            if ($dispatchedCount >= $this->maxEmailsPerRun) {
                break;
            }

            $this->commandBus->dispatch(new PrepareDigestEmailForPlayer(
                playerId: $playerId,
            ));
            $dispatchedCount++;
        }

        $io->success("Dispatched {$dispatchedCount} digest email preparation(s).");

        return self::SUCCESS;
    }
}
