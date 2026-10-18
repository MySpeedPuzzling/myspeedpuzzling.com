<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the box says that the catalogue also knows (docs/features/puzzle-approvals.md, "Box-text mismatches"): the
 * facts the `box_text` analysis reads from the box's text and barcodes, compared with the puzzle's record.
 */
enum BoxTextField: string
{
    // Piece counts printed on the box ("1000 Teile") vs puzzle.pieces_count
    case Pieces = 'pieces';
    // Barcodes (and printed GTINs with a right check digit) vs puzzle.ean, compared through EanList
    case Ean = 'ean';
    // Article numbers ("Art. 17370") vs puzzle.identification_number, compared through BrandCodeList
    case BrandCode = 'brand_code';
    // Catalogue brand names printed on the box vs the puzzle's brand
    case Brand = 'brand';

    public function label(): string
    {
        return match ($this) {
            self::Pieces => 'Pieces',
            self::Ean => 'EAN',
            self::BrandCode => 'Brand code',
            self::Brand => 'Brand',
        };
    }
}
