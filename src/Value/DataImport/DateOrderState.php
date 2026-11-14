<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

enum DateOrderState: string
{
    /** No date of the column is written in numbers whose order could be a question */
    case NotNeeded = 'not_needed';
    /** The values leave one order (or several that read every value the same) */
    case Resolved = 'resolved';
    /** Several orders fit every value and read some of them differently - ask the player */
    case Ambiguous = 'ambiguous';
    /** No order fits every value: the column mixes orders - a problem */
    case Mixed = 'mixed';
}
