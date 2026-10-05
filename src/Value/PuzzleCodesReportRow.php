<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One code field of one puzzle that myspeedpuzzling:canonicalize-puzzle-codes leaves to a person: the value as stored,
 * what a change proposal would likely set (null = no code), why, and the parts concerned.
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
    ) {
    }

    /**
     * @return list<string>
     */
    public function toCsvRow(): array
    {
        return [
            $this->puzzleId,
            $this->puzzleName,
            $this->field,
            $this->stored ?? '',
            $this->proposed ?? '',
            $this->reason->value,
            $this->detail,
        ];
    }

    /**
     * @return list<string>
     */
    public static function csvHeader(): array
    {
        return ['puzzle_id', 'puzzle_name', 'field', 'stored', 'proposed', 'reason', 'detail'];
    }
}
