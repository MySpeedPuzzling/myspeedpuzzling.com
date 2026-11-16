<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport\Reading;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationColumnResolver;
use SpeedPuzzling\Web\Services\DataImport\Reading\DurationReader;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\DurationCertainty;
use SpeedPuzzling\Web\Value\DataImport\DurationColumnCell;
use SpeedPuzzling\Web\Value\DataImport\DurationReadingRule;
use SpeedPuzzling\Web\Value\DataImport\DurationShape;
use SpeedPuzzling\Web\Value\DataImport\DurationShapeOption;
use SpeedPuzzling\Web\Value\DataImport\DurationUnit;

/**
 * One answer per column and shape, helped by the piece count - and a question whenever the rows leave room for
 * another meaning (docs/features/data-import/value-readers.md "The column decides"). A wrong answer here is a result
 * sixty times too fast on a public leaderboard.
 */
final class DurationColumnResolverTest extends TestCase
{
    public function testAColumnWithoutAmbiguousShapesNeedsNoDecision(): void
    {
        $resolution = (new DurationColumnResolver())->resolve(self::column([
            ['1:09:17', 1000],
            ['0:55:41', 500],
            ['8min', 100],
            ['', 500],
            ['tube', 500],
        ]));

        self::assertSame([], $resolution->decisions);
        self::assertSame([], $resolution->questions());
    }

    public function testASlowPuzzlersHoursAreNeverTakenForMinutes(): void
    {
        // The review's column: somebody who needs 35-52 hours for 1,000 pieces and writes h:mm. As minutes:seconds it
        // would be 19-28 pieces a minute - possible, so a "possible pace" rule put 40 minutes on a public leaderboard
        $cells = self::column([
            ['40:00', 1000],
            ['35:30', 1000],
            ['52:10', 1000],
        ]);

        $resolution = (new DurationColumnResolver())->resolve($cells);
        $decision = $resolution->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertFalse($decision->isResolved());
        self::assertNull($decision->rule);
        self::assertSame(DurationReadingRule::HoursMinutes, $decision->suggested, 'The ordinary pace is offered first - and only offered');
        self::assertSame([$decision], $resolution->questions());
        self::assertNull($resolution->apply($cells[0]->read)->seconds, 'Nothing is taken while the question is open');

        [$minutesSeconds, $hoursMinutes] = $decision->options;
        self::assertSame(DurationReadingRule::MinutesSeconds, $minutesSeconds->rule);
        self::assertSame(0, $minutesSeconds->ordinaryRows);
        self::assertSame(3, $minutesSeconds->plausibleRows);
        self::assertSame(DurationReadingRule::HoursMinutes, $hoursMinutes->rule);
        self::assertSame(3, $hoursMinutes->ordinaryRows);
    }

    public function testABareNumberOfHoursIsNeverTakenForMinutes(): void
    {
        // "40" for forty hours of a 1,000-piece puzzle: as minutes it is 25 pieces a minute
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['40', 1000],
            ['35', 1000],
            ['52', 1000],
        ]))->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
        self::assertSame(DurationReadingRule::Hours, $decision->suggested);
    }

    public function testAFastPaceIsNeverChosenForThePlayer(): void
    {
        // 500 pieces in 17-45 minutes are 11-28 pieces a minute: real for a speed puzzler, and never assumed
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['17:36', 500],
            ['25:10', 500],
            ['44:59', 500],
            ['31:05', 500],
        ]))->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
    }

    public function testMinutesAndSecondsOfAnOrdinaryPaceStillLeaveRoomForHours(): void
    {
        // 500 pieces in 45 minutes (11 a minute) or in 45 hours (0.18 a minute): slow, but not out of the world
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['45:10', 500],
            ['52:30', 500],
            ['38:05', 500],
        ]))->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
        self::assertSame(DurationReadingRule::MinutesSeconds, $decision->suggested);
    }

    public function testHoursAndMinutesAreSettledWhenMinutesWouldBeFasterThanAnybody(): void
    {
        // 1,000 pieces in 2:45-4:10 - as minutes:seconds 240-360 pieces a minute, beyond what the add form accepts
        $cells = self::column([
            ['3:30', 1000],
            ['2:45', 1000],
            ['4:10', 1000],
            ['1:09:17', 1000],
        ]);

        $resolution = (new DurationColumnResolver())->resolve($cells);
        $decision = $resolution->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertTrue($decision->isResolved());
        self::assertSame(DurationReadingRule::HoursMinutes, $decision->rule);
        self::assertNull($decision->suggested);
        self::assertFalse($decision->byHeaderUnit);
        self::assertSame(3, $decision->rows);
        self::assertSame(3, $decision->checkedRows);
        self::assertSame([], $resolution->questions());

        $applied = $resolution->apply($cells[0]->read);
        self::assertSame(DurationCertainty::Certain, $applied->certainty);
        self::assertSame(3 * 3600 + 30 * 60, $applied->seconds);
        self::assertSame(4157, $resolution->apply($cells[3]->read)->seconds, 'A value with one meaning stays as it is');
    }

    public function testMinutesAndSecondsAreSettledWhenHoursWouldBeSlowerThanAnybody(): void
    {
        // 100 pieces in 40-55 minutes of a child's first puzzles: 2 a minute; as hours 0.03 a minute - two days for 100 pieces
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['45:10', 100],
            ['52:30', 100],
            ['40:05', 100],
        ]))->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::MinutesSeconds, $decision->rule);
    }

    public function testMixedSizesSettleWhenEveryRowAgrees(): void
    {
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['1:40', 500],
            ['3:30', 1000],
            ['7:15', 2000],
            ['11:00', 3000],
        ]))->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::HoursMinutes, $decision->rule);
    }

    public function testMixedSizesWithOneRowThatSpeaksForTheOtherReadingAreAQuestion(): void
    {
        // Three large puzzles in hours:minutes and a 100-piece one in minutes:seconds - no single rule reads the column
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['3:30', 1000],
            ['7:15', 2000],
            ['11:00', 3000],
            ['45:10', 100],
        ]))->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertNull($decision->rule, 'The winning pace must be ordinary for every row');
        self::assertSame(DurationReadingRule::HoursMinutes, $decision->suggested);
    }

    public function testOneRowWithAPaceThatIsNotOrdinaryIsEnoughToAsk(): void
    {
        $rows = [];

        for ($i = 0; $i < 19; $i++) {
            $rows[] = [sprintf('%d:%02d', 2 + $i % 3, $i), 1000];
        }

        // A typo: 1,000 pieces in 0:20
        $rows[] = ['0:20', 1000];

        $decision = (new DurationColumnResolver())->resolve(self::column($rows))->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
        self::assertSame(DurationReadingRule::HoursMinutes, $decision->suggested);
    }

    public function testTooFewRowsWithAPieceCountDecideNothing(): void
    {
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['3:30', 1000],
            ['2:45', 1000],
            ['4:10', null],
            ['5:00', null],
        ]))->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
        self::assertSame(2, $decision->checkedRows);
        self::assertSame(4, $decision->rows);
        self::assertSame(DurationReadingRule::HoursMinutes, $decision->suggested);
        self::assertSame([0, 1, 2, 3], $decision->exampleRows);
    }

    public function testAFewRowsWithAPieceCountDoNotSpeakForALongColumn(): void
    {
        // Three rows with pieces among twenty: 15 % of the shape's rows
        $rows = [['3:30', 1000], ['2:45', 1000], ['4:10', 1000]];

        for ($i = 0; $i < 17; $i++) {
            $rows[] = [sprintf('%d:%02d', 20 + $i, $i), null];
        }

        $decision = (new DurationColumnResolver())->resolve(self::column($rows))->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
        self::assertSame(3, $decision->checkedRows);
        self::assertSame(20, $decision->rows);

        // The same three among ten are 30 %
        $decided = (new DurationColumnResolver())->resolve(self::column(array_slice($rows, 0, 10)))->decisionFor(DurationShape::TwoPartText);
        self::assertSame(DurationReadingRule::HoursMinutes, $decided?->rule);
    }

    public function testWithoutAnyPieceCountEveryShapeIsAQuestion(): void
    {
        $resolution = (new DurationColumnResolver())->resolve(self::column([
            ['17:36', null],
            ['25:10', null],
            ['31:05', null],
            ['237', null],
        ]));

        self::assertCount(2, $resolution->questions());
        self::assertNull($resolution->decisionFor(DurationShape::TwoPartText)?->rule);
        self::assertNull($resolution->decisionFor(DurationShape::TwoPartText)?->suggested);
        self::assertNull($resolution->decisionFor(DurationShape::BareNumber)?->rule);
    }

    public function testBareNumbersOfMinutes(): void
    {
        // 500 pieces in 3-4 hours written as minutes: as seconds 125+ a minute, as hours a week and more
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['180', 500],
            ['240', 500],
            ['200', 500],
        ]))->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::Minutes, $decision->rule);
        self::assertSame([DurationReadingRule::MinutesDotSeconds], $decision->options[0]->sameAs, 'For whole numbers "minutes.seconds" reads the same');
    }

    public function testBareNumbersThatCouldBeSecondsOfASpeedRunAreAQuestion(): void
    {
        // 100 pieces in 237-355: as seconds 17-25 a minute (a speed run), as minutes 0.3-0.4 a minute (a child)
        $decision = (new DurationColumnResolver())->resolve(self::column([
            [CellInput::number(237), 100],
            [CellInput::number(301), 100],
            [CellInput::number(288), 100],
            [CellInput::number(355), 100],
        ]))->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
        self::assertSame(DurationReadingRule::Minutes, $decision->suggested);
    }

    public function testBareNumbersOfHours(): void
    {
        // 3,000 pieces in 10-14: as minutes 214-300 a minute, as seconds thousands
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['12', 3000],
            ['10', 3000],
            ['14', 3000],
        ]))->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::Hours, $decision->rule);
        self::assertSame([DurationReadingRule::HoursDotMinutes], self::option($decision->options, DurationReadingRule::Hours)->sameAs);
    }

    public function testTheHoursDotMinutesHabitCannotBeToldFromDecimalHours(): void
    {
        // The public template's "8.10" for 8 h 10 min: 8.1 hours is six minutes away - both are ordinary for every row
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['8.10', 2000],
            ['3.45', 1000],
            ['5.20', 1500],
            ['2.05', 500],
        ]))->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
        self::assertSame(
            [DurationReadingRule::Minutes, DurationReadingRule::Seconds, DurationReadingRule::Hours, DurationReadingRule::HoursDotMinutes, DurationReadingRule::MinutesDotSeconds],
            array_map(static fn (DurationShapeOption $option): DurationReadingRule => $option->rule, $decision->options),
        );
    }

    public function testADecimalThatCannotBeMinutesRulesTheHabitOut(): void
    {
        // 2.75 is no "hours.minutes": of the readings that read every value only decimal hours are ordinary, and
        // decimal minutes and seconds are far faster than anybody
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['8.10', 3000],
            ['7.75', 3000],
            ['9.20', 3000],
            ['6.05', 2000],
        ]))->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::Hours, $decision->rule);
    }

    public function testAUnitInTheHeaderAnswersTheBareNumbersWhateverThePaceSays(): void
    {
        // "Time (min)" over 40, 35, 52 for 1,000 pieces: 25 pieces a minute is what the header says - no question,
        // and no "ordinary pace" second-guessing either
        $cells = self::column([
            ['40', 1000],
            ['35', 1000],
            ['52.5', 1000],
        ]);

        $resolution = (new DurationColumnResolver())->resolve($cells, DurationUnit::Minutes);
        $decision = $resolution->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::Minutes, $decision->rule);
        self::assertTrue($decision->byHeaderUnit);
        self::assertSame(2400, $resolution->apply($cells[0]->read)->seconds);
        self::assertSame(3150, $resolution->apply($cells[2]->read)->seconds);

        $hours = (new DurationColumnResolver())->resolve($cells, DurationUnit::Hours);
        self::assertSame(DurationReadingRule::Hours, $hours->decisionFor(DurationShape::BareNumber)?->rule);
        self::assertSame(144000, $hours->apply($cells[0]->read)->seconds);

        $seconds = (new DurationColumnResolver())->resolve($cells, DurationUnit::Seconds);
        self::assertSame(40, $seconds->apply($cells[0]->read)->seconds);
        self::assertSame(53, $seconds->apply($cells[2]->read)->seconds, 'Decimal seconds are rounded');
    }

    public function testAUnitInTheHeaderIsAboutBareNumbersOnly(): void
    {
        $resolution = (new DurationColumnResolver())->resolve(self::column([
            ['17:36', null],
            ['1:09:17', null],
        ]), DurationUnit::Minutes);

        self::assertNull($resolution->decisionFor(DurationShape::TwoPartText)?->rule);
        self::assertFalse((bool) $resolution->decisionFor(DurationShape::TwoPartText)?->byHeaderUnit);
    }

    public function testTimeCellsWithoutSecondsFollowThePaceWhenItLeavesNoRoom(): void
    {
        $resolver = new DurationColumnResolver();

        // "3:30" typed for 3 h 30 min of 1,000 pieces - as minutes:seconds 286 a minute
        $hours = $resolver->resolve(self::column([
            [CellInput::timeOfDay(3 * 3600 + 30 * 60), 1000],
            [CellInput::timeOfDay(2 * 3600 + 45 * 60), 1000],
            [CellInput::timeOfDay(4 * 3600 + 10 * 60), 1000],
        ]))->decisionFor(DurationShape::TimeOfDayCell);

        self::assertSame(DurationReadingRule::AsStored, $hours?->rule);

        // "17:36" typed for 17 min 36 s of 500 pieces is stored as 17 h 36 min - but 28 pieces a minute is never assumed
        $fast = $resolver->resolve(self::column([
            [CellInput::timeOfDay(17 * 3600 + 36 * 60), 500],
            [CellInput::timeOfDay(21 * 3600 + 5 * 60), 500],
            [CellInput::timeOfDay(19 * 3600 + 40 * 60), 500],
        ]))->decisionFor(DurationShape::TimeOfDayCell);

        self::assertNotNull($fast);
        self::assertNull($fast->rule);
    }

    public function testAColumnWhoseTimesAllEndInZeroSecondsIsAskedNeverSilentlyHours(): void
    {
        $resolver = new DurationColumnResolver();

        // An [h]:mm column without piece counts
        $durationCells = $resolver->resolve(self::column([
            [CellInput::duration(3600 + 120), null],
            [CellInput::duration(2 * 3600 + 600), null],
            [CellInput::duration(5 * 3600), null],
        ]))->decisionFor(DurationShape::ZeroSecondsDurationCell);

        self::assertNotNull($durationCells);
        self::assertNull($durationCells->rule);

        // The same as h:mm time cells, and as the text of a CSV
        $timeCells = $resolver->resolve(self::column([
            [CellInput::timeOfDay(3600 + 120), null],
            [CellInput::timeOfDay(2 * 3600 + 600), null],
        ]))->decisionFor(DurationShape::TimeOfDayCell);

        self::assertNull($timeCells?->rule);

        $texts = $resolver->resolve(self::column([
            ['1:02:00', null],
            ['2:10:00', null],
            ['5:00:00', null],
        ]))->decisionFor(DurationShape::ZeroSecondsDurationCell);

        self::assertNotNull($texts);
        self::assertNull($texts->rule);

        // And with piece counts that fit both ways (300 pieces in 8 minutes or 8 hours)
        $bothFit = $resolver->resolve(self::column([
            [CellInput::duration(8 * 3600 + 10 * 60), 300],
            [CellInput::duration(8 * 3600 + 45 * 60), 300],
            [CellInput::duration(9 * 3600 + 2 * 60), 300],
        ]))->decisionFor(DurationShape::ZeroSecondsDurationCell);

        self::assertNull($bothFit?->rule);
    }

    public function testATimeWithoutSecondsStandsInAColumnOfRealTimesWithSeconds(): void
    {
        $resolver = new DurationColumnResolver();

        foreach ([DurationShape::ZeroSecondsDurationCell, DurationShape::TimeOfDayCell] as $shape) {
            $rows = [[$shape === DurationShape::TimeOfDayCell ? CellInput::timeOfDay(3600) : CellInput::duration(3600), null]];

            for ($i = 1; $i <= 30; $i++) {
                $rows[] = [CellInput::duration(3000 + $i * 61), null];
            }

            $cells = self::column($rows);
            $resolution = $resolver->resolve($cells);
            $decision = $resolution->decisionFor($shape);

            self::assertNotNull($decision, $shape->value);
            self::assertSame(DurationReadingRule::AsStored, $decision->rule, $shape->value);
            self::assertSame(3600, $resolution->apply($cells[0]->read)->seconds);
            self::assertSame(DurationCertainty::Certain, $resolution->apply($cells[0]->read)->certainty);
        }
    }

    public function testTheSameForTheTextOfACsv(): void
    {
        $cells = self::column([
            ['1:00:00', null],
            ['0:55:41', null],
            ['1:09:17', null],
            ['0:48:03', null],
        ]);

        $resolution = (new DurationColumnResolver())->resolve($cells);

        self::assertSame(DurationReadingRule::AsStored, $resolution->decisionFor(DurationShape::ZeroSecondsDurationCell)?->rule);
        self::assertSame(3600, $resolution->apply($cells[0]->read)->seconds);
    }

    public function testManyTimesWithoutSecondsInAColumnWithSecondsAreAQuestion(): void
    {
        // The README: "a duration with :00 seconds in a column that has seconds" - two of five are no chance
        $decision = (new DurationColumnResolver())->resolve(self::column([
            [CellInput::duration(17 * 3600 + 36 * 60), null],
            [CellInput::duration(21 * 3600 + 5 * 60), null],
            [CellInput::duration(3341), null],
            [CellInput::duration(4157), null],
            [CellInput::duration(2989), null],
        ]))->decisionFor(DurationShape::ZeroSecondsDurationCell);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
    }

    public function testATimeWithoutSecondsDoesNotStandAgainstItsPace(): void
    {
        // One :00 time among real ones - but 17 h 36 min for 100 pieces is no pace
        $rows = [[CellInput::duration(17 * 3600 + 36 * 60), 100]];

        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [CellInput::duration(3000 + $i * 61), 100];
        }

        $decision = (new DurationColumnResolver())->resolve(self::column($rows))->decisionFor(DurationShape::ZeroSecondsDurationCell);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
    }

    public function testATimeWithoutSecondsNextToADurationOfADayAndMoreWithoutSecondsIsAQuestion(): void
    {
        // "45:30" typed for minutes and seconds is the same habit as "17:36" typed so
        $rows = [
            [CellInput::duration(17 * 3600 + 36 * 60), null],
            [CellInput::duration(45 * 3600 + 30 * 60), null],
        ];

        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [CellInput::duration(3000 + $i * 61), null];
        }

        $resolution = (new DurationColumnResolver())->resolve(self::column($rows));

        self::assertNull($resolution->decisionFor(DurationShape::ZeroSecondsDurationCell)?->rule);
        self::assertNull($resolution->decisionFor(DurationShape::LongDurationCell)?->rule);
        self::assertCount(2, $resolution->questions());
    }

    public function testADurationOfFortyFiveHoursOnASmallPuzzleIsAskedWithMinutesOfferedFirst(): void
    {
        // The README's example: "45:30" typed, stored as 45 h 30 min. 500 pieces in 45 minutes are 11 a minute, in 45
        // hours 0.18 - both can be, so the player says which
        $cells = self::column([
            [CellInput::duration(45 * 3600 + 30 * 60), 500],
            [CellInput::duration(52 * 3600 + 10 * 60), 500],
            [CellInput::duration(38 * 3600 + 45 * 60), 500],
            [CellInput::duration(3341), 500],
        ]);

        $resolution = (new DurationColumnResolver())->resolve($cells);
        $decision = $resolution->decisionFor(DurationShape::LongDurationCell);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
        self::assertSame(DurationReadingRule::StoredHoursAsMinutes, $decision->suggested);
        self::assertSame(45 * 60 + 30, $cells[0]->read->settledBy(DurationReadingRule::StoredHoursAsMinutes)->seconds);
    }

    public function testADurationOfADayAndMoreWithSecondsStandsUnlessItsPaceSaysOtherwise(): void
    {
        $resolver = new DurationColumnResolver();

        // Nothing else it could be, and nothing speaks against it
        $noPieces = $resolver->resolve(self::column([
            [CellInput::duration(30 * 3600 + 15 * 60 + 20), null],
        ]))->decisionFor(DurationShape::LongDurationCell);

        self::assertSame(DurationReadingRule::AsStored, $noPieces?->rule);

        // 30 h for 9,000 pieces: 5 a minute
        $fits = $resolver->resolve(self::column([
            [CellInput::duration(30 * 3600 + 15 * 60 + 20), 9000],
            [CellInput::duration(41 * 3600 + 2 * 60 + 11), 9000],
        ]))->decisionFor(DurationShape::LongDurationCell);

        self::assertSame(DurationReadingRule::AsStored, $fits?->rule);

        // 30 h for 100 pieces: there is no other way to read it, and this one is not believable
        $doesNotFit = $resolver->resolve(self::column([
            [CellInput::duration(30 * 3600 + 15 * 60 + 20), 100],
            [CellInput::duration(41 * 3600 + 2 * 60 + 11), 100],
        ]))->decisionFor(DurationShape::LongDurationCell);

        self::assertNotNull($doesNotFit);
        self::assertNull($doesNotFit->rule);
        self::assertCount(1, $doesNotFit->options);
    }

    public function testADurationOfADayAndMoreWithSecondsDoesNotPutTheColumnsOtherTimesInDoubt(): void
    {
        // A 9,000-piece puzzle in 30:15:20 beside one time that happens to end in :00
        $rows = [
            [CellInput::duration(3600), null],
            [CellInput::duration(30 * 3600 + 15 * 60 + 20), null],
        ];

        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [CellInput::duration(3000 + $i * 61), null];
        }

        $resolution = (new DurationColumnResolver())->resolve(self::column($rows));

        self::assertSame(DurationReadingRule::AsStored, $resolution->decisionFor(DurationShape::ZeroSecondsDurationCell)?->rule);
        self::assertSame(DurationReadingRule::AsStored, $resolution->decisionFor(DurationShape::LongDurationCell)?->rule);
        self::assertSame([], $resolution->questions());
    }

    public function testTwoPartsWithHundredthsAreAQuestionOfTheirOwn(): void
    {
        $resolution = (new DurationColumnResolver())->resolve(self::column([
            ['1:02.03', null],
            ['0:58.40', null],
        ]));

        $decision = $resolution->decisionFor(DurationShape::TwoPartTextWithHundredths);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
        self::assertSame(
            [DurationReadingRule::MinutesSeconds, DurationReadingRule::HoursMinutesDotSeconds],
            array_map(static fn (DurationShapeOption $option): DurationReadingRule => $option->rule, $decision->options),
        );
    }

    public function testEveryShapeOfAColumnGetsItsOwnDecision(): void
    {
        $resolution = (new DurationColumnResolver())->resolve(self::column([
            ['3:30', 1000],
            ['2:45', 1000],
            ['4:10', 1000],
            ['237', 1000],
            ['1.30 h', 1000],
            ['1:02.03', 100],
            [CellInput::timeOfDay(3720), 1000],
            [CellInput::duration(45 * 3600 + 30 * 60), 500],
            ['2:00:00', 1000],
        ]));

        self::assertSame(
            [
                DurationShape::TwoPartText,
                DurationShape::TwoPartTextWithHundredths,
                DurationShape::TimeOfDayCell,
                DurationShape::LongDurationCell,
                DurationShape::ZeroSecondsDurationCell,
                DurationShape::BareNumber,
                DurationShape::DecimalWithUnit,
            ],
            array_map(static fn ($decision): DurationShape => $decision->shape, $resolution->decisions),
        );
        self::assertSame(DurationReadingRule::HoursMinutes, $resolution->decisionFor(DurationShape::TwoPartText)?->rule);
        self::assertNull($resolution->decisionFor(DurationShape::BareNumber)?->rule, 'One row decides nothing');
        self::assertCount(6, $resolution->questions());
    }

    public function testAValueWithLeftoverTextCountsForItsShapeAndStillNeedsAYes(): void
    {
        $cells = self::column([
            ['3:30 for both', 1000],
            ['2:45', 1000],
            ['4:10', 1000],
        ]);

        $resolution = (new DurationColumnResolver())->resolve($cells);

        self::assertSame(DurationReadingRule::HoursMinutes, $resolution->decisionFor(DurationShape::TwoPartText)?->rule);

        $applied = $resolution->apply($cells[0]->read);
        self::assertSame(DurationCertainty::ReadWithLeftovers, $applied->certainty);
        self::assertSame(12600, $applied->seconds);
        self::assertSame('for both', $applied->leftover);
    }

    public function testRowsWithAPieceCountAreShownFirst(): void
    {
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['1:01', null],
            ['1:02', null],
            ['1:03', 500],
            ['1:04', null],
            ['1:05', 500],
            ['1:06', null],
            ['1:07', null],
        ]))->decisionFor(DurationShape::TwoPartText);

        self::assertSame([2, 4, 0, 1, 3], $decision?->exampleRows);
    }

    #[DataProvider('paces')]
    public function testThePaces(int $seconds, int $pieces, bool $plausible, bool $ordinary, bool $farOutside): void
    {
        self::assertSame($plausible, DurationColumnResolver::isPlausiblePace($seconds, $pieces));
        self::assertSame($ordinary, DurationColumnResolver::isOrdinaryPace($seconds, $pieces));
        self::assertSame($farOutside, DurationColumnResolver::isFarOutsidePace($seconds, $pieces));
    }

    /**
     * @return iterable<string, array{int, int, bool, bool, bool}>
     */
    public static function paces(): iterable
    {
        yield '1000 pieces in three hours' => [10800, 1000, true, true, false];
        yield '1000 pieces in an hour: fast, not ordinary' => [3600, 1000, true, false, false];
        yield 'the fastest ordinary: 15 a minute' => [4000, 1000, true, true, false];
        yield 'just faster than ordinary' => [3999, 1000, true, false, false];
        yield 'the fastest a person does: 60 a minute' => [500, 500, true, false, false];
        yield 'faster than a person, not yet far outside' => [499, 500, false, false, false];
        yield 'what the add form refuses: 100 a minute' => [600, 1000, false, false, false];
        yield 'far too fast' => [599, 1000, false, false, true];
        yield 'the slowest: one piece in five minutes' => [300_000, 1000, true, true, false];
        yield 'slower than that, not yet far outside' => [300_001, 1000, false, false, false];
        yield 'one piece in twenty minutes' => [1_200_000, 1000, false, false, false];
        yield 'far too slow' => [1_200_001, 1000, false, false, true];
        yield 'no time' => [0, 500, false, false, true];
        yield 'no pieces' => [3600, 0, false, false, true];
        yield 'a negative time' => [-5, 500, false, false, true];
    }

    public function testTheLimitsAreInsideTheAddFormsOwn(): void
    {
        // The add form refuses 100 pieces a minute and more (AddPuzzleSolvingTimeHandler)
        self::assertLessThanOrEqual(100.0, DurationColumnResolver::FAR_TOO_FAST_PIECES_PER_MINUTE);
        // The order of the other limits is pinned by paces(): 15 and 60 a minute, one piece in five and in twenty minutes
    }

    /**
     * @param list<DurationShapeOption> $options
     */
    private static function option(array $options, DurationReadingRule $rule): DurationShapeOption
    {
        foreach ($options as $option) {
            if ($option->rule === $rule) {
                return $option;
            }
        }

        self::fail('No option ' . $rule->value);
    }

    /**
     * @param list<array{string|CellInput, null|int}> $rows A cell (a text when a string) and the row's piece count
     * @return list<DurationColumnCell>
     */
    private static function column(array $rows): array
    {
        $reader = new DurationReader();

        return array_map(
            static fn (array $row): DurationColumnCell => new DurationColumnCell(
                $reader->read(is_string($row[0]) ? CellInput::text($row[0]) : $row[0]),
                $row[1],
            ),
            $rows,
        );
    }
}
