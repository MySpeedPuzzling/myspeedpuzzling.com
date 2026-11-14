<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * How sure the import is about a time it read (docs/features/data-import/README.md "Durations", value-readers.md).
 */
enum DurationCertainty: string
{
    /** Nothing in the cell (or a zero) - a result without a time, not a problem */
    case NoTime = 'no_time';
    /** Taken as it is */
    case Certain = 'certain';
    /** Taken, and shown once as read ("8min" → 8 minutes) */
    case ReadFromText = 'read_from_text';
    /** A time with other text around it: proposed, the rest goes to the notes, needs the player's yes */
    case ReadWithLeftovers = 'read_with_leftovers';
    /** A shape with more than one meaning ("17:36") - one answer per column and shape */
    case TwoReadings = 'two_readings';
    /** No time can be read - a problem of the row */
    case Unreadable = 'unreadable';
}
