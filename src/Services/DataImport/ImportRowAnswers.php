<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Services\DataImport\Reading\BarcodeCellReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\ImportDateReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\PiecesReader;
use SpeedPuzzling\Web\Value\DataImport\BarcodeCertainty;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\DateCertainty;
use SpeedPuzzling\Web\Value\DataImport\DurationCertainty;
use SpeedPuzzling\Web\Value\DataImport\DurationReadingRule;
use SpeedPuzzling\Web\Value\DataImport\DurationShape;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DataImport\ImportWordMeaning;
use SpeedPuzzling\Web\Value\DataImport\ParsedImportRow;
use SpeedPuzzling\Web\Value\DataImport\PiecesCertainty;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * What a player says about single rows of their import (docs/features/data-import/pairing-screens.md "To fix",
 * "The row sheet"): a value typed for one field of a row - or of every row with the same unclear value - and "leave
 * it out". Kept in `data_import_row.answers`:
 *
 *     {"time": {"text": "1:15:00", "value": 4500}, "pieces": {"text": "1000", "value": 1000}, "leftOut": true}
 *
 * read() checks a typed value with the same value readers a cell is read with and gives the value as the parser
 * keeps it - a text nobody can read for sure is refused, never guessed. apply() is the parser's side
 * (ImportRowParser): the answered value stands instead of the cell's, and nothing is unclear about that field any
 * more. Every parse of the row honours its answers, so they survive a changed mapping.
 */
final readonly class ImportRowAnswers
{
    public const string LEFT_OUT = 'leftOut';

    /** How many rows one message answers (AnswerImportRows) - they are parsed again under the import's lock */
    public const int MAX_ROWS = 500;

    /** The fields a player can answer, and the kind of input each gets (the row sheet's editors) */
    public const array INPUTS = [
        'puzzle_name' => 'text',
        'brand' => 'text',
        'pieces' => 'number',
        'barcode' => 'code',
        'solved_on' => 'date',
        'time' => 'time',
        'solving_type' => 'type',
        'attempt_number' => 'number',
        'first_try' => 'yes_no',
        'solved_without_box' => 'yes_no',
        'owned' => 'yes_no',
        'status' => 'status',
        'public_comment' => 'text',
    ];

    private const array STATUS = ['result', 'owned', 'wish_list', 'both'];

    public function __construct(
        private DurationReader $durationReader,
        private ImportDateReader $dateReader,
        private PiecesReader $piecesReader,
        private BarcodeCellReader $barcodeReader,
    ) {
    }

    public static function isAnswerable(string $field): bool
    {
        return isset(self::INPUTS[$field]);
    }

    /**
     * A typed value as the row keeps it - null when it can not be read for sure.
     *
     * @return null|array{text: string, value: bool|int|string|list<string>}
     */
    public function read(ImportField $field, string $typed): null|array
    {
        $text = mb_substr(ImportText::clean($typed), 0, 500);

        if ($text === '' || self::isAnswerable($field->value) === false) {
            return null;
        }

        $value = match ($field) {
            ImportField::PuzzleName => $text,
            ImportField::Brand => mb_substr($text, 0, 200),
            ImportField::PublicComment => $text,
            ImportField::Pieces => $this->pieces($text),
            ImportField::Barcode => $this->barcodes($text),
            ImportField::SolvedOn => $this->day($text),
            ImportField::Time => $this->seconds($text),
            ImportField::SolvingType => PuzzlingType::tryFrom($text)?->value,
            ImportField::AttemptNumber => preg_match('/^\d{1,4}$/', $text) === 1 && (int) $text >= 1 ? (int) $text : null,
            ImportField::FirstTry, ImportField::SolvedWithoutBox, ImportField::Owned => match ($text) {
                'yes' => true,
                'no' => false,
                default => null,
            },
            ImportField::Status => in_array($text, self::STATUS, true) ? $text : null,
            default => null,
        };

        return $value === null ? null : ['text' => $text, 'value' => $value];
    }

    /**
     * @param array<array-key, mixed> $answers `data_import_row.answers`
     */
    public static function leavesOut(array $answers): bool
    {
        return ($answers[self::LEFT_OUT] ?? null) === true;
    }

    /**
     * The typed text of an answer, as the row sheet shows it again - null when the field was not answered.
     *
     * @param array<array-key, mixed> $answers
     */
    public static function typed(array $answers, string $field): null|string
    {
        $answer = $answers[$field] ?? null;

        return is_array($answer) && is_string($answer['text'] ?? null) ? $answer['text'] : null;
    }

    /**
     * The parser's side: every answered field takes the player's value, and its problems are gone. A stored answer
     * that is not of its field's shape is passed over - the cell's own reading stays.
     *
     * @param array<array-key, mixed> $answers
     */
    public static function apply(array $answers, ImportRowDraft $row): void
    {
        foreach ($answers as $key => $answer) {
            $field = is_string($key) ? ImportField::tryFrom($key) : null;

            if ($field === null || is_array($answer) === false || self::take($field, $answer['value'] ?? null, $row) === false) {
                continue;
            }

            $row->problems = array_values(array_filter($row->problems, static fn (array $problem): bool => $problem['field'] !== $field->value));
        }
    }

    /**
     * After the sheet's pending questions marked the row (PendingQuestionRows): a field the player answered on the
     * row itself waits for no question of the sheet.
     *
     * @param array<array-key, mixed> $answers
     */
    public static function settled(ParsedImportRow $row, array $answers): ParsedImportRow
    {
        if ($answers === [] || $row->problems === []) {
            return $row;
        }

        $problems = array_values(array_filter(
            $row->problems,
            static fn (array $problem): bool => $problem['field'] === null || is_array($answers[$problem['field']] ?? null) === false || ($problem['waiting'] ?? false) === false,
        ));

        return count($problems) === count($row->problems) ? $row : $row->withProblems($problems);
    }

    private static function take(ImportField $field, mixed $value, ImportRowDraft $row): bool
    {
        switch ($field) {
            case ImportField::PuzzleName:
                if (is_string($value) && $value !== '') {
                    $row->name = $value;

                    return true;
                }

                return false;
            case ImportField::Brand:
                if (is_string($value) && $value !== '') {
                    $row->brand = $value;
                    unset($row->from['brand']);

                    return true;
                }

                return false;
            case ImportField::PublicComment:
                if (is_string($value) && $value !== '') {
                    $row->comment = $value;

                    return true;
                }

                return false;
            case ImportField::Pieces:
                if (is_int($value) && $value > 0) {
                    $row->pieces = $value;
                    unset($row->from['pieces']);

                    return true;
                }

                return false;
            case ImportField::Barcode:
                if (is_array($value) && $value !== []) {
                    $row->barcodes = array_values(array_filter($value, is_string(...)));

                    return true;
                }

                return false;
            case ImportField::SolvedOn:
                $day = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC')) : false;

                if ($day !== false) {
                    $row->date = $day;
                    $row->precision = DatePrecision::Day;

                    return true;
                }

                return false;
            case ImportField::Time:
                if (is_int($value) && $value > 0) {
                    $row->seconds = $value;
                    unset($row->from['seconds']);

                    return true;
                }

                return false;
            case ImportField::SolvingType:
                $type = is_string($value) ? PuzzlingType::tryFrom($value) : null;

                if ($type !== null) {
                    $row->type = $type;

                    return true;
                }

                return false;
            case ImportField::AttemptNumber:
                if (is_int($value) && $value >= 1) {
                    $row->attempt = $value;

                    return true;
                }

                return false;
            case ImportField::FirstTry:
                if (is_bool($value)) {
                    $row->firstTry = $value;

                    return true;
                }

                return false;
            case ImportField::SolvedWithoutBox:
                if (is_bool($value)) {
                    $row->withoutBox = $value;

                    return true;
                }

                return false;
            case ImportField::Owned:
                if (is_bool($value)) {
                    $row->owned = $value;

                    return true;
                }

                return false;
            case ImportField::Status:
                $meaning = is_string($value) && in_array($value, self::STATUS, true) ? ImportWordMeaning::tryFrom($value) : null;

                if ($meaning !== null) {
                    $row->meaning = $meaning;

                    return true;
                }

                return false;
            default:
                return false;
        }
    }

    /**
     * What tells one group of "To fix" from another: the field, what is unclear, and the value as the sheet has it.
     * The same in SQL (GetImportRowsToFix) - never the value itself in an address.
     */
    public static function problemKey(null|string $field, string $code, null|string $value): string
    {
        return md5(($field ?? '') . ':' . $code . ':' . ($value ?? ''));
    }

    private function pieces(string $text): null|int
    {
        $read = $this->piecesReader->read(CellInput::text($text));

        return $read->certainty === PiecesCertainty::Problem || $read->pieces === null || $read->pieces < 1 ? null : $read->pieces;
    }

    /**
     * @return null|list<string>
     */
    private function barcodes(string $text): null|array
    {
        $read = $this->barcodeReader->read(CellInput::text($text));

        return $read->certainty === BarcodeCertainty::Damaged || $read->codes === [] ? null : $read->codes;
    }

    /**
     * A whole day, one reading, not in the future - `Y-m-d`.
     */
    private function day(string $text): null|string
    {
        $read = $this->dateReader->read(CellInput::text($text));

        if (
            $read->certainty !== DateCertainty::Certain
            || $read->date === null
            || $read->yearKnown === false
            || $read->precision !== DatePrecision::Day
            || $read->problems !== []
            || $read->possibleOrders !== []
            || $read->possibleShortMeanings !== []
        ) {
            return null;
        }

        return $read->date->format('Y-m-d');
    }

    /**
     * A time with one reading: `1:15:00`, `45 min`, `1h 15m` - never `45:10`, which is minutes or hours. Hours,
     * minutes and seconds typed as an answer are what they say: in a sheet's cell `1:30:00` may be a `1:30` the
     * spreadsheet turned into hours, here the player was asked for the three parts.
     */
    private function seconds(string $text): null|int
    {
        $read = $this->durationReader->read(CellInput::text($text));

        if ($read->shape === DurationShape::ZeroSecondsDurationCell || $read->shape === DurationShape::LongDurationCell) {
            $read = $read->settledBy(DurationReadingRule::AsStored);
        }

        if ($read->shape !== null || $read->seconds === null || $read->seconds < 1) {
            return null;
        }

        return $read->certainty === DurationCertainty::Certain || $read->certainty === DurationCertainty::ReadFromText ? $read->seconds : null;
    }
}
