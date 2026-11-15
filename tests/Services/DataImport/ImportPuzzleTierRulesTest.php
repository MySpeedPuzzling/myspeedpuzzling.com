<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzleTierRules;
use SpeedPuzzling\Web\Value\ImportPuzzleTier;

/**
 * The pairing's rules for one puzzle (docs/features/data-import/pairing.md "Tiers") - the table of
 * ImportPuzzleTierRules, case by case. Puzzle A has 500 pieces and brand X.
 */
final class ImportPuzzleTierRulesTest extends TestCase
{
    private const string A = 'a';
    private const string B = 'b';
    private const string X = 'brand-x';

    /**
     * @param list<array{puzzleId: string, how: string, pieces: int, manufacturerId: null|string, isPlayers?: bool}> $hits
     * @param list<string> $candidates
     */
    #[DataProvider('provideCases')]
    public function testTier(null|int $pieces, null|string $brand, array $hits, null|ImportPuzzleTier $tier, null|string $found, array $candidates): void
    {
        $said = ImportPuzzleTierRules::exact($pieces, $brand, $hits);

        self::assertSame($tier, $said['tier']);
        self::assertSame($found, $said['puzzleId']);
        self::assertSame($candidates, array_map(static fn (array $candidate): string => $candidate['puzzleId'], $said['candidates']));
    }

    /**
     * @return iterable<string, array{null|int, null|string, list<array{puzzleId: string, how: string, pieces: int, manufacturerId: null|string, isPlayers?: bool}>, null|ImportPuzzleTier, null|string, list<string>}>
     */
    public static function provideCases(): iterable
    {
        $a = static fn (string $how, int $pieces = 500, null|string $brand = self::X): array => ['puzzleId' => self::A, 'how' => $how, 'pieces' => $pieces, 'manufacturerId' => $brand];
        $b = static fn (string $how, int $pieces = 500, null|string $brand = self::X, bool $isPlayers = false): array => ['puzzleId' => self::B, 'how' => $how, 'pieces' => $pieces, 'manufacturerId' => $brand, 'isPlayers' => $isPlayers];

        yield 'nothing' => [500, self::X, [], null, null, []];
        yield 'name, pieces, brand' => [500, self::X, [$a('name')], ImportPuzzleTier::Found, self::A, [self::A]];
        yield 'name and pieces, no brand in the file' => [500, null, [$a('name')], ImportPuzzleTier::Probably, null, [self::A]];
        yield 'name and pieces, another brand' => [500, 'brand-y', [$a('name')], ImportPuzzleTier::Probably, null, [self::A]];
        yield 'name, no pieces in the file' => [null, self::X, [$a('name')], ImportPuzzleTier::Probably, null, [self::A]];
        yield 'name with the brand beside the same name of another brand' => [500, self::X, [$b('name', brand: 'brand-y'), $a('name')], ImportPuzzleTier::Found, self::A, [self::A, self::B]];
        yield 'two puzzles of that name, pieces and brand' => [500, self::X, [$a('name'), $b('name')], ImportPuzzleTier::Choose, null, [self::A, self::B]];
        yield 'two puzzles of that name, none with the brand' => [500, null, [$a('name'), $b('name')], ImportPuzzleTier::Choose, null, [self::A, self::B]];
        yield 'among equals the one the player has comes first' => [500, null, [$a('name'), $b('name', isPlayers: true)], ImportPuzzleTier::Choose, null, [self::B, self::A]];
        yield 'barcode' => [500, null, [$a('barcode')], ImportPuzzleTier::Found, self::A, [self::A]];
        yield 'barcode, one piece apart' => [501, null, [$a('barcode')], ImportPuzzleTier::Found, self::A, [self::A]];
        yield 'barcode, no pieces in the file' => [null, null, [$a('barcode')], ImportPuzzleTier::Probably, null, [self::A]];
        yield 'barcode, another piece count' => [1000, null, [$a('barcode')], ImportPuzzleTier::Choose, null, [self::A]];
        yield 'a multipack barcode' => [500, null, [$a('barcode'), $b('barcode')], ImportPuzzleTier::Choose, null, [self::A, self::B]];
        yield 'barcode says one, name and brand another' => [500, self::X, [$a('barcode'), $b('name')], ImportPuzzleTier::Choose, null, [self::A, self::B]];
        yield 'brand code of the brand' => [500, self::X, [$a('brand_code')], ImportPuzzleTier::Probably, null, [self::A]];
        yield 'brand code of another brand is nothing' => [500, 'brand-y', [$a('brand_code')], null, null, []];
        yield 'brand code without a brand is nothing' => [500, null, [$a('brand_code')], null, null, []];
        yield 'own id' => [null, null, [$a('own_id')], ImportPuzzleTier::Found, self::A, [self::A]];
        yield 'own id, another piece count' => [1000, null, [$a('own_id')], ImportPuzzleTier::Choose, null, [self::A]];
        yield 'own result' => [500, null, [$a('own_result')], ImportPuzzleTier::Found, self::A, [self::A]];
        yield 'own result, no pieces in the file' => [null, null, [$a('own_result')], ImportPuzzleTier::Probably, null, [self::A]];
        yield 'own result, a name says another puzzle' => [500, null, [$a('own_result'), $b('name')], ImportPuzzleTier::Choose, null, [self::A, self::B]];
        yield 'own result and the name agree' => [500, null, [$a('own_result'), $a('name')], ImportPuzzleTier::Found, self::A, [self::A]];
        yield 'own results on two puzzles' => [500, null, [$a('own_result'), $b('own_result')], ImportPuzzleTier::Choose, null, [self::A, self::B]];
        yield 'a loose own result' => [500, null, [$a('own_result_loose')], ImportPuzzleTier::Probably, null, [self::A]];
        yield 'an earlier import' => [500, null, [$a('earlier_import')], ImportPuzzleTier::Found, self::A, [self::A]];
        yield 'only a candidate of another piece count beside a weak one' => [500, null, [$a('name'), $b('barcode', pieces: 1000)], ImportPuzzleTier::Probably, null, [self::A, self::B]];
    }

    public function testACandidateSaysWhenItsPieceCountDiffers(): void
    {
        $said = ImportPuzzleTierRules::exact(1000, null, [['puzzleId' => self::A, 'how' => 'barcode', 'pieces' => 500, 'manufacturerId' => null]]);

        self::assertSame([['puzzleId' => self::A, 'how' => 'barcode', 'score' => null, 'piecesDiffer' => true]], $said['candidates']);
    }

    public function testNoMoreCandidatesThanTheList(): void
    {
        $hits = [];

        for ($index = 0; $index < 12; $index++) {
            $hits[] = ['puzzleId' => 'puzzle-' . $index, 'how' => 'name', 'pieces' => 500, 'manufacturerId' => null];
        }

        self::assertCount(ImportPuzzleTierRules::MAX_CANDIDATES, ImportPuzzleTierRules::exact(500, null, $hits)['candidates']);
    }

    public function testSimilarNamesAreAChoiceAndNoneIsNone(): void
    {
        self::assertSame(ImportPuzzleTier::None, ImportPuzzleTierRules::similar(500, [])['tier']);

        $said = ImportPuzzleTierRules::similar(500, [
            ['puzzleId' => self::A, 'pieces' => 500, 'score' => 1.0],
            ['puzzleId' => self::B, 'pieces' => 1000, 'score' => 0.8],
        ]);

        // Even a name that is the very same text: found by similarity, it is a suggestion
        self::assertSame(ImportPuzzleTier::Choose, $said['tier']);
        self::assertSame([
            ['puzzleId' => self::A, 'how' => 'similar_name', 'score' => 1.0, 'piecesDiffer' => false],
            ['puzzleId' => self::B, 'how' => 'similar_name', 'score' => 0.8, 'piecesDiffer' => true],
        ], $said['candidates']);
    }
}
