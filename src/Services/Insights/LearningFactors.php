<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

/**
 * The community's cumulative learning factor per piece count and attempt: 1.0 for attempt 1, then the product of the
 * median ratios of the transitions before it (global_improvement_ratio, gap bucket "all"), attempts beyond 4 as 4. A
 * transition resting on fewer than MIN_SAMPLES uses the 500-piece one, then 1.0. Repeats are not "strengths" just
 * because everybody is faster on a second go - residuals divide by this (docs/features/insights/computation.md §6).
 */
final class LearningFactors
{
    public const int MIN_SAMPLES = 30;

    public const int FALLBACK_PIECES = 500;

    public const int MAX_ATTEMPT = 4;

    /** @var array<int, array<int, float>> pieces => attempt => factor */
    private array $cumulative = [];

    /**
     * @param array<int, array<int, float>> $transitions pieces => from attempt (1-3) => median ratio, enough samples only
     */
    private function __construct(
        private readonly array $transitions,
    ) {
    }

    /**
     * @param array<int, array<int, array{ratio: float, sample_size: int}>> $globalRatios pieces => from attempt => ratio
     */
    public static function fromGlobalRatios(array $globalRatios): self
    {
        $transitions = [];

        foreach ($globalRatios as $pieces => $byAttempt) {
            foreach ($byAttempt as $fromAttempt => $ratio) {
                if ($fromAttempt <= self::MAX_ATTEMPT - 1 && $ratio['sample_size'] >= self::MIN_SAMPLES) {
                    $transitions[$pieces][$fromAttempt] = $ratio['ratio'];
                }
            }
        }

        return new self($transitions);
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function factor(int $pieces, int $attempt): float
    {
        $attempt = min($attempt, self::MAX_ATTEMPT);

        if ($attempt <= 1) {
            return 1.0;
        }

        if (!isset($this->cumulative[$pieces][$attempt])) {
            $factor = 1.0;

            for ($from = 1; $from < $attempt; $from++) {
                $factor *= $this->transitions[$pieces][$from] ?? $this->transitions[self::FALLBACK_PIECES][$from] ?? 1.0;
            }

            $this->cumulative[$pieces][$attempt] = $factor;
        }

        return $this->cumulative[$pieces][$attempt];
    }
}
