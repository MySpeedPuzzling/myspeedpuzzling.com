<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\PuzzleStatistics;
use SpeedPuzzling\Web\Events\PuzzleSolved;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeDeleted;
use SpeedPuzzling\Web\Events\PuzzleSolvingTimeModified;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleStatisticsRepository;
use SpeedPuzzling\Web\Services\PuzzleStatisticsCalculator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecalculatePuzzleStatisticsOnSolvingTimeChange
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PuzzleStatisticsRepository $statisticsRepository,
        private PuzzleStatisticsCalculator $calculator,
    ) {
    }

    public function __invoke(PuzzleSolved|PuzzleSolvingTimeModified|PuzzleSolvingTimeDeleted $event): void
    {
        $this->recalculateForPuzzle($event->puzzleId);
    }

    private function recalculateForPuzzle(UuidInterface $puzzleId): void
    {
        $statistics = $this->statisticsRepository->findByPuzzleId($puzzleId);

        if ($statistics === null) {
            $puzzle = $this->puzzleRepository->get($puzzleId->toString());

            $statistics = new PuzzleStatistics($puzzle);
            $this->statisticsRepository->save($statistics);
        }

        $data = $this->calculator->calculateForPuzzle($puzzleId);
        $statistics->update($data);
    }
}
