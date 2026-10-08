<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\OfficialRoundResultsPublished;
use SpeedPuzzling\Web\Message\PublishCompetitionSeries;
use SpeedPuzzling\Web\Query\GetRoundsWithPublishedOfficialResults;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Services\Organizations\CompetitionSubmittedMailer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * docs/features/organizations/README.md "Drafts": a series still waiting for approval is submitted (the admin is
 * e-mailed). Its editions keep their own draft flags (P8). Official results published while it was hidden are told now
 * (the notification itself skips editions that are still drafts - NotifyWhenOfficialRoundResultsPublished).
 */
#[AsMessageHandler]
readonly final class PublishCompetitionSeriesHandler
{
    public function __construct(
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private CompetitionSubmittedMailer $competitionSubmittedMailer,
        private GetRoundsWithPublishedOfficialResults $getRoundsWithPublishedOfficialResults,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(PublishCompetitionSeries $message): void
    {
        $series = $this->competitionSeriesRepository->get($message->seriesId);

        if ($series->isDraft === false) {
            return;
        }

        $series->publish();

        if ($series->isApproved() === false && $series->isRejected() === false) {
            $this->competitionSubmittedMailer->notifyAdmin(
                $series->name,
                $series->addedByPlayer->name ?? 'Unknown',
                $series->location ?? 'Online',
            );
        }

        if ($series->isPubliclyVisible()) {
            foreach ($this->getRoundsWithPublishedOfficialResults->ofSeries($series->id->toString()) as $roundId) {
                $this->messageBus->dispatch(new OfficialRoundResultsPublished(Uuid::fromString($roundId)), [new DispatchAfterCurrentBusStamp()]);
            }
        }
    }
}
