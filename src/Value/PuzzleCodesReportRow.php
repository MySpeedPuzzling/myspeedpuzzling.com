<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One reason why myspeedpuzzling:canonicalize-puzzle-codes leaves a puzzle's code field to a person: the field as
 * stored and as proposed, the parts concerned, and the brand codes now and as proposed (a part of the EAN field that is
 * a catalogue number moves there). Every row of one puzzle carries the same proposal - everything the report found on
 * the puzzle, so one change proposal files it all.
 */
readonly final class PuzzleCodesReportRow
{
    public const string FIELD_EAN = 'ean';
    public const string FIELD_BRAND_CODES = 'identification_number';

    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public string $field,
        public null|string $stored,
        public null|string $proposed,
        public PuzzleCodesCleanupReason $reason,
        public string $detail,
        public null|string $currentBrandCodes,
        public null|string $proposedBrandCodes,
    ) {
    }

    /**
     * The row for a spreadsheet: a cell starting like a formula (`=` `+` `-` `@`, a tab or a carriage return) gets a
     * `'` first - puzzle names and codes are typed by players.
     *
     * @return list<string>
     */
    public function toCsvRow(): array
    {
        return array_map(self::cell(...), [
            $this->puzzleId,
            $this->puzzleName,
            $this->field,
            $this->stored ?? '',
            $this->proposed ?? '',
            $this->reason->value,
            $this->detail,
            $this->currentBrandCodes ?? '',
            $this->proposedBrandCodes ?? '',
        ]);
    }

    /**
     * @return list<string>
     */
    public static function csvHeader(): array
    {
        return ['puzzle_id', 'puzzle_name', 'field', 'stored', 'proposed', 'reason', 'detail', 'current_brand_codes', 'proposed_brand_codes'];
    }

    private static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }
}
