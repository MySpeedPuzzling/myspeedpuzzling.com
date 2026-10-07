<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One sheet of an uploaded participant list, for the sheet chooser
 * (docs/features/competitions-management/participant-import-preview.md, D3).
 */
readonly final class ParticipantFileSheetInfo
{
    public function __construct(
        /** Position in the list of non-empty sheets - what ParticipantFileReader::read() takes */
        public int $index,
        /** '' for a CSV file */
        public string $name,
        /** Hidden in the workbook - listed, never preselected */
        public bool $hidden,
        /** The number of the last row with a value */
        public int $rows,
    ) {
    }

    /**
     * @return array{index: int, name: string, hidden: bool, rows: int}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'name' => $this->name,
            'hidden' => $this->hidden,
            'rows' => $this->rows,
        ];
    }

    public static function fromArray(mixed $data): null|self
    {
        if (
            is_array($data) === false
            || is_int($data['index'] ?? null) === false
            || is_string($data['name'] ?? null) === false
            || is_bool($data['hidden'] ?? null) === false
            || is_int($data['rows'] ?? null) === false
        ) {
            return null;
        }

        return new self($data['index'], $data['name'], $data['hidden'], $data['rows']);
    }
}
