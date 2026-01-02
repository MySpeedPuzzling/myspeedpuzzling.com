<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class PuzzleStatisticsData
{
    public function __construct(
        public int $totalCount = 0,
        public null|int $fastestTime = null,
        public null|int $averageTime = null,
        public null|int $slowestTime = null,
        public int $soloCount = 0,
        public null|int $fastestTimeSolo = null,
        public null|int $averageTimeSolo = null,
        public null|int $slowestTimeSolo = null,
        public int $duoCount = 0,
        public null|int $fastestTimeDuo = null,
        public null|int $averageTimeDuo = null,
        public null|int $slowestTimeDuo = null,
        public int $teamCount = 0,
        public null|int $fastestTimeTeam = null,
        public null|int $averageTimeTeam = null,
        public null|int $slowestTimeTeam = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }
}
