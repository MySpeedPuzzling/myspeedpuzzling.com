<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * The batch-computed inputs of the Insights page next to the player's results (GetInsightsContext, one statement):
 * the insights generation (the page cache keys on it), the player's profile card, their learning ratios, the
 * community's learning ratios and their 500-piece MSP rating.
 */
readonly final class InsightsContext
{
    /**
     * @param list<InsightsProfileAxisScore> $profileAxes empty when the player has no card (yet)
     * @param array<int, array{ratio: float, sample_size: int}> $playerImprovementRatios from attempt => the player's
     *        median time ratio of the next attempt to this one (player_improvement_ratio, all piece counts)
     * @param array<int, array<int, array{ratio: float, sample_size: int}>> $globalImprovementRatios piece count => from
     *        attempt => the community's median ratio (global_improvement_ratio, gap bucket "all")
     */
    public function __construct(
        public int $generation,
        public array $profileAxes,
        public null|DateTimeImmutable $profileComputedAt,
        public array $playerImprovementRatios,
        public array $globalImprovementRatios,
        public null|float $rating500,
        /** The place on today's 500-piece ladder; null off the ladder (private, opted out of rankings, unrated) */
        public null|int $ratingPlace500 = null,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, [], null, [], [], null);
    }
}
