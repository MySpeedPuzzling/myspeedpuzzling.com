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
use SpeedPuzzling\Web\Value\DataImport\DurationShapeOption;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestion;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestionType;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\ShortDateMeaning;
use SpeedPuzzling\Web\Value\DataImportLimits;

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

    public function __construct(
        private DurationReader $durationReader,
        private PiecesReader $piecesReader,
        private ImportDateReader $dateReader,
        private DurationColumnResolver $durationResolver,
        private DateOrderResolver $dateOrderResolver,
    ) {
    }

    /**
     * @param iterable<int, array<int, array<string, mixed>>> $rows the sheet's rows: row number => cells by column
     *
     * @return array{ImportSheetOptions, list<ImportQuestion>}
     */
    public function settle(ImportMapping $mapping, ImportSheetOptions $options, string $sheetTitle, iterable $rows): array
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
            if ($table->holdsRow($number) === false) {
                continue;
            }

            if (++$seen > DataImportLimits::COLUMN_DECISION_ROWS) {
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

                $questions[] = new ImportQuestion(
                    ImportQuestionType::DurationShape,
                    $time->column,
                    array_values(array_unique(array_map(static fn (DurationShapeOption $option): string => $option->rule->value, $decision->options))),
                    $decision->suggested?->value,
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
     * @param list<\SpeedPuzzling\Web\Value\DataImport\ReadDate> $dates
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
                $most = $resolution->rowsByOrder;
                arsort($most);
                $options = $options->withDateOrder(DateOrder::from((string) array_key_first($most)), ImportSheetOptions::BY_MOST_ROWS);
            } elseif ($resolution->orders !== []) {
                $options = $options->withDateOrder($resolution->orders[0], ImportSheetOptions::BY_ROWS);
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
