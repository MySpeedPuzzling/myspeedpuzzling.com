<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Situations of the skill map's "Situation" group and the reasons a result in a list carries ("why" chips).
 */
enum InsightsSituation: string
{
    case FirstTries = 'first_tries';
    case Repeats = 'repeats';
    case AtEvent = 'at_event';
    case Weekend = 'weekend';
    /** More than BREAK_DAYS days since the previous solo result */
    case AfterBreak = 'after_break';
    /** Not the first solo result of its day */
    case SecondOfTheDay = 'second_of_the_day';
    case Unboxed = 'unboxed';

    public const int BREAK_DAYS = 14;
}
