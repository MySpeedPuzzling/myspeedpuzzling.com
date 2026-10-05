<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why myspeedpuzzling:canonicalize-puzzle-codes lists a puzzle's code field in its report (PuzzleCodesCleanup) - each
 * one is a change proposal for a person to decide. None of them is ever written by the command.
 */
enum PuzzleCodesCleanupReason: string
{
    // Something with a letter in the EAN field ("X002ROECA7", "PZL6522", "No. 14114 2") - proposed as a brand code
    // (a word or a placeholder like "None" is dropped)
    case EanNotANumber = 'ean_not_a_number';

    // Several barcodes in one part of the EAN field ("4005556147090 4005555001997") - proposed split
    case EanSeveralNumbersInOne = 'ean_several_numbers_in_one';

    // A number printed with dashes, dots, a # or a slash that is no barcode ("6000-5468", "15.427", "4795/4") - a
    // catalogue number in the wrong field, proposed as a brand code
    case EanCatalogueNumber = 'ean_catalogue_number';

    // A number typed with spaces or leading zeros that is no barcode ("12 556 2", "04512") - proposed as a brand code;
    // a barcode length with a wrong check digit ("5 051237 060134") stays for a person with the box; Ravensburger's
    // code typed without its first 4 ("005556195145") gets it back
    case EanNotABarcode = 'ean_not_a_barcode';

    // Ravensburger's 4005555…/4005556… without its two zeros - the Android reader's misread, the full code is known
    case EanRavensburgerMisread = 'ean_ravensburger_misread';

    // Something else would change beyond the format - never expected, so a person looks
    case EanNotFormatOnly = 'ean_not_format_only';

    // A word or a placeholder in the brand code field ("Clementoni", "Alpine village", "N/A") - proposed removed; a code
    // of capital letters only ("PZFSLF", "PZL/USA") is a code
    case BrandCodeNotACode = 'brand_code_not_a_code';

    // A brand code with words in it ("Article 30226", "68-08 lot number 23.10.18", "UPC is 0045622965214") - kept, a
    // person rewrites it
    case BrandCodeProse = 'brand_code_prose';

    // The canonical form would change the code search key - never expected, so a person looks
    case SearchKeyWouldChange = 'search_key_would_change';
}
