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

/**
 * One answer per column and shape, helped by the piece count - and a question whenever the rows do not settle it
 * (docs/features/data-import/value-readers.md "The column decides").
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

    public function testTwoPartTimesOfSmallPuzzlesAreMinutesAndSeconds(): void
    {
        // 500 pieces in 17-45 minutes; as hours they would be 0.2-0.5 pieces a minute
        $cells = self::column([
            ['17:36', 500],
            ['25:10', 500],
            ['44:59', 500],
            ['1:09:17', 1000],
            ['31:05', 500],
            ['19:48', 300],
        ]);

        $resolution = (new DurationColumnResolver())->resolve($cells);
        $decision = $resolution->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertTrue($decision->isResolved());
        self::assertSame(DurationReadingRule::MinutesSeconds, $decision->rule);
        self::assertNull($decision->suggested);
        self::assertSame(5, $decision->rows);
        self::assertSame(5, $decision->checkedRows);
        self::assertSame([], $resolution->questions());

        $applied = $resolution->apply($cells[0]->read);
        self::assertSame(DurationCertainty::Certain, $applied->certainty);
        self::assertSame(17 * 60 + 36, $applied->seconds);

        self::assertSame(4157, $resolution->apply($cells[3]->read)->seconds, 'A value with one meaning stays as it is');
    }

    public function testTwoPartTimesOfLargePuzzlesAreHoursAndMinutes(): void
    {
        // 2000-6000 pieces in 8-35 hours; as minutes they would be 100-250 pieces a minute
        $resolution = (new DurationColumnResolver())->resolve(self::column([
            ['17:36', 3000],
            ['8:05', 2000],
            ['34:50', 6000],
            ['12:20', 2000],
        ]));

        $decision = $resolution->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::HoursMinutes, $decision->rule);
    }

    public function testTwoPartTimesThatFitBothWaysAreAQuestion(): void
    {
        // 300 pieces in 8-9 minutes (34-37 a minute) or in 8-9 hours (0.6 a minute): nobody can tell
        $resolution = (new DurationColumnResolver())->resolve(self::column([
            ['8:10', 300],
            ['8:45', 300],
            ['9:02', 300],
        ]));

        $decision = $resolution->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertFalse($decision->isResolved());
        self::assertNull($decision->rule);
        self::assertNull($decision->suggested, 'Both fit every row - nothing to offer first');
        self::assertSame([$decision], $resolution->questions());
        self::assertSame([0, 1, 2], $decision->exampleRows);

        self::assertCount(2, $decision->options);
        self::assertSame(DurationReadingRule::MinutesSeconds, $decision->options[0]->rule);
        self::assertSame(3, $decision->options[0]->plausibleRows);
        self::assertSame(DurationReadingRule::HoursMinutes, $decision->options[1]->rule);
        self::assertSame(3, $decision->options[1]->plausibleRows);
    }

    public function testAColumnThatMixesBothReadingsIsAQuestion(): void
    {
        // Small puzzles in minutes:seconds and a huge one in hours:minutes - no single rule reads the column
        $resolution = (new DurationColumnResolver())->resolve(self::column([
            ['17:36', 500],
            ['25:10', 500],
            ['31:05', 500],
            ['44:59', 500],
            ['19:20', 500],
            ['17:36', 6000],
        ]));

        $decision = $resolution->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertNull($decision->rule, 'A row only the other reading explains keeps the question open');
        self::assertSame(DurationReadingRule::MinutesSeconds, $decision->suggested);
    }

    public function testTooFewRowsWithAPieceCountDecideNothing(): void
    {
        $resolution = (new DurationColumnResolver())->resolve(self::column([
            ['17:36', 500],
            ['25:10', 500],
            ['31:05', null],
            ['44:59', null],
        ]));

        $decision = $resolution->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
        self::assertSame(2, $decision->checkedRows);
        self::assertSame(4, $decision->rows);
        self::assertSame(DurationReadingRule::MinutesSeconds, $decision->suggested);
        self::assertSame([0, 1, 2, 3], $decision->exampleRows);
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

    public function testOneRowInTwentyMayBeOutOfRange(): void
    {
        $rows = [];

        for ($i = 0; $i < 19; $i++) {
            $rows[] = [sprintf('%d:%02d', 20 + $i, $i), 500];
        }

        // A typo that fits neither reading: 400 pieces in a thousand minutes, or a thousand hours
        $rows[] = ['999:59', 400];

        $decision = (new DurationColumnResolver())->resolve(self::column($rows))->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::MinutesSeconds, $decision->rule);
        self::assertSame(19, $decision->options[0]->plausibleRows);
        self::assertSame(1, $decision->options[0]->implausibleRows);
    }

    public function testTwoRowsInTwentyOutOfRangeAreAQuestion(): void
    {
        $rows = [];

        for ($i = 0; $i < 18; $i++) {
            $rows[] = [sprintf('%d:%02d', 20 + $i, $i), 500];
        }

        $rows[] = ['999:59', 400];
        $rows[] = ['998:59', 400];

        $decision = (new DurationColumnResolver())->resolve(self::column($rows))->decisionFor(DurationShape::TwoPartText);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
        self::assertSame(DurationReadingRule::MinutesSeconds, $decision->suggested);
    }

    public function testBareNumbersOfMinutes(): void
    {
        // 1000 pieces in 1.5-4 hours written as minutes
        $resolution = (new DurationColumnResolver())->resolve(self::column([
            ['237', 1000],
            ['95', 1000],
            ['180', 1000],
            ['62', 500],
        ]));

        $decision = $resolution->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::Minutes, $decision->rule);
        self::assertSame([DurationReadingRule::MinutesDotSeconds], $decision->options[0]->sameAs, 'For whole numbers "minutes.seconds" reads the same');
    }

    public function testBareNumbersOfSeconds(): void
    {
        // Speed runs of 100 pieces in 4-6 minutes written as seconds
        $decision = (new DurationColumnResolver())->resolve(self::column([
            [CellInput::number(237), 100],
            [CellInput::number(301), 100],
            [CellInput::number(288), 100],
            [CellInput::number(355), 100],
        ]))->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::Seconds, $decision->rule);
    }

    public function testBareNumbersOfHours(): void
    {
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['3', 1000],
            ['2', 1000],
            ['5', 2000],
            ['4', 1500],
        ]))->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::Hours, $decision->rule);
        self::assertSame([DurationReadingRule::HoursDotMinutes], $decision->options[2]->sameAs);
    }

    public function testTheHoursDotMinutesHabitCannotBeToldFromDecimalHours(): void
    {
        // The public template's "8.10" for 8 h 10 min: 8.1 hours is six minutes away - both fit every row
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['8.10', 2000],
            ['3.45', 1000],
            ['5.20', 1500],
            ['2.05', 500],
        ]))->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertNull($decision->rule);
        self::assertSame(
            [DurationReadingRule::Minutes, DurationReadingRule::Hours, DurationReadingRule::HoursDotMinutes, DurationReadingRule::MinutesDotSeconds],
            array_map(static fn ($option): DurationReadingRule => $option->rule, $decision->options),
        );
    }

    public function testADecimalThatCannotBeMinutesRulesTheHabitOut(): void
    {
        // 2.75 is no "hours.minutes": only decimal hours and decimal minutes read every value, and the pace tells them apart
        $decision = (new DurationColumnResolver())->resolve(self::column([
            ['8.10', 2000],
            ['2.75', 1000],
            ['5.20', 1500],
            ['2.05', 500],
        ]))->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::Hours, $decision->rule);
    }

    public function testWholeAndDecimalNumbersOfOneColumnAreOneQuestion(): void
    {
        $decision = (new DurationColumnResolver())->resolve(self::column([
            [CellInput::number(8), 2000],
            [CellInput::number(2.75), 1000],
            [CellInput::number(5.5), 1500],
            [CellInput::number(3), 1000],
        ]))->decisionFor(DurationShape::BareNumber);

        self::assertNotNull($decision);
        self::assertSame(4, $decision->rows);
        self::assertSame(DurationReadingRule::Hours, $decision->rule, 'Seconds cannot read a decimal, "hours.minutes" cannot read 2.75');
    }

    public function testTimeOfDayCellsWithoutSeconds(): void
    {
        // "1:02" typed for 1 min 2 s of a 100-piece speed run would be stored as 1 h 2 min
        $resolver = new DurationColumnResolver();

        $hours = $resolver->resolve(self::column([
            [CellInput::timeOfDay(3600 + 120), 1000],
            [CellInput::timeOfDay(2 * 3600 + 600), 1000],
            [CellInput::timeOfDay(3600 + 2700), 500],
        ]))->decisionFor(DurationShape::TimeOfDayCell);

        self::assertSame(DurationReadingRule::AsStored, $hours?->rule);

        $minutes = $resolver->resolve(self::column([
            [CellInput::timeOfDay(17 * 3600 + 36 * 60), 500],
            [CellInput::timeOfDay(21 * 3600 + 5 * 60), 500],
            [CellInput::timeOfDay(19 * 3600 + 40 * 60), 500],
        ]))->decisionFor(DurationShape::TimeOfDayCell);

        self::assertSame(DurationReadingRule::StoredHoursAsMinutes, $minutes?->rule);
    }

    public function testADurationCellOfFortyFiveHoursOnASmallPuzzleWasTypedAsMinutes(): void
    {
        // The README's example: "45:30" typed, stored as 45 h 30 min
        $cells = self::column([
            [CellInput::duration(45 * 3600 + 30 * 60), 500],
            [CellInput::duration(52 * 3600 + 10 * 60), 500],
            [CellInput::duration(38 * 3600 + 45 * 60), 500],
            [CellInput::duration(3341), 500],
        ]);

        $resolution = (new DurationColumnResolver())->resolve($cells);
        $decision = $resolution->decisionFor(DurationShape::LongDurationCell);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::StoredHoursAsMinutes, $decision->rule);
        self::assertSame(45 * 60 + 30, $resolution->apply($cells[0]->read)->seconds);
    }

    public function testALongDurationCellWithSecondsIsOnlyTakenWhenThePaceFits(): void
    {
        $resolver = new DurationColumnResolver();

        // 30 h for 9000 pieces: 5 a minute
        $fits = $resolver->resolve(self::column([
            [CellInput::duration(30 * 3600 + 15 * 60 + 20), 9000],
            [CellInput::duration(41 * 3600 + 2 * 60 + 11), 9000],
            [CellInput::duration(26 * 3600 + 40 * 60 + 5), 6000],
        ]))->decisionFor(DurationShape::LongDurationCell);

        self::assertSame(DurationReadingRule::AsStored, $fits?->rule);

        // 30 h for 500 pieces: there is no other way to read it, and this one is not believable
        $doesNotFit = $resolver->resolve(self::column([
            [CellInput::duration(30 * 3600 + 15 * 60 + 20), 500],
            [CellInput::duration(41 * 3600 + 2 * 60 + 11), 500],
            [CellInput::duration(26 * 3600 + 40 * 60 + 5), 500],
        ]))->decisionFor(DurationShape::LongDurationCell);

        self::assertNotNull($doesNotFit);
        self::assertNull($doesNotFit->rule);
        self::assertNull($doesNotFit->suggested);
        self::assertCount(1, $doesNotFit->options);
    }

    public function testADurationCellWithoutSecondsStandsInAColumnWithoutSeconds(): void
    {
        // Somebody who tracks hours and minutes only - no pieces known, nothing to doubt
        $decision = (new DurationColumnResolver())->resolve(self::column([
            [CellInput::duration(3600 + 120), null],
            [CellInput::duration(2 * 3600 + 600), null],
            [CellInput::duration(5 * 3600), null],
        ]))->decisionFor(DurationShape::ZeroSecondsDurationCell);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::AsStored, $decision->rule);
    }

    public function testOneDurationCellWithoutSecondsAmongManyWithSecondsIsChance(): void
    {
        $rows = [[CellInput::duration(3600), null]];

        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [CellInput::duration(3000 + $i * 61), null];
        }

        $decision = (new DurationColumnResolver())->resolve(self::column($rows))->decisionFor(DurationShape::ZeroSecondsDurationCell);

        self::assertNotNull($decision);
        self::assertSame(DurationReadingRule::AsStored, $decision->rule);
    }

    public function testManyDurationCellsWithoutSecondsInAColumnWithSecondsAreAQuestion(): void
    {
        // The README: "a duration with :00 seconds in a column that has seconds"
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

    public function testADurationCellWithoutSecondsNextToOneOfADayAndMoreIsAQuestion(): void
    {
        $resolution = (new DurationColumnResolver())->resolve(self::column([
            [CellInput::duration(17 * 3600 + 36 * 60), null],
            [CellInput::duration(45 * 3600 + 30 * 60), null],
        ]));

        self::assertNull($resolution->decisionFor(DurationShape::ZeroSecondsDurationCell)?->rule);
        self::assertNull($resolution->decisionFor(DurationShape::LongDurationCell)?->rule);
        self::assertCount(2, $resolution->questions());
    }

    public function testDurationCellsWithoutSecondsFollowThePaceWhenItIsKnown(): void
    {
        $resolver = new DurationColumnResolver();

        // 17 h 36 min for 500 pieces is no pace; 17 min 36 s is
        $typedAsMinutes = $resolver->resolve(self::column([
            [CellInput::duration(17 * 3600 + 36 * 60), 500],
            [CellInput::duration(21 * 3600 + 5 * 60), 500],
            [CellInput::duration(19 * 3600 + 40 * 60), 500],
        ]))->decisionFor(DurationShape::ZeroSecondsDurationCell);

        self::assertSame(DurationReadingRule::StoredHoursAsMinutes, $typedAsMinutes?->rule);

        // Both fit (300 pieces in 8 minutes or 8 hours) and nothing else gives a reason to doubt the cell
        $stands = $resolver->resolve(self::column([
            [CellInput::duration(8 * 3600 + 10 * 60), 300],
            [CellInput::duration(8 * 3600 + 45 * 60), 300],
            [CellInput::duration(9 * 3600 + 2 * 60), 300],
        ]))->decisionFor(DurationShape::ZeroSecondsDurationCell);

        self::assertSame(DurationReadingRule::AsStored, $stands?->rule);
    }

    public function testEveryShapeOfAColumnGetsItsOwnDecision(): void
    {
        $resolution = (new DurationColumnResolver())->resolve(self::column([
            ['17:36', 500],
            ['25:10', 500],
            ['31:05', 500],
            ['237', 1000],
            ['1.30 h', 1000],
            [CellInput::timeOfDay(3720), 1000],
            [CellInput::duration(45 * 3600 + 30 * 60), 500],
        ]));

        self::assertSame(
            [DurationShape::TwoPartText, DurationShape::TimeOfDayCell, DurationShape::LongDurationCell, DurationShape::BareNumber, DurationShape::DecimalWithUnit],
            array_map(static fn ($decision): DurationShape => $decision->shape, $resolution->decisions),
        );
        self::assertSame(DurationReadingRule::MinutesSeconds, $resolution->decisionFor(DurationShape::TwoPartText)?->rule);
        self::assertNull($resolution->decisionFor(DurationShape::BareNumber)?->rule, 'One row decides nothing');
        self::assertNull($resolution->decisionFor(DurationShape::ZeroSecondsDurationCell));
    }

    public function testAValueWithLeftoverTextCountsForItsShapeAndStillNeedsAYes(): void
    {
        $cells = self::column([
            ['17:36 for both', 500],
            ['25:10', 500],
            ['31:05', 500],
        ]);

        $resolution = (new DurationColumnResolver())->resolve($cells);

        self::assertSame(DurationReadingRule::MinutesSeconds, $resolution->decisionFor(DurationShape::TwoPartText)?->rule);

        $applied = $resolution->apply($cells[0]->read);
        self::assertSame(DurationCertainty::ReadWithLeftovers, $applied->certainty);
        self::assertSame(1056, $applied->seconds);
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
    public function testThePaceAPersonCanPuzzleAt(int $seconds, int $pieces, bool $plausible): void
    {
        self::assertSame($plausible, DurationColumnResolver::isPlausiblePace($seconds, $pieces));
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function paces(): iterable
    {
        yield '1000 pieces in an hour' => [3600, 1000, true];
        yield '500 pieces in half an hour' => [1800, 500, true];
        yield 'the fastest: 60 a minute' => [500, 500, true];
        yield 'faster than anybody' => [499, 500, false];
        yield 'the slowest: one in two minutes' => [120_000, 1000, true];
        yield 'slower than that' => [120_001, 1000, false];
        yield '500 pieces in 17 seconds' => [17, 500, false];
        yield 'no time' => [0, 500, false];
        yield 'no pieces' => [3600, 0, false];
        yield 'a negative time' => [-5, 500, false];
    }

    public function testTheLimitsAreInsideTheAddFormsOwn(): void
    {
        // The add form refuses 100 pieces a minute and more (AddPuzzleSolvingTimeHandler)
        self::assertLessThan(100.0, DurationColumnResolver::FASTEST_PIECES_PER_MINUTE);
        self::assertGreaterThan(0.0, DurationColumnResolver::SLOWEST_PIECES_PER_MINUTE);
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
