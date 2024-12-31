<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\NotifyAboutFailedPayment;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyAboutFailedPaymentHandler
{
    public function __construct(
        private MembershipRepository $membershipRepository,
        private MailerInterface $mailer,
    ) {
    }

    public function __invoke(NotifyAboutFailedPayment $message): void
    {
        $membership = $this->membershipRepository->getByStripeSubscriptionId($message->stripeSubscriptionId);
        $player = $membership->player;

        if ($player->email === null) {
            return;
        }

        $email = (new TemplatedEmail())
            ->to($player->email)
            ->locale('en') // TODO: take locale from user object
            ->subject('MySpeedPuzzling subscription renewed')
            ->htmlTemplate('emails/subscription_payment_failed.html.twig')
            ->context([]);

        $this->mailer->send($email);
    }
}
