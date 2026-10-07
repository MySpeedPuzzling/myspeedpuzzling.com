<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\ColumnMapping;
use SpeedPuzzling\Web\Value\ParticipantImportField;
use SpeedPuzzling\Web\Value\ParticipantImportRound;
use SpeedPuzzling\Web\Value\ParticipantSheet;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Component\Translation\TranslatableMessage;

final class ColumnMappingTest extends TestCase
{
    private const string SOLO = '00000000-0000-0000-0000-00000000000a';
    private const string PAIR = '00000000-0000-0000-0000-00000000000b';
    private const string TEAM_RELAY = '00000000-0000-0000-0000-00000000000c';

    /**
     * @param list<string> $headers
     * @param array<int, ParticipantImportField> $expected
     */
    #[DataProvider('detectedHeaders')]
    public function testDetectsKnownHeadersAndAliases(array $headers, array $expected): void
    {
        $mapping = ColumnMapping::detect($headers, self::rounds());

        self::assertEquals($expected, $mapping->fields);
    }

    /**
     * @return iterable<string, array{list<string>, array<int, ParticipantImportField>}>
     */
    public static function detectedHeaders(): iterable
    {
        yield 'export headers' => [
            ['name', 'country', 'external_id', 'msp_player_id', 'status', 'round_names', 'team_name', 'participant_id'],
            [
                0 => ParticipantImportField::Name,
                1 => ParticipantImportField::Country,
                2 => ParticipantImportField::ExternalId,
                3 => ParticipantImportField::PlayerId,
                4 => ParticipantImportField::Status,
                5 => ParticipantImportField::Rounds,
                6 => ParticipantImportField::Team,
                7 => ParticipantImportField::ParticipantId,
            ],
        ];

        yield 'aliases, any case, spaces, dashes and underscores' => [
            ['Member no.', ' Full-Name ', 'COUNTRY_CODE', 'Division', 'Team', 'MSP id', 'Address'],
            [
                1 => ParticipantImportField::Name,
                2 => ParticipantImportField::Country,
                3 => ParticipantImportField::Rounds,
                4 => ParticipantImportField::Team,
                5 => ParticipantImportField::PlayerId,
            ],
        ];

        yield 'one round' => [
            ['Player', 'Round'],
            [0 => ParticipantImportField::Name, 1 => ParticipantImportField::Round],
        ];

        yield 'first and last name' => [
            ['First name', 'Surname', 'Rounds'],
            [0 => ParticipantImportField::FirstName, 1 => ParticipantImportField::LastName, 2 => ParticipantImportField::Rounds],
        ];

        yield 'a full name wins over first and last name' => [
            ['Firstname', 'Lastname', 'Name'],
            [2 => ParticipantImportField::Name],
        ];

        yield 'a second column of a kind stays ignored' => [
            ['name', 'name', 'country', 'Nation'],
            [0 => ParticipantImportField::Name, 2 => ParticipantImportField::Country],
        ];
    }

    public function testDetectsTeamColumnsOfPairAndTeamRounds(): void
    {
        $mapping = ColumnMapping::detect(
            ['name', 'team_name: Pair', 'Team: team relay', 'Solo team', 'team_name: Unknown round', 'TEAM RELAY TEAM'],
            self::rounds(),
        );

        self::assertSame(ParticipantImportField::TeamInRound, $mapping->fields[1]);
        self::assertSame(ParticipantImportField::TeamInRound, $mapping->fields[2]);
        self::assertSame([1 => self::PAIR, 2 => self::TEAM_RELAY], $mapping->teamRounds);
        self::assertSame([self::PAIR => 1, self::TEAM_RELAY => 2], $mapping->teamColumnsByRound());
        // A solo round has no teams, an unknown round is not guessed, a round's second team column stays ignored
        self::assertArrayNotHasKey(3, $mapping->fields);
        self::assertArrayNotHasKey(4, $mapping->fields);
        self::assertArrayNotHasKey(5, $mapping->fields);
        self::assertSame([], $mapping->errors());
    }

    public function testRoundTripThroughTheQuery(): void
    {
        $headers = ['Name', 'Country', 'Address', 'Rounds', 'team_name: Pair', 'Notes'];
        $detected = ColumnMapping::detect($headers, self::rounds());

        $query = $detected->toQuery($headers);

        self::assertSame([
            0 => 'name',
            1 => 'country',
            2 => 'ignore',
            3 => 'round_names',
            4 => 'team_in_round:' . self::PAIR,
            5 => 'ignore',
        ], $query);

        $fromQuery = ColumnMapping::fromQuery($query, $headers, self::rounds());

        self::assertEquals($detected, $fromQuery);
        self::assertSame($query, $fromQuery->toQuery($headers));
    }

    public function testQueryIgnoresWhatDoesNotFit(): void
    {
        $headers = ['Name', 'Team', 'Notes'];

        $mapping = ColumnMapping::fromQuery([
            0 => 'name',
            1 => 'team_in_round:' . self::SOLO,
            2 => 'no_such_field',
            7 => 'country',
            'x' => 'status',
        ], $headers, self::rounds());

        self::assertEquals([0 => ParticipantImportField::Name], $mapping->fields);
        self::assertSame([], $mapping->teamRounds);
    }

    public function testMappingErrors(): void
    {
        $headers = ['A', 'B', 'C', 'D', 'E', 'F'];

        self::assertSame(
            ['competition.participants.import.mapping.name_missing'],
            self::errorKeys(ColumnMapping::fromQuery([0 => 'first_name', 1 => 'country'], $headers, self::rounds())),
        );

        self::assertSame(
            ['competition.participants.import.mapping.name_twice'],
            self::errorKeys(ColumnMapping::fromQuery([0 => 'name', 1 => 'last_name'], $headers, self::rounds())),
        );

        $twice = ColumnMapping::fromQuery([0 => 'name', 1 => 'country', 2 => 'country', 3 => 'team_in_round:' . self::PAIR, 4 => 'team_in_round:' . self::PAIR], $headers, self::rounds());
        self::assertSame(
            ['competition.participants.import.mapping.field_twice', 'competition.participants.import.mapping.team_round_twice'],
            self::errorKeys($twice),
        );

        $field = $twice->errors()[0]->getParameters()['%field%'];
        self::assertInstanceOf(TranslatableMessage::class, $field);
        self::assertSame('competition.participants.import.field.country', $field->getMessage());

        self::assertSame([], ColumnMapping::fromQuery([0 => 'first_name', 1 => 'last_name'], $headers, self::rounds())->errors());
    }

    public function testRowsOfTheSheet(): void
    {
        $sheet = new ParticipantSheet(
            headers: ['First name', 'Last name', 'Address', 'Country', 'Rounds', 'team_name: Pair'],
            rows: [
                2 => ['Alex', 'Example', '1 Sample Street', 'cz', 'Solo, Pair', 'Corner Crew'],
                3 => ['', '', '2 Sample Street', '', '', ''],
                4 => ['', '', '', 'de', 'Solo', ''],
                6 => ['  Blake ', 'O’Example  Junior', '', '', '', ''],
            ],
        );

        $rows = ColumnMapping::detect($sheet->headers, self::rounds())->toRows($sheet);

        // Row 3 has only an address - not a participant. Row 4 has data but no name - kept for the planner to report.
        self::assertSame([2, 4, 6], array_map(static fn ($row): int => $row->rowNumber, $rows->rows));

        [$alex, $nameless, $blake] = $rows->rows;
        self::assertSame('Alex Example', $alex->name);
        self::assertSame('cz', $alex->country);
        self::assertSame('Solo, Pair', $alex->roundNames);
        self::assertNull($alex->roundName);
        self::assertNull($alex->team);
        self::assertNull($alex->externalId);
        self::assertSame([self::PAIR => 'Corner Crew'], $alex->teamsByRound);

        self::assertSame('', $nameless->name);
        self::assertSame('de', $nameless->country);
        self::assertSame([self::PAIR => ''], $nameless->teamsByRound);

        self::assertSame('Blake O’Example Junior', $blake->name);

        self::assertSame(['Address'], $rows->unmappedHeaders);
        self::assertTrue($rows->roundsMapped);
    }

    public function testNoRoundsColumnMeansRoundsAreNotMapped(): void
    {
        $sheet = new ParticipantSheet(headers: ['Name', 'Team'], rows: [2 => ['Casey Example', 'Edge Lords']]);

        $rows = ColumnMapping::detect($sheet->headers, self::rounds())->toRows($sheet);

        self::assertFalse($rows->roundsMapped);
        self::assertSame('Edge Lords', $rows->rows[0]->team);
        self::assertSame([], $rows->unmappedHeaders);
    }

    /**
     * @return list<ParticipantImportRound>
     */
    private static function rounds(): array
    {
        return [
            new ParticipantImportRound(self::SOLO, 'Solo', RoundCategory::Solo),
            new ParticipantImportRound(self::PAIR, 'Pair', RoundCategory::Duo),
            new ParticipantImportRound(self::TEAM_RELAY, 'Team Relay', RoundCategory::Team),
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
