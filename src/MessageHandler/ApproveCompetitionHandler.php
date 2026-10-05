<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\ApproveCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class ApproveCompetitionHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private PlayerAccountEmail $playerAccountEmail,
    ) {
    }

    public function __invoke(ApproveCompetition $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);
        $approvedBy = $this->playerRepository->get($message->approvedByPlayerId);

        $competition->approve($approvedBy, $this->clock->now());

        $creator = $competition->addedByPlayer;

        // Nobody needs to be told about their own approval - an admin approving a competition they created
        if ($creator === null || $creator->id->equals($approvedBy->id)) {
            return;
        }

        $creatorEmail = $this->playerAccountEmail->ofPlayer($creator);

        if ($creatorEmail !== null) {
            $playerLocale = $creator->locale ?? 'en';

            $eventUrl = $this->urlGenerator->generate('event_detail', [
                'slug' => $competition->slug,
            ], UrlGeneratorInterface::ABSOLUTE_URL);

            $subject = $this->translator->trans(
                'competition_approved.subject',
                domain: 'emails',
                locale: $playerLocale,
            );

            $email = (new TemplatedEmail())
                ->to($creatorEmail)
                ->locale($playerLocale)
                ->subject($subject)
                ->htmlTemplate('emails/competition_approved.html.twig')
                ->context([
                    'competitionName' => $competition->name,
                    'eventUrl' => $eventUrl,
                ]);
            $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

            $this->mailer->send($email);
        }
    }
}
