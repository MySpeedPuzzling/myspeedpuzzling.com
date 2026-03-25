<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetSkillDistribution
{
    private const array BUCKET_LABELS = [
        'Much slower',
        'Slower',
        'Slightly slow',
        'Below avg',
        'Average',
        'Above avg',
        'Slightly fast',
        'Faster',
        'Much faster',
        'Top',
    ];

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Returns skill score distribution as histogram buckets for a piece count.
     * Uses 10 buckets from 0.60 to 1.40 with human-readable labels.
     *
     * @return array{labels: list<string>, counts: list<int>, player_bucket: int|null}
     */
    public function forPiecesCount(int $piecesCount, null|string $playerId = null): array
    {
        $bucketWidth = 0.08;
        $bucketStart = 0.60;
        $bucketCount = 10;

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

        $counts = array_fill(0, $bucketCount, 0);

        $playerBucket = null;
        $playerScore = null;

        if ($playerId !== null) {
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
            'labels' => self::BUCKET_LABELS,
            'counts' => $counts,
            'player_bucket' => $playerBucket,
        ];
    }
}
