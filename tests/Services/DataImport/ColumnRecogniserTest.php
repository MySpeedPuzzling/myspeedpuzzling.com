<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportTestKit as Kit;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\DurationUnit;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\MappedColumn;
use SpeedPuzzling\Web\Value\StandardCustomField;

/**
 * docs/features/data-import/columns.md "Certainty": what each column of a table is, from its header in six
 * languages and from its values. Every sheet here is made up.
 */
final class ColumnRecogniserTest extends TestCase
{
    /**
     * @param list<string> $headers
     */
    #[DataProvider('provideHeadersInSixLanguages')]
    public function testAResultsSheetIsRecognisedByItsHeadersInEveryLanguageOfTheSite(array $headers): void
    {
        $columns = $this->recognise([
            1 => $headers,
            2 => ['Tichá zátoka', 'Dino', 1000, '18.01.2025', Kit::duration(4250)],
            3 => ['Perníková ulička', 'Trefl', 500, '02.03.2025', Kit::duration(2105)],
            4 => ['Mlýn u potoka', 'Ravensburger', 1000, '27.12.2025', Kit::duration(3880)],
        ]);

        self::assertSame(
            [ImportField::PuzzleName, ImportField::Brand, ImportField::Pieces, ImportField::SolvedOn, ImportField::Time],
            array_map(static fn (MappedColumn $column): null|ImportField => $column->field, $columns),
        );

        foreach ($columns as $column) {
            self::assertSame(ColumnCertainty::Certain, $column->certainty, $column->header);
            self::assertSame(MappedColumn::BY_HEADER_AND_VALUES, $column->reason, $column->header);
        }
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideHeadersInSixLanguages(): iterable
    {
        yield 'en' => [['Puzzle', 'Brand', 'Pieces', 'Date', 'Time']];
        yield 'en, other words' => [['Title', 'Manufacturer', 'No. of Pieces', 'Date completed', 'Solve time']];
        yield 'cs' => [['Název', 'Výrobce', 'Počet dílků', 'Datum', 'Čas']];
        yield 'de' => [['Titel', 'Hersteller', 'Teile', 'Datum', 'Zeit']];
        yield 'es' => [['Título', 'Marca', 'Piezas', 'Fecha', 'Tiempo']];
        yield 'fr' => [['Titre', 'Marque', 'Pièces', 'Date', 'Temps']];
        yield 'ja' => [['タイトル', 'メーカー', 'ピース数', '日付', 'タイム']];
    }

    public function testAColumnMySpeedPuzzlingWorksOutItselfIsNotImported(): void
    {
        $columns = $this->recognise([
            1 => ['Puzzle', 'Pieces', 'Time', 'PPM', 'Diff between previous best attempt', 'Pace'],
            2 => ['Attic Window', 1000, Kit::duration(4011), Kit::formula(Kit::number(14.96)), Kit::formula(Kit::duration(120)), 14.9],
            3 => ['Fig Tree Lane', 1000, Kit::duration(4699), Kit::formula(Kit::number(12.77)), Kit::formula(Kit::duration(31)), 12.7],
        ]);

        self::assertSame([ImportField::Computed, ColumnCertainty::Certain, MappedColumn::BY_FORMULA], [$columns[3]->field, $columns[3]->certainty, $columns[3]->reason]);
        // The header only holds a formula's word - the formulas under it make it sure
        self::assertSame([ImportField::Computed, ColumnCertainty::Certain, MappedColumn::BY_FORMULA], [$columns[4]->field, $columns[4]->certainty, $columns[4]->reason]);
        // Typed in by hand, but the header says what it is
        self::assertSame([ImportField::Computed, MappedColumn::BY_HEADER], [$columns[5]->field, $columns[5]->reason]);
    }

    public function testFormulasAloneDoNotMakeAColumnComputed(): void
    {
        $sheet = ImportShapes::allFormulasExport()[0];
        $kit = new Kit();
        $columns = $kit->analyser->analyse($sheet)->mapping->columns;

        self::assertSame(
            [ImportField::PuzzleName, ImportField::Brand, ImportField::Pieces, ImportField::Status, ImportField::ReadyMadeField, null],
            array_map(static fn (MappedColumn $column): null|ImportField => $column->field, $columns),
        );
        self::assertSame(StandardCustomField::Artist, $columns[4]->readyMade);
    }

    public function testAHeaderTwoFieldsShareIsDecidedByTheValues(): void
    {
        $asDates = $this->recognise([
            1 => ['Title', 'Completed'],
            2 => ['Lemon Grove', Kit::date('2020-01-18')],
            3 => ['Night Ferry', Kit::date('2020-02-09')],
        ]);
        $asTicks = $this->recognise([
            1 => ['Title', 'Completed'],
            2 => ['Lemon Grove', 'Y'],
            3 => ['Night Ferry', 'Y - 30/12/2023'],
        ]);

        self::assertSame([ImportField::SolvedOn, ColumnCertainty::Certain], [$asDates[1]->field, $asDates[1]->certainty]);
        self::assertSame([ImportField::Status, ColumnCertainty::Certain], [$asTicks[1]->field, $asTicks[1]->certainty]);
    }

    public function testNeverTwoColumnsOnOneSingleValuedFieldTheWeakerBecomesUnknown(): void
    {
        $columns = $this->recognise([
            1 => ['Puzzle', 'Name', 'Date', 'End date', 'Partner', 'With'],
            2 => ['Paper Boats', 'Ana', Kit::date('2021-01-30'), Kit::date('2021-01-30'), 'Ana', 'Ben'],
            3 => ['Button Jar', 'Ben', Kit::date('2021-04-03'), Kit::date('2021-04-03'), '', 'Ana'],
        ]);

        // "Puzzle" can only be the name; "Name" may be it too - the weaker one keeps it as an offer
        self::assertSame([ImportField::PuzzleName, ColumnCertainty::Certain], [$columns[0]->field, $columns[0]->certainty]);
        self::assertSame([null, ColumnCertainty::Unknown, MappedColumn::BY_DUPLICATE, ImportField::PuzzleName], [$columns[1]->field, $columns[1]->certainty, $columns[1]->reason, $columns[1]->offered]);
        // Two dates: one is the solve date, the other is offered as one
        self::assertSame(ImportField::SolvedOn, $columns[2]->field);
        self::assertSame([null, MappedColumn::BY_DUPLICATE, ImportField::SolvedOn], [$columns[3]->field, $columns[3]->reason, $columns[3]->offered]);
        // Several people columns are fine
        self::assertSame([ImportField::SolvedWith, ImportField::SolvedWith], [$columns[4]->field, $columns[5]->field]);
    }

    public function testValuesThatSpeakAgainstASureHeaderLeaveTheColumnUnknown(): void
    {
        $columns = $this->recognise([
            1 => ['Puzzle', 'Pieces'],
            2 => ['Quiet Canal', 'landscape'],
            3 => ['Paper Kites', 'portrait'],
            4 => ['Tin Robots', 'square'],
        ]);

        self::assertSame([null, ColumnCertainty::Unknown, ImportField::Pieces], [$columns[1]->field, $columns[1]->certainty, $columns[1]->offered]);
    }

    public function testAWordThatMeansSeveralThingsIsProbableAtBest(): void
    {
        $columns = $this->recognise([
            1 => ['Puzzle', 'Year', 'Size'],
            2 => ['Quiet Canal', 2019, 1000],
            3 => ['Paper Kites', 2021, 500],
        ]);

        // "Year" is the year of release - never "solved on"
        self::assertSame([ImportField::ReadyMadeField, StandardCustomField::YearOfRelease, ColumnCertainty::Probable], [$columns[1]->field, $columns[1]->readyMade, $columns[1]->certainty]);
        self::assertSame([ImportField::Pieces, ColumnCertainty::Probable], [$columns[2]->field, $columns[2]->certainty]);
    }

    public function testWithoutAKnownHeaderOnlyWhatTheValuesSayPlainlyIsOffered(): void
    {
        $columns = $this->recognise([
            1 => ['Puzzle', 'Skener', 'Trvanie', 'Kedy', 'Odkiaľ', ''],
            2 => ['Quiet Canal', '8590000000015', Kit::duration(4157), Kit::date('2024-01-06'), 'a gift', 'landscape'],
            3 => ['Paper Kites', '8590000000022', Kit::duration(3907), Kit::date('2024-02-11'), 'bazaar', ''],
            4 => ['Tin Robots', '8590000000039', Kit::duration(1811), Kit::date('2024-03-17'), 'bazaar', ''],
        ]);

        self::assertSame(
            [[ImportField::Barcode, 'probable', 'values'], [ImportField::Time, 'probable', 'values'], [ImportField::SolvedOn, 'probable', 'values'], [null, 'unknown', 'nothing'], [null, 'unknown', 'nothing']],
            array_map(static fn (MappedColumn $column): array => [$column->field, $column->certainty->value, $column->reason], array_slice($columns, 1)),
        );
        // A column without a header that holds values is offered as "Column N"
        self::assertSame(['', 6], [$columns[5]->header, $columns[5]->column]);
    }

    public function testAColumnWithoutAHeaderAndWithoutAValueIsNotShown(): void
    {
        $columns = $this->recognise([
            1 => ['Puzzle', '', 'Pieces'],
            2 => ['Quiet Canal', '', 1000],
        ]);

        self::assertSame([1, 3], array_map(static fn (MappedColumn $column): int => $column->column, $columns));
    }

    public function testAUnitInTheHeaderIsKeptWithTheTimeColumn(): void
    {
        $columns = $this->recognise([
            1 => ['Puzzle', 'Time (min)'],
            2 => ['Quiet Canal', 237],
            3 => ['Paper Kites', 61],
        ]);

        self::assertSame([ImportField::Time, DurationUnit::Minutes], [$columns[1]->field, $columns[1]->unit]);
    }

    /**
     * @param array<int, list<mixed>> $rows
     *
     * @return list<MappedColumn>
     */
    private function recognise(array $rows): array
    {
        return (new Kit())->analyser->analyse(Kit::sheet('Sheet1', $rows))->mapping->columns;
    }
}
