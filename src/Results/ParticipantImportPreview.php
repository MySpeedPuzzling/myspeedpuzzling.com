<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ColumnMapping;
use SpeedPuzzling\Web\Value\ParticipantFileFormat;
use SpeedPuzzling\Web\Value\ParticipantFileOptions;
use SpeedPuzzling\Web\Value\ParticipantFileSheetInfo;
use SpeedPuzzling\Web\Value\ParticipantImportField;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRound;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use SpeedPuzzling\Web\Value\ParticipantSheet;
use SpeedPuzzling\Web\Value\StashedParticipantImport;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Everything the import preview shows for one state of its form (sheet, encoding, separator, mapping, mode) -
 * built the same way for the preview page and for the confirm, so the confirm applies exactly what was shown
 * (docs/features/competitions-management/participant-import-preview.md D2-D4, D8, D19).
 */
readonly final class ParticipantImportPreview
{
    /**
     * @param list<ParticipantFileSheetInfo> $sheets
     * @param list<ParticipantImportRound> $rounds
     * @param list<TranslatableMessage> $mappingErrors
     */
    public function __construct(
        public StashedParticipantImport $stashed,
        public array $sheets,
        public int $sheetIndex,
        public ParticipantFileOptions $options,
        public ParticipantImportMode $mode,
        /** null = the file (or the chosen sheet) could not be read - the page says so and offers encoding/separator */
        public null|ParticipantSheet $sheet = null,
        public array $rounds = [],
        public null|ColumnMapping $mapping = null,
        /** The mapping came from the form for exactly these columns (not detected anew) */
        public bool $mappingFromInput = false,
        public array $mappingErrors = [],
        public null|ParticipantImportRows $rows = null,
        public null|ParticipantImportPlan $plan = null,
        /** Why the file or the chosen sheet could not be read (sheet = null) */
        public null|TranslatableMessage $fileError = null,
    ) {
    }

    public function isCsv(): bool
    {
        return $this->stashed->format === ParticipantFileFormat::Csv;
    }

    /**
     * The columns of the sheet the mapping was made for - travels with the mapping, so a mapping made for other
     * columns (another encoding or separator read the file differently) is never applied to these.
     */
    public function headersKey(): string
    {
        return self::keyOfHeaders($this->sheet->headers ?? []);
    }

    /**
     * @param list<string> $headers
     */
    public static function keyOfHeaders(array $headers): string
    {
        return substr(hash('sha256', json_encode($headers, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * The form's state as query parameters - the preview URL, the hidden fields of the confirm form.
     *
     * @return array<string, int|string|array<int, string>>
     */
    public function query(null|ParticipantImportMode $mode = null): array
    {
        $query = [
            'sheet' => $this->sheetIndex,
            'encoding' => $this->options->encoding,
            'separator' => $this->options->separator,
            'mode' => ($mode ?? $this->mode)->value,
        ];

        if ($this->sheet !== null && $this->mapping !== null) {
            $query['headers'] = $this->headersKey();
            $query['map'] = $this->mapping->toQuery($this->sheet->headers);
        }

        return $query;
    }

    /**
     * The sheet chooser's link to another sheet - without the mapping, which belongs to the current sheet's columns.
     *
     * @return array<string, int|string>
     */
    public function sheetQuery(int $sheetIndex): array
    {
        return [
            'sheet' => $sheetIndex,
            'mode' => $this->mode->value,
        ];
    }

    /**
     * The rows' messages grouped by kind (D18): the first message of a kind stands for all of them, followed by the
     * rows it is on - one line instead of 230 identical warnings.
     *
     * @return list<array{message: TranslatableMessage, rows: list<int>}>
     */
    public function rowMessageGroups(): array
    {
        $groups = [];

        foreach ($this->plan->rows ?? [] as $row) {
            foreach ($row->messages as $message) {
                $key = $message->getMessage();
                $groups[$key] ??= ['message' => $message, 'rows' => []];

                if (!in_array($row->rowNumber, $groups[$key]['rows'], true)) {
                    $groups[$key]['rows'][] = $row->rowNumber;
                }
            }
        }

        return array_values($groups);
    }

    /**
     * Columns whose choice is also made for another column - marked next to their select.
     *
     * @return list<int>
     */
    public function duplicateColumns(): array
    {
        if ($this->sheet === null || $this->mapping === null) {
            return [];
        }

        $values = array_filter(
            $this->mapping->toQuery($this->sheet->headers),
            static fn (string $value): bool => $value !== ParticipantImportField::Ignore->value,
        );
        $counts = array_count_values($values);

        return array_keys(array_filter($values, static fn (string $value): bool => $counts[$value] > 1));
    }

    /**
     * People full sync takes off the event (organiser's and self-joined participants not in the file).
     */
    public function removedPeople(): int
    {
        return $this->plan?->removedPeople() ?? 0;
    }

    public function sheetInfo(): null|ParticipantFileSheetInfo
    {
        foreach ($this->sheets as $sheet) {
            if ($sheet->index === $this->sheetIndex) {
                return $sheet;
            }
        }

        return null;
    }
}
