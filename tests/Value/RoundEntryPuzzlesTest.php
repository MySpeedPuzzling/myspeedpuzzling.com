<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryResult;

final class RoundEntryPuzzlesTest extends TestCase
{
    private const string A = '018d0020-0000-0000-0000-00000000a201';
    private const string B = '018d0020-0000-0000-0000-00000000a202';
    private const string C = '018d0020-0000-0000-0000-00000000a203';
    private const array PIECES = [self::A => 500, self::B => 500, self::C => 1000];

    public function testWireRoundTripIsCanonical(): void
    {
        $puzzles = RoundEntryPuzzles::fromWire([
            ['done' => true, 'atSeconds' => 2710, 'roundPuzzleId' => strtoupper(self::B)],
            ['piecesPlaced' => 312, 'done' => false, 'roundPuzzleId' => self::A],
        ]);

        self::assertNotNull($puzzles);
        self::assertSame([
            ['roundPuzzleId' => self::B, 'done' => true, 'atSeconds' => 2710],
            ['roundPuzzleId' => self::A, 'done' => false, 'piecesPlaced' => 312],
        ], $puzzles->toWire());
        self::assertTrue(RoundEntryPuzzles::same($puzzles, RoundEntryPuzzles::fromStored(json_encode($puzzles->toWire()))));
        self::assertFalse(RoundEntryPuzzles::same($puzzles, null));
        self::assertTrue(RoundEntryPuzzles::same(null, RoundEntryPuzzles::fromWire([])));
        // Order is the solving order - another order is another record
        self::assertFalse(RoundEntryPuzzles::same($puzzles, RoundEntryPuzzles::fromWire(array_reverse($puzzles->toWire()))));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unreadable(): iterable
    {
        yield 'not a list' => [['roundPuzzleId' => self::A, 'done' => true]];
        yield 'a string' => ['A'];
        yield 'no id' => [[['done' => true]]];
        yield 'not a uuid' => [[['roundPuzzleId' => 'A', 'done' => true]]];
        yield 'done missing' => [[['roundPuzzleId' => self::A]]];
        yield 'done not bool' => [[['roundPuzzleId' => self::A, 'done' => 1]]];
        yield 'split on not done' => [[['roundPuzzleId' => self::A, 'done' => false, 'atSeconds' => 5]]];
        yield 'pieces on done' => [[['roundPuzzleId' => self::A, 'done' => true, 'piecesPlaced' => 5]]];
        yield 'split not int' => [[['roundPuzzleId' => self::A, 'done' => true, 'atSeconds' => '5']]];
        yield 'unknown key' => [[['roundPuzzleId' => self::A, 'done' => true, 'puzzleId' => self::B]]];
        yield 'too many' => [array_fill(0, RoundEntryPuzzles::MAX_ITEMS + 1, ['roundPuzzleId' => self::A, 'done' => false])];
    }

    #[DataProvider('unreadable')]
    public function testUnreadableShapes(mixed $wire): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RoundEntryPuzzles::fromWire($wire);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, int, null|string}>
     */
    public static function items(): iterable
    {
        yield 'a choice' => [[['roundPuzzleId' => self::A, 'done' => false]], 2, null];
        yield 'two done with splits' => [[['roundPuzzleId' => self::A, 'done' => true, 'atSeconds' => 10], ['roundPuzzleId' => self::C, 'done' => true, 'atSeconds' => 20]], 2, null];
        yield 'unknown' => [[['roundPuzzleId' => '018d0020-0000-0000-0000-00000000ffff', 'done' => false]], 2, 'unknown_round_puzzle'];
        yield 'twice' => [[['roundPuzzleId' => self::A, 'done' => false], ['roundPuzzleId' => self::A, 'done' => true]], 2, 'puzzle_listed_twice'];
        yield 'more than K' => [[['roundPuzzleId' => self::A, 'done' => false], ['roundPuzzleId' => self::B, 'done' => false]], 1, 'too_many_puzzles'];
        yield 'split 0' => [[['roundPuzzleId' => self::A, 'done' => true, 'atSeconds' => 0]], 2, 'invalid_split'];
        yield 'split too long' => [[['roundPuzzleId' => self::A, 'done' => true, 'atSeconds' => RoundEntryResult::MAX_SECONDS + 1]], 2, 'invalid_split'];
        yield 'pieces = all' => [[['roundPuzzleId' => self::A, 'done' => false, 'piecesPlaced' => 500]], 2, 'invalid_pieces_placed'];
        yield 'pieces 0' => [[['roundPuzzleId' => self::A, 'done' => false, 'piecesPlaced' => 0]], 2, 'invalid_pieces_placed'];
        yield 'not done first' => [[['roundPuzzleId' => self::A, 'done' => false], ['roundPuzzleId' => self::B, 'done' => true]], 2, 'splits_out_of_order'];
        yield 'splits decrease' => [[['roundPuzzleId' => self::A, 'done' => true, 'atSeconds' => 20], ['roundPuzzleId' => self::B, 'done' => true, 'atSeconds' => 10]], 2, 'splits_out_of_order'];
        yield 'equal splits' => [[['roundPuzzleId' => self::A, 'done' => true, 'atSeconds' => 20], ['roundPuzzleId' => self::B, 'done' => true, 'atSeconds' => 20]], 2, 'splits_out_of_order'];
        yield 'a split missing in between is fine' => [[['roundPuzzleId' => self::A, 'done' => true, 'atSeconds' => 10], ['roundPuzzleId' => self::B, 'done' => true], ['roundPuzzleId' => self::C, 'done' => true, 'atSeconds' => 30]], 3, null];
    }

    /**
     * @param list<array<string, mixed>> $wire
     */
    #[DataProvider('items')]
    public function testItemRules(array $wire, int $toSolve, null|string $refusal): void
    {
        $puzzles = RoundEntryPuzzles::fromWire($wire);

        self::assertNotNull($puzzles);
        self::assertSame($refusal, $puzzles->itemsRefusal($toSolve, self::PIECES));
    }

    /**
     * @return iterable<string, array{null|array<string, int|bool>, null|list<array<string, mixed>>, null|string}>
     */
    public static function consistency(): iterable
    {
        $twoDone = [['roundPuzzleId' => self::A, 'done' => true, 'atSeconds' => 100], ['roundPuzzleId' => self::C, 'done' => true, 'atSeconds' => 300]];

        yield 'no record' => [['seconds' => 300], null, null];
        yield 'no result yet' => [null, [['roundPuzzleId' => self::A, 'done' => false]], null];
        yield 'finished' => [['seconds' => 300], $twoDone, null];
        yield 'finished, last split not the time' => [['seconds' => 301], $twoDone, 'puzzles_mismatch'];
        yield 'finished, a split after the time' => [['seconds' => 250], [['roundPuzzleId' => self::A, 'done' => true, 'atSeconds' => 260], ['roundPuzzleId' => self::C, 'done' => true]], 'puzzles_mismatch'];
        yield 'finished without splits' => [['seconds' => 300], [['roundPuzzleId' => self::A, 'done' => true], ['roundPuzzleId' => self::C, 'done' => true]], null];
        yield 'finished, fewer than K' => [['seconds' => 300], [['roundPuzzleId' => self::A, 'done' => true]], 'puzzles_mismatch'];
        yield 'finished, one not done' => [['seconds' => 300], [['roundPuzzleId' => self::A, 'done' => true], ['roundPuzzleId' => self::C, 'done' => false]], 'puzzles_mismatch'];
        yield 'unfinished = sum' => [['piecesPlaced' => 812], [['roundPuzzleId' => self::A, 'done' => true], ['roundPuzzleId' => self::B, 'done' => false, 'piecesPlaced' => 312]], null];
        yield 'unfinished, wrong sum' => [['piecesPlaced' => 811], [['roundPuzzleId' => self::A, 'done' => true], ['roundPuzzleId' => self::B, 'done' => false, 'piecesPlaced' => 312]], 'puzzles_mismatch'];
        yield 'unfinished, all done' => [['piecesPlaced' => 1000], [['roundPuzzleId' => self::A, 'done' => true], ['roundPuzzleId' => self::B, 'done' => true]], 'puzzles_mismatch'];
        yield 'unfinished, one chosen, no pieces given' => [['piecesPlaced' => 1], [['roundPuzzleId' => self::A, 'done' => false]], 'puzzles_mismatch'];
        yield 'did not start' => [['didNotStart' => true], [['roundPuzzleId' => self::A, 'done' => false]], 'puzzles_mismatch'];
    }

    /**
     * @param null|array<string, int|bool> $result
     * @param null|list<array<string, mixed>> $wire
     */
    #[DataProvider('consistency')]
    public function testTheRecordAgainstTheResult(null|array $result, null|array $wire, null|string $refusal): void
    {
        self::assertSame($refusal, RoundEntryPuzzles::consistencyRefusal(RoundEntryResult::fromWire($result), RoundEntryPuzzles::fromWire($wire), 2, self::PIECES));
    }

    public function testTotalsDurationsAndBounds(): void
    {
        $puzzles = RoundEntryPuzzles::fromWire([
            ['roundPuzzleId' => self::C, 'done' => true, 'atSeconds' => 2710],
            ['roundPuzzleId' => self::A, 'done' => true],
            ['roundPuzzleId' => self::B, 'done' => false, 'piecesPlaced' => 312],
        ]);
        self::assertNotNull($puzzles);

        self::assertSame(1000 + 500 + 312, $puzzles->piecesPlaced(self::PIECES));
        self::assertSame(2000, $puzzles->piecesCount(self::PIECES));
        self::assertSame(2710, $puzzles->duration(0));
        self::assertNull($puzzles->duration(1));
        self::assertNull($puzzles->duration(2));
        self::assertNull($puzzles->duration(3));
        self::assertSame(2 * 1000 - 1, RoundEntryPuzzles::unfinishedMaximum(2, self::PIECES));
        self::assertSame(1500, RoundEntryPuzzles::roundPiecesBound(2, self::PIECES));
        self::assertSame(2000, RoundEntryPuzzles::roundPiecesBound(3, self::PIECES));
    }
}
