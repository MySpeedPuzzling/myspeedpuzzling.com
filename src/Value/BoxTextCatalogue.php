<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the catalogue says about a puzzle, for the comparison with its box (BoxTextMismatchDetector).
 */
readonly final class BoxTextCatalogue
{
    public function __construct(
        public int $piecesCount,
        // puzzle.ean as stored (EanList)
        public null|string $ean,
        // puzzle.identification_number as stored (BrandCodeList)
        public null|string $brandCodes,
        public null|string $brandName,
    ) {
    }

    /**
     * The catalogue's value of a field, as a moderator reads it - null for nothing.
     */
    public function valueOf(BoxTextField $field): null|string
    {
        $value = match ($field) {
            BoxTextField::Pieces => (string) $this->piecesCount,
            BoxTextField::Ean => $this->ean,
            BoxTextField::BrandCode => $this->brandCodes,
            BoxTextField::Brand => $this->brandName,
        };

        return $value !== null && trim($value) !== '' ? $value : null;
    }
}
