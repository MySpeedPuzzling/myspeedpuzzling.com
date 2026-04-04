<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RejectCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class RejectCompetitionHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(RejectCompetition $message): void
    {
        $competition = $this->competitionRepository->get($message->competitionId);
        $rejectedBy = $this->playerRepository->get($message->rejectedByPlayerId);

        $competition->reject($rejectedBy, $this->clock->now(), $message->reason);

        $creator = $competition->addedByPlayer;

        if ($creator?->email !== null) {
            $playerLocale = $creator->locale ?? 'en';

            $subject = $this->translator->trans(
                'competition_rejected.subject',
                domain: 'emails',
                locale: $playerLocale,
            );

            $email = (new TemplatedEmail())
                ->to($creator->email)
                ->locale($playerLocale)
                ->subject($subject)
                ->htmlTemplate('emails/competition_rejected.html.twig')
                ->context([
                    'competitionName' => $competition->name,
                    'reason' => $message->reason,
                ]);
            $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

            $this->mailer->send($email);
        }
    }
}
