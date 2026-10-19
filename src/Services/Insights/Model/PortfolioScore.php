<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights\Model;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\MspRatingFormula;

/**
 * One portfolio of the MSP rating (its entries' points) and the mean of its best CAP entries - with the mean after
 * replacing or adding one entry in O(1), so a what-if per candidate puzzle never sorts the portfolio again
 * (docs/features/insights/computation.md §4c.2). Equal points tie however they are ordered: the sums are the same.
 */
final class PortfolioScore
{
    /** @var list<float> best first */
    private array $sorted;

    private float $topSum = 0.0;

    private int $topCount;

    /**
     * @param array<string, float> $points puzzle id => points
     */
    public function __construct(
        private readonly array $points,
    ) {
        $sorted = array_values($points);
        rsort($sorted);
        $this->sorted = $sorted;
        $this->topCount = min(MspRatingFormula::CAP, count($sorted));

        for ($i = 0; $i < $this->topCount; $i++) {
            $this->topSum += $sorted[$i];
        }
    }

    /**
     * @return array<string, float>
     */
    public function points(): array
    {
        return $this->points;
    }

    public function count(): int
    {
        return count($this->sorted);
    }

    public function has(string $puzzleId): bool
    {
        return isset($this->points[$puzzleId]);
    }

    /**
     * Mean of the counted entries; 0.0 without entries.
     */
    public function mean(): float
    {
        return $this->topCount > 0 ? $this->topSum / $this->topCount : 0.0;
    }

    /**
     * The mean after the entry of $puzzleId (when it has one) is replaced by - or, without one, $newPoints is added as
     * - a new entry.
     */
    public function meanWith(string $puzzleId, float $newPoints): float
    {
        $cap = MspRatingFormula::CAP;
        $count = count($this->sorted);
        $sum = $this->topSum;
        $old = $this->points[$puzzleId] ?? null;
        $smallestCounted = $count >= $cap ? $this->sorted[$cap - 1] : null;

        if ($old !== null) {
            if ($count <= $cap) {
                $sum -= $old;
                $smallestCounted = null;
            } elseif ($old >= $this->sorted[$cap - 1]) {
                // It was counted: the best uncounted entry moves up
                $sum += $this->sorted[$cap] - $old;
                $smallestCounted = $this->sorted[$cap];
            }

            $count--;
        }

        if ($count < $cap) {
            return ($sum + $newPoints) / ($count + 1);
        }

        assert($smallestCounted !== null);

        if ($newPoints > $smallestCounted) {
            $sum += $newPoints - $smallestCounted;
        }

        return $sum / $cap;
    }

    /**
     * A new portfolio with entries replaced or added.
     *
     * @param array<string, float> $changes puzzle id => new points
     */
    public function with(array $changes): self
    {
        return new self(array_replace($this->points, $changes));
    }

    /**
     * The counted entries' points, best first (for MspRatingFormula::rating()).
     *
     * @return list<float>
     */
    public function topPoints(): array
    {
        return array_slice($this->sorted, 0, $this->topCount);
    }

    public static function rating(float $firstAttemptMean, float $bestTimeMean, bool $hasFirstAttempts): float
    {
        if ($hasFirstAttempts === false) {
            return 0.0;
        }

        return MspRatingFormula::FIRST_ATTEMPT_WEIGHT * $firstAttemptMean + (1.0 - MspRatingFormula::FIRST_ATTEMPT_WEIGHT) * $bestTimeMean;
    }
}
