<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\PlayerRanking;

/**
 * "Median placing: top 23 % across 412 puzzles" over the listed puzzles (feature request #292) - the share of every
 * board a share is shown for (RankShare::MIN_TOTAL), so the player can tell which puzzles went better or worse than
 * their usual.
 */
final readonly class PlacingSummary
{
    private function __construct(
        public RankShare $median,
        // The puzzles the median is taken over
        public int $count,
    ) {
    }

    /**
     * @param iterable<PlayerRanking> $rankings
     */
    public static function of(iterable $rankings): null|self
    {
        $fractions = [];

        foreach ($rankings as $ranking) {
            if ($ranking->totalPlayers >= RankShare::MIN_TOTAL) {
                $fractions[] = $ranking->rank / $ranking->totalPlayers;
            }
        }

        $count = count($fractions);

        if ($count === 0) {
            return null;
        }

        sort($fractions);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1 ? $fractions[$middle] : ($fractions[$middle - 1] + $fractions[$middle]) / 2;

        return new self(RankShare::ofFraction($median), $count);
    }
}
