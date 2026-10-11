<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PlainNumber;

final class PlainNumberTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float, string, null|int, string}>
     */
    public static function provideNumbers(): iterable
    {
        yield 'small' => [82, 'en', null, '82'];
        yield 'thousands: no separator' => [4141, 'en', null, '4141'];
        yield 'tens of thousands: no separator' => [10000, 'en', null, '10000'];
        yield 'just below the grouping' => [99999, 'en', null, '99999'];
        yield 'from 100000: no-break space, never a comma' => [1234567, 'en', null, "1\u{00A0}234\u{00A0}567"];
        yield 'czech the same' => [1234567, 'cs', null, "1\u{00A0}234\u{00A0}567"];
        yield 'german the same (not a dot)' => [123456, 'de', null, "123\u{00A0}456"];
        yield 'negative' => [-4141, 'en', null, '-4141'];
        yield 'decimals follow the locale' => [4.5, 'cs', 1, '4,5'];
    }

    #[DataProvider('provideNumbers')]
    public function testNumbersHaveNoCommaBetweenTheThousands(int|float $number, string $locale, null|int $decimals, string $expected): void
    {
        self::assertSame($expected, PlainNumber::format($number, $locale, $decimals));
    }
}
