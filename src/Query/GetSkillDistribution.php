<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetSkillDistribution
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Returns skill score distribution as histogram buckets for a piece count.
     *
     * @return array{labels: list<string>, counts: list<int>, player_bucket: int|null}
     */
    public function forPiecesCount(int $piecesCount, null|string $playerId = null): array
    {
        // Create 20 buckets from 0.5 to 1.5 (step 0.05)
        $bucketWidth = 0.05;
        $bucketStart = 0.50;
        $bucketEnd = 1.50;
        $bucketCount = (int) round(($bucketEnd - $bucketStart) / $bucketWidth);

        /** @var list<array{skill_score: float|string}> $rows */
        $rows = $this->database->executeQuery("
            SELECT skill_score
            FROM player_skill
            WHERE pieces_count = :piecesCount
            ORDER BY skill_score ASC
        ", [
            'piecesCount' => $piecesCount,
        ])->fetchAllAssociative();

        if ($rows === []) {
            return ['labels' => [], 'counts' => [], 'player_bucket' => null];
        }

        $labels = [];
        $counts = array_fill(0, $bucketCount, 0);

        for ($i = 0; $i < $bucketCount; $i++) {
            $low = $bucketStart + ($i * $bucketWidth);
            $labels[] = number_format($low, 2);
        }

        $playerBucket = null;
        $playerScore = null;

        if ($playerId !== null) {
            foreach ($rows as $row) {
                // Find player's score by checking all rows (player_id not in query for simplicity)
            }

            /** @var array{skill_score: float|string}|false $playerRow */
            $playerRow = $this->database->executeQuery("
                SELECT skill_score FROM player_skill WHERE player_id = :playerId AND pieces_count = :piecesCount
            ", ['playerId' => $playerId, 'piecesCount' => $piecesCount])->fetchAssociative();

            if ($playerRow !== false) {
                $playerScore = (float) $playerRow['skill_score'];
            }
        }

        foreach ($rows as $row) {
            $score = (float) $row['skill_score'];
            $bucket = (int) floor(($score - $bucketStart) / $bucketWidth);
            $bucket = max(0, min($bucketCount - 1, $bucket));
            $counts[$bucket]++;
        }

        if ($playerScore !== null) {
            $playerBucket = (int) floor(($playerScore - $bucketStart) / $bucketWidth);
            $playerBucket = max(0, min($bucketCount - 1, $playerBucket));
        }

        return [
            'labels' => $labels,
            'counts' => array_values($counts),
            'player_bucket' => $playerBucket,
        ];
    }
}
