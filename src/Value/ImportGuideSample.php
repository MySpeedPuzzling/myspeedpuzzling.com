<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The one small spreadsheet the import's guide follows through the five steps
 * (docs/features/data-import/guided-flow.md §13). Invented puzzles and people only - nothing of the catalogue, no
 * player. Fixed data: the guide reads nothing. What a cell says is what somebody would have typed; the names of the
 * puzzles are texts (`import_guide.sample.puzzle<N>`), the same six rows in every language.
 *
 * Each row is there for a reason: a clean row; a time written another way; a date that knows only its month; a pair;
 * a puzzle whose brand is missing; a puzzle we do not have.
 */
final class ImportGuideSample
{
    public const string MATCHED = 'matched';
    public const string LOOKS_LIKE_A_MATCH = 'looks';
    public const string NOT_IN_DATABASE = 'not_in_database';

    /**
     * @return list<array{number: int, brand: null|string, own_brand: bool, pieces: int, time: string, date: null|string, with: null|string, puzzle: string}>
     */
    public static function rows(): array
    {
        return [
            ['number' => 1, 'brand' => 'Pinewood', 'own_brand' => false, 'pieces' => 500, 'time' => '0:47:12', 'date' => '14/03/2024', 'with' => null, 'puzzle' => self::MATCHED],
            ['number' => 2, 'brand' => 'Pinewood', 'own_brand' => false, 'pieces' => 1000, 'time' => '1h 05', 'date' => '02/11/2023', 'with' => null, 'puzzle' => self::MATCHED],
            // No day: the cell is the month's name in the reader's language (`import_guide.sample.month`)
            ['number' => 3, 'brand' => 'Larkspur', 'own_brand' => false, 'pieces' => 500, 'time' => '1:58:40', 'date' => null, 'with' => null, 'puzzle' => self::MATCHED],
            ['number' => 4, 'brand' => 'Larkspur', 'own_brand' => false, 'pieces' => 1000, 'time' => '2:31:05', 'date' => '20/01/2024', 'with' => 'Anna', 'puzzle' => self::MATCHED],
            ['number' => 5, 'brand' => null, 'own_brand' => false, 'pieces' => 300, 'time' => '0:36:20', 'date' => '05/05/2024', 'with' => null, 'puzzle' => self::LOOKS_LIKE_A_MATCH],
            ['number' => 6, 'brand' => null, 'own_brand' => true, 'pieces' => 250, 'time' => '0:52:00', 'date' => '09/06/2024', 'with' => null, 'puzzle' => self::NOT_IN_DATABASE],
        ];
    }
}
