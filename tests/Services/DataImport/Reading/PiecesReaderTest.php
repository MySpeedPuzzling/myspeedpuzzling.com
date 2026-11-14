<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport\Reading;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\Reading\PiecesReader;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DataImport\PiecesCertainty;
use SpeedPuzzling\Web\Value\DataImport\PiecesProblem;

/**
 * Every shape of a piece count the real spreadsheets hold (docs/features/data-import/README.md "Pieces",
 * docs/features/data-import/value-readers.md).
 */
final class PiecesReaderTest extends TestCase
{
    #[DataProvider('certainCounts')]
    public function testAWholeNumberIsCertain(CellInput $cell, int $pieces): void
    {
        $read = (new PiecesReader())->read($cell);

        self::assertSame(PiecesCertainty::Certain, $read->certainty);
        self::assertSame($pieces, $read->pieces);
        self::assertNull($read->problem);
        self::assertNull($read->note);
        self::assertSame([], $read->numbers);
    }

    /**
     * @return iterable<string, array{CellInput, int}>
     */
    public static function certainCounts(): iterable
    {
        yield 'text' => [CellInput::text('1000'), 1000];
        yield 'a number' => [CellInput::number(1000), 1000];
        yield 'a number stored as a float' => [CellInput::number(1000.0), 1000];
        yield 'spaces around' => [CellInput::text(' 500 '), 500];
        yield 'a non-breaking space after' => [CellInput::text("500\u{00A0}"), 500];
        yield 'full-width digits' => [CellInput::text('１０００'), 1000];
        yield 'leading zeros' => [CellInput::text('0500'), 500];
        yield 'one piece' => [CellInput::number(1), 1];
        yield 'an odd count' => [CellInput::text('1014'), 1014];
        yield 'the largest puzzle' => [CellInput::number(60000), 60000];
        yield 'a direction mark before' => [CellInput::text("\u{200E}300"), 300];
    }

    #[DataProvider('countsReadFromText')]
    public function testACountWithAThousandsMarkOrAWordIsReadFromText(string $text, int $pieces): void
    {
        $read = (new PiecesReader())->read(CellInput::text($text));

        self::assertSame(PiecesCertainty::ReadFromText, $read->certainty, $text);
        self::assertSame($pieces, $read->pieces, $text);
        self::assertNull($read->problem);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function countsReadFromText(): iterable
    {
        // Thousands marks
        yield 'a comma' => ['1,000', 1000];
        yield 'a space' => ['1 000', 1000];
        yield 'a dot before exactly three digits is a thousands mark' => ['1.000', 1000];
        yield 'a non-breaking space' => ["1\u{00A0}000", 1000];
        yield 'a narrow non-breaking space' => ["1\u{202F}000", 1000];
        yield 'an apostrophe' => ['12\'000', 12000];
        yield 'two digits before the mark' => ['18,000', 18000];
        yield 'two thousands with a dot' => ['2.000', 2000];

        // A number written with decimals that are all zero
        yield 'one zero decimal' => ['1000.0', 1000];
        yield 'two zero decimals' => ['500.00', 500];
        yield 'a decimal comma' => ['1000,0', 1000];

        // Words
        yield 'pcs' => ['1000 pcs', 1000];
        yield 'pcs.' => ['1000 pcs.', 1000];
        yield 'pc' => ['1000pc', 1000];
        yield 'pieces' => ['500 pieces', 500];
        yield 'piece' => ['1 piece', 1];
        yield 'upper case' => ['500 PIECES', 500];
        yield 'p' => ['500p', 500];
        yield 'elements' => ['500 elements', 500];
        yield 'cs: dílků' => ['500 dílků', 500];
        yield 'cs: dílky' => ['4 dílky', 4];
        yield 'cs: ks' => ['1000 ks', 1000];
        yield 'de: Teile' => ['500 Teile', 500];
        yield 'de: Teilen' => ['1000 Teilen', 1000];
        yield 'de: Stück' => ['500 Stück', 500];
        yield 'es: piezas' => ['500 piezas', 500];
        yield 'es: pzas' => ['1000 pzas', 1000];
        yield 'fr: pièces' => ['500 pièces', 500];
        yield 'fr: pièce' => ['1 pièce', 1];
        yield 'ja: ピース' => ['500ピース', 500];
        yield 'ja: ピース after a space and full-width digits' => ['１０００ ピース', 1000];
        yield 'a thousands mark and a word' => ['1,000 pieces', 1000];
    }

    /**
     * @param list<int> $numbers
     */
    #[DataProvider('multipacks')]
    public function testAMultipackIsAProblemWithItsNumbersHandedOut(string $text, array $numbers): void
    {
        $read = (new PiecesReader())->read(CellInput::text($text));

        self::assertSame(PiecesCertainty::Problem, $read->certainty, $text);
        self::assertSame(PiecesProblem::Multipack, $read->problem, $text);
        self::assertNull($read->pieces, 'One row never becomes several puzzles, and no count is chosen');
        self::assertSame($numbers, $read->numbers);
        self::assertSame(ImportText::clean($text), $read->note, 'The value as typed goes to the notes');
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function multipacks(): iterable
    {
        yield '13x99' => ['13x99', [13, 99]];
        yield '12 x 99' => ['12 x 99', [12, 99]];
        yield '500*4' => ['500*4', [500, 4]];
        yield '2×500' => ['2×500', [2, 500]];
        yield 'file 2: 12*80' => ['12*80', [12, 80]];
        yield 'an upper-case X' => ['4X100', [4, 100]];
        yield 'three sizes' => ['100 x 200 x 300', [100, 200, 300]];
        yield 'a sum' => ['500 + 1000', [500, 1000]];
        yield 'with a word' => ['2 x 500 pcs', [2, 500]];
        yield 'full-width' => ['２ｘ５００', [2, 500]];
    }

    /**
     * @param list<int> $numbers
     */
    #[DataProvider('unknownCounts')]
    public function testAnUnknownCountIsAProblem(string $text, array $numbers): void
    {
        $read = (new PiecesReader())->read(CellInput::text($text));

        self::assertSame(PiecesCertainty::Problem, $read->certainty, $text);
        self::assertSame(PiecesProblem::Unknown, $read->problem, $text);
        self::assertNull($read->pieces);
        self::assertSame($numbers, $read->numbers, $text);
        self::assertSame($text, $read->note);
    }

    /**
     * @return iterable<string, array{string, list<int>}>
     */
    public static function unknownCounts(): iterable
    {
        yield '?' => ['?', []];
        yield '??' => ['??', []];
        yield 'mini?' => ['mini?', []];
        yield 'a count with a question mark' => ['1000?', [1000]];
        yield 'about, a tilde' => ['~1000', [1000]];
        yield 'about, ca.' => ['ca. 500', [500]];
        yield 'about, approx' => ['approx 300', [300]];
        yield 'about, cs' => ['asi 500', [500]];
        yield 'about, ja' => ['約1000', [1000]];
        yield 'or more' => ['500+', [500]];
        yield 'two guesses' => ['500 or 1000?', [500, 1000]];
    }

    /**
     * @param list<int> $numbers
     */
    #[DataProvider('otherProblems')]
    public function testOtherProblems(CellInput $cell, PiecesProblem $problem, null|string $note = null, array $numbers = []): void
    {
        $read = (new PiecesReader())->read($cell);

        self::assertSame(PiecesCertainty::Problem, $read->certainty);
        self::assertSame($problem, $read->problem);
        self::assertNull($read->pieces);
        self::assertSame($note, $read->note);
        self::assertSame($numbers, $read->numbers);
    }

    /**
     * @return iterable<string, array{0: CellInput, 1: PiecesProblem, 2?: null|string, 3?: list<int>}>
     */
    public static function otherProblems(): iterable
    {
        yield 'an average worked out by a formula' => [CellInput::number(731.78), PiecesProblem::NotWhole, '731.78'];
        yield 'an average as text' => [CellInput::text('731.78'), PiecesProblem::NotWhole, '731.78'];
        yield 'one and a half' => [CellInput::text('1,5'), PiecesProblem::NotWhole, '1,5'];
        yield 'a half' => [CellInput::number(0.5), PiecesProblem::NotWhole, '0.5'];
        yield 'zero' => [CellInput::number(0), PiecesProblem::Zero];
        yield 'zero as text' => [CellInput::text('0'), PiecesProblem::Zero];
        yield 'zero with decimals' => [CellInput::text('0.0'), PiecesProblem::Zero];
        yield 'negative' => [CellInput::number(-5), PiecesProblem::Negative, '-5'];
        yield 'negative as text' => [CellInput::text('-500'), PiecesProblem::Negative, '-500'];
        yield 'negative float' => [CellInput::number(-1.5), PiecesProblem::Negative, '-1.5'];
        yield 'a typographic minus' => [CellInput::text('−500'), PiecesProblem::Negative, '−500'];
        yield 'more than the largest puzzle' => [CellInput::number(60001), PiecesProblem::TooMany, null, [60001]];
        yield 'a barcode in the pieces column' => [CellInput::text('400555616'), PiecesProblem::TooMany, null, [400555616]];
        yield 'a thousands mark too far' => [CellInput::text('500.000'), PiecesProblem::TooMany, null, [500000]];
        yield 'a huge float' => [CellInput::number(1e30), PiecesProblem::TooMany];
        yield 'a word' => [CellInput::text('large'), PiecesProblem::Unreadable, 'large'];
        yield 'a word: mini' => [CellInput::text('mini'), PiecesProblem::Unreadable, 'mini'];
        yield 'scientific notation' => [CellInput::text('1e3'), PiecesProblem::Unreadable, '1e3'];
        yield 'NaN as text' => [CellInput::text('NaN'), PiecesProblem::Unreadable, 'NaN'];
        yield 'a dash' => [CellInput::text('-'), PiecesProblem::Unreadable, '-'];
        yield 'n/a' => [CellInput::text('n/a'), PiecesProblem::Unreadable, 'n/a'];
        yield 'a range' => [CellInput::text('500-1000'), PiecesProblem::Unreadable, '500-1000'];
        yield 'a size' => [CellInput::text('70 x 50 cm'), PiecesProblem::Unreadable, '70 x 50 cm'];
        yield 'a number too long' => [CellInput::text('12345678901'), PiecesProblem::Unreadable, '12345678901'];
        yield 'mixed thousands marks' => [CellInput::text('1,000.000'), PiecesProblem::Unreadable, '1,000.000'];
        yield 'an emoji' => [CellInput::text('🧩'), PiecesProblem::Unreadable, '🧩'];
        yield 'not a number' => [CellInput::number(NAN), PiecesProblem::Unreadable];
        yield 'infinity' => [CellInput::number(INF), PiecesProblem::Unreadable];
        yield 'a date' => [CellInput::date(TestClock::now()), PiecesProblem::Unreadable];
        yield 'a duration' => [CellInput::duration(500), PiecesProblem::Unreadable];
        yield 'a time of day' => [CellInput::timeOfDay(500), PiecesProblem::Unreadable];
        yield 'yes' => [CellInput::boolean(true), PiecesProblem::Unreadable];
        yield 'a very long text' => [CellInput::text(str_repeat('1000 pieces ', 100)), PiecesProblem::Unreadable];
        yield 'a megabyte of digits' => [CellInput::text(str_repeat('9', 1_000_000)), PiecesProblem::Unreadable];
    }

    #[DataProvider('noPieces')]
    public function testAnEmptyCellIsNoPieces(CellInput $cell): void
    {
        $read = (new PiecesReader())->read($cell);

        self::assertSame(PiecesCertainty::NoPieces, $read->certainty);
        self::assertNull($read->pieces);
        self::assertNull($read->problem);
    }

    /**
     * @return iterable<string, array{CellInput}>
     */
    public static function noPieces(): iterable
    {
        yield 'no cell' => [CellInput::blank()];
        yield 'an empty text' => [CellInput::text('')];
        yield 'spaces' => [CellInput::text('   ')];
        yield 'a non-breaking space' => [CellInput::text("\u{00A0}")];
        yield 'a zero-width space' => [CellInput::text("\u{200B}")];
    }

    public function testEveryCountTakenIsInRange(): void
    {
        $reader = new PiecesReader();

        foreach (['1', '60000', '60,000', '60.000', '60 000 pcs', '59999.0'] as $text) {
            $read = $reader->read(CellInput::text($text));

            self::assertNotNull($read->pieces, $text);
            self::assertGreaterThanOrEqual(1, $read->pieces);
            self::assertLessThanOrEqual(PiecesReader::MOST_PIECES, $read->pieces);
        }

        foreach (['60001', '60,001', '100.000', '99999 pcs'] as $text) {
            self::assertSame(PiecesProblem::TooMany, $reader->read(CellInput::text($text))->problem, $text);
        }
    }
}
