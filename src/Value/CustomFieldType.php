<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a player's own field holds (docs/features/custom-fields.md). The type of a field never changes - its stored
 * values would stop making sense.
 */
enum CustomFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case YesNo = 'yes_no';
    case Choice = 'choice';
    case Tags = 'tags';
    case Rating = 'rating';
}
