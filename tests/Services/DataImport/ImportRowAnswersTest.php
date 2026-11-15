<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Services\DataImport\Reading\BarcodeCellReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\ImportDateReader;
use SpeedPuzzling\Web\Services\DataImport\Reading\PiecesReader;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportRowLeftOut;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use Symfony\Component\Clock\MockClock;

/**
 * docs/features/data-import/pairing-screens.md "AnswerImportRows" - what a typed answer is read as (the value
 * readers' word, never a guess), and what the parser makes of a row that carries answers. Today is TestClock's
 * 14 October 2026.
 */
final class ImportRowAnswersTest extends TestCase
{
    /**
     * @return iterable<string, array{ImportField, string, mixed}>
     */
    public static function typed(): iterable
    {
        yield 'hours, minutes and seconds' => [ImportField::Time, '1:15:00', 4500];
        yield 'a time with seconds' => [ImportField::Time, '0:45:10', 2710];
        yield 'a time in words' => [ImportField::Time, '45 min', 2700];
        yield 'minutes or hours? - not read' => [ImportField::Time, '45:10', null];
        yield 'a bare number is no time' => [ImportField::Time, '75', null];
        yield 'words are no time' => [ImportField::Time, 'about an hour', null];
        yield 'pieces' => [ImportField::Pieces, '1000', 1000];
        yield 'pieces in words are not read' => [ImportField::Pieces, 'a few', null];
        yield 'a day' => [ImportField::SolvedOn, '2025-01-03', '2025-01-03'];
        yield 'a day in the future is not taken' => [ImportField::SolvedOn, '2027-01-03', null];
        yield 'two orders of a date are not guessed' => [ImportField::SolvedOn, '3/4/2023', null];
        yield 'a month is no day' => [ImportField::SolvedOn, 'January 2025', null];
        yield 'solo, pair or team' => [ImportField::SolvingType, 'duo', 'duo'];
        yield 'another word is none of them' => [ImportField::SolvingType, 'crowd', null];
        yield 'an attempt number' => [ImportField::AttemptNumber, '3', 3];
        yield 'attempt zero' => [ImportField::AttemptNumber, '0', null];
        yield 'yes' => [ImportField::FirstTry, 'yes', true];
        yield 'no' => [ImportField::SolvedWithoutBox, 'no', false];
        yield 'maybe is neither' => [ImportField::FirstTry, 'maybe', null];
        yield 'what the row is' => [ImportField::Status, 'wish_list', 'wish_list'];
        yield 'a name' => [ImportField::PuzzleName, '  Zq Circle of  Kites ', 'Zq Circle of Kites'];
        yield 'nothing typed' => [ImportField::PuzzleName, '   ', null];
        yield 'a field nobody answers' => [ImportField::SolvedWith, 'Anna', null];
        yield 'a barcode' => [ImportField::Barcode, '4005556174812', ['4005556174812']];
    }

    #[DataProvider('typed')]
    public function testATypedAnswerIsReadByItsFieldsReaderOrNotAtAll(ImportField $field, string $typed, mixed $expected): void
    {
        $read = self::answers()->read($field, $typed);

        self::assertSame($expected, $read === null ? null : $read['value']);
    }

    public function testTheParserTakesAnAnswerInsteadOfTheCellAndNothingIsUnclearAboutThatField(): void
    {
        $kit = new ImportTestKit();
        $sheet = ImportTestKit::sheet('Results', [
            1 => ['Puzzle', 'Pieces', 'Date', 'Time'],
            2 => ['Zq Answered Harbour', 'lots', ImportTestKit::date('2026-01-10'), 'about an hour'],
            3 => ['Zq Plain Meadow', 500, ImportTestKit::date('2026-01-11'), ImportTestKit::duration(2893)],
            4 => ['Zq Plain Orchard', 500, ImportTestKit::date('2026-01-12'), ImportTestKit::duration(2411)],
        ]);
        $proposal = $kit->workbook([$sheet])['Results']['proposal'];
        $mapping = $proposal->mapping;
        $options = $proposal->options;
        $cells = $sheet->rows[2];

        // As the file has it: two things are unclear
        $asRead = $kit->parser->parse(2, $cells, $mapping, $options);
        self::assertSame(['pieces_unreadable', 'time_unreadable'], array_column($asRead->problems, 'code'));

        $answers = [
            'pieces' => self::answers()->read(ImportField::Pieces, '1000'),
            'time' => self::answers()->read(ImportField::Time, '1:15:00'),
        ];
        $answered = $kit->parser->parse(2, $cells, $mapping, $options, rowAnswers: $answers);

        self::assertSame([], $answered->problems);
        self::assertSame([1000, 4500, 'Zq Answered Harbour'], [$answered->parsed['pieces'], $answered->parsed['seconds'], $answered->parsed['name']]);
        // The row's keys are those of the answered values: another puzzle key, a result key with the time
        self::assertNotSame($asRead->puzzleKey, $answered->puzzleKey);
        self::assertNotSame($asRead->sourceKey, $answered->sourceKey);
        // The same answers, the same row - whenever it is parsed
        self::assertEquals($answered, $kit->parser->parse(2, $cells, $mapping, $options, rowAnswers: $answers));

        // One answer leaves the other field as the file has it
        $half = $kit->parser->parse(2, $cells, $mapping, $options, rowAnswers: ['time' => $answers['time']]);
        self::assertSame(['pieces_unreadable'], array_column($half->problems, 'code'));

        // An answer that is not of its field's shape is passed over - never a guess
        $broken = $kit->parser->parse(2, $cells, $mapping, $options, rowAnswers: ['time' => ['text' => 'x', 'value' => 'soon'], 'pieces' => 'many']);
        self::assertSame(['pieces_unreadable', 'time_unreadable'], array_column($broken->problems, 'code'));

        // "Leave this row out"
        $leftOut = $kit->parser->parse(2, $cells, $mapping, $options, rowAnswers: [ImportRowAnswers::LEFT_OUT => true, ...$answers]);
        self::assertSame([ImportRowLeftOut::ByPlayer, DataImportRowKind::NotAPuzzle], [$leftOut->leftOut, $leftOut->kind]);
    }

    private static function answers(): ImportRowAnswers
    {
        return new ImportRowAnswers(new DurationReader(), new ImportDateReader(new MockClock(TestClock::now())), new PiecesReader(), new BarcodeCellReader());
    }
}
