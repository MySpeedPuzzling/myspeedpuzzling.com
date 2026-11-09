<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * Pins CountryCode::fromCode() to the behaviour of its original linear scan (kept below as the reference): matched by
 * the case name, case-insensitive (ASCII strtolower), nothing trimmed, anything else null.
 */
final class CountryCodeTest extends TestCase
{
    public function testEveryCaseIsFoundByItsNameInAnyLetterCase(): void
    {
        foreach (CountryCode::cases() as $case) {
            self::assertSame($case, CountryCode::fromCode($case->name));
            self::assertSame($case, CountryCode::fromCode(strtoupper($case->name)));
            self::assertSame($case, CountryCode::fromCode(ucfirst($case->name)));
            self::assertSame($case, CountryCode::fromCode(lcfirst(strtoupper($case->name))));
        }
    }

    #[DataProvider('trickyInputs')]
    public function testMatchesTheOriginalLinearScan(null|string $input): void
    {
        self::assertSame(self::referenceFromCode($input), CountryCode::fromCode($input));
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function trickyInputs(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'space' => [' '];
        yield 'leading space' => [' cz'];
        yield 'trailing space' => ['cz '];
        yield 'trailing newline' => ["cz\n"];
        yield 'tab' => ["\tde"];
        yield 'NUL byte' => ["cz\0"];
        yield 'unknown two letters' => ['xx'];
        yield 'unknown upper' => ['XX'];
        yield 'one letter' => ['c'];
        yield 'alpha-3' => ['CZE'];
        yield 'alpha-3 lower' => ['cze'];
        yield 'country name (enum value)' => ['Czechia'];
        yield 'enum value Afghanistan' => ['Afghanistan'];
        yield 'numeric code' => ['203'];
        yield 'uk is not gb' => ['uk'];
        yield 'el is not gr' => ['el'];
        yield 'with hyphen' => ['c-z'];
        yield 'multibyte upper (no mb lowering)' => ['ČZ'];
        yield 'turkish dotted I' => ['İt'];
        yield 'Åland name' => ['Åland Islands'];
        yield 'mixed case' => ['cZ'];
        yield 'case named like a keyword' => ['AS'];
        yield 'case named like a keyword lower' => ['is'];
    }

    private static function referenceFromCode(null|string $code): null|CountryCode
    {
        if ($code === null) {
            return null;
        }

        foreach (CountryCode::cases() as $case) {
            if ($case->name === strtolower($code)) {
                return $case;
            }
        }

        return null;
    }
}
