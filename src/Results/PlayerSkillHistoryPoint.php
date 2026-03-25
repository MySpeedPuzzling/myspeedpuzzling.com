<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class PlayerSkillHistoryPoint
{
    public function __construct(
        public DateTimeImmutable $month,
        public int $baselineSeconds,
        public null|int $skillTier,
        public null|float $skillPercentile,
    ) {
    }

    /**
     * @param array{
     *     month: string,
     *     baseline_seconds: int|string,
     *     skill_tier: null|int|string,
     *     skill_percentile: null|float|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            month: new DateTimeImmutable($row['month']),
            baselineSeconds: (int) $row['baseline_seconds'],
            skillTier: $row['skill_tier'] !== null ? (int) $row['skill_tier'] : null,
            skillPercentile: $row['skill_percentile'] !== null ? (float) $row['skill_percentile'] : null,
        );
    }
}
