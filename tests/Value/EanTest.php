<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidEan;
use SpeedPuzzling\Web\Value\Ean;

final class EanTest extends TestCase
{
    public function testValidEan13(): void
    {
        $ean = Ean::from('4005556123452');

        self::assertSame('4005556123452', $ean->digits);
        self::assertSame('4005556123452', $ean->normalized());
    }

    public function testValidEan8(): void
    {
        // 9638507 → check digit 4
        $ean = Ean::from('96385074');

        self::assertSame('96385074', $ean->digits);
    }

    public function testUpcAIsReadAsEan13WithLeadingZero(): void
    {
        $upc = Ean::from('036000291452');
        $ean = Ean::from('0036000291452');

        self::assertSame('0036000291452', $upc->digits);
        self::assertSame('36000291452', $upc->normalized());
        self::assertTrue($upc->equals($ean));
    }

    public function testWhitespaceAndSeparatorsAreIgnored(): void
    {
        self::assertSame('4005556123452', Ean::from(' 4005 556-123452 ')->digits);
    }

    public function testGtin14WithZeroIndicatorIsTheEan13Inside(): void
    {
        self::assertSame('4005556123452', Ean::from('04005556123452')->digits);
        self::assertNull(Ean::tryFrom('14005556123452'), 'a real GTIN-14 indicator digit is not a retail code');
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidCodesAreRejected(string $input): void
    {
        self::assertNull(Ean::tryFrom($input));

        $this->expectException(InvalidEan::class);
        Ean::from($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'wrong check digit' => ['4005556123456'];
        yield 'too short' => ['12345'];
        yield 'too long' => ['40055561234521'];
        yield 'empty' => [''];
        yield 'letters only' => ['abc'];
        yield 'all zeros' => ['0000000000000'];
    }

    public function testIsListedInHandlesCommaSeparatedListsAndLeadingZeros(): void
    {
        $ean = Ean::from('4005556123452');

        self::assertTrue($ean->isListedIn('4005556123452'));
        self::assertTrue($ean->isListedIn('4005556147090, 4005556123452'));
        self::assertTrue($ean->isListedIn('04005556123452'));
        self::assertFalse($ean->isListedIn('40055561234520'));
        self::assertFalse($ean->isListedIn('4005556147090'));
        self::assertFalse($ean->isListedIn(null));
    }
}
