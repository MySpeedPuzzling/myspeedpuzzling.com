<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A participant's own value a sheet `field` change sets.
 */
enum SheetChangeField: string
{
    case Name = 'name';
    case Country = 'country';
    case ExternalId = 'externalId';
    case Note = 'note';
}
