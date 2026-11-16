<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Services\DataImport\Reading\DateOrderResolver;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationColumnResolver;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\ImportDateReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\PiecesReader;
use SpeedPuzzling\Web\Value\DataImport\DateCertainty;
use SpeedPuzzling\Web\Value\DataImport\DateOrder;
use SpeedPuzzling\Web\Value\DataImport\DateOrderState;
use SpeedPuzzling\Web\Value\DataImport\DurationColumnCell;
use SpeedPuzzling\Web\Value\DataImport\DurationReadingRule;
use SpeedPuzzling\Web\Value\DataImport\DurationShape;
use SpeedPuzzling\Web\Value\DataImport\DurationShapeOption;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestion;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestionType;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\ReadDate;
use SpeedPuzzling\Web\Value\DataImport\ShortDateMeaning;
use SpeedPuzzling\Web\Value\DataImport\SolvingTypeWords;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * Settles how a sheet's times and dates are read - and what must be asked (docs/features/data-import/columns.md
 * "Options", "Questions"). The value readers leave some shapes open on purpose (`17:36`, a bare `237`, `3/4/2023`,
 * `Jan 23`, a date without its year): here the column's rows decide them where they can (DurationColumnResolver,
 * DateOrderResolver), the header's unit and the sheet's name help, and **what is left is a question** - never a
 * silent choice. A sheet with a question waits; its rows are parsed once it is answered.
 *
 * What the player (or a known export) said stays said: only what the rows settled is worked out again.
 * At most DataImportLimits::COLUMN_DECISION_ROWS rows of the table are looked at; a value further down that does
 * not go with what they settled is a problem of its row.
 */
final readonly class ImportColumnDecisions
{
    private const int EXAMPLES = 3;
    /** A reading of a time beyond this is not offered as an answer: 99 hours */
    private const int LONGEST_BELIEVABLE_SECONDS = 99 * 3600;
    /** Rows that can only be read in the winning order, for day first against month first to be taken unasked ... */
    private const int ROWS_TO_SETTLE_THE_ORDER = 3;
    /** ... or this share of the column's dates (a column of three dates is settled by one) */
    private const float SHARE_TO_SETTLE_THE_ORDER = 0.3;

    public function __construct(
        private DurationReader $durationReader,
        private PiecesReader $piecesReader,
        private ImportDateReader $dateReader,
        private DurationColumnResolver $durationResolver,
        private DateOrderResolver $dateOrderResolver,
    ) {
    }

    /**
     * @param iterable<int, array<int, array<string, mixed>>> $rows the table's rows in their order: row number =>
     *        cells by column (GetImportSheetRows::rows() from the table's first row to its last, capped)
     *
     * @return array{ImportSheetOptions, list<ImportQuestion>}
     */
    public function settle(ImportMapping $mapping, ImportSheetOptions $options, string $sheetTitle, iterable $rows): array
    {
        [$options, $questions] = $this->timesAndDates($mapping, $options, $sheetTitle, $rows);
        $asked = self::solvingTypeQuestion($mapping, $options, $sheetTitle);

        return [$options, $asked === null ? $questions : [$asked, ...$questions]];
    }

    /** The answer "each row says" of the sheet's solo / pair / team question - offered where a column says it */
    public const string SOLVING_TYPE_BY_ROWS = 'rows';

    /**
     * **A sheet whose title says pair or team and that has no column for the partners asks** - "500 piece Pairs"
     * without one would be read as solo results, on the solo leaderboards, silently; taking the title's word would
     * be as silent the other way. Not asked when the player (or a layout, or the sheet's own setting) already said
     * what every row is, when a column names the people (the rows say it themselves: the number of people), or when
     * the player answered before.
     */
    private static function solvingTypeQuestion(ImportMapping $mapping, ImportSheetOptions $options, string $sheetTitle): null|ImportQuestion
    {
        $table = $mapping->table();

        if ($table === null || $mapping->isImported() === false || $mapping->kind !== DataImportSheetKind::Results) {
            return null;
        }

        if ($options->solvingType !== null || $options->solvingTypeAnswered || $mapping->column(ImportField::SolvedWith) !== null) {
            return null;
        }

        // Any pair or team word in the title asks - also beside other words ("Pairs and Teams", "Doubles - single
        // sitting"); the one kind the title says, if it says one, is what the question suggests
        if (SolvingTypeWords::titleNamesPairOrTeam($sheetTitle) === false) {
            return null;
        }

        $said = SolvingTypeWords::ofTitle($sheetTitle);

        $choices = [PuzzlingType::Solo->value, PuzzlingType::Duo->value, PuzzlingType::Team->value];

        if ($mapping->column(ImportField::SolvingType) !== null) {
            $choices[] = self::SOLVING_TYPE_BY_ROWS;
        }

        return new ImportQuestion(
            ImportQuestionType::SheetSolvingType,
            $mapping->column(ImportField::PuzzleName)->column ?? $table->firstColumn,
            $choices,
            $said?->value,
            [mb_substr($sheetTitle, 0, 200)],
            rows: $table->lastRow === null ? 0 : max(0, $table->lastRow - $table->firstRow + 1),
        );
    }

    /**
     * @param iterable<int, array<int, array<string, mixed>>> $rows
     *
     * @return array{ImportSheetOptions, list<ImportQuestion>}
     */
    private function timesAndDates(ImportMapping $mapping, ImportSheetOptions $options, string $sheetTitle, iterable $rows): array
    {
        $options = $options->keptAnswers();
        $table = $mapping->table();

        if ($table === null || $mapping->isImported() === false) {
            return [$options, []];
        }

        $time = $mapping->column(ImportField::Time);
        $date = $mapping->column(ImportField::SolvedOn);
        $pieces = $mapping->column(ImportField::Pieces);

        if ($time === null && $date === null) {
            return [$options, []];
        }

        $durations = [];
        $durationTexts = [];
        $dates = [];
        $dateTexts = [];
        $storedYears = [];
        $seen = 0;

        foreach ($rows as $number => $cells) {
            if ($number < $table->firstRow) {
                continue;
            }

            // Past the table, or past what is looked at: nothing more is read (the caller hands over a stream)
            if ($table->holdsRow($number) === false || ++$seen > DataImportLimits::COLUMN_DECISION_ROWS) {
                break;
            }

            if ($time !== null && isset($cells[$time->column])) {
                $count = $pieces === null ? null : $this->piecesReader->read(StoredCells::input($cells[$pieces->column] ?? null))->pieces;
                $durations[] = new DurationColumnCell($this->durationReader->read(StoredCells::input($cells[$time->column])), $count);
                $durationTexts[] = StoredCells::shown($cells[$time->column]);
            }

            if ($date !== null && isset($cells[$date->column])) {
                $dates[] = $this->dateReader->read(StoredCells::input($cells[$date->column]));
                $dateTexts[] = StoredCells::shown($cells[$date->column]);
                $storedYears[] = ($cells[$date->column]['y'] ?? true) === false ? StoredCells::storedYear($cells[$date->column]) : null;
            }
        }

        $questions = [];

        if ($time !== null && $durations !== []) {
            foreach ($this->durationResolver->resolve($durations, $time->unit)->decisions as $decision) {
                if ($options->rule($decision->shape) !== null) {
                    continue;
                }

                if ($decision->rule !== null) {
                    $options = $options->withDuration($decision->shape, $decision->rule, $decision->byHeaderUnit ? ImportSheetOptions::BY_HEADER : ImportSheetOptions::BY_ROWS);

                    continue;
                }

                // "30:20.23": two parts and a fraction are minutes and seconds - never asked. (The reader still knows
                // the "h:mm.ss" habit: the rows' pace above, a layout or an answer given earlier may say so.)
                if ($decision->shape === DurationShape::TwoPartTextWithHundredths) {
                    $options = $options->withDuration($decision->shape, DurationReadingRule::MinutesSeconds, ImportSheetOptions::BY_ROWS);

                    continue;
                }

                // A reading that makes one of the rows shown longer than anybody puzzles ("326" as 326 hours) is not
                // offered; when one reading is left, it is taken - nothing to ask
                $rules = array_values(array_unique(array_map(static fn (DurationShapeOption $option): string => $option->rule->value, $decision->options)));
                $believable = array_values(array_filter($rules, static function (string $rule) use ($decision, $durations): bool {
                    foreach ($decision->exampleRows as $row) {
                        $seconds = isset($durations[$row]) ? $durations[$row]->read->secondsBy(DurationReadingRule::from($rule)) : null;

                        if ($seconds !== null && $seconds > self::LONGEST_BELIEVABLE_SECONDS) {
                            return false;
                        }
                    }

                    return true;
                }));

                if (count($believable) === 1) {
                    $options = $options->withDuration($decision->shape, DurationReadingRule::from($believable[0]), ImportSheetOptions::BY_ROWS);

                    continue;
                }

                $rules = $believable === [] ? $rules : $believable;

                $questions[] = new ImportQuestion(
                    ImportQuestionType::DurationShape,
                    $time->column,
                    $rules,
                    $decision->suggested !== null && in_array($decision->suggested->value, $rules, true) ? $decision->suggested->value : null,
                    self::examples($durationTexts, $decision->exampleRows),
                    $decision->shape,
                    $decision->rows,
                );
            }
        }

        if ($date !== null && $dates !== []) {
            [$options, $questions] = $this->dates($date->column, $dates, $dateTexts, $storedYears, $sheetTitle, $options, $questions);
        }

        return [$options, $questions];
    }

    /**
     * The player's answers to a sheet's questions, written into its options.
     *
     * @param array<mixed> $answers question id => the chosen value
     *
     * @throws InvalidImportMapping an answer nobody asked for, or one that is none of the choices
     */
    public function answered(ImportMapping $mapping, ImportSheetOptions $options, array $answers): ImportSheetOptions
    {
        foreach ($answers as $id => $answer) {
            $question = null;

            foreach ($mapping->questions as $asked) {
                if ($asked->id() === $id) {
                    $question = $asked;
                }
            }

            if ($question === null || is_string($answer) === false || $question->accepts($answer) === false) {
                throw new InvalidImportMapping('An answer does not go with any question of the sheet.');
            }

            $options = match ($question->type) {
                ImportQuestionType::DurationShape => $question->shape === null
                    ? $options
                    : $options->withDuration($question->shape, DurationReadingRule::from($answer), ImportSheetOptions::BY_PLAYER),
                ImportQuestionType::ShortDates => $options->withShortDates(ShortDateMeaning::from($answer), ImportSheetOptions::BY_PLAYER),
                ImportQuestionType::DateOrder => $options->withDateOrder(DateOrder::from($answer), ImportSheetOptions::BY_PLAYER),
                ImportQuestionType::TypedDates => $options->withTypedDatesSwapped($answer === 'yes'),
                ImportQuestionType::Year => $options->withYear($answer === ImportQuestion::YEAR_AS_STORED ? $answer : (int) $answer, ImportSheetOptions::BY_PLAYER),
                ImportQuestionType::SheetSolvingType => $options
                    ->withSolvingType($answer === self::SOLVING_TYPE_BY_ROWS ? null : PuzzlingType::from($answer))
                    ->withSolvingTypeAnswered(),
                // "Yes, this is right": nothing to write down - the question is gone with the sheet's next version
                ImportQuestionType::CheckColumns => $options,
            };
        }

        return $options;
    }

    /**
     * The year a sheet's name says, when it says exactly one ("2019", "Results 2021").
     */
    public static function yearOf(string $sheetTitle): null|int
    {
        preg_match_all('/(?<!\d)(19[5-9]\d|20\d\d)(?!\d)/', $sheetTitle, $matches);
        $years = array_unique($matches[1]);

        return count($years) === 1 ? (int) reset($years) : null;
    }

    /**
     * @param list<ReadDate> $dates
     * @param list<string> $texts
     * @param list<null|int> $storedYears the year a date cell stores although its format hides it
     * @param list<ImportQuestion> $questions
     *
     * @return array{ImportSheetOptions, list<ImportQuestion>}
     */
    private function dates(int $column, array $dates, array $texts, array $storedYears, string $sheetTitle, ImportSheetOptions $options, array $questions): array
    {
        $resolution = $this->dateOrderResolver->resolve($dates, $options->shortDates['meaning'] ?? null);
        $short = $resolution->shortDates;

        if ($short->state === DateOrderState::Ambiguous) {
            $questions[] = new ImportQuestion(
                ImportQuestionType::ShortDates,
                $column,
                [ShortDateMeaning::DayOfMonth->value, ShortDateMeaning::MonthOfYear->value],
                $short->suggested?->value,
                self::examples($texts, $short->exampleRows),
                rows: $short->rows,
            );
        } elseif ($short->meaning !== null && $options->shortDates === null) {
            $options = $options->withShortDates($short->meaning, ImportSheetOptions::BY_ROWS);
        }

        if ($options->dateOrder === null) {
            $meaning = $short->meaning;
            $settled = array_map(static fn (ReadDate $date): ReadDate => $meaning === null ? $date : $date->asShort($meaning), $dates);

            if ($resolution->state === DateOrderState::Ambiguous) {
                $questions[] = new ImportQuestion(
                    ImportQuestionType::DateOrder,
                    $column,
                    array_map(static fn (DateOrder $order): string => $order->value, $resolution->orders),
                    $resolution->suggested?->value,
                    self::examples($texts, $resolution->exampleRows),
                    rows: $resolution->rows,
                );
            } elseif ($resolution->state === DateOrderState::Mixed) {
                // Rows that can only be day first beside rows that can only be month first: never chosen for the
                // player - asked, with a row of each. The rows that do not follow the answer are row problems
                $alive = array_keys(array_filter($resolution->rowsByOrder));
                $most = $resolution->rowsByOrder;
                arsort($most);
                $examples = [];

                foreach ($alive as $order) {
                    $examples = [...$examples, ...array_slice(self::rowsOnlyIn(DateOrder::from($order), $alive, $settled), 0, 2)];
                }

                $questions[] = new ImportQuestion(
                    ImportQuestionType::DateOrder,
                    $column,
                    array_map(strval(...), $alive),
                    (string) array_key_first($most),
                    self::examples($texts, $examples),
                    rows: $resolution->rows,
                );
            } elseif ($resolution->orders !== []) {
                $order = $resolution->orders[0];
                $other = match ($order) {
                    DateOrder::DayMonthYear => DateOrder::MonthDayYear,
                    DateOrder::MonthDayYear => DateOrder::DayMonthYear,
                    default => null,
                };
                $evidence = $other === null || ($resolution->rowsByOrder[$other->value] ?? 0) === 0
                    ? null
                    : count(self::rowsOnlyIn($order, [$order->value, $other->value], $settled));

                // One value settles a short column; in a long one a single "25/3" among hundreds of dates that read
                // both ways may be the typo - so day first against month first is taken from the rows only on some
                // evidence, and asked otherwise (with the order the evidence points to offered first)
                if ($evidence === null || $evidence >= self::ROWS_TO_SETTLE_THE_ORDER || $evidence >= self::SHARE_TO_SETTLE_THE_ORDER * $resolution->rows) {
                    $options = $options->withDateOrder($order, ImportSheetOptions::BY_ROWS);
                } else {
                    $questions[] = new ImportQuestion(
                        ImportQuestionType::DateOrder,
                        $column,
                        [$order->value, $other->value],
                        $order->value,
                        self::examples($texts, array_keys(array_filter($settled, static fn (ReadDate $date): bool => $date->allows($order) && $date->allows($other)))),
                        rows: $resolution->rows,
                    );
                }
            }
        }

        if ($resolution->state === DateOrderState::TypedDatesInDoubt && $options->typedDatesSwapped === null) {
            $questions[] = new ImportQuestion(
                ImportQuestionType::TypedDates,
                $column,
                ['yes', 'no'],
                null,
                self::examples($texts, $resolution->exampleRows),
                rows: count($resolution->exampleRows),
            );
        }

        if ($options->year !== null) {
            return [$options, $questions];
        }

        // Dates whose year nobody saw: "1/3", "20-Sep", a cell showing "m/d"
        $meaning = $options->shortDates['meaning'] ?? null;
        $without = [];
        $hidden = [];
        $allCells = true;

        foreach ($dates as $index => $read) {
            if ($read->possibleShortMeanings !== []) {
                if ($meaning === null) {
                    // Its own question comes first
                    continue;
                }

                $read = $read->asShort($meaning);
            }

            if ($read->yearKnown || in_array($read->certainty, [DateCertainty::NoDate, DateCertainty::Unreadable], true)) {
                continue;
            }

            $without[] = $index;
            $allCells = $allCells && $storedYears[$index] !== null;

            if ($storedYears[$index] !== null) {
                $hidden[$storedYears[$index]] = true;
            }
        }

        if ($without === []) {
            return [$options, $questions];
        }

        $named = self::yearOf($sheetTitle);

        if ($named !== null && ($allCells === false || array_keys($hidden) === [$named])) {
            return [$options->withYear($named, ImportSheetOptions::BY_SHEET_NAME), $questions];
        }

        // The cells hide a year and the sheet's name says another - or says none: asked, never guessed
        $questions[] = new ImportQuestion(
            ImportQuestionType::Year,
            $column,
            array_values(array_filter([$named === null ? null : (string) $named, $allCells ? ImportQuestion::YEAR_AS_STORED : null])),
            null,
            self::examples($texts, $without),
            rows: count($without),
        );

        return [$options, $questions];
    }

    /**
     * The rows (their indexes) written in numbers that can be read in one order and in none of the others.
     *
     * @param list<int|string> $among the orders compared (DateOrder's values)
     * @param array<int, ReadDate> $dates
     *
     * @return list<int>
     */
    private static function rowsOnlyIn(DateOrder $order, array $among, array $dates): array
    {
        $rows = [];

        foreach ($dates as $index => $date) {
            if ($date->possibleOrders === [] || $date->allows($order) === false) {
                continue;
            }

            foreach ($among as $other) {
                if ($other !== $order->value && $date->allows(DateOrder::from((string) $other))) {
                    continue 2;
                }
            }

            $rows[] = $index;
        }

        return $rows;
    }

    /**
     * @param list<string> $texts
     * @param array<int> $rows
     *
     * @return list<string>
     */
    private static function examples(array $texts, array $rows): array
    {
        $examples = [];

        foreach ($rows as $row) {
            if (isset($texts[$row]) && count($examples) < self::EXAMPLES && in_array($texts[$row], $examples, true) === false) {
                $examples[] = mb_substr($texts[$row], 0, 60);
            }
        }

        return $examples;
    }
}
