<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PiecesRange;

final class PiecesRangeTest extends TestCase
{
    public function testBetween(): void
    {
        $range = PiecesRange::between(500, 1000);

        self::assertSame(500, $range->minPieces);
        self::assertSame(1000, $range->maxPieces);
        self::assertFalse($range->isUnbounded());

        $exact = PiecesRange::between(500, 500);
        self::assertSame(500, $exact->minPieces);
        self::assertSame(500, $exact->maxPieces);

        $openEnded = PiecesRange::between(2000, null);
        self::assertSame(2000, $openEnded->minPieces);
        self::assertNull($openEnded->maxPieces);

        self::assertTrue(PiecesRange::between(null, null)->isUnbounded());
        self::assertTrue(PiecesRange::any()->isUnbounded());
    }

    public function testContradictingBoundsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PiecesRange::between(1000, 500);
    }

    /**
     * @return iterable<string, array{string, null|int, null|int}>
     */
    public static function validParams(): iterable
    {
        yield 'exact' => ['500', 500, 500];
        yield 'between' => ['300-499', 300, 499];
        yield 'at least' => ['2000-', 2000, null];
        yield 'at most' => ['-199', null, 199];
        yield 'surrounding whitespace' => [' 750 ', 750, 750];
        yield 'swapped bounds' => ['500-300', 300, 500];
        yield 'maximum' => ['100000', 100000, 100000];
        // The old /puzzle buckets, still in shared and indexed links
        yield 'legacy up to 499' => ['1-499', 1, 499];
        yield 'legacy 501-999' => ['501-999', 501, 999];
        yield 'legacy 1000' => ['1000', 1000, 1000];
        yield 'legacy more than 1000' => ['1001+', 1001, null];
        yield 'legacy more than 1000, unencoded in a URL' => ['1001 ', 1001, null];
    }

    #[DataProvider('validParams')]
    public function testParse(string $param, null|int $expectedMin, null|int $expectedMax): void
    {
        $range = PiecesRange::parse($param);

        self::assertNotNull($range);
        self::assertSame($expectedMin, $range->minPieces);
        self::assertSame($expectedMax, $range->maxPieces);
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function invalidParams(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'dash only' => ['-'];
        yield 'any' => ['any'];
        yield 'text' => ['abc'];
        yield 'negative' => ['-5-'];
        yield 'zero' => ['0'];
        yield 'zero bound' => ['0-500'];
        yield 'above maximum' => ['100001'];
        yield 'too many digits' => ['1234567'];
        yield 'decimal' => ['500.5'];
        yield 'plus in the middle' => ['500+600'];
    }

    #[DataProvider('invalidParams')]
    public function testParseRejectsMalformedInput(null|string $param): void
    {
        self::assertNull(PiecesRange::parse($param));
    }

    public function testToParamIsCanonicalAndRoundTrips(): void
    {
        self::assertSame('500', PiecesRange::between(500, 500)->toParam());
        self::assertSame('300-499', PiecesRange::between(300, 499)->toParam());
        self::assertSame('2000-', PiecesRange::between(2000, null)->toParam());
        self::assertSame('-199', PiecesRange::between(null, 199)->toParam());
        self::assertSame('', PiecesRange::any()->toParam());
        self::assertSame('1001-', PiecesRange::parse('1001+')?->toParam());

        foreach (['500', '99-100', '2000-', '-199', '250-420'] as $param) {
            self::assertSame($param, PiecesRange::parse($param)?->toParam());
        }
    }

    public function testFromBounds(): void
    {
        self::assertNull(PiecesRange::fromBounds(null, null));
        self::assertNull(PiecesRange::fromBounds(0, -3));

        self::assertSame('300-', PiecesRange::fromBounds(300, null)?->toParam());
        self::assertSame('-500', PiecesRange::fromBounds(null, 500)?->toParam());
        self::assertSame('300-500', PiecesRange::fromBounds(500, 300)?->toParam());
        self::assertSame('750', PiecesRange::fromBounds(750, 750)?->toParam());
        // An unusable bound is dropped, the other one still filters
        self::assertSame('-500', PiecesRange::fromBounds(0, 500)?->toParam());
        self::assertSame('300-', PiecesRange::fromBounds(300, PiecesRange::MAX_PIECES + 1)?->toParam());
    }

    public function testPresets(): void
    {
        self::assertSame(
            array_map(strval(...), array_keys(PiecesRange::PRESETS)),
            array_map(static fn (PiecesRange $preset): string => $preset->toParam(), PiecesRange::presets()),
        );
        self::assertSame(
            array_values(PiecesRange::PRESETS),
            array_map(static fn (PiecesRange $preset): string => $preset->label(), PiecesRange::presets()),
        );

        $hundred = PiecesRange::presets()[0];
        self::assertTrue($hundred->contains(99));
        self::assertTrue($hundred->contains(100));
        self::assertFalse($hundred->contains(101));
    }

    public function testLabelOfCustomRanges(): void
    {
        self::assertSame('250–420', PiecesRange::between(250, 420)->label());
        self::assertSame('3000+', PiecesRange::between(3000, null)->label());
        self::assertSame('≤ 150', PiecesRange::between(null, 150)->label());
        self::assertSame('150', PiecesRange::between(150, 150)->label());
    }

    public function testContains(): void
    {
        $range = PiecesRange::between(300, 500);
        self::assertFalse($range->contains(299));
        self::assertTrue($range->contains(300));
        self::assertTrue($range->contains(500));
        self::assertFalse($range->contains(501));

        self::assertTrue(PiecesRange::between(2000, null)->contains(9000));
        self::assertFalse(PiecesRange::between(2000, null)->contains(1999));
        self::assertTrue(PiecesRange::between(null, 199)->contains(1));
        self::assertTrue(PiecesRange::any()->contains(500));
    }
}
