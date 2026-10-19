<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\FitPredictionModel;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerPaceHistoryWriter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelRecalculator;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class FitPredictionModelHandler
{
    public function __construct(
        private PredictionModelRecalculator $predictionModelRecalculator,
        private ClockInterface $clock,
        private PlayerPaceHistoryWriter $paceHistoryWriter,
    ) {
    }

    /**
     * @return array{prior_groups: int, drift_rows_written: int, parameters: PredictionModelParameters}
     */
    public function __invoke(FitPredictionModel $message): array
    {
        // The fit rewrites drift and spread on player_pace_history inside this handler's transaction: the batch's pace
        // history and drift writes wait for its commit instead of locking the same rows in another order (deadlock)
        $this->paceHistoryWriter->lockUntilCommit();

        return $this->predictionModelRecalculator->fit($this->clock->now());
    }
}
