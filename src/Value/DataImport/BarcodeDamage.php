<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * How a spreadsheet spoiled a barcode - the code a message explains the damage with.
 */
enum BarcodeDamage: string
{
    /** "4.00556E+12": the cell was saved the way a narrow column shows a long number */
    case ScientificNotation = 'scientific_notation';
    /** 4005560000000: a long number whose last digits became zeros and whose check digit is wrong */
    case DigitsLost = 'digits_lost';
}
