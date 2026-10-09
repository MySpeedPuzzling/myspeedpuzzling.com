<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CannotUnpublish;
use SpeedPuzzling\Web\Message\UnpublishCompetitionSeries;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Services\Drafts\UnpublishBlockers;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Back to draft only while none of its editions has participants, results or linked solving times (P8).
 */
#[AsMessageHandler]
readonly final class UnpublishCompetitionSeriesHandler
{
    public function __construct(
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private UnpublishBlockers $unpublishBlockers,
    ) {
    }

    /**
     * @throws CannotUnpublish
     */
    public function __invoke(UnpublishCompetitionSeries $message): void
    {
        $series = $this->competitionSeriesRepository->get($message->seriesId);
        $check = $this->unpublishBlockers->forSeries($series->id->toString());

        if ($check->allowed() === false) {
            throw new CannotUnpublish($check->blockers());
        }

        $series->unpublish();
    }
}
