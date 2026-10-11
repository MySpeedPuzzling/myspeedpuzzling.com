<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Component\XpRing;

/**
 * The level colour bands (design-spec §9): one per 10 levels, Level 50 its own - the rule XpRing and the reveal's
 * level medallion share.
 */
final class XpRingBandTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function levels(): iterable
    {
        yield 'below the curve' => [0, 1];
        yield 'Level 1' => [1, 1];
        yield 'Level 9' => [9, 1];
        yield 'Level 10' => [10, 2];
        yield 'Level 19' => [19, 2];
        yield 'Level 20' => [20, 3];
        yield 'Level 39' => [39, 4];
        yield 'Level 40' => [40, 5];
        yield 'Level 49' => [49, 5];
        yield 'Level 50' => [50, 6];
    }

    #[DataProvider('levels')]
    public function testEveryTenLevelsAreOneBandAndTheSummitItsOwn(int $level, int $band): void
    {
        self::assertSame($band, XpRing::band($level));
    }
}
