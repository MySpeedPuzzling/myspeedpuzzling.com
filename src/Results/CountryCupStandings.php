<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCupMeasure;
use SpeedPuzzling\Web\Value\CountryCupPeriod;

/**
 * One board of the Country Cup - a measure in a period (docs/features/players-page/README.md).
 */
readonly final class CountryCupStandings
{
    /**
     * @param list<CountryCupRow> $top the leaders
     * @param list<CountryCupRow> $marked the viewer's and the page scope's country when they are not among the leaders
     */
    public function __construct(
        public CountryCupMeasure $measure,
        public CountryCupPeriod $period,
        public array $top,
        public array $marked,
    ) {
    }
}
