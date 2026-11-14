<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport\Reading;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\Reading\YesNoReader;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\YesNoCertainty;

/**
 * The words and marks people use for yes and no (docs/features/data-import/value-readers.md "Yes / no").
 */
final class YesNoReaderTest extends TestCase
{
    private const array YES = [
        'y', 'Y', 'yes', 'Yes', 'YES', 'yep', 'yeah', 'true', 'TRUE', 'True', 't', '1', 'x', 'X', 'ok', 'done',
        '✓', '✔', '☑', '✅', "✔\u{FE0F}", '○',
        'ano', 'Ano', 'ANO', 'jo', 'pravda',
        'ja', 'Ja', 'j', 'wahr', 'WAHR',
        'sí', 'Sí', 'si', 'SI', 's', 'verdadero',
        'oui', 'Oui', 'o', 'vrai', 'VRAI',
        'はい', '済',
        ' yes ', "yes\u{00A0}", "\u{200E}yes", 'Yes.', 'yes!', '(x)', '[x]', '"yes"', 'ｙｅｓ', 'Ｙ',
    ];

    private const array NO = [
        'n', 'N', 'no', 'No', 'NO', 'nope', 'false', 'FALSE', 'False', 'f', '0',
        '☐', '✗', '✘', '❌', '×',
        'ne', 'Ne', 'NE', 'nepravda',
        'nein', 'Nein', 'falsch', 'FALSCH',
        'falso',
        'non', 'Non', 'faux', 'FAUX',
        'いいえ',
        ' no ', 'No.', '(n)', 'ｎｏ',
    ];

    #[DataProvider('yes')]
    public function testYes(CellInput $cell): void
    {
        $read = (new YesNoReader())->read($cell);

        self::assertSame(YesNoCertainty::Known, $read->certainty);
        self::assertTrue($read->value);
        self::assertTrue($read->isYes());
        self::assertFalse($read->isNo());
        self::assertNull($read->word);
    }

    /**
     * @return iterable<string, array{CellInput}>
     */
    public static function yes(): iterable
    {
        foreach (self::YES as $text) {
            yield 'text ' . json_encode($text) => [CellInput::text($text)];
        }

        yield 'a ticked checkbox' => [CellInput::boolean(true)];
        yield 'the number 1' => [CellInput::number(1)];
        yield 'the number 1.0' => [CellInput::number(1.0)];
    }

    #[DataProvider('no')]
    public function testNo(CellInput $cell): void
    {
        $read = (new YesNoReader())->read($cell);

        self::assertSame(YesNoCertainty::Known, $read->certainty);
        self::assertFalse($read->value);
        self::assertTrue($read->isNo());
        self::assertFalse($read->isYes());
        self::assertNull($read->word);
    }

    /**
     * @return iterable<string, array{CellInput}>
     */
    public static function no(): iterable
    {
        foreach (self::NO as $text) {
            yield 'text ' . json_encode($text) => [CellInput::text($text)];
        }

        yield 'an unticked checkbox' => [CellInput::boolean(false)];
        yield 'the number 0' => [CellInput::number(0)];
        yield 'the number 0.0' => [CellInput::number(0.0)];
    }

    #[DataProvider('unknown')]
    public function testAnythingElseIsUnknownAndComesBackAsTyped(CellInput $cell, string $word): void
    {
        $read = (new YesNoReader())->read($cell);

        self::assertSame(YesNoCertainty::Unknown, $read->certainty);
        self::assertNull($read->value);
        self::assertFalse($read->isYes());
        self::assertFalse($read->isNo());
        self::assertSame($word, $read->word);
    }

    /**
     * @return iterable<string, array{CellInput, string}>
     */
    public static function unknown(): iterable
    {
        yield 'the README\'s status' => [CellInput::text('Back to the library'), 'Back to the library'];
        yield 'maybe' => [CellInput::text('maybe'), 'maybe'];
        yield 'a question mark' => [CellInput::text('?'), '?'];
        yield 'a dash' => [CellInput::text('-'), '-'];
        yield 'n/a' => [CellInput::text('n/a'), 'n/a'];
        yield 'a word with its case and accents' => [CellInput::text('  Prodáno Aleně  '), 'Prodáno Aleně'];
        yield 'yes and more' => [CellInput::text('yes, twice'), 'yes, twice'];
        yield 'a date after the flag is not this reader\'s' => [CellInput::text('Y - 30/12/2023'), 'Y - 30/12/2023'];
        yield 'a tag' => [CellInput::text('#xSOLD'), '#xSOLD'];
        yield 'a count' => [CellInput::text('2'), '2'];
        yield 'the number 2 - "Have multiple copies: 2"' => [CellInput::number(2), '2'];
        yield 'a negative number' => [CellInput::number(-1), '-1'];
        yield 'a decimal' => [CellInput::number(0.5), '0.5'];
        yield 'an empty pair of brackets' => [CellInput::text('[ ]'), '[ ]'];
        yield 'an emoji' => [CellInput::text('🧩'), '🧩'];
        yield 'two letters that are a word together only' => [CellInput::text('yn'), 'yn'];
        yield 'a date' => [CellInput::date(TestClock::now()), '2026-10-14'];
        yield 'a duration' => [CellInput::duration(60), '60'];
        yield 'a time of day' => [CellInput::timeOfDay(3600), '3600'];
    }

    public function testALongTextIsCutForTheQuestion(): void
    {
        $read = (new YesNoReader())->read(CellInput::text(str_repeat('ž', 5000)));

        self::assertSame(YesNoCertainty::Unknown, $read->certainty);
        self::assertSame(str_repeat('ž', 100), $read->word);
    }

    #[DataProvider('blank')]
    public function testAnEmptyCellSaysNothing(CellInput $cell): void
    {
        $read = (new YesNoReader())->read($cell);

        self::assertSame(YesNoCertainty::Blank, $read->certainty);
        self::assertNull($read->value);
        self::assertNull($read->word);
    }

    /**
     * @return iterable<string, array{CellInput}>
     */
    public static function blank(): iterable
    {
        yield 'no cell' => [CellInput::blank()];
        yield 'an empty text' => [CellInput::text('')];
        yield 'spaces' => [CellInput::text('   ')];
        yield 'a non-breaking space' => [CellInput::text("\u{00A0}")];
        yield 'a direction mark' => [CellInput::text("\u{200F}")];
    }
}
