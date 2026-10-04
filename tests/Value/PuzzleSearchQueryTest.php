<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\PuzzleSearchQuery;

final class PuzzleSearchQueryTest extends TestCase
{
    public function testNothingTypedHasNoPattern(): void
    {
        foreach ([null, '', '   ', "\t\n", "\u{200B}"] as $raw) {
            $query = PuzzleSearchQuery::fromUserInput($raw);

            self::assertTrue($query->isEmpty());
            self::assertNull($query->namesContains);
            self::assertNull($query->eanExact);
            self::assertNull($query->codeExact);
            self::assertNull($query->codePart);
        }
    }

    public function testNamePatternsAreFoldedThenEscaped(): void
    {
        $query = PuzzleSearchQuery::fromUserInput('  Kouzelná   ZAHRADA ');

        self::assertSame('kouzelna zahrada', $query->folded);
        self::assertSame('%kouzelna zahrada%', $query->namesContains);
        self::assertSame("%\nkouzelna zahrada\n%", $query->namesWhole);
        self::assertSame("%\nkouzelna zahrada%", $query->namesStart);
        self::assertSame('% kouzelna zahrada%', $query->namesWordStart);

        // Full-width wildcards fold to ASCII first, so they are escaped like typed ones
        self::assertSame('%\\%\\_\\\\%', PuzzleSearchQuery::fromUserInput('％＿＼')->namesContains);
        self::assertSame('%\\%\\_\\\\%', PuzzleSearchQuery::fromUserInput('%_\\')->namesContains);
    }

    public function testANumberIsABarcodeWithoutLeadingZeros(): void
    {
        foreach (['4005556147090', '04005556147090', '400-5556-147090', '4 005556 147090'] as $raw) {
            $query = PuzzleSearchQuery::fromUserInput($raw);

            self::assertSame("%e:4005556147090\n%", $query->eanExact, $raw);
            self::assertSame("\ne:4005556147090\n", $query->eanLine, $raw);
            self::assertSame("%4005556147090\n%", $query->eanLineEnd, $raw);
            self::assertSame('%4005556147090%', $query->codePart, $raw);
        }

        // The brand code keeps its zeros: "c:" lines are letters and digits as printed
        self::assertSame("%c:04005556147090\n%", PuzzleSearchQuery::fromUserInput('04005556147090')->codeExact);
        self::assertNull(PuzzleSearchQuery::fromUserInput('000')->eanExact);
        self::assertSame("%c:000\n%", PuzzleSearchQuery::fromUserInput('000')->codeExact);
    }

    public function testTextIsNoBarcode(): void
    {
        $query = PuzzleSearchQuery::fromUserInput('RB-1000-001');

        self::assertNull($query->eanExact);
        self::assertNull($query->eanLine);
        self::assertSame("%c:rb1000001\n%", $query->codeExact);
        self::assertSame('%rb1000001%', $query->codePart);
    }

    public function testAPartOfACodeNeedsFiveLettersOrDigits(): void
    {
        self::assertNull(PuzzleSearchQuery::fromUserInput('1000')->codePart);
        self::assertSame("%e:1000\n%", PuzzleSearchQuery::fromUserInput('1000')->eanExact);
        self::assertSame('%10000%', PuzzleSearchQuery::fromUserInput('10000')->codePart);
        // Leading zeros do not count
        self::assertNull(PuzzleSearchQuery::fromUserInput('001000')->codePart);
        self::assertNull(PuzzleSearchQuery::fromUserInput('ab-12')->codePart);
        self::assertSame('%魔法の庭です%', PuzzleSearchQuery::fromUserInput('魔法の庭です')->codePart);
        // Nothing to compare to a code
        self::assertNull(PuzzleSearchQuery::fromUserInput('％')->codeExact);
    }
}
