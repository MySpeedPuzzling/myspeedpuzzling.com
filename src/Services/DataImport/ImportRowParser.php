<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Services\DataImport\Reading\BarcodeCellReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\FlagWithDateReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\ImportDateReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\PiecesReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\YesNoReader;
use SpeedPuzzling\Web\Services\Export\SpreadsheetSafeValue;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\DataImport\BarcodeCertainty;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\DateCertainty;
use SpeedPuzzling\Web\Value\DataImport\DateProblem;
use SpeedPuzzling\Web\Value\DataImport\DurationCertainty;
use SpeedPuzzling\Web\Value\DataImport\DurationReading;
use SpeedPuzzling\Web\Value\DataImport\ImportColumnRole;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleKey;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestion;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestionType;
use SpeedPuzzling\Web\Value\DataImport\ImportRowLeftOut;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\ImportSourceKey;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DataImport\ImportWordMeaning;
use SpeedPuzzling\Web\Value\DataImport\KnownExportLayout;
use SpeedPuzzling\Web\Value\DataImport\MappedColumn;
use SpeedPuzzling\Web\Value\DataImport\ParsedImportRow;
use SpeedPuzzling\Web\Value\DataImport\SolvingTypeWords;
use SpeedPuzzling\Web\Value\DataImport\PiecesCertainty;
use SpeedPuzzling\Web\Value\DataImport\YesNoCertainty;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * Reads one stored row of a sheet under the sheet's mapping (docs/features/data-import/columns.md "What a parsed
 * row holds"): raw cells + mapping + options → typed values through the value readers of stage 4a, what is unclear
 * as problems per field, what the row is, and its keys (ImportPuzzleKey, ImportSourceKey - no key is made here that
 * those two do not make).
 *
 * Pure and deterministic: the same cells under the same mapping and options give the same row, whenever and
 * wherever it runs - which is what keeps a row's keys the same across a re-parse. It never throws on a value and
 * never guesses one: what the readers leave open and the sheet's options do not settle is a problem of the row.
 *
 * The result key is made with occurrence 1; rows of one sheet that are equal in all of a key are numbered against
 * each other afterwards (ImportEqualRows).
 */
final readonly class ImportRowParser
{
    private const int MAX_TEXT = 2000;
    private const int MAX_PEOPLE = 20;

    /** Folded words of a status column and what they make a row */
    private const array STATUS_WORDS = [
        'result' => [
            'completed', 'complete', 'finished', 'done', 'solved', 'hotovo', 'dokonceno', 'slozeno', 'fertig', 'abgeschlossen',
            'beendet', 'gelegt', 'terminado', 'completado', 'termine', 'fini', '完成', '完了',
        ],
        'owned' => [
            'waiting', 'waiting list', 'to do', 'todo', 'tbd', 'unsolved', 'not started', 'unopened', 'new', 'backlog', 'owned',
            'in progress', 'neslozeno', 'ceka', 'nove', 'offen', 'ungelegt', 'neu', 'pendiente', 'por hacer', 'a faire', '未完成', '未開封',
        ],
        'wish_list' => ['wish list', 'wishlist', 'wish', 'wanted', 'prani', 'chci', 'wunschliste', 'wunsch', 'deseos', 'souhait', '欲しい'],
    ];

    public function __construct(
        private PuzzleRowFilter $rowFilter,
        private DurationReader $durationReader,
        private ImportDateReader $dateReader,
        private PiecesReader $piecesReader,
        private YesNoReader $yesNoReader,
        private FlagWithDateReader $flagWithDateReader,
        private BarcodeCellReader $barcodeReader,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $cells the stored row: column (1 = A) => cell
     * @param array<array-key, mixed> $valueAnswers `data_import_sheet.value_answers`: column number => folded word =>
     *        ImportWordMeaning's value
     * @param array<array-key, mixed> $rowAnswers `data_import_row.answers`: what the player said about this very row
     *        (ImportRowAnswers) - a value typed for a field stands instead of the cell's, "leave it out" leaves it out
     */
    public function parse(
        int $rowNumber,
        array $cells,
        ImportMapping $mapping,
        ImportSheetOptions $options,
        array $valueAnswers = [],
        null|SheetLookup $lookup = null,
        array $rowAnswers = [],
    ): ParsedImportRow {
        $headers = [];

        foreach ($mapping->columns as $column) {
            $headers[$column->column] = $column->header;
        }

        $leftOut = $this->rowFilter->leftOut($rowNumber, $cells, $mapping, $headers);

        if ($leftOut !== null) {
            return ParsedImportRow::leftOut($leftOut);
        }

        if (ImportRowAnswers::leavesOut($rowAnswers)) {
            return ParsedImportRow::leftOut(ImportRowLeftOut::ByPlayer);
        }

        $layout = $mapping->layout === null ? null : KnownExportLayout::byId($mapping->layout);
        $row = new ImportRowDraft();

        foreach ($mapping->columns as $column) {
            $cell = $cells[$column->column] ?? null;

            if ($cell === null || StoredCells::isBlank($cell)) {
                continue;
            }

            $text = self::text($cell, $layout);

            if ($column->is(ImportField::Time)) {
                $row->timeCellFilled = true;
            }

            if ($column->role !== null) {
                $this->role($column->role, $text, $row);
            } elseif ($column->field !== null) {
                $this->field($column, $cell, $text, $options, $valueAnswers[$column->column] ?? null, $row);
            }

            // A word or a tag the player (or a known export) gave a meaning
            $this->words($text, $valueAnswers[$column->column] ?? null, $column->field === ImportField::ReadyMadeField ? $layout : null, $column->is(ImportField::Status), $row);
        }

        // The player's own word about a field of this row stands instead of what its cell says
        ImportRowAnswers::apply($rowAnswers, $row);

        if ($lookup !== null && $row->name !== null && ($row->brand === null || $row->pieces === null)) {
            $found = $lookup->find($row->name);

            if ($lookup->isAmbiguous($row->name)) {
                $row->problem(ImportField::PuzzleName, 'lookup_ambiguous', $row->name);
            } elseif ($found !== null) {
                if ($row->brand === null && $found['brand'] !== null) {
                    $row->brand = $found['brand'];
                    $row->from['brand'] = 'lookup';
                }

                if ($row->pieces === null && $found['pieces'] !== null && $row->hasProblemOf(ImportField::Pieces) === false) {
                    $row->pieces = $found['pieces'];
                    $row->from['pieces'] = 'lookup';
                }
            }
        }

        $hasResult = $row->date !== null || $row->seconds !== null || $row->solved === true || $row->meaning === ImportWordMeaning::Result || $row->meaning === ImportWordMeaning::Both
            || $row->hasProblemOf(ImportField::Time) || $row->hasProblemOf(ImportField::SolvedOn) || $row->untimed;
        [$leftOut, $nameMissing] = $this->rowFilter->judge(
            $row->name !== null,
            $row->brand !== null,
            $row->pieces !== null || $row->hasProblemOf(ImportField::Pieces),
            $row->barcodes !== [],
            $hasResult,
            $row->sourceRowId !== null,
        );

        if ($leftOut !== null) {
            return ParsedImportRow::leftOut($leftOut);
        }

        if ($nameMissing) {
            $row->problem(ImportField::PuzzleName, 'no_name');
        }

        $kind = self::kind($mapping, $row, $hasResult);

        if ($kind === null) {
            return ParsedImportRow::leftOut(ImportRowLeftOut::NotMine);
        }

        $isResult = $kind === DataImportRowKind::Result || $kind === DataImportRowKind::Both;

        // "Who was your partner on this sheet?" - the player's one answer for a sheet of pair / team results that has
        // no column for it (docs/features/data-import/people.md "A sheet called Pairs"): the people of every row
        // that names nobody and is not solo. Who that person is - a player, a guest - is the import's people's to say
        if ($row->people === [] && $options->partner !== null && in_array($row->type ?? $options->solvingType, [PuzzlingType::Duo, PuzzlingType::Team], true)) {
            $row->people = self::peopleOf($options->partner);
        }

        // The sheet asks "are these pair results?" (its title says pair or team, no column names the partners): until
        // it is answered, a result that says nothing itself is not solo - it waits (PendingQuestionRows)
        if ($isResult && $row->type === null && $row->people === [] && $options->solvingType === null) {
            foreach ($mapping->questions as $question) {
                if ($question->type === ImportQuestionType::SheetSolvingType) {
                    $row->problem(ImportField::SolvingType, PendingQuestionRows::TYPE_OPEN);
                }
            }
        }

        $type = $row->type ?? $options->solvingType ?? match (true) {
            count($row->people) === 0 => PuzzlingType::Solo,
            count($row->people) === 1 => PuzzlingType::Duo,
            default => PuzzlingType::Team,
        };
        // First try is off unless a column says so - a first-try column, or attempt number 1 (README D15)
        $firstTry = $row->firstTry ?? ($row->attempt === null ? null : $row->attempt === 1);

        $puzzleKey = $row->name === null ? null : ImportPuzzleKey::of($row->name, $row->pieces, $row->brand);
        $keys = [];

        if ($puzzleKey !== null) {
            $keys['puzzle'] = $puzzleKey;

            if ($isResult) {
                $keys['result'] = ImportSourceKey::result($puzzleKey, $row->date, $row->precision, $row->seconds, $type);
            }

            if ($kind === DataImportRowKind::Owned || $kind === DataImportRowKind::Both) {
                $keys['owned'] = ImportSourceKey::owned($puzzleKey, $row->collection ?? $options->collectionLabel);
            }

            if ($kind === DataImportRowKind::WishList) {
                $keys['wishList'] = ImportSourceKey::wishList($puzzleKey);
            }
        }

        $parsed = array_filter([
            'name' => $row->name,
            'brand' => $row->brand,
            'pieces' => $row->pieces,
            'barcodes' => $row->barcodes,
            'otherCodes' => $row->otherCodes,
            'brandCodes' => $row->brandCodes,
            'puzzleId' => $row->puzzleId,
            'pictureUrl' => $row->pictureUrl,
            'date' => $isResult && $row->date !== null ? ImportSourceKey::dateAt($row->date, $row->precision) : null,
            'datePrecision' => $isResult && $row->date !== null ? $row->precision->value : null,
            'seconds' => $isResult ? $row->seconds : null,
            'type' => $isResult ? $type->value : null,
            'people' => $isResult ? $row->people : [],
            'firstTry' => $isResult ? $firstTry : null,
            'attempt' => $isResult ? $row->attempt : null,
            'withoutBox' => $isResult ? $row->withoutBox : null,
            'alreadyOnMsp' => $isResult ? $row->alreadyOnMsp : null,
            'comment' => $row->comment,
            'collection' => $row->collection,
            'notes' => $row->notes,
            'custom' => $row->custom,
            'from' => $row->from,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        // What tells two rows with the same key apart in a stable way (ImportEqualRows) - never their place on the sheet.
        // Partial output never throws (a value json can not hold becomes null), so the hash is always made
        $parsed['content'] = hash('sha256', (string) json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $parsed['keys'] = $keys;

        return new ParsedImportRow(
            $kind,
            $parsed,
            $row->problems,
            $puzzleKey,
            $keys['result'] ?? $keys['owned'] ?? $keys['wishList'] ?? null,
            $row->sourceRowId,
        );
    }

    private function role(ImportColumnRole $role, string $text, ImportRowDraft $row): void
    {
        match ($role) {
            ImportColumnRole::SourceRowId => $row->sourceRowId = mb_substr($text, 0, 200),
            ImportColumnRole::PuzzleId => $row->puzzleId = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $text) === 1 ? strtolower($text) : null,
            ImportColumnRole::PictureUrl => $row->pictureUrl = preg_match('~^https://[^\s<>"\']{4,1000}$~', $text) === 1 ? $text : null,
        };
    }

    /**
     * @param array<string, mixed> $cell
     */
    private function field(MappedColumn $column, array $cell, string $text, ImportSheetOptions $options, mixed $answers, ImportRowDraft $row): void
    {
        $input = StoredCells::input($cell);
        $field = $column->field;
        assert($field !== null);

        switch ($field) {
            case ImportField::PuzzleName:
                $row->name = mb_substr($text, 0, 500);
                break;
            case ImportField::Brand:
                $row->brand = mb_substr($text, 0, 200);
                break;
            case ImportField::Pieces:
                $read = $this->piecesReader->read($input);

                if ($read->certainty === PiecesCertainty::Problem && $read->problem !== null) {
                    // A multipack, an unknown count: a problem with choices - never a guess, never several rows
                    $row->problem($field, 'pieces_' . $read->problem->value, $text, $read->numbers);
                } else {
                    $row->pieces = $read->pieces;
                }

                break;
            case ImportField::Barcode:
                $read = $this->barcodeReader->read($input);

                if ($read->certainty === BarcodeCertainty::Damaged) {
                    $row->problem($field, 'barcode_' . ($read->damage->value ?? 'damaged'), $text);
                } else {
                    $row->barcodes = $read->codes;
                    $row->otherCodes = array_map(static fn (string $code): string => mb_substr($code, 0, 100), array_slice($read->other, 0, 10));
                }

                break;
            case ImportField::BrandCode:
                $row->brandCodes = array_slice(BrandCodeList::fromInputs([mb_substr($text, 0, 255)])->codes(), 0, BrandCodeList::FORM_MAX_CODES);
                break;
            case ImportField::SolvedOn:
                $this->date($cell, $text, $options, $row);
                break;
            case ImportField::Time:
                $this->time($cell, $text, $options, $row);
                break;
            case ImportField::SolvedWith:
                $row->people = array_slice(array_values(array_unique([...$row->people, ...self::peopleOf($text)])), 0, self::MAX_PEOPLE);
                break;
            case ImportField::SolvingType:
                $type = self::solvingType($text);

                if ($type === null) {
                    $row->problem($field, 'type_unknown', $text);
                } else {
                    $row->type = $type;
                }

                break;
            case ImportField::AttemptNumber:
                if (preg_match('/^#?\s*(\d{1,4})(?:\.0+)?\.?$/', $text, $match) === 1 && (int) $match[1] >= 1) {
                    $row->attempt = (int) $match[1];
                } else {
                    $row->problem($field, 'attempt_unreadable', $text);
                }

                break;
            case ImportField::FirstTry:
                $row->firstTry = $this->yesNo($field, $input, $text, $answers, $row);
                break;
            case ImportField::SolvedWithoutBox:
                $row->withoutBox = $this->yesNo($field, $input, $text, $answers, $row);
                break;
            case ImportField::AlreadyOnMySpeedPuzzling:
                $row->alreadyOnMsp = $this->yesNo($field, $input, $text, $answers, $row);
                break;
            case ImportField::Owned:
                $row->owned = $this->yesNo($field, $input, $text, $answers, $row);
                break;
            case ImportField::NotOwned:
                $row->notMine = $this->yesNo($field, $input, $text, $answers, $row) === true ? true : $row->notMine;
                break;
            case ImportField::Status:
                $this->status($cell, $text, $answers, $row);
                break;
            case ImportField::PublicComment:
                $row->comment = mb_substr($text, 0, self::MAX_TEXT);
                break;
            case ImportField::Collection:
                $read = $this->yesNoReader->read($input);

                if ($read->certainty === YesNoCertainty::Known) {
                    // A yes/no column that stands for a collection: the header is its name
                    $row->collection = $read->value === true ? mb_substr($column->header, 0, 200) : $row->collection;
                } else {
                    $row->collection = mb_substr($text, 0, 200);
                }

                break;
            case ImportField::ReadyMadeField:
                if ($column->readyMade !== null) {
                    $value = $cell['v'] ?? null;
                    $row->custom[$column->readyMade->value] = is_int($value) || is_float($value) || is_bool($value) || StoredCells::kind($cell) === 'date'
                        ? $value
                        : mb_substr($text, 0, self::MAX_TEXT);
                }

                break;
            case ImportField::Computed:
                break;
        }
    }

    /**
     * @param array<string, mixed> $cell
     */
    private function date(array $cell, string $text, ImportSheetOptions $options, ImportRowDraft $row): void
    {
        $input = StoredCells::input($cell);
        $read = $this->dateReader->read($input);

        if ($read->certainty === DateCertainty::Unreadable) {
            // "Y - 30/12/2023": a flag and a date in one cell
            $flagged = $this->flagWithDateReader->read($input);

            if ($flagged->flag->certainty !== YesNoCertainty::Known || $flagged->date->certainty === DateCertainty::NoDate) {
                $row->problem(ImportField::SolvedOn, 'date_unreadable', $text);

                return;
            }

            $row->solved = $flagged->flag->value;
            $read = $flagged->date;
        }

        if ($read->certainty === DateCertainty::NoDate) {
            return;
        }

        if ($read->possibleShortMeanings !== []) {
            $meaning = $options->shortDates['meaning'] ?? null;

            if ($meaning === null) {
                $row->problem(ImportField::SolvedOn, 'date_day_or_month_open', $text);

                return;
            }

            $read = $read->asShort($meaning);
        }

        if ($options->typedDatesSwapped === true) {
            $read = $this->dateReader->withDayAndMonthSwapped($read);
        }

        if ($read->possibleOrders !== []) {
            $order = $options->dateOrder['order'] ?? null;

            if ($order !== null && $read->allows($order)) {
                $read = $read->inOrder($order);
            } elseif ($read->date === null) {
                $row->problem(ImportField::SolvedOn, $order === null ? 'date_order_open' : 'date_unreadable', $text);

                return;
            } elseif ($order !== null) {
                // It can only be read the other way round than the sheet's dates are: taken, and shown
                $row->problem(ImportField::SolvedOn, 'date_other_order', $text);
            }
        }

        if ($read->certainty === DateCertainty::Unreadable || $read->date === null) {
            $row->problem(ImportField::SolvedOn, 'date_unreadable', $text);

            return;
        }

        if ($read->yearKnown === false) {
            $year = $options->year['year'] ?? null;

            if ($year === ImportQuestion::YEAR_AS_STORED && StoredCells::storedYear($cell) !== null) {
                // The year the cell stores behind a format that hides it - the player said it is right
                $row->date = $read->date;
                $row->precision = $read->precision;

                return;
            }

            $read = is_int($year) ? $this->dateReader->withYear($read, $year) : $read;

            if ($read->yearKnown === false || $read->date === null) {
                // A year nobody saw is never used
                $row->problem(ImportField::SolvedOn, $read->certainty === DateCertainty::Unreadable ? 'date_unreadable' : 'date_no_year', $text);

                return;
            }
        }

        foreach ($read->problems as $problem) {
            $row->problem(ImportField::SolvedOn, $problem === DateProblem::InTheFuture ? 'date_in_the_future' : 'date_before_1950', $text);
        }

        $row->date = $read->date;
        $row->precision = $read->precision;
    }

    /**
     * @param array<string, mixed> $cell
     */
    private function time(array $cell, string $text, ImportSheetOptions $options, ImportRowDraft $row): void
    {
        $read = $this->durationReader->read(StoredCells::input($cell));

        if ($read->shape !== null) {
            $rule = $options->rule($read->shape);

            if ($rule === null) {
                // A shape the sheet's first rows did not show - its meaning was never asked
                $row->problem(ImportField::Time, 'time_two_readings', $text, array_map(static fn (DurationReading $reading): string => $reading->rule->value, $read->alternatives));

                return;
            }

            $read = $read->settledBy($rule);
        }

        if ($read->certainty === DurationCertainty::NoTime) {
            return;
        }

        if ($read->seconds === null || $read->certainty === DurationCertainty::Unreadable || $read->certainty === DurationCertainty::TwoReadings) {
            $row->problem(ImportField::Time, 'time_unreadable', $text);

            return;
        }

        $row->seconds = $read->seconds;

        if ($read->certainty === DurationCertainty::ReadWithLeftovers) {
            // The time is proposed, the rest goes to the notes - and the player says yes
            $row->problem(ImportField::Time, 'time_needs_yes', $text);

            if ($read->leftover !== null) {
                $row->notes[] = mb_substr($read->leftover, 0, self::MAX_TEXT);
            }
        } elseif ($read->certainty === DurationCertainty::ReadFromText) {
            $row->from['seconds'] = 'text';
        }
    }

    /**
     * @param array<string, mixed> $cell
     */
    private function status(array $cell, string $text, mixed $answers, ImportRowDraft $row): void
    {
        $word = ImportText::plain($text);

        if (is_array($answers) && is_string($answers[$word] ?? null)) {
            // Its meaning is the sheet's own: taken where every word is (words())
            return;
        }

        foreach (self::STATUS_WORDS as $meaning => $words) {
            if (in_array($word, $words, true)) {
                $row->meaning = ImportWordMeaning::from($meaning);

                return;
            }
        }

        $input = StoredCells::input($cell);
        $flag = $this->yesNoReader->read($input);

        if ($flag->certainty === YesNoCertainty::Known) {
            $row->solved = $flag->value;

            return;
        }

        // "Y - 30/12/2023", or a date alone in a "Completed" column: solved, and when
        $flagged = $this->flagWithDateReader->read($input);

        if ($flagged->date->certainty !== DateCertainty::NoDate && $flagged->date->certainty !== DateCertainty::Unreadable && $flagged->flag->certainty !== YesNoCertainty::Unknown) {
            $row->solved = $flagged->flag->value ?? true;
            $row->statusDate = $flagged->date;

            return;
        }

        $row->problem(ImportField::Status, 'status_unknown', $text);
    }

    private function yesNo(ImportField $field, CellInput $input, string $text, mixed $answers, ImportRowDraft $row): null|bool
    {
        $read = $this->yesNoReader->read($input);

        if ($read->certainty === YesNoCertainty::Known) {
            return $read->value;
        }

        if ($read->certainty === YesNoCertainty::Unknown && (is_array($answers) === false || isset($answers[ImportText::plain($text)]) === false)) {
            // A word that is neither yes nor no: asked once per distinct word
            $row->problem($field, 'yes_no_unknown', $text);
        }

        return null;
    }

    /**
     * The meanings the sheet's own answers - or a known export - give the words and tags of a cell.
     */
    private function words(string $text, mixed $answers, null|KnownExportLayout $layout, bool $isStatus, ImportRowDraft $row): void
    {
        if ((is_array($answers) === false || $answers === []) && $layout === null) {
            return;
        }

        $whole = ImportText::plain($text);
        $words = [$whole, ...array_slice(preg_split('/[\s,;]+/u', $whole, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 50)];

        foreach (array_unique($words) as $word) {
            $answer = is_array($answers) ? $answers[$word] ?? null : null;
            $meaning = is_string($answer) ? ImportWordMeaning::tryFrom($answer) : $layout?->tagMeaning($word);

            match ($meaning) {
                ImportWordMeaning::Result, ImportWordMeaning::Owned, ImportWordMeaning::WishList, ImportWordMeaning::Both => $row->meaning = $isStatus ? $meaning : $row->meaning,
                ImportWordMeaning::Solo => $row->type ??= PuzzlingType::Solo,
                ImportWordMeaning::Pair => $row->type ??= PuzzlingType::Duo,
                ImportWordMeaning::Team => $row->type ??= PuzzlingType::Team,
                ImportWordMeaning::FirstTry => $row->firstTry = true,
                ImportWordMeaning::NotMine, ImportWordMeaning::Borrowed => $row->notMine = true,
                ImportWordMeaning::Nothing, null => null,
            };
        }
    }

    /**
     * What the row is - null: nothing of the player's (a puzzle that went, without a result).
     */
    private static function kind(ImportMapping $mapping, ImportRowDraft $row, bool $hasResult): null|DataImportRowKind
    {
        if ($row->statusDate !== null && $row->date === null) {
            // The date a "Completed" column carried beside its tick - only when it is a plain, whole date
            if ($row->statusDate->date !== null && $row->statusDate->yearKnown && $row->statusDate->certainty === DateCertainty::Certain) {
                $row->date = $row->statusDate->date;
                $row->precision = $row->statusDate->precision;
            } else {
                $row->problem(ImportField::Status, 'date_unreadable');
            }
        }

        $ownedSheet = $mapping->kind === DataImportSheetKind::Owned || $mapping->kind === DataImportSheetKind::PuzzleList;
        $byColumn = match (true) {
            $row->meaning === ImportWordMeaning::Result => DataImportRowKind::Result,
            $row->meaning === ImportWordMeaning::Owned => DataImportRowKind::Owned,
            $row->meaning === ImportWordMeaning::WishList => DataImportRowKind::WishList,
            $row->meaning === ImportWordMeaning::Both => DataImportRowKind::Both,
            // A tick in a list of puzzles: solved, and still on the shelf
            $row->solved === true => $ownedSheet ? DataImportRowKind::Both : DataImportRowKind::Result,
            $row->solved === false => DataImportRowKind::Owned,
            $hasResult => $ownedSheet ? DataImportRowKind::Both : DataImportRowKind::Result,
            default => DataImportRowKind::Owned,
        };

        $kind = match ($mapping->rowMeaning) {
            DataImportRowMeaning::Result => DataImportRowKind::Result,
            DataImportRowMeaning::Owned => DataImportRowKind::Owned,
            DataImportRowMeaning::Both => DataImportRowKind::Both,
            DataImportRowMeaning::WishList => DataImportRowKind::WishList,
            DataImportRowMeaning::ByColumn => $byColumn,
        };

        if ($row->owned === true && $kind === DataImportRowKind::Result) {
            $kind = DataImportRowKind::Both;
        }

        if ($row->owned === false || $row->notMine === true) {
            $kind = match ($kind) {
                DataImportRowKind::Both => DataImportRowKind::Result,
                DataImportRowKind::Owned => null,
                default => $kind,
            };
        }

        return $kind;
    }

    /**
     * The people a text names: one per comma, semicolon, ampersand, plus, line - or "and".
     *
     * @return list<string>
     */
    private static function peopleOf(string $text): array
    {
        $people = preg_split('/[,;&+\n]|\s+(?:and|und|et)\s+/iu', $text) ?: [];
        $people = array_filter(array_map(static fn (string $name): string => mb_substr(trim($name), 0, 100), $people), static fn (string $name): bool => $name !== '');

        return array_slice(array_values(array_unique($people)), 0, self::MAX_PEOPLE);
    }

    private static function solvingType(string $text): null|PuzzlingType
    {
        $word = ImportText::plain($text);

        // The one list of the words that say solo, pair or team (SolvingTypeWords) - a sheet's title is read by it too
        $said = SolvingTypeWords::ofCell($word);

        if ($said !== null) {
            return $said;
        }

        // A number of people
        return preg_match('/^\d{1,2}$/', $word) === 1 && (int) $word >= 3 ? PuzzlingType::Team : null;
    }

    /**
     * @param array<string, mixed> $cell
     */
    private static function text(array $cell, null|KnownExportLayout $layout): string
    {
        $shown = StoredCells::shown($cell);

        if ($layout !== null && $layout->formulaGuarded && StoredCells::kind($cell) === 'text') {
            $shown = SpreadsheetSafeValue::withoutFormulaGuard($shown);
        }

        return ImportText::clean($shown);
    }
}
