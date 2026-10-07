<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Value\RegistrationEmail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The e-mails of managed registration to the player connected to a participant row, in the player's language
 * (docs/features/transactional-emails.md). Nothing for a row without a player or a player without an e-mail address.
 *
 * The event's name, entry fee and payment instructions are typed by its organiser: the templates escape them, and
 * label the payment instructions as the organiser's - MySpeedPuzzling does not process payments. The fee and the
 * instructions go out only while the event manages registration and is not over - a waitlist promoted by switching
 * management off, or a status changed after the event, never asks anybody to pay.
 */
readonly final class CompetitionRegistrationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private PlayerAccountEmail $playerAccountEmail,
        private CompetitionDetailUrl $competitionDetailUrl,
        private ClockInterface $clock,
    ) {
    }

    public function send(CompetitionParticipant $participant, RegistrationEmail $kind, null|int $waitlistPosition = null): void
    {
        $player = $participant->player;

        if ($player === null) {
            return;
        }

        $address = $this->playerAccountEmail->ofPlayer($player);

        if ($address === null) {
            return;
        }

        $locale = $player->locale ?? 'en';
        $competition = $participant->competition;
        $paymentDetails = $competition->registrationManaged && $competition->isOver($this->clock->now()) === false;

        $subject = $this->translator->trans(
            'competition_registration.' . $kind->value . '.subject',
            ['%competitionName%' => $competition->name],
            domain: 'emails',
            locale: $locale,
        );

        $email = (new TemplatedEmail())
            ->to($address)
            ->locale($locale)
            ->subject($subject)
            ->htmlTemplate('emails/competition_registration_' . $kind->value . '.html.twig')
            ->context([
                'competitionName' => $competition->name,
                'eventUrl' => $this->competitionDetailUrl->absoluteOf($competition->id->toString(), $locale),
                'entryFeeText' => $paymentDetails ? $competition->entryFeeText : null,
                'paymentInstructions' => $paymentDetails ? $competition->paymentInstructions : null,
                'waitlistPosition' => $waitlistPosition,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
