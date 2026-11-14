<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use SpeedPuzzling\Web\Value\EanList;

/**
 * What BarcodeCellReader made of one cell of a barcode column (docs/features/data-import/value-readers.md "Barcodes").
 */
final readonly class ReadBarcodes
{
    /**
     * @param list<string> $codes The barcodes, each once, the way the catalogue stores and compares them: digits without
     *        leading zeros (a UPC-A typed "036000291452", or as a number that lost its zero, is "36000291452")
     * @param list<string> $other What else the cell holds, as typed: catalogue numbers, numbers with a wrong check
     *        digit, words - never compared with the catalogue as a barcode
     */
    private function __construct(
        public BarcodeCertainty $certainty,
        public array $codes = [],
        public array $other = [],
        public null|BarcodeDamage $damage = null,
    ) {
    }

    public static function noCode(): self
    {
        return new self(BarcodeCertainty::NoCode);
    }

    /**
     * @param list<string> $codes
     * @param list<string> $other
     */
    public static function of(array $codes, array $other): self
    {
        return new self($codes === [] ? BarcodeCertainty::NotABarcode : BarcodeCertainty::Barcodes, $codes, $other);
    }

    public static function damaged(BarcodeDamage $damage, string $typed): self
    {
        return new self(BarcodeCertainty::Damaged, [], [$typed], $damage);
    }

    /**
     * The barcodes as the list a puzzle's EAN field is (the one parser and writer of that field).
     */
    public function eanList(): EanList
    {
        return EanList::fromInputs($this->codes);
    }

    /**
     * The barcodes as printed under the bars: a UPC-A gets its leading zero back.
     *
     * @return list<string>
     */
    public function printed(): array
    {
        return $this->eanList()->display();
    }
}
