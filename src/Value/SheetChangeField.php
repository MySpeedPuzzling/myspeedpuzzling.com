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
    // The organiser's private address of the person (participant-linking.md §7.1) - for the claim invitation
    case Email = 'email';
}
