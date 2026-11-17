<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Bracket;

use SpeedPuzzling\Web\Results\AdvancementStepOverview;

/**
 * Who goes into a round of the bracket (competition toolkit A9): the step feeding it and its source rounds' names -
 * "Top 8 of each round from Group A, Group B".
 */
readonly final class BracketFeed
{
    /**
     * @param list<string> $sourceNames
     */
    public function __construct(
        public AdvancementStepOverview $step,
        public array $sourceNames,
    ) {
    }
}
