<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Value\DataImport\ImportSourceKey;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * Rows of ONE sheet that are equal in everything a key holds (docs/features/data-import/README.md D6, "Never
 * twice"; value-readers.md "The occurrence"):
 *
 * - they are **numbered** 1, 2, 3 … by a stable order - the attempt number when the rows carry one, then a hash of
 *   the row's remaining content - never by their place on the sheet, so sorting the sheet changes no key;
 * - they are **one result unless the player says two**: each is linked to the first of them - except rows that both
 *   carry an attempt number and the numbers differ (a log of repeated solves: 13 real pairs in one file).
 */
final class ImportEqualRows
{
    /**
     * @param list<array{id: string, attempt: null|int, content: string, rowNumber: int}> $rows rows of one sheet
     *        with the same key
     *
     * @return array<string, array{occurrence: int, sameAs: null|string}> by row id
     */
    public static function number(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [$a['attempt'] ?? PHP_INT_MAX, $a['content'], $a['rowNumber']] <=> [$b['attempt'] ?? PHP_INT_MAX, $b['content'], $b['rowNumber']]);

        $numbered = [];
        $standing = [];

        foreach ($rows as $position => $row) {
            $sameAs = null;

            foreach ($standing as $earlier) {
                $toldApart = $row['attempt'] !== null && $earlier['attempt'] !== null && $row['attempt'] !== $earlier['attempt'];

                if ($toldApart === false) {
                    $sameAs = $earlier['id'];

                    break;
                }
            }

            if ($sameAs === null) {
                $standing[] = $row;
            }

            $numbered[$row['id']] = ['occurrence' => $position + 1, 'sameAs' => $sameAs];
        }

        return $numbered;
    }

    /**
     * A parsed result row's key as the n-th of its equal rows - null for a row that is no result.
     *
     * @param array<mixed> $parsed `data_import_row.parsed`
     */
    public static function resultKey(array $parsed, string $puzzleKey, int $occurrence): null|string
    {
        $type = is_string($parsed['type'] ?? null) ? PuzzlingType::tryFrom($parsed['type']) : null;
        $keys = $parsed['keys'] ?? null;

        if ($type === null || is_array($keys) === false || isset($keys['result']) === false) {
            return null;
        }

        $precision = is_string($parsed['datePrecision'] ?? null) ? DatePrecision::tryFrom($parsed['datePrecision']) : null;
        $precision ??= DatePrecision::Day;
        $stored = $parsed['date'] ?? null;
        $date = is_string($stored)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $stored . match ($precision) {
                DatePrecision::Day => '',
                DatePrecision::Month => '-01',
                DatePrecision::Year => '-01-01',
            }, new DateTimeZone('UTC'))
            : false;
        $seconds = $parsed['seconds'] ?? null;

        return ImportSourceKey::result($puzzleKey, $date === false ? null : $date, $precision, is_int($seconds) ? $seconds : null, $type, $occurrence);
    }
}
