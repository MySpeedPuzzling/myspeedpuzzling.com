<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * Where a question about a column's dates stands (DateOrderResolution, ShortDateDecision).
 */
enum DateOrderState: string
{
    /** No value of the column raises the question */
    case NotNeeded = 'not_needed';
    /** The values leave one answer */
    case Resolved = 'resolved';
    /** Several answers fit every value and read some of them differently - ask the player */
    case Ambiguous = 'ambiguous';
    /** No answer fits every value: the column mixes them - a problem */
    case Mixed = 'mixed';
    /**
     * Orders only: the text dates follow one order and every typed date of the column has a day of 12 or less - what
     * a spreadsheet leaves behind when it read day-first dates as month-first (3 April became 4 March, 13 April
     * stayed text). Ask whether the typed dates are turned around - never read the column in two orders silently.
     */
    case TypedDatesInDoubt = 'typed_dates_in_doubt';
}
