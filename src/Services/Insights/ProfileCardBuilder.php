<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsProfileCardSection;
use SpeedPuzzling\Web\Results\InsightsContext;
use SpeedPuzzling\Web\Results\InsightsProfileAxisScore;

/**
 * "What kind of puzzler am I?" - the daily profile card (player_insights_profile, computation.md §6) with the axis you
 * are strongest on and the one with the most room to grow (by percentile; ties keep the axis order).
 */
readonly final class ProfileCardBuilder
{
    public function build(InsightsContext $context): InsightsProfileCardSection
    {
        $known = array_values(array_filter($context->profileAxes, static fn (InsightsProfileAxisScore $axis): bool => $axis->percentile !== null));

        // Highest percentile first; a stable sort keeps the axis order on ties
        usort($known, static fn (InsightsProfileAxisScore $a, InsightsProfileAxisScore $b): int => $b->percentile <=> $a->percentile);
        $count = count($known);

        return new InsightsProfileCardSection(
            axes: $context->profileAxes,
            strongest: $count >= 1 ? $known[0]->axis : null,
            roomToGrow: $count >= 2 ? $known[$count - 1]->axis : null,
            computedAt: $context->profileComputedAt,
            notSureYet: $known === [],
        );
    }
}
