<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Services\DataImport\Reading\BarcodeCellReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\HeaderMatcher;
use SpeedPuzzling\Web\Services\DataImport\Reading\ImportDateReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\PiecesReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\YesNoReader;
use SpeedPuzzling\Web\Value\DataImport\BarcodeCertainty;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\DataRegion;
use SpeedPuzzling\Web\Value\DataImport\DateCertainty;
use SpeedPuzzling\Web\Value\DataImport\DurationCertainty;
use SpeedPuzzling\Web\Value\DataImport\DurationShape;
use SpeedPuzzling\Web\Value\DataImport\FieldGuess;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\KnownExportLayout;
use SpeedPuzzling\Web\Value\DataImport\MappedColumn;
use SpeedPuzzling\Web\Value\DataImport\PiecesCertainty;
use SpeedPuzzling\Web\Value\DataImport\PiecesProblem;
use SpeedPuzzling\Web\Value\DataImport\YesNoCertainty;
use SpeedPuzzling\Web\Value\DatePrecision;

/**
 * Says what each column of a table is and how sure it is (docs/features/data-import/README.md "Recognising columns
 * and values", columns.md "Certainty"). No model - two signals:
 *
 * 1. the header against the words of every field in six languages (HeaderMatcher),
 * 2. the values: what share of a sample of the column each value reader can read.
 *
 * - A known export's column is what the export says: certain.
 * - A column MySpeedPuzzling works out itself (pieces per minute, rank, percentages) is "computed" - not imported.
 *   Formulas alone say nothing: a whole sheet may be formulas (a Google Sheets IMPORTRANGE export).
 * - A header that can only mean one field (FieldGuess::$sure) is certain unless the values speak against it.
 * - A header two fields share ("Completed": a date or a yes/no) is decided by the values; one that means several
 *   things ("Year", "Size") or only holds a known word is probable at best.
 * - Without a known header only what the values say plainly is offered: barcodes, durations, dates - probable.
 *   Times written as text too ("1:30:41" - every cell of a CSV is text). On a sheet without a header row also piece
 *   counts, dates written as text and the name.
 * - **The brand** is the one field the values say only with the catalogue's help: a column nothing else was made of,
 *   most of whose values are names of brands we know, is offered as the brand - probable, with a reason of its own
 *   (withBrandByValues(); the brands are resolved by the caller, in one read for the whole sheet).
 * - **Never two columns on one single-valued field**: the weaker one becomes unknown and keeps what it would have
 *   been as an offer.
 */
final readonly class ColumnRecogniser
{
    /** Values of a column that are looked at */
    public const int SAMPLE = 200;

    /** A column is the brand when at least this share of its distinct values, and of its cells, are brands we know */
    public const float BRAND_SHARE = 0.6;
    /** … and at least this many of its cells hold one */
    public const int BRAND_CELLS = 5;

    /** Piece counts boxes carry - a column of nothing else is a pieces column even under an unknown header */
    private const array USUAL_PIECES = [
        24, 35, 48, 54, 60, 63, 70, 99, 100, 108, 120, 150, 200, 204, 250, 260, 300, 350, 400, 450, 500, 550, 600, 636, 750, 800,
        900, 1000, 1200, 1500, 2000, 2500, 3000, 4000, 5000, 6000, 8000, 9000, 10000, 12000, 13200, 18000, 24000, 32000, 40320,
    ];

    public function __construct(
        private HeaderMatcher $headerMatcher,
        private PiecesReader $piecesReader,
        private DurationReader $durationReader,
        private ImportDateReader $dateReader,
        private YesNoReader $yesNoReader,
        private BarcodeCellReader $barcodeReader,
    ) {
    }

    /**
     * @param array<int, string> $headers column => header text ('' = none)
     * @param iterable<array<int, array<string, mixed>>> $rows rows of the table's data (cells by column), a sample
     *
     * @return list<MappedColumn> left to right; a column without a header and without a value is left out
     */
    public function recognise(DataRegion $region, array $headers, iterable $rows, null|KnownExportLayout $layout = null): array
    {
        $values = [];

        foreach ($rows as $cells) {
            foreach ($cells as $column => $cell) {
                if ($region->holdsColumn($column) && StoredCells::isBlank($cell) === false && count($values[$column] ?? []) < self::SAMPLE) {
                    $values[$column][] = $cell;
                }
            }
        }

        $columns = [];
        $strength = [];

        for ($column = $region->firstColumn; $column <= $region->lastColumn; $column++) {
            $header = $headers[$column] ?? '';

            if ($header === '' && isset($values[$column]) === false) {
                continue;
            }

            if ($layout !== null) {
                $columns[] = $layout->column($column, $header);

                continue;
            }

            [$mapped, $score] = $this->column($column, $header, $values[$column] ?? [], $region->headerRow === null);
            $strength[$column] = $score;
            $columns[] = $mapped;
        }

        // A known export with a header twice (two "Notes" columns) is held to the rule too: the first one stands
        return self::oneColumnPerField($columns, $strength);
    }

    /**
     * **The brand, from the values** (docs/features/data-import/columns.md "The brand by its values"): among the
     * columns nothing was made of - no field, no role, and no header that says something else - the one most of whose
     * values are names of brands in the catalogue becomes the brand: `probable`, `reason: brand_values`.
     *
     * - Never when a column is the brand already (a header match always wins), nor a column that has a field.
     * - A column counts when at least BRAND_SHARE of its distinct text values **and** of its cells are brands we
     *   know, in at least BRAND_CELLS cells. Of several, the one with the largest share of cells - the left one first.
     * - The brands are resolved by the caller for all candidate columns at once (ImportBrandResolver: the name as
     *   typed, then letters and digits only - exact matches, nothing similar): one read for the whole sheet.
     *
     * @param list<MappedColumn> $columns
     * @param iterable<array<int, array<string, mixed>>> $rows rows of the table's data (cells by column), a sample
     * @param callable(list<string>): array<string, string> $brandsOf the texts that are brands, by the text as given
     *
     * @return list<MappedColumn>
     */
    public static function withBrandByValues(array $columns, iterable $rows, callable $brandsOf): array
    {
        $candidates = [];

        foreach ($columns as $index => $column) {
            if ($column->field === ImportField::Brand) {
                return $columns;
            }

            // Nothing was made of it: no header we know (or none at all), or the weaker of two columns guessed
            // from their values alone
            $free = $column->field === null && $column->role === null
                && ($column->offered === null || ($column->reason === MappedColumn::BY_DUPLICATE && $column->header === ''));

            if ($free) {
                $candidates[$column->column] = $index;
            }
        }

        if ($candidates === []) {
            return $columns;
        }

        /** @var array<int, array<string, int>> $texts column => text => in how many cells */
        $texts = [];
        $seen = [];

        foreach ($rows as $cells) {
            foreach ($candidates as $number => $index) {
                $cell = $cells[$number] ?? null;

                if (StoredCells::isBlank($cell) || ($seen[$number] ?? 0) >= self::SAMPLE) {
                    continue;
                }

                $seen[$number] = ($seen[$number] ?? 0) + 1;
                $shown = trim(StoredCells::shown($cell));

                if (StoredCells::kind($cell) === 'text' && $shown !== '' && is_numeric($shown) === false && mb_strlen($shown) <= 100) {
                    $texts[$number][$shown] = ($texts[$number][$shown] ?? 0) + 1;
                }
            }
        }

        $asked = [];

        foreach ($texts as $counted) {
            // A column of fewer cells than the floor can not reach it: not asked about
            if (array_sum($counted) >= self::BRAND_CELLS) {
                foreach (array_keys($counted) as $text) {
                    $asked[(string) $text] = true;
                }
            }
        }

        if ($asked === []) {
            return $columns;
        }

        $brands = $brandsOf(array_map(strval(...), array_keys($asked)));
        $best = null;
        $bestShare = 0.0;

        foreach ($texts as $number => $counted) {
            $distinct = 0;
            $cells = 0;

            foreach ($counted as $text => $times) {
                if (isset($brands[(string) $text])) {
                    $distinct++;
                    $cells += $times;
                }
            }

            $share = $cells / max(1, $seen[$number] ?? 0);

            if ($cells >= self::BRAND_CELLS && $distinct / count($counted) >= self::BRAND_SHARE && $share >= self::BRAND_SHARE && $share > $bestShare) {
                $best = $number;
                $bestShare = $share;
            }
        }

        if ($best === null) {
            return $columns;
        }

        return array_map(
            static fn (MappedColumn $column): MappedColumn => $column->column === $best
                ? new MappedColumn($column->column, $column->header, ImportField::Brand, ColumnCertainty::Probable, MappedColumn::BY_BRAND_VALUES)
                : $column,
            $columns,
        );
    }

    /**
     * @param list<array<string, mixed>> $values
     *
     * @return array{MappedColumn, float} the column, and how strongly it is what it is (for two columns on one field)
     */
    private function column(int $number, string $header, array $values, bool $noHeaderRow): array
    {
        $guesses = $this->headerMatcher->guess($header);
        $profile = $this->profile($values);
        $count = $profile['count'];
        $column = static fn (null|ImportField $field, ColumnCertainty $certainty, string $reason, null|FieldGuess $guess = null, null|ImportField $offered = null): MappedColumn => new MappedColumn(
            $number,
            $header,
            $field,
            $certainty,
            $reason,
            $field === ImportField::ReadyMadeField ? $guess?->readyMadeField : null,
            null,
            $offered,
            $field === ImportField::Time ? $guess?->unitHint : null,
        );

        // MySpeedPuzzling works this out itself: the header says so; formulas under it make it sure
        foreach ($guesses as $position => $guess) {
            if ($guess->field !== ImportField::Computed) {
                continue;
            }

            $formulas = $count > 0 && $profile['formulas'] / $count >= 0.5;

            if ($guess->isExact() || $formulas) {
                return [$column(ImportField::Computed, ColumnCertainty::Certain, $formulas ? MappedColumn::BY_FORMULA : MappedColumn::BY_HEADER), 100.0];
            }

            if ($position === 0) {
                return [$column(ImportField::Computed, ColumnCertainty::Probable, MappedColumn::BY_HEADER), (float) $guess->score];
            }
        }

        $guesses = array_values(array_filter($guesses, static fn (FieldGuess $guess): bool => $guess->field !== ImportField::Computed));
        $exact = array_values(array_filter($guesses, static fn (FieldGuess $guess): bool => $guess->isExact()));

        foreach ($guesses as $guess) {
            if ($guess->sure === false) {
                continue;
            }

            $fit = self::fit($guess, $profile);

            if ($count === 0 || $fit >= 0.6) {
                return [$column($guess->field, ColumnCertainty::Certain, $count === 0 ? MappedColumn::BY_HEADER : MappedColumn::BY_HEADER_AND_VALUES, $guess), 100.0 + $fit];
            }

            // The values speak against the header: offered, not taken
            return [$column(null, ColumnCertainty::Unknown, MappedColumn::BY_NOTHING, null, $guess->field), 0.0];
        }

        if ($exact !== []) {
            // A word two fields share, or one that means several things: the values choose
            $fits = array_map(static fn (FieldGuess $guess): float => $count === 0 ? 0.0 : self::fit($guess, $profile), $exact);
            arsort($fits);
            $best = (int) array_key_first($fits);
            $others = $fits;
            unset($others[$best]);
            $runnerUp = $others === [] ? null : max($others);

            if ($count === 0) {
                return [$column($exact[0]->field, ColumnCertainty::Probable, MappedColumn::BY_HEADER, $exact[0]), 50.0];
            }

            if ($fits[$best] >= 0.8 && $runnerUp !== null && $runnerUp <= 0.5) {
                return [$column($exact[$best]->field, ColumnCertainty::Certain, MappedColumn::BY_HEADER_AND_VALUES, $exact[$best]), 90.0 + $fits[$best]];
            }

            if ($fits[$best] >= 0.5) {
                return [$column($exact[$best]->field, ColumnCertainty::Probable, MappedColumn::BY_HEADER_AND_VALUES, $exact[$best]), 60.0 + $fits[$best]];
            }

            return [$column(null, ColumnCertainty::Unknown, MappedColumn::BY_NOTHING, null, $exact[$best]->field), 0.0];
        }

        foreach ($guesses as $guess) {
            // The header only holds one of a field's words
            if ($count > 0 && self::fit($guess, $profile) >= 0.7) {
                return [$column($guess->field, ColumnCertainty::Probable, MappedColumn::BY_HEADER_AND_VALUES, $guess), $guess->score / 2 + self::fit($guess, $profile)];
            }
        }

        if ($guesses !== []) {
            return [$column(null, ColumnCertainty::Unknown, MappedColumn::BY_NOTHING, null, $guesses[0]->field), 0.0];
        }

        // No known word in the header: only what the values say plainly
        if ($count >= 3) {
            $byValues = match (true) {
                $profile['barcodes'] / $count >= 0.8 => ImportField::Barcode,
                $profile['durationCells'] / $count >= 0.8 => ImportField::Time,
                // Times somebody typed as text - every cell of a CSV is text: "1:30:41", "45:10", "1h 30m". As plain
                // as a duration cell, with or without a header row. Never a column of bare numbers (a year, pieces,
                // minutes without a unit say nothing by themselves), a date, or a time with words around it (notes)
                $profile['textTimes'] / $count >= 0.8 => ImportField::Time,
                $profile['dateCells'] / $count >= 0.8 => ImportField::SolvedOn,
                $noHeaderRow && $profile['usualPieces'] / $count >= 0.8 => ImportField::Pieces,
                // Dates somebody typed as text ("12.3.2024", "2024-03-12"): whole days, in every row - as plain as
                // a date cell. Only without a header row: under a header nobody knows, a column of dates may be
                // anything (bought on, released on)
                $noHeaderRow && $profile['textDays'] / $count >= 0.8 => ImportField::SolvedOn,
                $noHeaderRow && $profile['texts'] / $count >= 0.9 && $profile['distinct'] / $count >= 0.5 && $profile['length'] / $count >= 8 => ImportField::PuzzleName,
                default => null,
            };

            if ($byValues !== null) {
                return [$column($byValues, ColumnCertainty::Probable, MappedColumn::BY_VALUES), 10.0 + $profile['length'] / $count / 1000];
            }
        }

        return [$column(null, ColumnCertainty::Unknown, MappedColumn::BY_NOTHING), 0.0];
    }

    /**
     * How well a column's values go with a field: the share of them the field's reader takes, 0 - 1.
     *
     * @param array{count: int, formulas: int, texts: int, numbers: int, dateCells: int, durationCells: int, pieces: int, usualPieces: int, durations: int, dates: int, yesNo: int, barcodes: int, smallNumbers: int, distinct: int, length: int, textDays: int, textTimes: int} $profile
     */
    private static function fit(FieldGuess $guess, array $profile): float
    {
        return self::fitOf($guess->field, $profile);
    }

    /**
     * How well a sample of a column's values goes with a field somebody else said the column is (a remembered
     * layout - docs/features/data-import/layouts.md): the share of them the field's reader takes, 0 - 1. A column
     * without values says nothing against anything: 1.
     *
     * @param list<array<string, mixed>> $values
     */
    public function valuesFit(ImportField $field, array $values): float
    {
        return $values === [] ? 1.0 : self::fitOf($field, $this->profile($values));
    }

    /**
     * @param array{count: int, formulas: int, texts: int, numbers: int, dateCells: int, durationCells: int, pieces: int, usualPieces: int, durations: int, dates: int, yesNo: int, barcodes: int, smallNumbers: int, distinct: int, length: int, textDays: int, textTimes: int} $profile
     */
    private static function fitOf(ImportField $field, array $profile): float
    {
        $count = $profile['count'];

        if ($count === 0) {
            return 0.0;
        }

        return match ($field) {
            ImportField::PuzzleName, ImportField::Brand, ImportField::SolvedWith => $profile['texts'] / $count,
            ImportField::Pieces => $profile['pieces'] / $count,
            ImportField::Barcode => $profile['barcodes'] / $count,
            ImportField::SolvedOn => $profile['dates'] / $count,
            ImportField::Time => $profile['durations'] / $count,
            ImportField::FirstTry, ImportField::SolvedWithoutBox, ImportField::AlreadyOnMySpeedPuzzling, ImportField::Owned => $profile['yesNo'] / $count,
            // "Got rid of": yes / x / "Back to the library" - a word is an answer too
            ImportField::NotOwned => max($profile['yesNo'], $profile['texts']) / $count,
            ImportField::AttemptNumber => $profile['smallNumbers'] / $count,
            // A status is words or ticks - a column of dates or of numbers is not one
            ImportField::Status => max($profile['yesNo'], $profile['texts'] - $profile['dates']) / $count,
            ImportField::SolvingType => max($profile['texts'], $profile['smallNumbers']) / $count,
            default => 1.0,
        };
    }

    /**
     * @param list<array<string, mixed>> $values
     *
     * @return array{count: int, formulas: int, texts: int, numbers: int, dateCells: int, durationCells: int, pieces: int, usualPieces: int, durations: int, dates: int, yesNo: int, barcodes: int, smallNumbers: int, distinct: int, length: int, textDays: int, textTimes: int}
     */
    private function profile(array $values): array
    {
        $profile = ['count' => count($values), 'formulas' => 0, 'texts' => 0, 'numbers' => 0, 'dateCells' => 0, 'durationCells' => 0, 'pieces' => 0, 'usualPieces' => 0, 'durations' => 0, 'dates' => 0, 'yesNo' => 0, 'barcodes' => 0, 'smallNumbers' => 0, 'length' => 0, 'textDays' => 0, 'textTimes' => 0];
        $seen = [];

        foreach ($values as $cell) {
            $input = StoredCells::input($cell);
            $shown = StoredCells::shown($cell);
            $kind = StoredCells::kind($cell);
            $seen[$shown] = true;
            $profile['length'] += mb_strlen($shown);
            $profile['formulas'] += StoredCells::isFormula($cell) ? 1 : 0;
            $profile['texts'] += $kind === 'text' && is_numeric(trim($shown)) === false ? 1 : 0;
            $profile['numbers'] += $kind === 'number' ? 1 : 0;
            $profile['dateCells'] += $kind === 'date' ? 1 : 0;
            $profile['durationCells'] += $kind === 'duration' || $kind === 'time_of_day' ? 1 : 0;

            $pieces = $this->piecesReader->read($input);
            $countLike = in_array($pieces->problem, [PiecesProblem::Multipack, PiecesProblem::Unknown, PiecesProblem::PossiblyThousands], true);
            $profile['pieces'] += $pieces->certainty !== PiecesCertainty::Problem || $countLike ? 1 : 0;
            $profile['usualPieces'] += in_array($pieces->pieces, self::USUAL_PIECES, true) ? 1 : 0;

            $duration = $this->durationReader->read($input);
            $profile['durations'] += in_array($duration->certainty, [DurationCertainty::Unreadable, DurationCertainty::NoTime], true) ? 0 : 1;

            $date = $this->dateReader->read($input);
            // A bare year is a number like any other: it says nothing about a column of dates
            $profile['dates'] += $date->certainty !== DateCertainty::Unreadable && $date->certainty !== DateCertainty::NoDate
                && ($date->precision !== DatePrecision::Year || $kind === 'date') ? 1 : 0;
            // A time written as text: read by the import's own reader, whole (nothing left over), and not a number
            // without a unit - nor something the date reader takes for a day
            $profile['textTimes'] += $kind === 'text' && is_numeric(trim($shown)) === false
                && in_array($duration->certainty, [DurationCertainty::Certain, DurationCertainty::ReadFromText, DurationCertainty::TwoReadings], true)
                && $duration->shape !== DurationShape::BareNumber
                && ($date->certainty === DateCertainty::Unreadable || $date->certainty === DateCertainty::NoDate) ? 1 : 0;
            // A whole day written as text - not a number, a time or a short date ("Jan 23")
            $profile['textDays'] += $kind === 'text' && is_numeric(trim($shown)) === false
                && $date->certainty !== DateCertainty::Unreadable && $date->certainty !== DateCertainty::NoDate
                && $date->precision === DatePrecision::Day && $date->possibleShortMeanings === []
                && in_array($duration->certainty, [DurationCertainty::Unreadable, DurationCertainty::NoTime], true) ? 1 : 0;

            $profile['yesNo'] += $this->yesNoReader->read($input)->certainty === YesNoCertainty::Known ? 1 : 0;
            // A barcode a spreadsheet spoiled (4.00556E+12) still says what the column is
            $profile['barcodes'] += in_array($this->barcodeReader->read($input)->certainty, [BarcodeCertainty::Barcodes, BarcodeCertainty::Damaged], true) ? 1 : 0;
            $profile['smallNumbers'] += preg_match('/^\d{1,3}$/', trim($shown)) === 1 ? 1 : 0;
        }

        $profile['distinct'] = count($seen);

        return $profile;
    }

    /**
     * @param list<MappedColumn> $columns
     * @param array<int, float> $strength by column number
     *
     * @return list<MappedColumn>
     */
    private static function oneColumnPerField(array $columns, array $strength): array
    {
        $holder = [];

        foreach ($columns as $index => $column) {
            $slot = $column->role !== null || $column->isSingleValued() ? $column->slot() : null;

            if ($slot === null) {
                continue;
            }

            $current = $holder[$slot] ?? null;

            if ($current === null) {
                $holder[$slot] = $index;

                continue;
            }

            $stronger = [$column->certainty->rank(), $strength[$column->column] ?? 0.0] > [$columns[$current]->certainty->rank(), $strength[$columns[$current]->column] ?? 0.0];
            $weaker = $stronger ? $current : $index;
            $holder[$slot] = $stronger ? $index : $current;
            $columns[$weaker] = $columns[$weaker]->asUnknown(MappedColumn::BY_DUPLICATE);
        }

        return array_values($columns);
    }
}
