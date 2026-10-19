<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\FitPredictionModel;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelRecalculator;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class FitPredictionModelHandler
{
    public function __construct(
        private PredictionModelRecalculator $predictionModelRecalculator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{prior_groups: int, drift_rows_written: int, parameters: PredictionModelParameters}
     */
    public function __invoke(FitPredictionModel $message): array
    {
        return $this->predictionModelRecalculator->fit($this->clock->now());
    }
}
