<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A studio member's numbers "as of now" (analytics-studio.md R4, GetAnalyticsStudioPlayerFacts): community totals, the
 * MSP Rating at 500 pieces with its place on the ladder and the skill tier per piece count - rating and skill null for a
 * player who opted out of rankings.
 */
readonly final class AnalyticsStudioPlayerFacts
{
    /**
     * @param array<int, int> $skillTiers piece count => skill tier (1-6)
     */
    public function __construct(
        public string $playerId,
        public int $solvedTotal,
        public null|int $best500Seconds,
        public null|int $rating,
        public null|int $ratingPlace,
        public array $skillTiers,
    ) {
    }
}
