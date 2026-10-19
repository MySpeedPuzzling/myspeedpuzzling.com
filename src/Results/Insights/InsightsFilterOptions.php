<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * What the members' filters can offer from the player's own results: their brands (most results first, at most
 * MAX_BRANDS) and the years they solved in (newest first) - so a filter never offers something with nothing behind it.
 */
readonly final class InsightsFilterOptions
{
    public const int MAX_BRANDS = 40;

    /**
     * @param array<string, string> $brands lower-case manufacturer id => name
     * @param list<int> $years
     */
    public function __construct(
        public array $brands = [],
        public array $years = [],
    ) {
    }
}
