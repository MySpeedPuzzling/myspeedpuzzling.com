<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RejectCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class RejectCompetitionSeriesHandler
{
    public function __construct(
        private CompetitionSeriesRepository $seriesRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(RejectCompetitionSeries $message): void
    {
        $series = $this->seriesRepository->get($message->seriesId);
        $rejectedBy = $this->playerRepository->get($message->rejectedByPlayerId);

        $series->reject($rejectedBy, $this->clock->now(), $message->reason);

        $creator = $series->addedByPlayer;

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
                    'competitionName' => $series->name,
                    'reason' => $message->reason,
                ]);
            $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

            $this->mailer->send($email);
        }
    }
}
