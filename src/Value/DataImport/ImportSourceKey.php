<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * `source_key`: what a row says, folded and parsed - the answer to "was this row added by an import before?"
 * (docs/features/data-import/README.md D6, "Keys, exactly", "Never twice"). The same data in another file, renamed,
 * re-saved, with its rows reordered or its columns mapped differently has the same keys.
 *
 * The key is the sha256 (64 hex characters) of this canonical text, its seven lines joined by "\n":
 *
 *     source-key:1
 *     <the row's kind: result | owned | both | wish_list>
 *     <the puzzle_key (ImportPuzzleKey)>
 *     <the solve date at its precision: 2023-12-30 | 2023-12 | 2023 - or "no date">
 *     <the time in seconds - or "no time">
 *     <solo | duo | team - or "no type" for a row that is no result>
 *     <the occurrence: 1 for the first row of a sheet with all of the above, 2 for the second …>
 *
 * Not in it, on purpose: the attempt number (the same result written with it on one sheet and without it on another
 * must stay one result - it only tells two otherwise equal rows of one sheet apart, which is what the occurrence
 * counts), the comment, the people, codes, custom values, the row's number, the sheet, the file.
 *
 * Keys are stored for ever (`data_import_row.source_key`): changing the canonical text changes every key.
 * ImportKeysTest pins exact outputs.
 */
final readonly class ImportSourceKey
{
    private const string VERSION = 'source-key:1';

    /**
     * @param null|DateTimeImmutable $date The solve date; only the part $precision names is used
     * @param int $occurrence Which of the rows of one sheet that are equal in everything else this is, from 1
     */
    public static function of(
        ImportRowKind $kind,
        string $puzzleKey,
        null|DateTimeImmutable $date,
        DatePrecision $precision,
        null|int $seconds,
        null|PuzzlingType $type,
        int $occurrence = 1,
    ): string {
        return hash('sha256', self::canonical($kind, $puzzleKey, $date, $precision, $seconds, $type, $occurrence));
    }

    public static function canonical(
        ImportRowKind $kind,
        string $puzzleKey,
        null|DateTimeImmutable $date,
        DatePrecision $precision,
        null|int $seconds,
        null|PuzzlingType $type,
        int $occurrence = 1,
    ): string {
        return implode("\n", [
            self::VERSION,
            $kind->value,
            strtolower($puzzleKey),
            $date === null ? 'no date' : self::dateAt($date, $precision),
            $seconds === null || $seconds <= 0 ? 'no time' : (string) $seconds,
            $type === null ? 'no type' : $type->value,
            (string) max(1, $occurrence),
        ]);
    }

    /**
     * A date as far as it is known: 2023-12-30, 2023-12, 2023 - the calendar day it carries, whatever its time zone.
     */
    public static function dateAt(DateTimeImmutable $date, DatePrecision $precision): string
    {
        return $date->format(match ($precision) {
            DatePrecision::Day => 'Y-m-d',
            DatePrecision::Month => 'Y-m',
            DatePrecision::Year => 'Y',
        });
    }
}
