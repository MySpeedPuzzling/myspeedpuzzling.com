<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\EanParityMisread;

final class EanParityMisreadTest extends TestCase
{
    /**
     * Misreads found in the catalogue (docs/TODO.md, README §3.3): what the scanner returned → the code on the box.
     */
    #[DataProvider('provideMisreadsFromTheCatalogue')]
    public function testTheCodeOnTheBoxIsAmongThePossibleOriginals(string $scanned, string $onTheBox): void
    {
        self::assertContains($onTheBox, EanParityMisread::possibleOriginals($scanned));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideMisreadsFromTheCatalogue(): iterable
    {
        yield 'Ravensburger, as scanned' => ['0045555011897', '4005555011897'];
        yield 'Ravensburger, as stored without the zeros' => ['45555011897', '4005555011897'];
        yield 'The World of Trolls, UPC-A form' => ['045570100330', '4005556100330'];
        yield 'Blitz (Aurore)' => ['778649925052', '3770039925052'];
    }

    public function testEveryPossibleOriginalIsAValidCodeWithTheSameRightHalf(): void
    {
        $originals = EanParityMisread::possibleOriginals('0045555011897');

        self::assertNotSame([], $originals);

        foreach ($originals as $original) {
            self::assertMatchesRegularExpression('/^[1-9]\d{6}011897$/', $original);
        }
    }

    #[DataProvider('provideCodesThatCannotBeThisMisread')]
    public function testOnlyACodeStartingWithZeroCanBeThisMisread(string $scanned): void
    {
        self::assertSame([], EanParityMisread::possibleOriginals($scanned));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCodesThatCannotBeThisMisread(): iterable
    {
        yield 'first digit not 0' => ['4005555011897'];
        yield 'EAN-8' => ['96385074'];
        yield 'wrong check digit' => ['0045555011898'];
        yield 'too short' => ['123'];
        yield 'empty' => [''];
    }
}
