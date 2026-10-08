<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How "Add several dates" repeats (docs/features/organizations/README.md "Add several dates"): every week on a weekday,
 * the 1st-4th weekday of every month, the last weekday of every month. Nothing is stored - the rule only proposes dates.
 */
enum EditionDateRuleKind: string
{
    case Weekly = 'weekly';
    case NthWeekday = 'nth_weekday';
    case LastWeekday = 'last_weekday';

    public function translationKey(): string
    {
        return 'organizer_tools.add_editions.rule.' . $this->value;
    }
}
