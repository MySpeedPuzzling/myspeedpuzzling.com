<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\MembershipSubscriptionRenewed;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class NotifyWhenMembershipSubscriptionRenewed
{
    public function __construct(
        private MembershipRepository $membershipRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(MembershipSubscriptionRenewed $event): void
    {
        $membership = $this->membershipRepository->get($event->membershipId->toString());
        $player = $membership->player;

        if ($player->email === null) {
            return;
        }

        $playerLocale = $player->locale;
        $subject = $this->translator->trans(
            'subscription_renewed.subject',
            domain: 'emails',
            locale: $playerLocale,
        );

        $email = (new TemplatedEmail())
            ->to($player->email)
            ->locale($playerLocale)
            ->subject($subject)
            ->htmlTemplate('emails/subscription_renewed.html.twig')
            ->context([
                'nextBillingPeriod' => $membership->billingPeriodEndsAt?->format('d.m.Y H:i'),
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
