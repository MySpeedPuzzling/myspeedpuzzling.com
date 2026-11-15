<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\ImportEqualRows;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleKey;
use SpeedPuzzling\Web\Value\DataImport\ImportSourceKey;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * Rows of one sheet that are equal in all a key holds: numbered in a stable order, and one result unless attempt
 * numbers tell them apart (docs/features/data-import/README.md D6, "Never twice").
 */
final class ImportEqualRowsTest extends TestCase
{
    public function testEqualRowsWithoutAttemptNumbersAreOneResult(): void
    {
        $numbered = ImportEqualRows::number([
            ['id' => 'b', 'attempt' => null, 'content' => 'same', 'rowNumber' => 9],
            ['id' => 'a', 'attempt' => null, 'content' => 'same', 'rowNumber' => 4],
            ['id' => 'c', 'attempt' => null, 'content' => 'same', 'rowNumber' => 12],
        ]);

        self::assertSame(
            ['a' => ['occurrence' => 1, 'sameAs' => null], 'b' => ['occurrence' => 2, 'sameAs' => 'a'], 'c' => ['occurrence' => 3, 'sameAs' => 'a']],
            $numbered,
        );
    }

    public function testDifferentAttemptNumbersKeepALogOfRepeatedSolvesApart(): void
    {
        $numbered = ImportEqualRows::number([
            ['id' => 'fourth', 'attempt' => 4, 'content' => 'y', 'rowNumber' => 2],
            ['id' => 'third', 'attempt' => 3, 'content' => 'x', 'rowNumber' => 3],
            ['id' => 'third again', 'attempt' => 3, 'content' => 'z', 'rowNumber' => 7],
            ['id' => 'no number', 'attempt' => null, 'content' => 'w', 'rowNumber' => 1],
        ]);

        self::assertSame(
            [
                'third' => ['occurrence' => 1, 'sameAs' => null],
                // The same attempt twice is the same result
                'third again' => ['occurrence' => 2, 'sameAs' => 'third'],
                'fourth' => ['occurrence' => 3, 'sameAs' => null],
                // A row without a number is not told apart from anything
                'no number' => ['occurrence' => 4, 'sameAs' => 'third'],
            ],
            $numbered,
        );
    }

    public function testTheNumberingDoesNotFollowTheOrderOnTheSheet(): void
    {
        $rows = [
            ['id' => 'a', 'attempt' => 2, 'content' => 'x', 'rowNumber' => 5],
            ['id' => 'b', 'attempt' => 1, 'content' => 'x', 'rowNumber' => 9],
            ['id' => 'c', 'attempt' => null, 'content' => 'a', 'rowNumber' => 2],
            ['id' => 'd', 'attempt' => null, 'content' => 'b', 'rowNumber' => 1],
        ];
        $sorted = $rows;
        // The sheet sorted another way, a row inserted above: other row numbers, the same rows
        $sorted[0]['rowNumber'] = 40;
        $sorted[1]['rowNumber'] = 3;
        $sorted[2]['rowNumber'] = 77;
        $sorted[3]['rowNumber'] = 78;

        self::assertSame(ImportEqualRows::number($rows), ImportEqualRows::number(array_reverse($sorted)));
        self::assertSame(['b' => 1, 'a' => 2, 'c' => 3, 'd' => 4], array_map(static fn (array $row): int => $row['occurrence'], ImportEqualRows::number($rows)));
    }

    public function testTheNthRowsKeyIsTheKeyFunctionsOwnWithThatOccurrence(): void
    {
        $puzzleKey = ImportPuzzleKey::of('Glass Marbles', 500, 'Jumbo');
        self::assertNotNull($puzzleKey);
        $parsed = ['date' => '2026-02-08', 'datePrecision' => 'day', 'seconds' => 2750, 'type' => 'solo', 'keys' => ['result' => 'x']];

        self::assertSame(
            ImportSourceKey::result($puzzleKey, new DateTimeImmutable('2026-02-08'), DatePrecision::Day, 2750, PuzzlingType::Solo, 2),
            ImportEqualRows::resultKey($parsed, $puzzleKey, 2),
        );
        self::assertSame(
            ImportSourceKey::result($puzzleKey, new DateTimeImmutable('2021-09-01'), DatePrecision::Month, null, PuzzlingType::Duo, 3),
            ImportEqualRows::resultKey(['date' => '2021-09', 'datePrecision' => 'month', 'type' => 'duo', 'keys' => ['result' => 'x']], $puzzleKey, 3),
        );
        self::assertSame(
            ImportSourceKey::result($puzzleKey, null, DatePrecision::Day, null, PuzzlingType::Solo, 1),
            ImportEqualRows::resultKey(['type' => 'solo', 'keys' => ['result' => 'x']], $puzzleKey, 1),
        );
        // An owned puzzle has no result key
        self::assertNull(ImportEqualRows::resultKey(['keys' => ['owned' => 'x']], $puzzleKey, 2));
    }
}
