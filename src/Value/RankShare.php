<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A rank on a puzzle as a share of everybody on its list: "#204 of 1391 · top 14.7 %" (feature request #281,
 * docs/features/puzzle-leaderboard-chart.md "Rank share"). The share is rounded up, so it never says better than the
 * truth - one decimal, two under 1 % ("top 0.22 %"). Shown in the bottom half too: the rank next to it says the same.
 *
 * The bar next to it fills with the share of the others the rank is ahead of.
 */
final readonly class RankShare
{
    // Below this many on the list a share says little ("top 34 %" of three people) - the rank stands alone
    public const int MIN_TOTAL = 10;

    private function __construct(
        // 0.147 for "top 14.7 %", null for the first place ("fastest")
        public null|float $fraction,
        public int $fractionDigits,
        // Share of the others behind this rank, 0..1 - the bar's fill
        public float $aheadFraction,
    ) {
    }

    public static function of(int $rank, int $total): null|self
    {
        if ($total < self::MIN_TOTAL || $rank < 1 || $rank > $total) {
            return null;
        }

        $aheadFraction = ($total - $rank) / ($total - 1);

        if ($rank === 1) {
            return new self(null, 0, $aheadFraction);
        }

        // Integer maths, so 204 of 1391 is exactly 14.7 and never 14.700000001 rounded up to 14.8
        $perTenThousand = intdiv(10_000 * $rank + $total - 1, $total);

        if ($perTenThousand < 100) {
            return new self($perTenThousand / 10_000, 2, $aheadFraction);
        }

        $perMille = intdiv(1_000 * $rank + $total - 1, $total);

        return new self($perMille / 1_000, 1, $aheadFraction);
    }

    /**
     * A share that is no single rank - a median of shares: rounded up like of(), never "fastest"
     */
    public static function ofFraction(float $fraction): self
    {
        $fraction = max(0.0, min(1.0, $fraction));
        // Rounded first, so 0.147 stored as 0.14700000001 never rounds up to 14.8
        $perTenThousand = (int) ceil(round($fraction * 10_000, 6));

        if ($perTenThousand < 100) {
            return new self(max(1, $perTenThousand) / 10_000, 2, 1 - $fraction);
        }

        return new self(((int) ceil(round($fraction * 1_000, 6))) / 1_000, 1, 1 - $fraction);
    }

    public function isFastest(): bool
    {
        return $this->fraction === null;
    }
}
