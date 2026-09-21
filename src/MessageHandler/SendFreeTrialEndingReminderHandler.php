<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Message\SendFreeTrialEndingReminder;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class SendFreeTrialEndingReminderHandler
{
    public function __construct(
        private MembershipRepository $membershipRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The one e-mail of a free trial's last days. One message per membership, so each reminder is
     * marked as sent in a transaction of its own - a failure cannot make the others go out twice.
     *
     * @throws MembershipNotFound
     */
    public function __invoke(SendFreeTrialEndingReminder $message): void
    {
        $membership = $this->membershipRepository->get($message->membershipId);
        $player = $membership->player;
        $now = $this->clock->now();

        // Asked for by a query a moment ago - the player may have subscribed or the trial ended since
        if (
            $membership->trialEndsAt === null
            || $membership->trialEndsAt <= $now
            || $membership->trialEndingReminderSentAt !== null
            || $membership->stripeSubscriptionId !== null
            || $player->email === null
        ) {
            return;
        }

        $membership->trialEndingReminderSentAt = $now;

        $email = (new TemplatedEmail())
            ->to($player->email)
            ->locale($player->locale)
            ->subject($this->translator->trans('free_trial_ending.subject', domain: 'emails', locale: $player->locale))
            ->htmlTemplate('emails/free_trial_ending.html.twig')
            ->context([
                'trialEndsAt' => $membership->trialEndsAt->format('d.m.Y'),
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
