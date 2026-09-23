<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Message\NotifyAboutFailedPayment;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class NotifyAboutFailedPaymentHandler
{
    public function __construct(
        private MembershipRepository $membershipRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private PlayerAccountEmail $playerAccountEmail,
    ) {
    }

    public function __invoke(NotifyAboutFailedPayment $message): void
    {
        try {
            $membership = $this->membershipRepository->getByStripeSubscriptionId($message->stripeSubscriptionId);
            $player = $membership->player;
            $playerEmail = $this->playerAccountEmail->ofPlayer($player);

            if ($playerEmail === null) {
                return;
            }

            $playerLocale = $player->locale;
            $subject = $this->translator->trans(
                'subscription_payment_failed.subject',
                domain: 'emails',
                locale: $playerLocale,
            );

            $email = (new TemplatedEmail())
                ->to($playerEmail)
                ->locale($player->locale)
                ->subject($subject)
                ->htmlTemplate('emails/subscription_payment_failed.html.twig')
                ->context([]);
            $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

            $this->mailer->send($email);
        } catch (MembershipNotFound) {
            // Payment failed for new membership, ignore...
            return;
        }
    }
}
