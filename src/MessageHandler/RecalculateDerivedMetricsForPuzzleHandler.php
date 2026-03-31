<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\RecalculateDerivedMetricsForPuzzle;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\DerivedMetricsCalculator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecalculateDerivedMetricsForPuzzleHandler
{
    public function __construct(
        private Connection $connection,
        private DerivedMetricsCalculator $derivedMetricsCalculator,
    ) {
    }

    public function __invoke(RecalculateDerivedMetricsForPuzzle $message): void
    {
        $puzzleId = $message->puzzleId->toString();

        $metrics = $this->derivedMetricsCalculator->calculateForPuzzle($puzzleId);

        // Update all derived metrics except memorability (needs global normalization done in hourly batch)
        $this->connection->executeStatement("
            UPDATE puzzle_difficulty SET
                skill_sensitivity_score = :skillSensitivity,
                predictability_score = :predictability,
                box_dependence_score = :boxDependence,
                improvement_ceiling_score = :improvementCeiling
            WHERE puzzle_id = :puzzleId
        ", [
            'puzzleId' => $puzzleId,
            'skillSensitivity' => $metrics['skill_sensitivity_score'],
            'predictability' => $metrics['predictability_score'],
            'boxDependence' => $metrics['box_dependence_score'],
            'improvementCeiling' => $metrics['improvement_ceiling_score'],
        ]);
    }
}
