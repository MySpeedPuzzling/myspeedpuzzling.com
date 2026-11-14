<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

enum BarcodeCertainty: string
{
    /** Nothing in the cell */
    case NoCode = 'no_code';
    /** At least one barcode with a right check digit */
    case Barcodes = 'barcodes';
    /** A barcode a spreadsheet has spoiled - the digits are gone, the player has to look at the box (BarcodeDamage) */
    case Damaged = 'damaged';
    /** Something else: a catalogue number, a word, "n/a" */
    case NotABarcode = 'not_a_barcode';
}
