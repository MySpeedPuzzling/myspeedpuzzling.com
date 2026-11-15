<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value\DataImport;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportShapes;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportTestKit;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;

/**
 * The mapping and the options as they are stored (docs/features/data-import/columns.md "The mapping", "Options"):
 * what was stored comes back the same, and nothing a browser could send in their shape is taken unchecked.
 */
final class ImportMappingTest extends TestCase
{
    public function testAStoredMappingAndItsOptionsComeBackTheSame(): void
    {
        $kit = new ImportTestKit();

        foreach ([ImportShapes::tenSheetWorkbook(), ImportShapes::allFormulasExport(), ImportShapes::trainingWorkbook(), ImportShapes::puzzleTrackerExport()] as $workbook) {
            foreach ($kit->workbook($workbook) as $title => $sheet) {
                $mapping = $sheet['proposal']->mapping->toArray();
                // (The kit names a looked-up sheet by its title - the application by its id)
                $options = $sheet['proposal']->options->withLookupSheet(null)->toArray();
                // Through JSON, as the database keeps them
                $storedMapping = json_decode(json_encode($mapping, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                $storedOptions = json_decode(json_encode($options, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                self::assertIsArray($storedMapping);
                self::assertIsArray($storedOptions);

                self::assertSame($mapping, ImportMapping::fromArray($storedMapping)->toArray(), (string) $title);
                self::assertSame($options, ImportSheetOptions::fromArray($storedOptions)->toArray(), (string) $title);
            }
        }
    }

    public function testTheStoredShapeIsTheOneTheDocsName(): void
    {
        $sheet = (new ImportTestKit())->workbook(ImportShapes::tenSheetWorkbook())[2023];
        $stored = $sheet['proposal']->mapping->toArray();
        /** @var array{regions: list<array<string, mixed>>, columns: list<array<string, mixed>>, questions: list<array<string, mixed>>} $mapping */
        $mapping = $stored;

        self::assertSame(['shape', 'layout', 'kind', 'kindCertainty', 'rowMeaning', 'region', 'regions', 'columns', 'questions'], array_keys($stored));
        self::assertSame([1, null, 'results', 'certain', 'result', 0], array_slice(array_values($stored), 0, 6));
        self::assertSame(
            ['range' => 'A1:E', 'firstColumn' => 1, 'lastColumn' => 5, 'headerRow' => 1, 'firstRow' => 2, 'lastRow' => null, 'source' => 'header_words', 'name' => null, 'rows' => 4, 'cut' => false],
            $mapping['regions'][0],
        );
        self::assertSame(
            ['column' => 5, 'header' => 'Time', 'field' => 'time', 'readyMade' => null, 'role' => null, 'certainty' => 'certain', 'reason' => 'header_and_values', 'offered' => null, 'unit' => null],
            $mapping['columns'][4],
        );
        self::assertSame(
            ['id' => 'duration_shape.two_part_text', 'type' => 'duration_shape', 'column' => 5, 'shape' => 'two_part_text', 'choices' => ['minutes_seconds', 'hours_minutes'], 'suggested' => null, 'examples' => ['45:10', '52:31', '40:05'], 'rows' => 4],
            $mapping['questions'][0],
        );
        self::assertSame(
            ['durations' => [], 'dateOrder' => null, 'shortDates' => null, 'typedDatesSwapped' => null, 'year' => ['year' => 2023, 'by' => 'sheet_name'], 'solvingType' => null, 'lookupSheetId' => null, 'collectionLabel' => null],
            $sheet['proposal']->options->toArray(),
        );
    }

    public function testWhatIsNotAMappingIsRefused(): void
    {
        /** @var array{columns: list<array<string, mixed>>, regions: list<array<string, mixed>>} $valid */
        $valid = (new ImportTestKit())->workbook(ImportShapes::tenSheetWorkbook())[2022]['proposal']->mapping->toArray();

        $broken = [
            'another shape' => ['shape' => 2] + $valid,
            'an unknown kind' => ['kind' => 'everything'] + $valid,
            'a table that is not there' => ['region' => 3] + $valid,
            'two columns on one single-valued field' => ['columns' => [...$valid['columns'], ['column' => 6, 'field' => 'time'] + $valid['columns'][4]]] + $valid,
            'a column named twice' => ['columns' => [...$valid['columns'], $valid['columns'][0]]] + $valid,
            'a column outside the table' => ['columns' => [['column' => 9] + $valid['columns'][0]]] + $valid,
            'an unknown field' => ['columns' => [['field' => 'shoe_size'] + $valid['columns'][0]]] + $valid,
            'a ready-made field without its name' => ['columns' => [['field' => 'ready_made_field'] + $valid['columns'][0]]] + $valid,
            'a table wider than 60 columns' => ['regions' => [['lastColumn' => 80] + $valid['regions'][0]]] + $valid,
            'an export nobody knows' => ['layout' => 'somebody_elses_app'] + $valid,
            'columns that are no list' => ['columns' => 'all of them'] + $valid,
        ];

        $taken = [];

        foreach ($broken as $what => $mapping) {
            try {
                ImportMapping::fromArray($mapping);
                $taken[] = $what;
            } catch (InvalidImportMapping) {
            }
        }

        foreach ([['year' => ['year' => 1066, 'by' => 'player']], ['dateOrder' => ['order' => 'dmy']], ['durations' => ['wavy' => ['rule' => 'minutes', 'by' => 'player']]], ['lookupSheetId' => 'the second one']] as $options) {
            try {
                ImportSheetOptions::fromArray($options);
                $taken[] = json_encode($options);
            } catch (InvalidImportMapping) {
            }
        }

        self::assertSame([], $taken);
    }
}
