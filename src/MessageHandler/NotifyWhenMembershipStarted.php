<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\MembershipStarted;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyWhenMembershipStarted
{
    public function __construct(
        private MembershipRepository $membershipRepository,
        private MailerInterface $mailer,
    ) {
    }

    /**
     * @throws MembershipNotFound
     */
    public function __invoke(MembershipStarted $message): void
    {
        $membership = $this->membershipRepository->get($message->membershipId->toString());
        $player = $membership->player;

        if ($player->email === null) {
            return;
        }

        if ($membership->billingPeriodEndsAt === null) {
            $email = (new TemplatedEmail())
                ->to($player->email)
                ->locale('en') // TODO: take locale from user object
                ->subject('Congratulations to your MySpeedPuzzling membership!')
                ->htmlTemplate('emails/membership_granted.html.twig')
                ->context([
                    'membershipExpiresAt' => $membership->endsAt?->format('d.m.Y'),
                ]);

            $this->mailer->send($email);
        }

        if ($membership->billingPeriodEndsAt !== null) {
            $email = (new TemplatedEmail())
                ->to($player->email)
                ->locale('en') // TODO: take locale from user object
                ->subject('Congratulations to your MySpeedPuzzling membership!')
                ->htmlTemplate('emails/membership_subscribed.html.twig')
                ->context([
                    'nextBillingPeriod' => $membership->billingPeriodEndsAt->format('d.m.Y H:i'),
                ]);

            $this->mailer->send($email);
        }
    }
}
