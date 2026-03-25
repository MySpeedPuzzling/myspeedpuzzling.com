<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\SkillTier;

readonly final class PlayerSkillResult
{
    public function __construct(
        public string $playerId,
        public int $piecesCount,
        public float $skillScore,
        public SkillTier $skillTier,
        public float $skillPercentile,
        public MetricConfidence $confidence,
        public int $qualifyingPuzzlesCount,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     pieces_count: int|string,
     *     skill_score: float|string,
     *     skill_tier: int|string,
     *     skill_percentile: float|string,
     *     confidence: string,
     *     qualifying_puzzles_count: int|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            piecesCount: (int) $row['pieces_count'],
            skillScore: (float) $row['skill_score'],
            skillTier: SkillTier::from((int) $row['skill_tier']),
            skillPercentile: (float) $row['skill_percentile'],
            confidence: MetricConfidence::from($row['confidence']),
            qualifyingPuzzlesCount: (int) $row['qualifying_puzzles_count'],
        );
    }
}
