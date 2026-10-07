<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One sheet of an uploaded participant list, as read by ParticipantFileReader: trimmed strings only.
 */
readonly final class ParticipantSheet
{
    /**
     * @param list<string> $headers the first row, as written (trimmed)
     * @param array<int, list<string>> $rows row number as the organiser sees it in the file (header = 1) => cells,
     *                                       every row padded to count($headers) cells; rows with no value left out
     */
    public function __construct(
        public array $headers,
        public array $rows,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Up to $count non-empty values of a column, for the column mapping step.
     *
     * @return list<string>
     */
    public function samples(int $column, int $count = 3): array
    {
        $samples = [];

        foreach ($this->rows as $row) {
            $value = $row[$column] ?? '';

            if ($value !== '') {
                $samples[] = $value;
            }

            if (count($samples) >= $count) {
                break;
            }
        }

        return $samples;
    }
}
