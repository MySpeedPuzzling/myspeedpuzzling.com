<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\OfficialRoundResultsPublished;
use SpeedPuzzling\Web\Message\ApproveCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Query\GetRoundsWithPublishedOfficialResults;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class ApproveCompetitionSeriesHandler
{
    public function __construct(
        private CompetitionSeriesRepository $seriesRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private PlayerAccountEmail $playerAccountEmail,
        private GetRoundsWithPublishedOfficialResults $getRoundsWithPublishedOfficialResults,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(ApproveCompetitionSeries $message): void
    {
        $series = $this->seriesRepository->get($message->seriesId);
        $approvedBy = $this->playerRepository->get($message->approvedByPlayerId);

        $series->approve($approvedBy, $this->clock->now());

        // Official results published while the event was not public yet - the players are told now
        foreach ($this->getRoundsWithPublishedOfficialResults->ofSeries($series->id->toString()) as $roundId) {
            $this->messageBus->dispatch(new OfficialRoundResultsPublished(Uuid::fromString($roundId)), [new DispatchAfterCurrentBusStamp()]);
        }

        $creator = $series->addedByPlayer;

        if ($message->notifyCreator === false) {
            return;
        }

        $creatorEmail = $creator === null ? null : $this->playerAccountEmail->ofPlayer($creator);

        if ($creatorEmail !== null) {
            $playerLocale = $creator->locale ?? 'en';

            $eventUrl = $this->urlGenerator->generate('competition_series_detail', [
                'slug' => $series->slug,
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
                    'competitionName' => $series->name,
                    'eventUrl' => $eventUrl,
                ]);
            $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

            $this->mailer->send($email);
        }
    }
}
