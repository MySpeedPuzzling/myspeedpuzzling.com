<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\ColumnMapping;
use SpeedPuzzling\Web\Value\ParticipantImportField;
use SpeedPuzzling\Web\Value\ParticipantImportRound;
use SpeedPuzzling\Web\Value\ParticipantImportRowData;
use SpeedPuzzling\Web\Value\ParticipantSheet;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Team rows (participant-import-preview.md "As built: team rows"): member columns make a row one team ticket, a Ticket
 * type column says the round. Made-up data only.
 */
final class ColumnMappingTeamRowsTest extends TestCase
{
    private const string SOLO = '00000000-0000-0000-0000-00000000000a';
    private const string PAIRS = '00000000-0000-0000-0000-00000000000b';
    private const string TEAMS = '00000000-0000-0000-0000-00000000000c';

    /**
     * @param list<string> $headers
     */
    #[DataProvider('memberHeaders')]
    public function testMemberColumnsAreRecognisedByTheirNumber(array $headers): void
    {
        $mapping = ColumnMapping::detect($headers, self::rounds());

        self::assertSame([1, 2, 3], $mapping->memberColumns());
        self::assertTrue($mapping->hasName());
        self::assertSame([], $mapping->errors());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function memberHeaders(): iterable
    {
        yield 'player N name' => [['Team name', 'Player 1 name', 'Player 2 name', 'Player 3 name']];
        yield 'player N' => [['Team', 'Player 1', 'Player_2', 'PLAYER 3']];
        yield 'member N' => [['Team', 'Member 1', 'Member 2', 'Member 3']];
        yield 'team member N' => [['Team', 'Team member 1', 'Team member 2 name', 'Team-Member-3']];
        yield 'name N' => [['Team', 'Name 1', 'Name 2', 'Name 3']];
        yield 'participant #N' => [['Team', 'Participant #1', 'Participant #2', 'Participant #3']];
        yield 'no space, full name' => [['Team', 'Player1', 'Player2 full name', 'Puzzler 3']];
    }

    public function testNumberingGapsAndNumbersOutOfRange(): void
    {
        // 1, 3, 12 are members (the gap does not matter), 0 and 13 are not, a second "Player 1" stays ignored
        $headers = ['Player 1 name', 'Player 3 name', 'Player 12 name', 'Player 0 name', 'Player 13 name', 'Player 1'];
        $mapping = ColumnMapping::detect($headers, self::rounds());

        self::assertSame([0, 1, 2], $mapping->memberColumns());
        self::assertSame([0, 1, 2], array_keys($mapping->fields));
    }

    public function testNamesAndPerPersonColumnsGiveWayToMemberColumns(): void
    {
        $headers = ['Name', 'Email', 'External id', 'Status', 'Ticket type', 'Team name', 'Player 1 name', 'Player 2 name', 'Country'];
        $mapping = ColumnMapping::detect($headers, self::rounds());

        self::assertEquals([
            4 => ParticipantImportField::TicketType,
            5 => ParticipantImportField::Team,
            6 => ParticipantImportField::MemberName,
            7 => ParticipantImportField::MemberName,
            8 => ParticipantImportField::Country,
        ], $mapping->fields);
        self::assertSame([], $mapping->errors());
    }

    public function testAFileOfPeopleKeepsItsTicketAndDivisionColumnsAsBefore(): void
    {
        $mapping = ColumnMapping::detect(['Name', 'Ticket type', 'Division'], self::rounds());

        self::assertEquals([0 => ParticipantImportField::Name, 2 => ParticipantImportField::Rounds], $mapping->fields);

        // Rows of a file of people carry nothing new - they hash exactly as before
        $row = (new ParticipantImportRowData(2, 'Alex Example', roundNames: 'Solo'))->toArray();
        self::assertArrayNotHasKey('teamRow', $row);
        self::assertArrayNotHasKey('ticketType', $row);
    }

    public function testATicketTypeColumnNextToMemberColumnsTakesTheRoundOverADivisionQuestion(): void
    {
        $mapping = ColumnMapping::detect(['Ticket type', 'Player 1 name', 'Division'], self::rounds());

        self::assertEquals([0 => ParticipantImportField::TicketType, 1 => ParticipantImportField::MemberName], $mapping->fields);
    }

    public function testMappingErrorsOfMemberColumns(): void
    {
        $headers = ['A', 'B', 'C', 'D'];

        self::assertSame(
            ['team_tickets.mapping.name_with_members'],
            self::errorKeys(ColumnMapping::fromQuery([0 => 'name', 1 => 'member_name', 2 => 'member_name'], $headers, self::rounds())),
        );

        // The buyer's e-mail of a team ticket is nobody's of the members
        self::assertSame(['team_tickets.mapping.per_person_column'], self::errorKeys(ColumnMapping::fromQuery([0 => 'email', 1 => 'member_name'], $headers, self::rounds())));

        $perPerson = ColumnMapping::fromQuery([0 => 'external_id', 1 => 'member_name'], $headers, self::rounds());
        self::assertSame(['team_tickets.mapping.per_person_column'], self::errorKeys($perPerson));
        $field = $perPerson->errors()[0]->getParameters()['%field%'];
        self::assertInstanceOf(TranslatableMessage::class, $field);
        self::assertSame('competition.participants.import.field.external_id', $field->getMessage());

        // Many member columns are never "chosen twice"; two ticket type columns are
        self::assertSame([], ColumnMapping::fromQuery([0 => 'member_name', 1 => 'member_name', 2 => 'member_name'], $headers, self::rounds())->errors());
        self::assertSame(
            ['competition.participants.import.mapping.field_twice'],
            self::errorKeys(ColumnMapping::fromQuery([0 => 'member_name', 1 => 'ticket_type', 2 => 'ticket_type'], $headers, self::rounds())),
        );

        $thirteen = array_fill(0, 13, 'member_name');
        self::assertSame(
            ['team_tickets.mapping.too_many_member_columns'],
            self::errorKeys(ColumnMapping::fromQuery($thirteen, array_fill(0, 13, 'X'), self::rounds())),
        );
    }

    public function testARowIsOnePersonPerFilledMemberCell(): void
    {
        $headers = ['Ticket type', 'Team name', 'Player 1 name', 'Player 2 name', 'Player 3 name', 'Country'];
        $sheet = new ParticipantSheet($headers, [
            2 => ['Team ticket', 'Corner Crew', 'Avery  Example', '', 'Casey Demo', 'us'],
            // No member, but a team name: one nameless row, so the planner reports it
            3 => ['Team ticket', 'Lonely Crew', '', '', '', ''],
            // Nothing mapped at all: no row
            4 => ['', '', '', '', '', ''],
        ]);
        $mapping = ColumnMapping::detect($headers, self::rounds());
        $rows = $mapping->toRows($sheet);

        self::assertTrue($rows->roundsMapped);
        self::assertCount(3, $rows->rows);

        [$avery, $casey, $nameless] = $rows->rows;
        self::assertSame(201, $avery->rowNumber);
        self::assertSame('Avery Example', $avery->name);
        self::assertSame(2, $avery->teamRow);
        self::assertSame(1, $avery->member);
        self::assertSame('Corner Crew', $avery->team);
        self::assertSame('us', $avery->country);
        self::assertSame('Team ticket', $avery->ticketType);
        self::assertSame(self::TEAMS, $avery->ticketRound);

        // The empty second cell is nobody; Casey keeps her column's number
        self::assertSame(203, $casey->rowNumber);
        self::assertSame(3, $casey->member);
        self::assertSame('us', $casey->country);

        self::assertSame(300, $nameless->rowNumber);
        self::assertSame('', $nameless->name);
        self::assertSame(3, $nameless->teamRow);
        self::assertSame(0, $nameless->member);
    }

    public function testTheRoundOfATicketTypeIsGuessedUntilTheOrganiserChooses(): void
    {
        $rounds = self::rounds();

        self::assertSame(self::TEAMS, ColumnMapping::guessTicketRound('500 Teams CHALLENGE - Team of 4', $rounds));
        self::assertSame(self::PAIRS, ColumnMapping::guessTicketRound('Pairs - 1000 pieces', $rounds));
        self::assertSame(self::SOLO, ColumnMapping::guessTicketRound('Solo - 500 pieces', $rounds));
        // A round's own name wins - the longest one
        self::assertSame('x', ColumnMapping::guessTicketRound('Saturday Pairs Cup ticket', [...$rounds, new ParticipantImportRound('z', 'Cup', RoundCategory::Solo), new ParticipantImportRound('x', 'Pairs Cup', RoundCategory::Duo)]));
        self::assertNull(ColumnMapping::guessTicketRound('Adult', $rounds));
        // Two kinds named: not guessed
        self::assertNull(ColumnMapping::guessTicketRound('Solo + Pairs bundle', $rounds));
        // Two rounds of the kind: not guessed
        self::assertNull(ColumnMapping::guessTicketRound('Team ticket', [...$rounds, new ParticipantImportRound('y', 'Team Final', RoundCategory::Team)]));

        $headers = ['Ticket type', 'Player 1 name'];
        $sheet = new ParticipantSheet($headers, [
            2 => ['Team ticket', 'Avery Example'],
            3 => ['Adult', 'Blake Sample'],
            4 => ['adult', 'Casey Demo'],
            5 => ['Spectator', 'Drew Placeholder'],
        ]);

        $detected = ColumnMapping::detect($headers, $rounds);
        self::assertSame([
            ['key' => ColumnMapping::ticketKey('Team ticket'), 'value' => 'Team ticket', 'rows' => 1, 'choice' => self::TEAMS, 'guessed' => true],
            ['key' => ColumnMapping::ticketKey('Adult'), 'value' => 'Adult', 'rows' => 2, 'choice' => null, 'guessed' => true],
            ['key' => ColumnMapping::ticketKey('Spectator'), 'value' => 'Spectator', 'rows' => 1, 'choice' => null, 'guessed' => true],
        ], $detected->ticketTypes($sheet));
        self::assertSame([ColumnMapping::ticketKey('Team ticket') => self::TEAMS], $detected->ticketQuery($sheet));

        // The organiser's choices; a round of another event and a malformed key are ignored
        $chosen = ColumnMapping::fromQuery($detected->toQuery($headers), $headers, $rounds, false, [
            ColumnMapping::ticketKey('Adult') => self::SOLO,
            ColumnMapping::ticketKey('Spectator') => ColumnMapping::TICKET_SKIP,
            ColumnMapping::ticketKey('Team ticket') => '00000000-0000-0000-0000-0000000000ff',
            'nonsense' => self::SOLO,
        ]);
        $expected = [ColumnMapping::ticketKey('Adult') => self::SOLO, ColumnMapping::ticketKey('Spectator') => ColumnMapping::TICKET_SKIP];
        ksort($expected);
        self::assertSame($expected, $chosen->ticketRounds);

        $rows = $chosen->toRows($sheet)->rows;
        self::assertSame([self::TEAMS, self::SOLO, self::SOLO, ColumnMapping::TICKET_SKIP], array_map(static fn (ParticipantImportRowData $row): null|string => $row->ticketRound, $rows));
        self::assertSame(ColumnMapping::ticketKey('ADULT '), ColumnMapping::ticketKey('adult'));
    }

    /**
     * @return list<ParticipantImportRound>
     */
    private static function rounds(): array
    {
        return [
            new ParticipantImportRound(self::SOLO, 'Solo Round', RoundCategory::Solo),
            new ParticipantImportRound(self::PAIRS, 'Pair Round', RoundCategory::Duo),
            new ParticipantImportRound(self::TEAMS, 'Team Round', RoundCategory::Team, teamSize: 4),
        ];
    }

    /**
     * @return list<string>
     */
    private static function errorKeys(ColumnMapping $mapping): array
    {
        return array_map(static fn (TranslatableMessage $error): string => $error->getMessage(), $mapping->errors());
    }
}
