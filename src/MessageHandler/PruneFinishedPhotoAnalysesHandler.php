<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\PruneFinishedPhotoAnalyses;
use SpeedPuzzling\Web\Repository\FinishedPhotoAnalysisRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * What was measured from a player's photo goes when the photo goes (docs/features/insights/finished-photos.md): a
 * deleted result takes its analysis along (cascade), a removed or replaced photo and a player who went private are
 * caught here, daily.
 */
#[AsMessageHandler]
readonly final class PruneFinishedPhotoAnalysesHandler
{
    public function __construct(
        private FinishedPhotoAnalysisRepository $finishedPhotoAnalysisRepository,
    ) {
    }

    public function __invoke(PruneFinishedPhotoAnalyses $message): int
    {
        return $this->finishedPhotoAnalysisRepository->deleteForGonePhotos();
    }
}
