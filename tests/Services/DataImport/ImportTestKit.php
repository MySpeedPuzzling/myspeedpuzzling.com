<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use SpeedPuzzling\Web\Services\DataImport\ColumnRecogniser;
use SpeedPuzzling\Web\Services\DataImport\DataRegionFinder;
use SpeedPuzzling\Web\Services\DataImport\ImportColumnDecisions;
use SpeedPuzzling\Web\Services\DataImport\ImportRowParser;
use SpeedPuzzling\Web\Services\DataImport\ImportSheetAnalyser;
use SpeedPuzzling\Web\Services\DataImport\PuzzleRowFilter;
use SpeedPuzzling\Web\Services\DataImport\Reading\BarcodeCellReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\DateOrderResolver;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationColumnResolver;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\FlagWithDateReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\HeaderMatcher;
use SpeedPuzzling\Web\Services\DataImport\Reading\ImportDateReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\PiecesReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\YesNoReader;
use SpeedPuzzling\Web\Services\DataImport\SheetKindGuesser;
use SpeedPuzzling\Web\Services\DataImport\SheetLookupFactory;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetProposal;
use SpeedPuzzling\Web\Value\DataImport\ParsedImportRow;
use SpeedPuzzling\Web\Value\DataImport\SheetSample;
use Symfony\Component\Clock\MockClock;

/**
 * The import's pure services wired by hand (no container, no database), cells as `data_import_row.cells` keeps
 * them, and the whole way of a workbook - recognise, link, settle, parse - the way the handlers go it. Today is
 * TestClock's 14 October 2026.
 */
final class ImportTestKit
{
    public readonly HeaderMatcher $headerMatcher;
    public readonly DataRegionFinder $regionFinder;
    public readonly ColumnRecogniser $columnRecogniser;
    public readonly SheetKindGuesser $kindGuesser;
    public readonly ImportSheetAnalyser $analyser;
    public readonly ImportColumnDecisions $decisions;
    public readonly PuzzleRowFilter $rowFilter;
    public readonly ImportRowParser $parser;
    public readonly SheetLookupFactory $lookupFactory;

    public function __construct()
    {
        $dateReader = new ImportDateReader(new MockClock(TestClock::now()));
        $yesNoReader = new YesNoReader();
        $this->headerMatcher = new HeaderMatcher();
        $this->regionFinder = new DataRegionFinder($this->headerMatcher);
        $this->columnRecogniser = new ColumnRecogniser($this->headerMatcher, new PiecesReader(), new DurationReader(), $dateReader, $yesNoReader, new BarcodeCellReader());
        $this->kindGuesser = new SheetKindGuesser();
        $this->analyser = new ImportSheetAnalyser($this->regionFinder, $this->columnRecogniser, $this->kindGuesser);
        $this->decisions = new ImportColumnDecisions(new DurationReader(), new PiecesReader(), $dateReader, new DurationColumnResolver(), new DateOrderResolver());
        $this->rowFilter = new PuzzleRowFilter();
        $this->parser = new ImportRowParser($this->rowFilter, new DurationReader(), $dateReader, new PiecesReader(), $yesNoReader, new FlagWithDateReader($yesNoReader, $dateReader), new BarcodeCellReader());
        $this->lookupFactory = new SheetLookupFactory(new PiecesReader());
    }

    /**
     * @return array<string, mixed>
     */
    public static function text(string $text): array
    {
        return ['k' => 'text', 'v' => $text];
    }

    /**
     * @return array<string, mixed>
     */
    public static function number(int|float $number): array
    {
        return ['k' => 'number', 'v' => $number];
    }

    /**
     * @param string $date Y-m-d
     *
     * @return array<string, mixed>
     */
    public static function date(string $date, bool $yearShown = true, null|string $precision = null): array
    {
        $cell = ['k' => 'date', 'v' => $date, 's' => $yearShown ? $date : '--' . substr($date, 5)];

        if ($yearShown === false) {
            $cell['y'] = false;
        }

        if ($precision !== null) {
            $cell['p'] = $precision;
        }

        return $cell;
    }

    /**
     * @return array<string, mixed>
     */
    public static function duration(int $seconds): array
    {
        return ['k' => 'duration', 'v' => $seconds, 's' => sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function boolean(bool $value): array
    {
        return ['k' => 'boolean', 'v' => $value, 's' => $value ? 'TRUE' : 'FALSE'];
    }

    /**
     * The saved result of a formula.
     *
     * @param array<string, mixed> $cell
     *
     * @return array<string, mixed>
     */
    public static function formula(array $cell): array
    {
        return [...$cell, 'f' => true];
    }

    /**
     * A row from plain values: a string is a text, a number a number, true / false a checkbox, null no cell, an
     * array a cell as it is.
     *
     * @param list<mixed> $values
     *
     * @return array<int, array<string, mixed>> by column, from $firstColumn
     */
    public static function row(array $values, int $firstColumn = 1): array
    {
        $cells = [];

        foreach ($values as $index => $value) {
            $cell = match (true) {
                is_array($value) => $value,
                is_string($value) => $value === '' ? null : self::text($value),
                is_int($value), is_float($value) => self::number($value),
                is_bool($value) => self::boolean($value),
                default => null,
            };

            if ($cell !== null) {
                /** @var array<string, mixed> $cell */
                $cells[$firstColumn + $index] = $cell;
            }
        }

        return $cells;
    }

    /**
     * @param array<int, list<mixed>> $rows row number => plain values (self::row())
     * @param null|array<string, mixed> $hints
     */
    public static function sheet(string $title, array $rows, null|array $hints = null): SheetSample
    {
        ksort($rows);

        return new SheetSample($title, array_map(static fn (array $values): array => self::row($values), $rows), $hints, count($rows));
    }

    /**
     * A workbook the whole way the handlers take it: every sheet recognised, the puzzle list found, times and dates
     * settled, and - for a sheet that is data and asks nothing - every row parsed.
     *
     * @param list<SheetSample> $sheets
     *
     * @return array<array-key, array{proposal: ImportSheetProposal, rows: array<int, ParsedImportRow>, understood: int, needLook: int, leftOut: int}> by sheet title (a title such as "2023" is an integer key)
     */
    public function workbook(array $sheets): array
    {
        $proposals = [];
        $names = [];
        $lists = [];

        foreach ($sheets as $sheet) {
            $proposals[$sheet->title] = $this->analyser->analyse($sheet);
        }

        foreach ($sheets as $sheet) {
            if (SheetLookupFactory::needsALookup($proposals[$sheet->title]->mapping)) {
                $names[$sheet->title] = $this->lookupFactory->names($proposals[$sheet->title]->mapping, $sheet->rows);
            }
        }

        foreach ($names === [] ? [] : $sheets as $sheet) {
            if (SheetLookupFactory::couldBeTheList($proposals[$sheet->title]->mapping)) {
                $lists[$sheet->title] = $this->lookupFactory->build($proposals[$sheet->title]->mapping, $sheet->rows);
            }
        }

        $proposals = SheetLookupFactory::link($proposals, $names, $lists);
        $byTitle = [];

        foreach ($sheets as $sheet) {
            $byTitle[$sheet->title] = $sheet;
        }

        $workbook = [];

        foreach ($sheets as $sheet) {
            $proposal = $proposals[$sheet->title];
            [$options, $questions] = $this->decisions->settle($proposal->mapping, $proposal->options, $sheet->title, $sheet->rows);
            $proposal = $proposal->with($proposal->mapping, $options, $questions);
            $result = ['proposal' => $proposal, 'rows' => [], 'understood' => 0, 'needLook' => 0, 'leftOut' => 0];

            if ($proposal->mapping->isImported() && $proposal->mapping->needsAnAnswer() === false) {
                // In this kit a sheet is named by its title where the application names it by its id
                $list = $options->lookupSheetId === null ? null : $byTitle[$options->lookupSheetId] ?? null;
                $lookup = $list === null ? null : $this->lookupFactory->build($proposals[$list->title]->mapping, $list->rows);

                foreach ($sheet->rows as $number => $cells) {
                    $parsed = $this->parser->parse($number, $cells, $proposal->mapping, $options, $proposal->valueAnswers, $lookup);
                    $result['rows'][$number] = $parsed;
                    $result[$parsed->isData() === false ? 'leftOut' : ($parsed->isUnderstood() ? 'understood' : 'needLook')]++;
                }
            }

            $workbook[$sheet->title] = $result;
        }

        return $workbook;
    }
}
