<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\PublishCompetition;
use SpeedPuzzling\Web\Message\PublishCompetitionSeries;
use SpeedPuzzling\Web\Message\PublishEventWizardSeries;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The series first, then its draft editions - each through its own existing message, nested in this handler's
 * transaction.
 */
#[AsMessageHandler]
readonly final class PublishEventWizardSeriesHandler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private CompetitionRepository $competitionRepository,
    ) {
    }

    public function __invoke(PublishEventWizardSeries $message): void
    {
        $series = $this->competitionSeriesRepository->get($message->seriesId);

        $this->messageBus->dispatch(new PublishCompetitionSeries($message->seriesId, notifyAdmin: $message->notifyAdmin));

        foreach ($this->competitionRepository->editionsOfSeries($series) as $edition) {
            if ($edition->isDraft) {
                $this->messageBus->dispatch(new PublishCompetition($edition->id->toString(), notifyAdmin: false));
            }
        }
    }
}
