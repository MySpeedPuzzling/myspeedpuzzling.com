<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\MembershipSubscriptionCancelled;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class NotifyWhenMembershipSubscriptionCancelled
{
    public function __construct(
        private MembershipRepository $membershipRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(MembershipSubscriptionCancelled $event): void
    {
        $membership = $this->membershipRepository->get($event->membershipId->toString());
        $player = $membership->player;

        if ($player->email === null) {
            return;
        }

        $playerLocale = $player->locale;
        $subject = $this->translator->trans('membership_cancelled.subject',
            domain: 'emails',
            locale: $playerLocale,
        );

        $email = (new TemplatedEmail())
            ->to($player->email)
            ->locale($player->locale)
            ->subject($subject)
            ->htmlTemplate('emails/membership_cancelled.html.twig')
            ->context([
                'membershipExpiresAt' => $membership->endsAt?->format('d.m.Y H:i'),
            ]);

        $this->mailer->send($email);
    }
}
