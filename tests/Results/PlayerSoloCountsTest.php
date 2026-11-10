<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PlayerSoloCounts;

final class PlayerSoloCountsTest extends TestCase
{
    private const string BRAND_OLDER = '018d0002-0000-0000-0000-000000000001';
    private const string BRAND_NEWER = '018d0002-0000-0000-0000-000000000002';

    public function testAPieceCountSumsItsBrandsAndATieGoesToTheSmallerPieceCount(): void
    {
        $counts = PlayerSoloCounts::fromDatabaseRows([
            ['pieces_count' => 2000, 'brand_id' => self::BRAND_OLDER, 'brand_name' => 'A', 'solved_count' => 3],
            ['pieces_count' => 300, 'brand_id' => self::BRAND_OLDER, 'brand_name' => 'A', 'solved_count' => 1],
            ['pieces_count' => 300, 'brand_id' => self::BRAND_NEWER, 'brand_name' => 'B', 'solved_count' => '2'],
            ['pieces_count' => '500', 'brand_id' => self::BRAND_NEWER, 'brand_name' => 'B', 'solved_count' => 4],
        ]);

        self::assertSame([500, 300, 2000], $counts->piecesCounts(null));
        self::assertSame([2000, 300], $counts->piecesCounts(self::BRAND_OLDER));
        self::assertSame([], $counts->piecesCounts('018d0002-0000-0000-0000-000000000003'));
    }

    public function testBrandsMostSolvedFirstATieGoesToTheOlderBrandNotTheName(): void
    {
        $counts = PlayerSoloCounts::fromDatabaseRows([
            ['pieces_count' => 500, 'brand_id' => self::BRAND_NEWER, 'brand_name' => 'Alpha', 'solved_count' => 2],
            ['pieces_count' => 500, 'brand_id' => self::BRAND_OLDER, 'brand_name' => 'Zeta', 'solved_count' => 2],
            ['pieces_count' => 500, 'brand_id' => '018d0002-0000-0000-0000-000000000003', 'brand_name' => 'Mid', 'solved_count' => 5],
            ['pieces_count' => 1000, 'brand_id' => self::BRAND_NEWER, 'brand_name' => 'Alpha', 'solved_count' => 9],
        ]);

        self::assertSame([
            '018d0002-0000-0000-0000-000000000003' => 'Mid (5)',
            self::BRAND_OLDER => 'Zeta (2)',
            self::BRAND_NEWER => 'Alpha (2)',
        ], $counts->brands(500));
        self::assertSame([], $counts->brands(750));
    }
}
