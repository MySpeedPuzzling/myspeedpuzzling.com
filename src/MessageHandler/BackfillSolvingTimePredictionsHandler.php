<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\BackfillSolvingTimePredictions;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionReconstructor;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class BackfillSolvingTimePredictionsHandler
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private PredictionReconstructor $predictionReconstructor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int how many times got a prediction (or "not predictable")
     */
    public function __invoke(BackfillSolvingTimePredictions $message): int
    {
        // Pending ones (a live prediction is history and is never replaced) + what an edit invalidated.
        // Locked before anything is read, so the reconstruction sees every edit committed before it
        $times = $this->puzzleSolvingTimeRepository->findForPredictionOfPlayer($message->playerId, $message->reevaluateTimeIds);

        if ($times === []) {
            return 0;
        }

        $predictions = $this->predictionReconstructor->reconstruct(
            $message->playerId,
            array_map(static fn ($time): string => $time->id->toString(), $times),
        );

        $now = $this->clock->now();
        $recorded = 0;

        foreach ($times as $time) {
            $prediction = $predictions[$time->id->toString()] ?? null;

            if ($prediction !== null) {
                $time->recordPrediction($prediction, $now);
                $recorded++;
            }
        }

        return $recorded;
    }
}
