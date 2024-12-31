<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\MembershipSubscriptionRenewed;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyWhenMembershipSubscriptionRenewed
{
    public function __construct(
        private MembershipRepository $membershipRepository,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(MembershipSubscriptionRenewed $event): void
    {
        $membership = $this->membershipRepository->get($event->membershipId->toString());
        $player = $membership->player;

        if ($player->email === null) {
            return;
        }

        $email = (new TemplatedEmail())
            ->to($player->email)
            ->locale('en') // TODO: take locale from user object
            ->subject('MySpeedPuzzling subscription renewed')
            ->htmlTemplate('emails/subscription_renewed.html.twig')
            ->context([
                'nextBillingPeriod' => $membership->billingPeriodEndsAt?->format('d.m.Y H:i'),
            ]);

        $this->mailer->send($email);
    }
}
