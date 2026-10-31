<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RefreshCandidatePredictionEvidence;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\CandidateModelRecalculator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RefreshCandidatePredictionEvidenceHandler
{
    public function __construct(
        private CandidateModelRecalculator $recalculator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{evidence_written: int, fitted: bool, picture_projections_written?: int}
     */
    public function __invoke(RefreshCandidatePredictionEvidence $message): array
    {
        return $this->recalculator->refreshEvidence($this->clock->now());
    }
}
