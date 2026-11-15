<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidImportMapping;
use SpeedPuzzling\Web\Services\DataImport\ImportColumnDecisions;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportTestKit as Kit;
use SpeedPuzzling\Web\Value\DataImport\DateOrder;
use SpeedPuzzling\Web\Value\DataImport\DurationReadingRule;
use SpeedPuzzling\Web\Value\DataImport\DurationShape;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestion;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetProposal;
use SpeedPuzzling\Web\Value\DataImport\ShortDateMeaning;
use SpeedPuzzling\Web\Value\DataImport\SheetSample;

/**
 * docs/features/data-import/columns.md "Questions": what the rows settle about a sheet's times and dates, and what
 * a sheet must ask. Every sheet here is made up.
 */
final class ImportColumnDecisionsTest extends TestCase
{
    public function testATimeTheRowsCanNotSettleIsAskedWithItsReadingsAndExamples(): void
    {
        [$options, $questions] = $this->settle(Kit::sheet('2023', [
            1 => ['Puzzle', 'Pieces', 'Time'],
            2 => ['Plum Orchard', 500, '45:10'],
            3 => ['Chalk Cliffs', 500, '52:31'],
            4 => ['Yellow Bicycles', 1000, '40:05'],
        ]));

        self::assertCount(1, $questions);
        self::assertSame('duration_shape.two_part_text', $questions[0]->id());
        self::assertSame([3, DurationShape::TwoPartText, 3], [$questions[0]->column, $questions[0]->shape, $questions[0]->rows]);
        self::assertEqualsCanonicalizing(['minutes_seconds', 'hours_minutes'], $questions[0]->choices);
        self::assertContains('45:10', $questions[0]->examples);
        self::assertNull($options->rule(DurationShape::TwoPartText));
    }

    public function testATimeTheRowsSettleIsNotAsked(): void
    {
        // 1,000 pieces in "3:30" can only be hours and minutes: as minutes and seconds it is 286 pieces a minute
        [$options, $questions] = $this->settle(Kit::sheet('Slow', [
            1 => ['Puzzle', 'Pieces', 'Time'],
            2 => ['Plum Orchard', 1000, '3:30'],
            3 => ['Chalk Cliffs', 1000, '4:12'],
            4 => ['Yellow Bicycles', 1000, '2:55'],
        ]));

        self::assertSame([], $questions);
        self::assertSame(['rule' => DurationReadingRule::HoursMinutes, 'by' => ImportSheetOptions::BY_ROWS], $options->durations['two_part_text']);
    }

    public function testAUnitInTheHeaderAnswersTheBareNumbers(): void
    {
        [$options, $questions] = $this->settle(Kit::sheet('Minutes', [
            1 => ['Puzzle', 'Pieces', 'Time (min)'],
            2 => ['Plum Orchard', 500, 237],
            3 => ['Chalk Cliffs', 500, 61],
        ]));
        [, $asked] = $this->settle(Kit::sheet('Numbers', [
            1 => ['Puzzle', 'Pieces', 'Time'],
            2 => ['Plum Orchard', 500, 237],
            3 => ['Chalk Cliffs', 500, 61],
        ]));

        self::assertSame([], $questions);
        self::assertSame(['rule' => DurationReadingRule::Minutes, 'by' => ImportSheetOptions::BY_HEADER], $options->durations['bare_number']);
        self::assertSame(['duration_shape.bare_number'], array_map(static fn (ImportQuestion $question): string => $question->id(), $asked));
    }

    public function testTheOrderOfDayAndMonthIsSettledByAValueOrAsked(): void
    {
        [$settled, $none] = $this->settle(Kit::sheet('A', [1 => ['Puzzle', 'Date'], 2 => ['Plum Orchard', '3/4/2021'], 3 => ['Chalk Cliffs', '30/4/2021']]));
        [$open, $questions] = $this->settle(Kit::sheet('B', [1 => ['Puzzle', 'Date'], 2 => ['Plum Orchard', '3/4/2021'], 3 => ['Chalk Cliffs', '5/6/2021']]));

        self::assertSame([], $none);
        self::assertSame(['order' => DateOrder::DayMonthYear, 'by' => ImportSheetOptions::BY_ROWS], $settled->dateOrder);
        self::assertNull($open->dateOrder);
        self::assertSame(['date_order'], array_map(static fn (ImportQuestion $question): string => $question->id(), $questions));
        self::assertEqualsCanonicalizing(['dmy', 'mdy'], $questions[0]->choices);
        self::assertSame(['3/4/2021', '5/6/2021'], $questions[0]->examples);
    }

    public function testRowsThatCanOnlyBeDayFirstBesideRowsThatCanOnlyBeMonthFirstAreAlwaysAsked(): void
    {
        $rows = [1 => ['Puzzle', 'Date'], 2 => ['Plum Orchard', '25/3/2023'], 3 => ['Chalk Cliffs', '3/25/2023']];

        // One of each among hundreds that read both ways: a tie no count may settle
        for ($row = 4; $row <= 300; $row++) {
            $rows[$row] = ['Puzzle ' . $row, sprintf('%d/%d/2023', 1 + $row % 12, 1 + $row % 11)];
        }

        [$options, $questions] = $this->settle(Kit::sheet('Mixed', $rows));

        self::assertNull($options->dateOrder);
        self::assertSame(['date_order'], array_map(static fn (ImportQuestion $question): string => $question->id(), $questions));
        self::assertEqualsCanonicalizing(['dmy', 'mdy'], $questions[0]->choices);
        // A row of each is shown
        self::assertSame(['25/3/2023', '3/25/2023'], $questions[0]->examples);

        // Also when most rows go one way: the others are not outvoted silently
        [$options, $questions] = $this->settle(Kit::sheet('Mixed', [
            1 => ['Puzzle', 'Date'],
            2 => ['Plum Orchard', '30/01/2021'],
            3 => ['Chalk Cliffs', '14/02/2021'],
            4 => ['Yellow Bicycles', '12/31/2021'],
        ]));

        self::assertNull($options->dateOrder);
        self::assertSame(['dmy', ['30/01/2021', '14/02/2021', '12/31/2021']], [$questions[0]->suggested, $questions[0]->examples]);
    }

    public function testOneValueAmongHundredsThatReadBothWaysDoesNotSettleTheOrder(): void
    {
        $rows = [1 => ['Puzzle', 'Date'], 2 => ['Plum Orchard', '25/3/2023']];

        for ($row = 3; $row <= 300; $row++) {
            $rows[$row] = ['Puzzle ' . $row, sprintf('%d/%d/2023', 1 + $row % 12, 1 + $row % 11)];
        }

        [$options, $questions] = $this->settle(Kit::sheet('Long', $rows));

        // Asked, with the order the one value points to offered first
        self::assertNull($options->dateOrder);
        self::assertSame(['date_order', ['dmy', 'mdy'], 'dmy'], [$questions[0]->id(), $questions[0]->choices, $questions[0]->suggested]);

        // Three such values are evidence
        $rows[3] = ['Chalk Cliffs', '26/3/2023'];
        $rows[4] = ['Tin Robots', '27/3/2023'];
        [$options, $questions] = $this->settle(Kit::sheet('Long', $rows));

        self::assertSame([], $questions);
        self::assertSame(['order' => DateOrder::DayMonthYear, 'by' => ImportSheetOptions::BY_ROWS], $options->dateOrder);
    }

    public function testShortDatesAreAskedWhenEveryValueCanBeADayOrAYear(): void
    {
        [, $questions] = $this->settle(Kit::sheet('Short', [1 => ['Puzzle', 'Date'], 2 => ['Plum Orchard', 'Jan 23'], 3 => ['Chalk Cliffs', 'Feb 23'], 4 => ['Tin Robots', 'Mar 23']]));
        [$settled, $none] = $this->settle(Kit::sheet('Short', [1 => ['Puzzle', 'Date'], 2 => ['Plum Orchard', 'Jan 23'], 3 => ['Chalk Cliffs', 'Feb 30']]));

        self::assertSame('short_dates', $questions[0]->id());
        self::assertSame(['day_of_month', 'month_of_year'], $questions[0]->choices);
        self::assertSame('month_of_year', $questions[0]->suggested);
        // "Feb 30" can only be February 2030: the column is months of years
        self::assertSame([], $none);
        self::assertSame(['meaning' => ShortDateMeaning::MonthOfYear, 'by' => ImportSheetOptions::BY_ROWS], $settled->shortDates);
    }

    public function testTheYearOfDatesWithoutOneComesFromTheSheetsNameOrIsAsked(): void
    {
        $hidden = static fn (string $date): array => Kit::date($date, false);

        [$named] = $this->settle(Kit::sheet('Results 2022', [1 => ['Puzzle', 'Date'], 2 => ['Plum Orchard', $hidden('2022-01-03')], 3 => ['Chalk Cliffs', '20-Sep']]));
        [, $disagrees] = $this->settle(Kit::sheet('2019', [1 => ['Puzzle', 'Date'], 2 => ['Plum Orchard', $hidden('2020-01-05')]]));
        [, $unnamed] = $this->settle(Kit::sheet('Results', [1 => ['Puzzle', 'Date'], 2 => ['Plum Orchard', $hidden('2020-01-05')]]));
        [, $text] = $this->settle(Kit::sheet('Results', [1 => ['Puzzle', 'Date'], 2 => ['Plum Orchard', '20-Sep']]));
        [$known, $none] = $this->settle(Kit::sheet('Results', [1 => ['Puzzle', 'Date'], 2 => ['Plum Orchard', Kit::date('2020-01-05')]]));

        self::assertSame(['year' => 2022, 'by' => ImportSheetOptions::BY_SHEET_NAME], $named->year);
        // The sheet is named 2019, its cells store 2020: asked, never chosen
        self::assertSame([['2019', ImportQuestion::YEAR_AS_STORED], 1], [$disagrees[0]->choices, $disagrees[0]->rows]);
        self::assertSame([ImportQuestion::YEAR_AS_STORED], $unnamed[0]->choices);
        // A text without a year has no year stored anywhere: only a year the player types
        self::assertSame([], $text[0]->choices);
        self::assertTrue($text[0]->accepts('2021'));
        self::assertFalse($text[0]->accepts('21'));
        self::assertSame([null, []], [$known->year, $none]);
    }

    public function testAnAnswerIsWrittenIntoTheOptionsAndTheQuestionIsGone(): void
    {
        $kit = new Kit();
        $sheet = Kit::sheet('2023', [
            1 => ['Puzzle', 'Pieces', 'Date', 'Time'],
            2 => ['Plum Orchard', 500, '3/4/2023', '45:10'],
            3 => ['Chalk Cliffs', 500, '5/6/2023', '52:31'],
        ]);
        $proposal = $this->proposal($kit, $sheet);
        self::assertCount(2, $proposal->mapping->questions);

        $answered = $kit->decisions->answered($proposal->mapping, $proposal->options, ['duration_shape.two_part_text' => 'minutes_seconds', 'date_order' => 'dmy']);
        [$options, $questions] = $kit->decisions->settle($proposal->mapping, $answered, $sheet->title, $sheet->rows);

        self::assertSame([], $questions);
        self::assertSame(['rule' => DurationReadingRule::MinutesSeconds, 'by' => ImportSheetOptions::BY_PLAYER], $options->durations['two_part_text']);
        self::assertSame(['order' => DateOrder::DayMonthYear, 'by' => ImportSheetOptions::BY_PLAYER], $options->dateOrder);
    }

    public function testAnAnswerNobodyAskedForIsRefused(): void
    {
        $kit = new Kit();
        $sheet = Kit::sheet('2023', [1 => ['Puzzle', 'Pieces', 'Time'], 2 => ['Plum Orchard', 500, '45:10']]);
        $proposal = $this->proposal($kit, $sheet);

        $refused = 0;

        foreach ([['date_order' => 'dmy'], ['duration_shape.two_part_text' => 'days'], ['duration_shape.two_part_text' => 5]] as $answers) {
            try {
                $kit->decisions->answered($proposal->mapping, $proposal->options, $answers);
            } catch (InvalidImportMapping) {
                $refused++;
            }
        }

        self::assertSame(3, $refused);
    }

    public function testTheYearASheetsNameSays(): void
    {
        self::assertSame(2019, ImportColumnDecisions::yearOf('2019'));
        self::assertSame(2021, ImportColumnDecisions::yearOf('Results 2021 (final)'));
        self::assertNull(ImportColumnDecisions::yearOf('2019-2020'));
        self::assertNull(ImportColumnDecisions::yearOf('Sheet1'));
        self::assertNull(ImportColumnDecisions::yearOf('Top 20000'));
    }

    /**
     * @return array{ImportSheetOptions, list<ImportQuestion>}
     */
    private function settle(SheetSample $sheet): array
    {
        $proposal = $this->proposal(new Kit(), $sheet);

        return [$proposal->options, $proposal->mapping->questions];
    }

    private function proposal(Kit $kit, SheetSample $sheet): ImportSheetProposal
    {
        return $kit->workbook([$sheet])[$sheet->title]['proposal'];
    }
}
