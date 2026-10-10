<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\KarmaKind;

/**
 * A player's karma by kind (docs/features/karma.md) - what the total is made of.
 */
readonly final class KarmaBreakdown
{
    /**
     * @param array<string, int> $counts KarmaKind value => number of items
     */
    public function __construct(
        private array $counts,
    ) {
    }

    public function total(): int
    {
        $total = 0;

        foreach (KarmaKind::cases() as $kind) {
            if ($kind->counts()) {
                $total += $this->of($kind);
            }
        }

        return $total;
    }

    public function of(KarmaKind $kind): int
    {
        return $this->counts[$kind->value] ?? 0;
    }

    /**
     * What the total is made of, in KarmaKind order, kinds the player has none of left out
     *
     * @return array<string, int> KarmaKind value => count
     */
    public function countedByKind(): array
    {
        $counted = [];

        foreach (KarmaKind::cases() as $kind) {
            if ($kind->counts() && $this->of($kind) > 0) {
                $counted[$kind->value] = $this->of($kind);
            }
        }

        return $counted;
    }

    /** Every line of the history, the not-counted copies included - for paging */
    public function items(): int
    {
        return array_sum($this->counts);
    }
}
