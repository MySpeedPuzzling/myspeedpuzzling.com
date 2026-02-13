<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\MessageNotificationLog;
use SpeedPuzzling\Web\Entity\RequestNotificationLog;
use SpeedPuzzling\Web\Query\GetPlayersWithUnreadMessages;
use SpeedPuzzling\Web\Repository\MessageNotificationLogRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\RequestNotificationLogRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'myspeedpuzzling:messages:notify-unread',
    description: 'Send email notifications for unread messages older than 12 hours',
)]
final class SendUnreadMessageNotificationsCommand extends Command
{
    public function __construct(
        readonly private GetPlayersWithUnreadMessages $getPlayersWithUnreadMessages,
        readonly private MessageNotificationLogRepository $messageNotificationLogRepository,
        readonly private RequestNotificationLogRepository $requestNotificationLogRepository,
        readonly private PlayerRepository $playerRepository,
        readonly private MailerInterface $mailer,
        readonly private TranslatorInterface $translator,
        readonly private ClockInterface $clock,
        readonly private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $playersToNotify = $this->getPlayersWithUnreadMessages->findPlayersToNotify(12);
        $pendingRequestNotifications = $this->getPlayersWithUnreadMessages->findPlayersWithPendingRequestsToNotify(12);

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

        foreach ($allPlayerIds as $playerId) {
            $unreadNotification = $unreadByPlayer[$playerId] ?? null;
            $pendingNotification = $pendingByPlayer[$playerId] ?? null;

            $summaries = [];
            if ($unreadNotification !== null) {
                $summaries = $this->getPlayersWithUnreadMessages->getUnreadSummaryForPlayer($playerId);
            }

            $pendingRequestCount = $pendingNotification !== null ? $pendingNotification->pendingCount : 0;

            if (count($summaries) === 0 && $pendingRequestCount === 0) {
                $skippedCount++;
                continue;
            }

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

            $subject = $this->translator->trans(
                'unread_messages.subject',
                domain: 'emails',
                locale: $playerLocale,
            );

            $email = (new TemplatedEmail())
                ->to($playerEmail)
                ->locale($playerLocale ?? 'en')
                ->subject($subject)
                ->htmlTemplate('emails/unread_messages.html.twig')
                ->context([
                    'playerName' => $playerName,
                    'summaries' => $summaries,
                    'pendingRequestCount' => $pendingRequestCount,
                    'locale' => $playerLocale ?? 'en',
                ]);

            $this->mailer->send($email);

            $player = $this->playerRepository->get($playerId);

            if ($unreadNotification !== null) {
                $log = new MessageNotificationLog(
                    id: Uuid::uuid7(),
                    player: $player,
                    sentAt: $this->clock->now(),
                    oldestUnreadMessageAt: $unreadNotification->oldestUnreadAt,
                );

                $this->messageNotificationLogRepository->save($log);
            }

            if ($pendingNotification !== null) {
                $requestLog = new RequestNotificationLog(
                    id: Uuid::uuid7(),
                    player: $player,
                    sentAt: $this->clock->now(),
                    oldestPendingRequestAt: $pendingNotification->oldestPendingAt,
                );

                $this->requestNotificationLogRepository->save($requestLog);
            }

            $sentCount++;
        }

        $this->entityManager->flush();

        $io->success("Sent {$sentCount} notification emails, skipped {$skippedCount} players.");

        return self::SUCCESS;
    }
}
