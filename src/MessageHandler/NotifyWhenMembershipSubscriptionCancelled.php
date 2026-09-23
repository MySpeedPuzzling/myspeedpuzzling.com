<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\MembershipSubscriptionCancelled;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
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
        private PlayerAccountEmail $playerAccountEmail,
    ) {
    }

    public function __invoke(MembershipSubscriptionCancelled $event): void
    {
        $membership = $this->membershipRepository->get($event->membershipId->toString());
        $player = $membership->player;
        $playerEmail = $this->playerAccountEmail->ofPlayer($player);

        if ($playerEmail === null) {
            return;
        }

        // Claiming a lifetime voucher cancels the subscription on purpose - "your membership is ending" would be wrong
        if ($membership->hasLifetimeGrant()) {
            return;
        }

        $playerLocale = $player->locale;
        $subject = $this->translator->trans(
            'membership_cancelled.subject',
            domain: 'emails',
            locale: $playerLocale,
        );

        $email = (new TemplatedEmail())
            ->to($playerEmail)
            ->locale($player->locale)
            ->subject($subject)
            ->htmlTemplate('emails/membership_cancelled.html.twig')
            ->context([
                'membershipExpiresAt' => $membership->endsAt?->format('d.m.Y H:i'),
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
