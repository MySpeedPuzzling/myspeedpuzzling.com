<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ReorderPageSections;
use SpeedPuzzling\Web\Repository\CompetitionPageSectionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ReorderPageSectionsHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionSeriesRepository $seriesRepository,
        private CompetitionPageSectionRepository $sectionRepository,
    ) {
    }

    public function __invoke(ReorderPageSections $message): void
    {
        // Persist the full ordered layout (system + custom entries) in one write,
        // and mirror the order into the custom sections' position column
        $position = 1;

        foreach ($message->layout as $entry) {
            if (str_starts_with($entry['section'], 'custom:')) {
                $sectionId = substr($entry['section'], strlen('custom:'));
                $section = $this->sectionRepository->get($sectionId);
                $section->moveTo($position);
                $section->toggleVisibility($entry['visible']);
            }

            $position++;
        }

        if ($message->competitionId !== null) {
            $competition = $this->competitionRepository->get($message->competitionId);
            $competition->updatePageLayout($message->layout);
        } elseif ($message->seriesId !== null) {
            $series = $this->seriesRepository->get($message->seriesId);
            $series->updatePageLayout($message->layout);
        }
    }
}
