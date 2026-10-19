<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\InsightsProfileAxisScore;
use SpeedPuzzling\Web\Value\InsightsProfileAxis;

/**
 * "What kind of puzzler am I?" (free) - the 7 axes of the daily profile card, the strongest one and the most room to
 * grow (by percentile, only with ≥ 2 known axes). Not filtered: the card is about all your puzzling.
 */
readonly final class InsightsProfileCardSection
{
    /**
     * @param list<InsightsProfileAxisScore> $axes
     */
    public function __construct(
        public array $axes,
        public null|InsightsProfileAxis $strongest,
        public null|InsightsProfileAxis $roomToGrow,
        public null|DateTimeImmutable $computedAt,
        public bool $notSureYet,
    ) {
    }
}
