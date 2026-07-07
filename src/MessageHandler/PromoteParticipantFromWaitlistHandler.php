<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\PromoteParticipantFromWaitlist;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class PromoteParticipantFromWaitlistHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(PromoteParticipantFromWaitlist $message): void
    {
        $participant = $this->participantRepository->get($message->participantId);
        $participant->promoteFromWaitlist();

        $player = $participant->player;

        if ($player === null || $player->email === null) {
            return;
        }

        $playerLocale = $player->locale ?? 'en';
        $competition = $participant->competition;

        $eventUrl = $this->urlGenerator->generate('event_detail', [
            'slug' => $competition->slug,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $subject = $this->translator->trans(
            'competition_registration_promoted.subject',
            ['%competitionName%' => $competition->name],
            domain: 'emails',
            locale: $playerLocale,
        );

        $email = (new TemplatedEmail())
            ->to($player->email)
            ->locale($playerLocale)
            ->subject($subject)
            ->htmlTemplate('emails/competition_registration_promoted.html.twig')
            ->context([
                'competitionName' => $competition->name,
                'eventUrl' => $eventUrl,
                'entryFeeText' => $competition->entryFeeText,
                'paymentInstructions' => $competition->paymentInstructions,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
