<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * `source_key`: the answer to "was this added by an import before?" (docs/features/data-import/README.md D6, "Keys,
 * exactly", "Never twice"; docs/features/data-import/value-readers.md "Keys"). The same data in another file, renamed,
 * re-saved, with its rows reordered or its columns mapped differently has the same keys.
 *
 * **A key is per created thing, not per row**, and what kind of row it came from is not in it: a result, a puzzle in a
 * collection, a puzzle on the wish list. A row that is both a result and an owned puzzle simply has two keys - and an
 * owned puzzle is found again whether its row also carried a result or not.
 *
 * Each value is `1:` + the sha256 (64 hex characters) of a text, its lines joined by "\n". $puzzleKey is always the
 * TEXT puzzle key (ImportPuzzleKey::of()), never the resolved one - a source key must not depend on the catalogue.
 *
 *     result()                                    owned()                          wishList()
 *     source-key:1                                source-key:1                     source-key:1
 *     result                                      owned                            wish-list
 *     <the puzzle key>                            <the puzzle key>                 <the puzzle key>
 *     <2023-12-30 | 2023-12 | 2023 | no date>     <main | label: + folded label>
 *     <the seconds | no time>
 *     <solo | duo | team>
 *     <the occurrence, from 1>
 *
 * Not in a result's key, on purpose: the attempt number, the comment, the people, codes, custom values, the row's
 * number, the sheet, the file.
 *
 * **A key gives exact equality and nothing else.** A result known to the month (`2020-09`) and one known to the day
 * (`2020-09-14`) have unrelated keys. The pairing of "Never twice" - exact days first, then months, then years, then
 * rows without a date - compares parsed values and created results, never keys.
 *
 * **The occurrence** tells apart rows of ONE sheet that are equal in the whole rest of the key: 1, 2, 3 … They are
 * numbered by a stable secondary order - the attempt number when the rows carry one, then a hash of the row's
 * remaining content - never by their order on the sheet, so sorting the sheet or inserting a row changes no key. Rows
 * of different sheets are not numbered against each other (the same result on two sheets is one result). The
 * player's answer to "one result or two?" does not change keys either: it decides how many of the keyed rows are
 * added, not what they are called.
 *
 * The `1` in front is the key's version (the text above); a change is version 2, computed side by side.
 * ImportKeysTest pins exact outputs.
 */
final readonly class ImportSourceKey
{
    public const string VERSION = '1';

    /**
     * @param null|DateTimeImmutable $date The solve date; only the part $precision names is used
     * @param int $occurrence Which of the rows of one sheet that are equal in everything else this is, from 1
     */
    public static function result(
        string $puzzleKey,
        null|DateTimeImmutable $date,
        DatePrecision $precision,
        null|int $seconds,
        PuzzlingType $solvingType,
        int $occurrence = 1,
    ): string {
        return self::hashed(self::canonicalResult($puzzleKey, $date, $precision, $seconds, $solvingType, $occurrence));
    }

    /**
     * A puzzle in one of the player's collections.
     *
     * @param null|string $collectionLabel What the file calls the collection (a sheet's or a column's name), folded
     *        here; null or blank = the player's main collection
     */
    public static function owned(string $puzzleKey, null|string $collectionLabel = null): string
    {
        return self::hashed(self::canonicalOwned($puzzleKey, $collectionLabel));
    }

    public static function wishList(string $puzzleKey): string
    {
        return self::hashed(self::canonicalWishList($puzzleKey));
    }

    public static function canonicalResult(
        string $puzzleKey,
        null|DateTimeImmutable $date,
        DatePrecision $precision,
        null|int $seconds,
        PuzzlingType $solvingType,
        int $occurrence = 1,
    ): string {
        return implode("\n", [
            'source-key:' . self::VERSION,
            'result',
            self::puzzleKey($puzzleKey),
            $date === null ? 'no date' : self::dateAt($date, $precision),
            $seconds === null || $seconds <= 0 ? 'no time' : (string) $seconds,
            $solvingType->value,
            (string) max(1, $occurrence),
        ]);
    }

    public static function canonicalOwned(string $puzzleKey, null|string $collectionLabel = null): string
    {
        $label = ImportKeyFold::v1($collectionLabel ?? '');

        return implode("\n", [
            'source-key:' . self::VERSION,
            'owned',
            self::puzzleKey($puzzleKey),
            $label === '' ? 'main' : 'label:' . $label,
        ]);
    }

    public static function canonicalWishList(string $puzzleKey): string
    {
        return implode("\n", [
            'source-key:' . self::VERSION,
            'wish-list',
            self::puzzleKey($puzzleKey),
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

    private static function puzzleKey(string $puzzleKey): string
    {
        return strtolower(trim($puzzleKey));
    }

    private static function hashed(string $canonical): string
    {
        return self::VERSION . ':' . hash('sha256', $canonical);
    }
}
