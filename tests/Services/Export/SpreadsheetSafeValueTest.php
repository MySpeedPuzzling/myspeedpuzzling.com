<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Export;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Export\SpreadsheetSafeValue;

final class SpreadsheetSafeValueTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function texts(): iterable
    {
        yield 'a formula' => ['=SUM(A1:A9)'];
        yield 'a plus' => ['+420 123 456 789'];
        yield 'a minus' => ['-5 pieces missing'];
        yield 'an at sign' => ['@home'];
        yield 'a tab' => ["\tindented"];
        yield 'plain text' => ['Made-up Meadow'];
        yield 'an apostrophe of its own' => ["'t Huis"];
        yield 'one character' => ['='];
        yield 'empty' => [''];
    }

    #[DataProvider('texts')]
    public function testTheGuardOfAnExportedTextComesOffAgain(string $text): void
    {
        self::assertSame($text, SpreadsheetSafeValue::withoutFormulaGuard(SpreadsheetSafeValue::forCsv($text)));
    }

    public function testOnlyAGuardIsTakenOff(): void
    {
        self::assertSame('=1+1', SpreadsheetSafeValue::withoutFormulaGuard("'=1+1"));
        self::assertSame("'t Huis", SpreadsheetSafeValue::withoutFormulaGuard("'t Huis"));
        self::assertSame("'", SpreadsheetSafeValue::withoutFormulaGuard("'"));
        self::assertSame("''=x", SpreadsheetSafeValue::withoutFormulaGuard("''=x"));
        // The one text that does not come back: an apostrophe and a formula sign the player typed themselves
        self::assertSame("'=typed", SpreadsheetSafeValue::forCsv("'=typed"));
        self::assertSame('=typed', SpreadsheetSafeValue::withoutFormulaGuard("'=typed"));
    }
}
