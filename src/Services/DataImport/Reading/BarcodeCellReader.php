<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport\Reading;

use SpeedPuzzling\Web\Value\DataImport\BarcodeDamage;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\CellInputKind;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DataImport\ReadBarcodes;
use SpeedPuzzling\Web\Value\EanList;

/**
 * Reads one cell of a barcode column - a thin layer over EanList, the one parser of barcodes
 * (docs/features/data-import/value-readers.md "Barcodes").
 *
 * What it adds is what a spreadsheet does to a barcode: typed as a number (4005556169320.0), saved in scientific
 * notation ("4.00556E+12") or with its last digits turned into zeros - reported as damaged, never repaired - and a
 * UPC-A that lost its leading zero, which EanList reads as the same code. Only a number EanList accepts as a new
 * barcode (a right check digit, a length a box carries) is handed over as one; everything else comes back as typed.
 * Never throws.
 */
final readonly class BarcodeCellReader
{
    /** A number of at least this many digits ending in at least five zeros lost its end (a barcode never ends so) */
    private const int DAMAGED_FROM_DIGITS = 12;
    private const string LOST_END = '/0{5,}$/';

    // A long number (eight digits and more) as a narrow column shows it: "4.00556E+12", "4,00556e12"
    private const string SCIENTIFIC = '/^\d(?:[.,]\d+)?e\+?(?:0?[7-9]|1\d)$/i';

    public function read(CellInput $cell): ReadBarcodes
    {
        return match ($cell->kind) {
            CellInputKind::Blank => ReadBarcodes::noCode(),
            CellInputKind::Text => $this->text($cell->asText()),
            CellInputKind::Number => $this->number($cell->asNumber()),
            CellInputKind::Boolean => ReadBarcodes::of([], [$cell->asBoolean() ? 'TRUE' : 'FALSE']),
            CellInputKind::Date => ReadBarcodes::of([], [$cell->asDate()->format('Y-m-d')]),
            CellInputKind::Duration, CellInputKind::TimeOfDay => ReadBarcodes::of([], [(string) $cell->asSeconds()]),
        };
    }

    private function number(int|float $number): ReadBarcodes
    {
        if (is_int($number)) {
            return $number > 0 ? $this->digits((string) $number) : ReadBarcodes::of([], [ImportText::ofNumber($number)]);
        }

        // A float holds 15 digits exactly: a barcode fits, anything longer is none
        if (is_nan($number) || is_infinite($number) || $number <= 0 || $number >= 1e15 || floor($number) !== $number) {
            return ReadBarcodes::of([], [ImportText::ofNumber($number)]);
        }

        return $this->digits(sprintf('%.0F', $number));
    }

    private function text(string $text): ReadBarcodes
    {
        if (ImportText::isTooLong($text)) {
            return ReadBarcodes::of([], [mb_substr(mb_scrub($text, 'UTF-8'), 0, ImportText::MAX_VALUE_LENGTH, 'UTF-8')]);
        }

        $typed = ImportText::clean($text);

        if ($typed === '') {
            return ReadBarcodes::noCode();
        }

        if (preg_match(self::SCIENTIFIC, $typed) === 1) {
            return ReadBarcodes::damaged(BarcodeDamage::ScientificNotation, $typed);
        }

        // A number written out with its decimals: "4005556169320.0"
        if (preg_match('/^(\d{8,14})[.,]0+$/', $typed, $match) === 1) {
            return $this->digits($match[1]);
        }

        if (preg_match('/^\d+$/', $typed) === 1) {
            return $this->digits($typed);
        }

        return $this->list($typed);
    }

    /**
     * One number in digits only.
     */
    private function digits(string $digits): ReadBarcodes
    {
        $read = $this->list($digits);

        if ($read->codes === [] && strlen($digits) >= self::DAMAGED_FROM_DIGITS && preg_match(self::LOST_END, $digits) === 1) {
            return ReadBarcodes::damaged(BarcodeDamage::DigitsLost, $digits);
        }

        return $read;
    }

    /**
     * Every code of a typed value, split and cleaned by EanList: its barcodes, and what else it holds.
     */
    private function list(string $typed): ReadBarcodes
    {
        $list = EanList::fromInputs([$typed]);
        $codes = [];
        $other = $list->junk();

        foreach ($list->codes() as $code) {
            if (EanList::isAcceptedNewCode($code)) {
                $codes[] = $code;
            } else {
                $other[] = $code;
            }
        }

        if ($codes === [] && $other === []) {
            // Nothing EanList keeps: "-", "---"
            $other[] = $typed;
        }

        return ReadBarcodes::of($codes, $other);
    }
}
