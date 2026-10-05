<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why myspeedpuzzling:canonicalize-puzzle-codes lists a puzzle's code field in its report instead of writing it
 * (PuzzleCodesCleanup) - each one is a change proposal for a person to decide.
 */
enum PuzzleCodesCleanupReason: string
{
    // Something with a letter in the EAN field ("X002ROECA7", "None", "B-54138") - kept as it is until removed
    case EanNotANumber = 'ean_not_a_number';

    // Several numbers in one part of the EAN field ("4005556147090 4005555001997", "4795/4") - which are codes?
    case EanSeveralNumbersInOne = 'ean_several_numbers_in_one';

    // A number printed with dashes, dots or a # that is no barcode ("6000-5468", "15.427") - a brand code?
    case EanCatalogueNumber = 'ean_catalogue_number';

    // Ravensburger's 4005555…/4005556… without its two zeros - the Android reader's misread, the full code is known
    case EanRavensburgerMisread = 'ean_ravensburger_misread';

    // Something else would change beyond the format (codes in another order)
    case EanNotFormatOnly = 'ean_not_format_only';

    // A word or a placeholder in the brand code field ("Clementoni", "Alpine village", "N/A") - a code of letters only
    // ("PZFSLF", "PZL/USA") is not one
    case BrandCodeNotACode = 'brand_code_not_a_code';

    // The canonical form would change the code search key - never expected, so a person looks
    case SearchKeyWouldChange = 'search_key_would_change';
}
