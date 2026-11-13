<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventResultsImport;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\EventResultsImport\SarahDoesPuzzlesTabParser;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblemCode;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * One tab of an organizer's results sheet, as Google's CSV export gives it (docs/features/event-results-import/README.md
 * "Format"). Made-up events and teams - the real sheets never go into this public repo.
 */
final class SarahDoesPuzzlesTabParserTest extends TestCase
{
    private const string HEADER = 'Name,Time,Team Size,Pieces placed,Contest Name,Location,City and State,Brand,Puzzle Title,Date,Start time,Time limit,Competition Type,Link to MySpeedPuzzling,Puzzle Link- manufacturer';
    private const string PUZZLE_URL = 'https://myspeedpuzzling.com/en/puzzle/018d0003-0000-0000-0000-000000000001';

    public function testATeamsTabBecomesOneEventWithItsTeams(): void
    {
        $tab = $this->parse(
            self::HEADER,
            'Puzzle Pals,1:10:13,4,,Maple Street Puzzle Night - Teams 10/5/26,Maple Street Taproom,"Springfield, MN",Some Brand,Some Puzzle,10/5/26,7:00 PM,120,Teams,' . self::PUZZLE_URL . ',https://example.com/puzzle',
            '"Edge Lords, Inc",1:12:04,3,,,,,,,,,,,,',
        );

        self::assertSame([], $tab->problems);
        self::assertTrue($tab->isImportable());
        self::assertSame('Maple Street Puzzle Night - Teams 10/5/26', $tab->eventName);
        self::assertSame('Maple Street Taproom', $tab->location);
        self::assertSame('Springfield, MN', $tab->cityAndState);
        self::assertSame('2026-10-05 19:00', $tab->localStartsAt?->format('Y-m-d H:i'));
        self::assertSame(120, $tab->timeLimitMinutes);
        self::assertSame(RoundCategory::Team, $tab->category);
        self::assertSame('018d0003-0000-0000-0000-000000000001', $tab->puzzleId);

        self::assertCount(2, $tab->rows);
        self::assertSame('Puzzle Pals', $tab->rows[0]->teamName);
        self::assertSame(4210 + 3, $tab->rows[0]->seconds);
        self::assertSame(4, $tab->rows[0]->teamSize);
        self::assertSame('Edge Lords, Inc', $tab->rows[1]->teamName);
        self::assertSame(3, $tab->rows[1]->teamSize);
    }

    public function testAPairsTabIsADuoRound(): void
    {
        $tab = $this->parse(
            self::HEADER,
            'Two of Us,24:48,2,,Corner Cafe Pairs,Corner Cafe,"Springfield, MN",,,2026-10-07,19:00,90,Pairs,' . self::PUZZLE_URL . ',',
        );

        self::assertSame([], $tab->problems);
        self::assertSame(RoundCategory::Duo, $tab->category);
        self::assertSame(24 * 60 + 48, $tab->rows[0]->seconds);
        self::assertSame('2026-10-07 19:00', $tab->localStartsAt?->format('Y-m-d H:i'));
    }

    public function testHeadersAreMatchedWhateverTheirCaseAndSpacing(): void
    {
        $header = str_replace(['Team Size', 'Time limit'], ['team Size', ' TIME LIMIT '], self::HEADER);
        $tab = $this->parse($header, $this->eventRow('Puzzle Pals', '1:00:00', '4'));

        self::assertSame([], $tab->problems);
        self::assertSame(120, $tab->timeLimitMinutes);
    }

    public function testTeamNamesAreTrimmedAndMatchedWithoutCaseOrAccents(): void
    {
        $tab = $this->parse(self::HEADER, $this->eventRow('  Pièce   de Résistance ', '1:00:00', '4'));

        self::assertSame('Pièce de Résistance', $tab->rows[0]->teamName);
        self::assertSame($tab->rows[0]->teamKey, $this->parse(self::HEADER, $this->eventRow('piece de resistance', '1:00:00', '4'))->rows[0]->teamKey);
    }

    public function testTheOrderOfRowsDoesNotMatter(): void
    {
        $tab = $this->parse(self::HEADER, $this->eventRow('Slower', '1:39:55', '4'), 'Faster,1:39:43,4,,,,,,,,,,,,');

        self::assertSame([], $tab->problems);
        self::assertCount(2, $tab->rows);
    }

    public function testEmptyRowsAreIgnored(): void
    {
        $tab = $this->parse(self::HEADER, $this->eventRow('Puzzle Pals', '1:00:00', '4'), ',,,,,,,,,,,,,,', '');

        self::assertSame([], $tab->problems);
        self::assertCount(1, $tab->rows);
    }

    public function testWithoutAPuzzleLinkTheTabWaitsForThePuzzle(): void
    {
        $tab = $this->parse(self::HEADER, str_replace(self::PUZZLE_URL, '', $this->eventRow('Puzzle Pals', '1:00:00', '4')));

        self::assertNull($tab->puzzleId);
        self::assertSame([], $tab->problems);
        self::assertFalse($tab->isImportable());
        self::assertTrue($tab->waitsForPuzzle());
    }

    public function testALinkThatIsNotAMySpeedPuzzlingPuzzleIsAProblem(): void
    {
        $tab = $this->parse(self::HEADER, str_replace(self::PUZZLE_URL, 'https://example.com/puzzle/123', $this->eventRow('Puzzle Pals', '1:00:00', '4')));

        self::assertSame([EventTabProblemCode::UnreadablePuzzleLink], $this->codes($tab));
        self::assertFalse($tab->isImportable());
    }

    public function testAMissingColumnIsAProblemOfTheWholeTab(): void
    {
        $header = str_replace(',Date', '', self::HEADER);
        $row = $this->eventRow('Puzzle Pals', '1:00:00', '4');
        $row = preg_replace('/,10\/5\/26/', '', $row, 1);
        self::assertIsString($row);

        $tab = $this->parse($header, $row);

        self::assertSame([EventTabProblemCode::MissingColumn], $this->codes($tab));
        self::assertSame('Date', $tab->problems[0]->detail);
        self::assertFalse($tab->isImportable());
    }

    public function testWithoutStartTimeAndTimeLimitColumnsOnlyTheDayIsKnown(): void
    {
        // Sarah's sheet as it is: neither column
        $header = str_replace(',Start time,Time limit', '', self::HEADER);
        $row = str_replace(',7:00 PM,120', '', $this->eventRow('Puzzle Pals', '1:00:00', '4'));

        $tab = $this->parse($header, $row);

        self::assertSame([], $tab->problems);
        self::assertTrue($tab->isImportable());
        self::assertFalse($tab->startTimeKnown);
        // Noon of the day - dates the round on its own day in every zone
        self::assertSame('2026-10-05 12:00', $tab->localStartsAt?->format('Y-m-d H:i'));
        self::assertNull($tab->timeLimitMinutes);
    }

    public function testEmptyStartTimeAndTimeLimitCellsAreFine(): void
    {
        $row = str_replace(',7:00 PM,120', ',,', $this->eventRow('Puzzle Pals', '1:00:00', '4'));

        $tab = $this->parse(self::HEADER, $row);

        self::assertSame([], $tab->problems);
        self::assertFalse($tab->startTimeKnown);
        self::assertNull($tab->timeLimitMinutes);

        $withTimes = $this->parse(self::HEADER, $this->eventRow('Puzzle Pals', '1:00:00', '4'));
        self::assertTrue($withTimes->startTimeKnown);
        self::assertNotSame($withTimes->contentHash, $tab->contentHash, 'Adding a start time later is applied');
    }

    public function testAnEmptyTabIsAProblem(): void
    {
        self::assertSame([EventTabProblemCode::NoTeams], $this->codes($this->parse(self::HEADER)));
        $emptyFile = $this->parse('');
        self::assertCount(9, $emptyFile->problems, 'Every required column is named');
        self::assertSame([EventTabProblemCode::MissingColumn->value], array_values(array_unique(array_map(static fn($code) => $code->value, $this->codes($emptyFile)))));
    }

    /**
     * @return iterable<string, array{string, string, EventTabProblemCode}>
     */
    public static function unreadableEventFields(): iterable
    {
        yield 'date' => ['10/5/26', 'someday', EventTabProblemCode::UnreadableDate];
        yield 'impossible date' => ['10/5/26', '2/30/26', EventTabProblemCode::UnreadableDate];
        yield 'start time' => ['7:00 PM', 'evening', EventTabProblemCode::UnreadableStartTime];
        yield 'time limit' => [',120,', ',two hours,', EventTabProblemCode::UnreadableTimeLimit];
        yield 'competition type' => ['Teams', 'Relay', EventTabProblemCode::UnknownCompetitionType];
        yield 'event name' => ['Maple Street Puzzle Night', '', EventTabProblemCode::MissingEventField];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableEventFields')]
    public function testAnUnreadableEventFieldIsAProblemOfTheWholeTab(string $search, string $replace, EventTabProblemCode $expected): void
    {
        $row = $this->eventRow('Puzzle Pals', '1:00:00', '4');
        $changed = preg_replace('/' . preg_quote($search, '/') . '/', $replace, $row, 1);
        self::assertIsString($changed);

        $tab = $this->parse(self::HEADER, $changed);

        self::assertContains($expected, $this->codes($tab));
        self::assertFalse($tab->isImportable());
    }

    /**
     * @return iterable<string, array{string, string, string, EventTabProblemCode}>
     */
    public static function unusableRows(): iterable
    {
        yield 'unreadable time' => ['Puzzle Pals', 'about an hour', '4', EventTabProblemCode::UnreadableTime];
        yield 'no time, no pieces' => ['Puzzle Pals', '', '4', EventTabProblemCode::RowWithoutResult];
        yield 'no team size' => ['Puzzle Pals', '1:00:00', '', EventTabProblemCode::UnreadableTeamSize];
        yield 'a team of two at a teams event' => ['Puzzle Pals', '1:00:00', '2', EventTabProblemCode::TeamSizeDoesNotFitCategory];
        yield 'an absurd team size' => ['Puzzle Pals', '1:00:00', '40', EventTabProblemCode::UnreadableTeamSize];
        yield 'a name too long for a team' => [str_repeat('Long name ', 6), '1:00:00', '4', EventTabProblemCode::TeamNameTooLong];
        yield 'no name' => ['', '1:00:00', '4', EventTabProblemCode::RowWithoutTeamName];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableRows')]
    public function testAnUnusableRowIsSkippedAndTheRestImported(string $name, string $time, string $size, EventTabProblemCode $expected): void
    {
        $tab = $this->parse(self::HEADER, $this->eventRow('Fine Team', '0:59:00', '4'), sprintf('"%s",%s,%s,,,,,,,,,,,,', $name, $time, $size));

        self::assertSame([$expected], $this->codes($tab));
        self::assertSame(3, $tab->problems[0]->line);
        self::assertTrue($tab->isImportable(), 'A skipped row does not hold back the rest');
        self::assertCount(1, $tab->rows);
        self::assertSame('Fine Team', $tab->rows[0]->teamName);
    }

    public function testATeamNamedTwiceIsSkippedBothTimes(): void
    {
        $tab = $this->parse(
            self::HEADER,
            $this->eventRow('Puzzle Pals', '1:00:00', '4'),
            'puzzle pals,1:05:00,4,,,,,,,,,,,,',
            'Other Team,1:10:00,4,,,,,,,,,,,,',
        );

        self::assertSame([EventTabProblemCode::TeamNamedTwice], $this->codes($tab));
        self::assertCount(1, $tab->rows);
        self::assertSame('Other Team', $tab->rows[0]->teamName);
    }

    public function testAnUnfinishedTeamIsNotImportedYet(): void
    {
        // Pieces placed are recorded once unfinished results are built (README "Unfinished results")
        $tab = $this->parse(self::HEADER, $this->eventRow('Fine Team', '0:59:00', '4'), 'Out of Time,,4,412,,,,,,,,,,,');

        self::assertSame([EventTabProblemCode::UnfinishedNotSupportedYet], $this->codes($tab));
        self::assertCount(1, $tab->rows);
    }

    public function testATimeAndPiecesPlacedTogetherAreAProblem(): void
    {
        $tab = $this->parse(self::HEADER, $this->eventRow('Fine Team', '0:59:00', '4'), 'Both,1:00:00,4,412,,,,,,,,,,,');

        self::assertSame([EventTabProblemCode::TimeAndPiecesPlaced], $this->codes($tab));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function times(): iterable
    {
        yield 'h:mm:ss' => ['1:10:13', 4213];
        yield 'leading zero hours' => ['0:24:48', 1488];
        yield 'm:ss' => ['24:48', 1488];
        yield 'surrounding spaces' => [' 1:00:00 ', 3600];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('times')]
    public function testTimes(string $time, int $seconds): void
    {
        self::assertSame($seconds, $this->parse(self::HEADER, $this->eventRow('Puzzle Pals', $time, '4'))->rows[0]->seconds);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dates(): iterable
    {
        yield 'US short year' => ['10/5/26', '2026-10-05'];
        yield 'US full year' => ['10/05/2026', '2026-10-05'];
        yield 'ISO' => ['2026-10-05', '2026-10-05'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dates')]
    public function testDates(string $date, string $expected): void
    {
        $row = preg_replace('/,10\/5\/26,/', ',' . $date . ',', $this->eventRow('Puzzle Pals', '1:00:00', '4'), 1);
        self::assertIsString($row);

        self::assertSame($expected, $this->parse(self::HEADER, $row)->localStartsAt?->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function startTimes(): iterable
    {
        yield '12-hour' => ['7:00 PM', '19:00'];
        yield '12-hour lowercase, no minutes' => ['7 pm', '19:00'];
        yield 'noon' => ['12:30 PM', '12:30'];
        yield 'midnight-ish' => ['12:15 AM', '00:15'];
        yield '24-hour' => ['18:30', '18:30'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('startTimes')]
    public function testStartTimes(string $startTime, string $expected): void
    {
        $row = preg_replace('/,7:00 PM,/', ',' . $startTime . ',', $this->eventRow('Puzzle Pals', '1:00:00', '4'), 1);
        self::assertIsString($row);

        self::assertSame($expected, $this->parse(self::HEADER, $row)->localStartsAt?->format('H:i'));
    }

    public function testTheContentHashChangesOnlyWithTheContent(): void
    {
        $one = $this->parse(self::HEADER, $this->eventRow('Puzzle Pals', '1:00:00', '4'));
        $same = $this->parse(self::HEADER, $this->eventRow('Puzzle Pals', '1:00:00', '4'));
        $changed = $this->parse(self::HEADER, $this->eventRow('Puzzle Pals', '1:00:01', '4'));

        self::assertSame($one->contentHash, $same->contentHash);
        self::assertNotSame($one->contentHash, $changed->contentHash);
    }

    private function eventRow(string $name, string $time, string $size): string
    {
        return sprintf(
            '"%s",%s,%s,,Maple Street Puzzle Night,Maple Street Taproom,"Springfield, MN",Some Brand,Some Puzzle,10/5/26,7:00 PM,120,Teams,%s,',
            $name,
            $time,
            $size,
            self::PUZZLE_URL,
        );
    }

    private function parse(string ...$lines): \SpeedPuzzling\Web\Value\EventResultsImport\ParsedEventTab
    {
        return (new SarahDoesPuzzlesTabParser())->parse(implode("\n", $lines));
    }

    /**
     * @return list<EventTabProblemCode>
     */
    private function codes(\SpeedPuzzling\Web\Value\EventResultsImport\ParsedEventTab $tab): array
    {
        return array_map(static fn($problem) => $problem->code, $tab->problems);
    }
}
