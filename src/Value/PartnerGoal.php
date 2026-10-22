<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a puzzler listed in the partner finder is after (docs/features/partner-finder.md) - optional on the listing.
 */
enum PartnerGoal: string
{
    case TopResult = 'top_result';
    case Either = 'either';
    case FunFirst = 'fun_first';

    public function translationKey(): string
    {
        return 'partner_finder.goal.' . $this->value;
    }
}
