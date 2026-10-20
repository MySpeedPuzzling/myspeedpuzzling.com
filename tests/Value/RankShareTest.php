<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\RankShare;

final class RankShareTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, float, int}>
     */
    public static function provideShares(): iterable
    {
        yield 'Madelin\'s example: 14.67 % is rounded up to one decimal' => [204, 1391, 0.147, 1];
        yield 'a share that is a whole tenth stays as it is' => [100, 1000, 0.1, 1];
        yield 'under 10 % still one decimal' => [58, 1391, 0.042, 1];
        yield 'under 1 % two decimals' => [3, 1391, 0.0022, 2];
        yield 'exactly 1 % is one decimal' => [10, 1000, 0.01, 1];
        yield 'just under 1 % that rounds up to 1 % says 1.0' => [199, 20000, 0.01, 1];
        yield 'the bottom half is shown too' => [1203, 1391, 0.865, 1];
        yield 'the last place is the whole list' => [1391, 1391, 1.0, 1];
        yield 'the smallest list with a share' => [2, 10, 0.2, 1];
    }

    #[DataProvider('provideShares')]
    public function testShare(int $rank, int $total, float $fraction, int $fractionDigits): void
    {
        $share = RankShare::of($rank, $total);

        self::assertNotNull($share);
        self::assertFalse($share->isFastest());
        self::assertSame($fraction, $share->fraction);
        self::assertSame($fractionDigits, $share->fractionDigits);
    }

    public function testTheFirstPlaceIsTheFastest(): void
    {
        $share = RankShare::of(1, 1391);

        self::assertNotNull($share);
        self::assertTrue($share->isFastest());
        self::assertSame(1.0, $share->aheadFraction);
    }

    public function testTheBarFillsWithTheShareOfTheOthersBehind(): void
    {
        self::assertSame(0.0, RankShare::of(1391, 1391)?->aheadFraction);
        self::assertSame(0.5, RankShare::of(6, 11)?->aheadFraction);
    }

    public function testTooFewOnTheListSayNothing(): void
    {
        self::assertNull(RankShare::of(2, 9));
        self::assertNull(RankShare::of(1, 9));
    }

    public function testARankOutsideTheListSaysNothing(): void
    {
        self::assertNull(RankShare::of(0, 100));
        self::assertNull(RankShare::of(101, 100));
    }
}
