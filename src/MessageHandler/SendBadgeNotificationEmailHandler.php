<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\SendBadgeNotificationEmail;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class SendBadgeNotificationEmailHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(SendBadgeNotificationEmail $message): void
    {
        try {
            $player = $this->playerRepository->get($message->playerId);
        } catch (PlayerNotFound) {
            return;
        }

        if ($player->email === null) {
            return;
        }

        $subject = $this->translator->trans(
            'badges_earned.subject',
            domain: 'emails',
            locale: $player->locale,
        );

        $email = (new TemplatedEmail())
            ->to($player->email)
            ->locale($player->locale)
            ->subject($subject)
            ->htmlTemplate('emails/badges_earned.html.twig')
            ->context([
                'badges' => $message->badgeSummary,
                'locale' => $player->locale,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
