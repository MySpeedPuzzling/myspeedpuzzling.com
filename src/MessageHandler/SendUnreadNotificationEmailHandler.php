<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\MessageNotificationLog;
use SpeedPuzzling\Web\Entity\RequestNotificationLog;
use SpeedPuzzling\Web\Message\SendUnreadNotificationEmail;
use SpeedPuzzling\Web\Repository\MessageNotificationLogRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\RequestNotificationLogRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class SendUnreadNotificationEmailHandler
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private PlayerRepository $playerRepository,
        private MessageNotificationLogRepository $messageNotificationLogRepository,
        private RequestNotificationLogRepository $requestNotificationLogRepository,
    ) {
    }

    public function __invoke(SendUnreadNotificationEmail $message): void
    {
        $subject = $this->translator->trans(
            'unread_messages.subject',
            domain: 'emails',
            locale: $message->playerLocale,
        );

        $email = (new TemplatedEmail())
            ->to($message->playerEmail)
            ->locale($message->playerLocale ?? 'en')
            ->subject($subject)
            ->htmlTemplate('emails/unread_messages.html.twig')
            ->context([
                'playerName' => $message->playerName,
                'summaries' => $message->summaries,
                'pendingRequestCount' => $message->pendingRequestCount,
                'locale' => $message->playerLocale ?? 'en',
            ]);

        $this->mailer->send($email);

        $player = $this->playerRepository->get($message->playerId);

        if ($message->oldestUnreadAt !== null) {
            $log = new MessageNotificationLog(
                id: Uuid::uuid7(),
                player: $player,
                sentAt: $this->clock->now(),
                oldestUnreadMessageAt: $message->oldestUnreadAt,
            );

            $this->messageNotificationLogRepository->save($log);
        }

        if ($message->oldestPendingAt !== null) {
            $requestLog = new RequestNotificationLog(
                id: Uuid::uuid7(),
                player: $player,
                sentAt: $this->clock->now(),
                oldestPendingRequestAt: $message->oldestPendingAt,
            );

            $this->requestNotificationLogRepository->save($requestLog);
        }
    }
}
