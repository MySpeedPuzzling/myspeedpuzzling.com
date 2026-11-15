<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportWordMeaning;
use SpeedPuzzling\Web\Value\DataImport\ReadDate;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * What ImportRowParser has read of a row so far - its working sheet, nothing more.
 *
 * @internal
 */
final class ImportRowDraft
{
    public null|string $name = null;
    public null|string $brand = null;
    public null|int $pieces = null;
    /** @var list<string> */
    public array $barcodes = [];
    /** @var list<string> */
    public array $otherCodes = [];
    /** @var list<string> */
    public array $brandCodes = [];
    public null|DateTimeImmutable $date = null;
    public DatePrecision $precision = DatePrecision::Day;
    public null|int $seconds = null;
    /** The row's time cell holds something - a time, or a text nobody could read */
    public bool $timeCellFilled = false;
    /**
     * The player said "No time - I didn't time this one" (ImportRowAnswers::NO_TIME) about a row whose time cell
     * holds something: a result. Never set for an empty time cell - a row of a shelf stays a row of a shelf
     */
    public bool $untimed = false;
    /** @var list<string> */
    public array $people = [];
    public null|PuzzlingType $type = null;
    public null|bool $firstTry = null;
    public null|int $attempt = null;
    public null|bool $withoutBox = null;
    public null|bool $alreadyOnMsp = null;
    public null|bool $owned = null;
    public null|bool $notMine = null;
    public null|string $comment = null;
    public null|string $collection = null;
    /** @var list<string> */
    public array $notes = [];
    /** @var array<string, mixed> */
    public array $custom = [];
    public null|string $puzzleId = null;
    public null|string $pictureUrl = null;
    public null|string $sourceRowId = null;
    /** What a status word makes the row */
    public null|ImportWordMeaning $meaning = null;
    /** A tick (or a cross) in a "Completed?" column */
    public null|bool $solved = null;
    public null|ReadDate $statusDate = null;
    /** @var array<string, string> where a value came from when not from its own cell */
    public array $from = [];
    /** @var list<array{field: null|string, code: string, value?: string, choices?: list<int|string>}> */
    public array $problems = [];

    /**
     * @param list<int|string> $choices
     */
    public function problem(null|ImportField $field, string $code, null|string $value = null, array $choices = []): void
    {
        $problem = ['field' => $field?->value, 'code' => $code];

        if ($value !== null) {
            $problem['value'] = mb_substr($value, 0, 100);
        }

        if ($choices !== []) {
            $problem['choices'] = array_slice($choices, 0, 12);
        }

        $this->problems[] = $problem;
    }

    public function hasProblemOf(ImportField $field): bool
    {
        foreach ($this->problems as $problem) {
            if ($problem['field'] === $field->value) {
                return true;
            }
        }

        return false;
    }
}
