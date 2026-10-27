<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\FitCandidatePredictionModel;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\CandidateModelRecalculator;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class FitCandidatePredictionModelHandler
{
    public function __construct(
        private CandidateModelRecalculator $recalculator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{candidate: CandidateModelParameters, priors_written: int, evidence_written: int, taste_states_written: int, pace_offsets_written: int}
     */
    public function __invoke(FitCandidatePredictionModel $message): array
    {
        return $this->recalculator->fit($this->clock->now());
    }
}
