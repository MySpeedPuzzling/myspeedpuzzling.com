<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Value\DataImport\DurationReadingRule;
use SpeedPuzzling\Web\Value\DataImport\DurationShape;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestion;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;

/**
 * How a sheet's dates and times were read, as plain lines for "Check and add"
 * (docs/features/data-import/guided-flow.md §5.9 "How we read your file"): every reading the import settled for the
 * sheet - by the rows, the header, a known export or the player's own answer (ImportSheetOptions) - is said before
 * anything is added, whoever settled it. Pure: it reads what is stored and decides nothing.
 */
final class ImportSheetReadings
{
    /**
     * @param null|array<mixed> $options `data_import_sheet.options`
     *
     * @return list<array{type: string, value: string}> `date_order` (a DateOrder), `duration` (a
     *         DurationReadingRule - each rule once), `short_dates` (a ShortDateMeaning), `year` (the year of dates
     *         written without one)
     */
    public static function of(null|array $options): array
    {
        try {
            $read = ImportSheetOptions::fromArray($options);
        } catch (InvalidImportMapping) {
            return [];
        }

        $readings = [];

        if ($read->dateOrder !== null) {
            $readings[] = ['type' => 'date_order', 'value' => $read->dateOrder['order']->value];
        }

        if ($read->shortDates !== null) {
            $readings[] = ['type' => 'short_dates', 'value' => $read->shortDates['meaning']->value];
        }

        if ($read->year !== null && $read->year['year'] !== ImportQuestion::YEAR_AS_STORED) {
            $readings[] = ['type' => 'year', 'value' => (string) $read->year['year']];
        }

        $rules = [];

        foreach ($read->durations as $shape => $setting) {
            // "30:20.23" is minutes and seconds to everybody, and nobody was asked: not a reading worth a phrase
            // beside the column's own ("As written … · Minutes and seconds" read as two rules for one time)
            if ($shape === DurationShape::TwoPartTextWithHundredths->value && $setting['rule'] === DurationReadingRule::MinutesSeconds) {
                continue;
            }

            $rules[$setting['rule']->value] = true;
        }

        foreach (array_keys($rules) as $rule) {
            $readings[] = ['type' => 'duration', 'value' => $rule];
        }

        return $readings;
    }

    /**
     * What the time and the date cells of a row say in the file, as the player wrote them - for the sheet's example.
     *
     * @param null|array<mixed> $mapping `data_import_sheet.mapping`
     * @param null|array<mixed> $cells `data_import_row.cells`, by column
     *
     * @return array{time: null|string, date: null|string}
     */
    public static function said(null|array $mapping, null|array $cells): array
    {
        $said = ['time' => null, 'date' => null];
        $columns = $mapping['columns'] ?? null;

        if (is_array($columns) === false || $cells === null) {
            return $said;
        }

        foreach ($columns as $column) {
            if (is_array($column) === false || is_int($column['column'] ?? null) === false) {
                continue;
            }

            $part = match ($column['field'] ?? null) {
                ImportField::Time->value => 'time',
                ImportField::SolvedOn->value => 'date',
                default => null,
            };
            $cell = $cells[$column['column']] ?? $cells[(string) $column['column']] ?? null;

            if ($part === null || $said[$part] !== null || is_array($cell) === false) {
                continue;
            }

            $text = trim(mb_substr(StoredCells::shown($cell), 0, 60));

            if ($text !== '') {
                $said[$part] = $text;
            }
        }

        return $said;
    }
}
