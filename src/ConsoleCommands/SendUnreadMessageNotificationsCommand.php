<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\MessageNotificationLog;
use SpeedPuzzling\Web\Query\GetPlayersWithUnreadMessages;
use SpeedPuzzling\Web\Repository\MessageNotificationLogRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
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

        if (count($playersToNotify) === 0) {
            $io->success('No players to notify.');
            return self::SUCCESS;
        }

        $sentCount = 0;
        $skippedCount = 0;

        foreach ($playersToNotify as $notification) {
            $summaries = $this->getPlayersWithUnreadMessages->getUnreadSummaryForPlayer($notification->playerId);

            if (count($summaries) === 0) {
                $skippedCount++;
                continue;
            }

            $subject = $this->translator->trans(
                'unread_messages.subject',
                domain: 'emails',
                locale: $notification->playerLocale,
            );

            $email = (new TemplatedEmail())
                ->to($notification->playerEmail)
                ->locale($notification->playerLocale ?? 'en')
                ->subject($subject)
                ->htmlTemplate('emails/unread_messages.html.twig')
                ->context([
                    'playerName' => $notification->playerName,
                    'summaries' => $summaries,
                    'locale' => $notification->playerLocale ?? 'en',
                ]);

            $this->mailer->send($email);

            $player = $this->playerRepository->get($notification->playerId);

            $log = new MessageNotificationLog(
                id: Uuid::uuid7(),
                player: $player,
                sentAt: $this->clock->now(),
                oldestUnreadMessageAt: $notification->oldestUnreadAt,
            );

            $this->messageNotificationLogRepository->save($log);
            $sentCount++;
        }

        $this->entityManager->flush();

        $io->success("Sent {$sentCount} notification emails, skipped {$skippedCount} players.");

        return self::SUCCESS;
    }
}
