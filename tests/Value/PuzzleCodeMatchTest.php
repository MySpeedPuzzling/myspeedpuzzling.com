<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PuzzleCodeMatch;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;

/**
 * "Matched: …" for a code (docs/features/navigation PROF review Q3): the code the puzzle search found a puzzle by, as
 * printed - the same rules as the stored code key and PuzzleTextSearch (a whole barcode with or without leading zeros,
 * a whole brand code with or without the EAN's check digit, a part of any code from 5 letters/digits) - and nothing when
 * a name holds the typed text.
 */
final class PuzzleCodeMatchTest extends TestCase
{
    // Ravensburger's 12002028 is in its EAN 4005555020288 (4005555 02028 8)
    private const string EANS = '4005555020288, 0012345678905';
    private const string BRAND_CODES = '12002028, RB-500-001';

    /**
     * @return iterable<string, array{null|string, null|string}>
     */
    public static function provideSearches(): iterable
    {
        // [typed, matched code]
        yield 'the whole EAN' => ['4005555020288', '4005555020288'];
        yield 'the EAN with spaces' => ['4005555 02028 8', '4005555020288'];
        yield 'a UPC typed with its leading zeros' => ['0012345678905', '012345678905'];
        yield 'a part of an EAN (5+ digits)' => ['55502', '4005555020288'];
        yield 'the whole brand code' => ['12002028', '12002028'];
        yield 'the brand code with the check digit' => ['120020288', '12002028'];
        yield 'another brand code, any case' => ['rb-500-001', 'RB-500-001'];
        yield 'a part of a brand code (5+ letters/digits)' => ['rb500', 'RB-500-001'];
        yield 'a part shorter than 5 is nothing' => ['rb-5', null];
        yield 'a name holds it: the name lines say it' => ['garden', null];
        yield 'a name holds the digits too' => ['1000', null];
        yield 'nothing typed' => ['  ', null];
        yield 'no code' => ['99999999', null];
    }

    #[DataProvider('provideSearches')]
    public function testTheCodeTheSearchFoundThePuzzleBy(string $search, null|string $expected): void
    {
        $names = new PuzzleNames([new PuzzleName('Zauberhafter Garten', 'de')]);

        self::assertSame($expected, PuzzleCodeMatch::find($search, 'Magic garden 1000', $names, self::EANS, self::BRAND_CODES));
    }

    public function testAPuzzleWithoutCodesMatchesNone(): void
    {
        self::assertNull(PuzzleCodeMatch::find('12345', 'Night sky', new PuzzleNames(), null, null));
    }
}
