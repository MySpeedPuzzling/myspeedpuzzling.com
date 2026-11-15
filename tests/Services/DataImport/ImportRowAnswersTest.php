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
        yield 'no time - not timed' => [ImportField::Time, ImportRowAnswers::NO_TIME, 0];
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
        yield 'people, each a guest' => [ImportField::SolvedWith, 'Anna, Ben ; Anna', ['Anna', 'Ben']];
        yield 'a field nobody answers' => [ImportField::BrandCode, 'RB-123', null];
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

    public function testNoTimeMakesTheRowWhatAnEmptyTimeCellMakesIt(): void
    {
        $kit = new ImportTestKit();
        $rows = static fn (mixed $unreadable, mixed $readable, mixed $alone): array => [
            1 => ['Puzzle', 'Pieces', 'Date', 'Time'],
            2 => ['Zq Untimed Harbour', 500, ImportTestKit::date('2026-01-10'), $unreadable],
            3 => ['Zq Untimed Meadow', 500, ImportTestKit::date('2026-01-11'), $readable],
            // Nothing but its time says that this one was solved
            4 => ['Zq Untimed Orchard', 500, null, $alone],
            5 => ['Zq Plain Lighthouse', 500, ImportTestKit::date('2026-01-12'), ImportTestKit::duration(2411)],
            6 => ['Zq Plain Windmill', 500, ImportTestKit::date('2026-01-13'), ImportTestKit::duration(2512)],
            7 => ['Zq Plain Bridge', 500, ImportTestKit::date('2026-01-14'), ImportTestKit::duration(2613)],
            // Nothing says that this one was solved, and its time cell is empty
            8 => ['Zq Shelf Windmill', 500, null, null],
        ];
        $sheet = ImportTestKit::sheet('Results', $rows('casually, over a week', ImportTestKit::duration(2893), 'no idea'));
        $empty = ImportTestKit::sheet('Results', $rows(null, null, null));
        $proposal = $kit->workbook([$sheet])['Results']['proposal'];
        $mapping = $proposal->mapping;
        $options = $proposal->options;
        $noTime = ['time' => self::answers()->read(ImportField::Time, ImportRowAnswers::NO_TIME)];
        self::assertSame(['time' => ['text' => 'no_time', 'value' => 0]], $noTime);

        // A time nobody can read, and a time that was read: "No time" makes both a result without a time, with
        // nothing unclear - the very row an empty time cell makes (the same keys: never twice, equal rows)
        self::assertSame(['time_unreadable'], array_column($kit->parser->parse(2, $sheet->rows[2], $mapping, $options)->problems, 'code'));
        self::assertSame(2893, $kit->parser->parse(3, $sheet->rows[3], $mapping, $options)->parsed['seconds']);

        foreach ([2, 3] as $rowNumber) {
            $answered = $kit->parser->parse($rowNumber, $sheet->rows[$rowNumber], $mapping, $options, rowAnswers: $noTime);
            $asEmptyCell = $kit->parser->parse($rowNumber, $empty->rows[$rowNumber], $mapping, $options);

            self::assertSame([], $answered->problems);
            self::assertSame(DataImportRowKind::Result, $answered->kind);
            self::assertArrayNotHasKey('seconds', $answered->parsed);
            self::assertEquals($asEmptyCell, $answered);
        }

        // A row that only its time made a result stays a result - the player said it was solved, just not timed
        $alone = $kit->parser->parse(4, $sheet->rows[4], $mapping, $options, rowAnswers: $noTime);
        self::assertSame([DataImportRowKind::Result, []], [$alone->kind, $alone->problems]);
        self::assertArrayNotHasKey('seconds', $alone->parsed);
        self::assertNotNull($alone->sourceKey);

        // "No time" never says "solved" by itself: with an empty time cell and nothing else of a result, the row is
        // what it was without the answer - a row of a shelf stays a row of a shelf
        self::assertEquals(
            $kit->parser->parse(8, $sheet->rows[8], $mapping, $options),
            $kit->parser->parse(8, $sheet->rows[8], $mapping, $options, rowAnswers: $noTime),
        );

        // Back to a time: the answer is a time again, or is taken back and the cell is read as before
        $timed = $kit->parser->parse(2, $sheet->rows[2], $mapping, $options, rowAnswers: ['time' => self::answers()->read(ImportField::Time, '1:15:00')]);
        self::assertSame(4500, $timed->parsed['seconds']);
        self::assertSame(2893, $kit->parser->parse(3, $sheet->rows[3], $mapping, $options, rowAnswers: [])->parsed['seconds']);
    }

    private static function answers(): ImportRowAnswers
    {
        return new ImportRowAnswers(new DurationReader(), new ImportDateReader(new MockClock(TestClock::now())), new PiecesReader(), new BarcodeCellReader());
    }
}
