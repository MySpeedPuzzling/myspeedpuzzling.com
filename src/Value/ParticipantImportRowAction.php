<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What confirming the import does with one row of the file.
 */
enum ParticipantImportRowAction: string
{
    case New = 'new';
    case Update = 'update';
    /** A participant removed on the site (soft-deleted) comes back */
    case Restore = 'restore';
    case Unchanged = 'unchanged';
    /** `status = deleted` in the file */
    case Remove = 'remove';
    /** Nothing happens - the row's messages say why (no name, ambiguous name, ...) */
    case Skipped = 'skipped';
    /** A further row of a person an earlier row already stands for - its rounds and teams add up there */
    case SamePerson = 'same_person';
}
