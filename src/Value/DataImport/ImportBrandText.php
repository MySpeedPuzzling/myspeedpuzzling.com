<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Value\SearchText;

/**
 * A brand as a spreadsheet writes it, reduced to what two spellings of one brand share
 * (docs/features/data-import/README.md "Brands", "Keys, exactly").
 */
final readonly class ImportBrandText
{
    /**
     * Folded, letters and digits only: "Master Pieces", "MasterPieces" and "master-pieces" are "masterpieces",
     * "Schmidt Spiele®" is "schmidtspiele". Empty for a text without a letter or a digit.
     */
    public static function lettersAndDigits(null|string $brand): string
    {
        return $brand === null ? '' : SearchText::code($brand);
    }
}
