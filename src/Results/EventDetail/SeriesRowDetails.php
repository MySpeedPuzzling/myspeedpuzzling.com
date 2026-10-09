<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\EventDetail;

/**
 * What a row or past line of the series page shows and carries beyond the events page's parts (docs/features/
 * events-page/high-frequency-series.md "Series page for 200+ editions"): the categories of its rounds (pills, when the
 * series has more than one) and the names of its revealed round puzzles - and, for the filter bar
 * (series_filter_controller.js), `data-categories`, `data-search`, `data-month`.
 */
readonly final class SeriesRowDetails
{
    /**
     * @param list<string> $categories RoundCategory values of its rounds, none without rounds
     * @param list<string> $puzzleNames its revealed round puzzles - never a secret one
     */
    public function __construct(
        public array $categories,
        public array $puzzleNames,
        // folded (SearchText::fold()): its edition name, session label and revealed round puzzles' names - the template
        // adds its date in the page's language
        public string $search,
        // Y-m of its first day, '' when undated
        public string $month,
    ) {
    }

    public function categoriesAttribute(): string
    {
        return implode(' ', $this->categories);
    }
}
