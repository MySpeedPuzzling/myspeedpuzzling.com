<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ParticipantImport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Results\ParticipantImportPlan;
use SpeedPuzzling\Web\Results\ParticipantImportRow;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportApplier;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPlanner;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantImportOperations;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\ColumnMapping;
use SpeedPuzzling\Web\Value\ParticipantImportField;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use SpeedPuzzling\Web\Value\ParticipantSheet;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Team rows of a registration export (participant-import-preview.md "As built: team rows"): one row per team ticket,
 * member columns, a ticket type saying the round. The event is EDITION_OFFLINE_1 (Solo Round, Team Round) plus a
 * Pair Round. Made-up people only - the Ticket Tailor file is the hand-off's made-up example.
 */
final class ParticipantImportTeamRowsTest extends KernelTestCase
{
    private const string EVENT = CompetitionSeriesFixture::EDITION_OFFLINE_1;
    private const string SOLO = CompetitionSeriesFixture::ROUND_OFFLINE_SOLO;
    private const string TEAM = CompetitionSeriesFixture::ROUND_OFFLINE_TEAM;

    private ParticipantImportPlanner $planner;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->planner = self::getContainer()->get(ParticipantImportPlanner::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheTicketTailorExportImportsOneTeamPerRowAndAgainChangesNothing(): void
    {
        $pair = $this->addRound('Pair Round', RoundCategory::Duo);
        $this->round(self::TEAM)->changeTeamSize(4);
        $this->entityManager->flush();

        $sheet = self::sheet((string) file_get_contents(__DIR__ . '/fixtures/ticket-tailor-team-tickets.csv'));
        $mapping = ColumnMapping::detect($sheet->headers, $this->planner->rounds(self::EVENT));

        // Ticket type, Team name, four members; the buyer's columns and the "Division" question are not imported
        self::assertEquals([
            2 => ParticipantImportField::TicketType,
            8 => ParticipantImportField::Team,
            9 => ParticipantImportField::MemberName,
            10 => ParticipantImportField::MemberName,
            11 => ParticipantImportField::MemberName,
            12 => ParticipantImportField::MemberName,
        ], $mapping->fields);
        self::assertSame([], $mapping->errors());

        $plan = $this->planner->plan(self::EVENT, $mapping->toRows($sheet), ParticipantImportMode::Update);

        self::assertSame([], $plan->errors);
        self::assertTrue($plan->canBeApplied());
        self::assertSame(
            ['Avery Example', 'Blake Sample', 'Casey Demo', 'Drew Placeholder', 'Emery Testperson', 'Finley Mock', 'Harper Fictional'],
            array_map(static fn (ParticipantImportRow $row): string => $row->name, $plan->rows),
        );
        self::assertSame(array_fill(0, 7, ParticipantImportRowAction::New), array_map(static fn (ParticipantImportRow $row): ParticipantImportRowAction => $row->action, $plan->rows));
        self::assertSame([2, 2, 2, 2, 3, 3, 4], array_map(static fn (ParticipantImportRow $row): int => $row->fileRow(), $plan->rows));
        self::assertSame([1, 2, 3, 4, 1, 2, 1], array_map(static fn (ParticipantImportRow $row): null|int => $row->member, $plan->rows));
        self::assertSame('Corner Crew', $plan->rows[0]->teamName);
        self::assertSame(['Team Round'], $plan->rows[0]->teamRounds);
        self::assertSame(['Team Round' => 'Corner Crew'], $plan->rows[0]->teams);
        self::assertSame(['Pair Round' => 'Edge Lords'], $plan->rows[4]->teams);
        // A solo ticket has no team
        self::assertNull($plan->rows[6]->teamName);
        self::assertSame(['Solo Round'], $plan->rows[6]->roundsAdded);
        self::assertSame([], array_merge(...array_map(static fn (ParticipantImportRow $row): array => $row->messages, $plan->rows)));

        $operations = self::operations($plan);
        self::assertCount(2, $operations->newTeams);
        self::assertCount(7, $operations->newEntries);

        $this->apply($plan);

        self::assertSame(['Avery Example', 'Blake Sample', 'Casey Demo', 'Drew Placeholder'], $this->members(self::TEAM, 'Corner Crew'));
        self::assertSame(['Emery Testperson', 'Finley Mock'], $this->members($pair, 'Edge Lords'));
        self::assertSame([null], $this->database->fetchFirstColumn(
            'SELECT cpr.team_id FROM competition_participant_round cpr INNER JOIN competition_participant cp ON cp.id = cpr.participant_id WHERE cp.name = ? AND cpr.round_id = ?',
            ['Harper Fictional', self::SOLO],
        ));

        // The same file again: everybody is matched by name, nothing changes
        $again = $this->planner->plan(self::EVENT, $mapping->toRows($sheet), ParticipantImportMode::Update);
        self::assertSame(array_fill(0, 7, ParticipantImportRowAction::Unchanged), array_map(static fn (ParticipantImportRow $row): ParticipantImportRowAction => $row->action, $again->rows));
        self::assertTrue(self::operations($again)->isEmpty());
    }

    public function testExistingParticipantsAreMatchedAndANamelessTeamIsCalledAfterItsFirstMember(): void
    {
        $blake = $this->participant('Blake Sample', [self::SOLO]);
        $this->entityManager->flush();

        $plan = $this->planFile("Ticket type,Team name,Player 1 name,Player 2 name\nTeams,,Avery Example,Blake Sample\n");

        self::assertSame(ParticipantImportRowAction::New, $plan->rows[0]->action);
        self::assertSame(ParticipantImportRowAction::Update, $plan->rows[1]->action);
        self::assertSame($blake->id->toString(), $plan->rows[1]->participantId);
        self::assertSame(['Team Round'], $plan->rows[1]->roundsAdded);
        self::assertSame('Team of Avery Example', $plan->rows[0]->teamName);

        $this->apply($plan);
        self::assertSame(['Avery Example', 'Blake Sample'], $this->members(self::TEAM, 'Team of Avery Example'));
        // Blake is one participant, in both rounds
        self::assertSame(2, $this->rowCount('SELECT COUNT(*) FROM competition_participant_round WHERE participant_id = ?', [$blake->id->toString()]));
    }

    public function testMoreMembersThanTheTeamSizeKeepTheRowOutAndFewerIsAWarning(): void
    {
        $this->round(self::TEAM)->changeTeamSize(3);
        $this->entityManager->flush();

        $plan = $this->planFile(
            "Ticket type,Team name,Player 1 name,Player 2 name,Player 3 name,Player 4 name\n"
            . "Teams,Big Crew,Avery Example,Blake Sample,Casey Demo,Drew Placeholder\n"
            . "Teams,Small Crew,Emery Testperson,Finley Mock,,\n",
        );

        self::assertSame(array_fill(0, 4, ParticipantImportRowAction::Skipped), array_map(static fn (ParticipantImportRow $row): ParticipantImportRowAction => $row->action, array_slice($plan->rows, 0, 4)));
        self::assertSame(
            ['Row 2: 4 people for “Big Crew” in Team Round, which has teams of 3 - the row is not imported. Check the row, or change the team size of the round.'],
            $this->texts($plan->rows[0]->messages),
        );
        self::assertSame([], $plan->rows[1]->messages);

        self::assertSame(ParticipantImportRowAction::New, $plan->rows[4]->action);
        self::assertSame(['Row 3: only 2 of 3 people for “Small Crew” in Team Round.'], $this->texts($plan->rows[4]->messages));

        // Only Small Crew is written; full sync is off (it would take the people of row 2 off the event)
        $operations = self::operations($plan);
        self::assertSame(['Small Crew'], array_column($operations->newTeams, 'name'));
        self::assertCount(2, $operations->newEntries);
        self::assertTrue($plan->canBeApplied());

        $sync = $this->planFile(
            "Ticket type,Team name,Player 1 name,Player 2 name,Player 3 name,Player 4 name\nTeams,Big Crew,Avery Example,Blake Sample,Casey Demo,Drew Placeholder\n",
            mode: ParticipantImportMode::Sync,
        );
        self::assertContains('1 row of the file is not imported (row 2)', $this->texts($sync->syncBlockers));
        self::assertFalse($sync->canBeApplied());
    }

    public function testOnePersonInTwoTeamRowsOfARoundKeepsBothRowsOut(): void
    {
        $pair = $this->addRound('Pair Round', RoundCategory::Duo);

        $plan = $this->planFile(
            "Ticket type,Team name,Player 1 name,Player 2 name\n"
            . "Teams,Corner Crew,Avery Example,Casey Demo\n"
            . "Teams,Edge Lords,Casey  demo,Drew Placeholder\n" // spaces and case do not matter
            . "Teams,Piece Makers,Emery Testperson,Finley Mock\n"
            // Another round: the same person may be in a pair too
            . "Pairs,Twin Peaks,Emery Testperson,Harper Fictional\n",
        );

        self::assertSame(
            [ParticipantImportRowAction::Skipped, ParticipantImportRowAction::Skipped, ParticipantImportRowAction::Skipped, ParticipantImportRowAction::Skipped, ParticipantImportRowAction::New, ParticipantImportRowAction::New, ParticipantImportRowAction::SamePerson, ParticipantImportRowAction::New],
            array_map(static fn (ParticipantImportRow $row): ParticipantImportRowAction => $row->action, $plan->rows),
        );
        self::assertSame(['Row 2: Casey Demo is in more than one team in Team Round (rows 2, 3) - these rows are not imported.'], $this->texts($plan->rows[0]->messages));
        self::assertSame(['Row 3: Casey demo is in more than one team in Team Round (rows 2, 3) - these rows are not imported.'], $this->texts($plan->rows[2]->messages));

        $this->apply($plan);
        self::assertSame(['Emery Testperson', 'Finley Mock'], $this->members(self::TEAM, 'Piece Makers'));
        self::assertSame(['Emery Testperson', 'Harper Fictional'], $this->members($pair, 'Twin Peaks'));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM competition_participant WHERE competition_id = ? AND name IN (?, ?)', [self::EVENT, 'Avery Example', 'Drew Placeholder']));
    }

    public function testEmptyMemberCellsAreNobodyAndARowWithoutMembersIsReported(): void
    {
        $plan = $this->planFile(
            "Ticket type,Team name,Player 1 name,Player 2 name,Player 3 name\n"
            . "Teams,Corner Crew,Avery Example,,Casey Demo\n"
            . "Teams,Lonely Crew,,,\n",
        );

        self::assertSame(['Avery Example', 'Casey Demo', ''], array_map(static fn (ParticipantImportRow $row): string => $row->name, $plan->rows));
        self::assertSame([1, 3, 0], array_map(static fn (ParticipantImportRow $row): null|int => $row->member, $plan->rows));
        self::assertSame(ParticipantImportRowAction::Skipped, $plan->rows[2]->action);
        self::assertSame(3, $plan->rows[2]->fileRow());
        // The file's row, not the planning row
        self::assertSame(3, $plan->rows[2]->messages[0]->getParameters()['%row%']);
        self::assertSame(['Corner Crew'], array_column(self::operations($plan)->newTeams, 'name'));
    }

    public function testEveryTicketTypeNeedsARoundAndASkippedOneIsNotImported(): void
    {
        $csv = "Ticket type,Team name,Player 1 name,Player 2 name\n"
            . "Adult team,Corner Crew,Avery Example,Blake Sample\n"
            . "Spectator,,Casey Demo,\n";

        // "Adult team" is the one team round; "Spectator" names no round - nothing can be imported until it is chosen
        $plan = $this->planFile($csv);
        self::assertSame(['Choose a round for the ticket type “Spectator” (rows 3).'], $this->texts($plan->errors));
        self::assertFalse($plan->canBeApplied());

        $plan = $this->planFile($csv, [ColumnMapping::ticketKey('Spectator') => ColumnMapping::TICKET_SKIP]);
        self::assertSame([], $plan->errors);
        self::assertTrue($plan->canBeApplied());
        self::assertSame(ParticipantImportRowAction::Skipped, $plan->rows[2]->action);
        self::assertSame(['Row 3: the ticket type “Spectator” is not imported.'], $this->texts($plan->rows[2]->messages));

        // Chosen into the solo round: Casey is a solo entrant there, with no team
        $plan = $this->planFile($csv, [ColumnMapping::ticketKey('Spectator') => self::SOLO, ColumnMapping::ticketKey('Adult team') => self::TEAM]);
        self::assertSame(ParticipantImportRowAction::New, $plan->rows[2]->action);
        self::assertSame(['Solo Round'], $plan->rows[2]->roundsAdded);
        self::assertSame(['Corner Crew'], array_column(self::operations($plan)->newTeams, 'name'));
    }

    /**
     * @param array<string, string> $tickets
     */
    private function planFile(string $csv, array $tickets = [], ParticipantImportMode $mode = ParticipantImportMode::Update): ParticipantImportPlan
    {
        $sheet = self::sheet($csv);
        $rounds = $this->planner->rounds(self::EVENT);
        $detected = ColumnMapping::detect($sheet->headers, $rounds);
        $mapping = ColumnMapping::fromQuery($detected->toQuery($sheet->headers), $sheet->headers, $rounds, false, $tickets);

        return $this->planner->plan(self::EVENT, $mapping->toRows($sheet), $mode);
    }

    private static function sheet(string $csv): ParticipantSheet
    {
        $lines = array_values(array_filter(explode("\n", $csv), static fn (string $line): bool => trim($line) !== ''));
        $headers = array_map(static fn (null|string $cell): string => trim((string) $cell), str_getcsv(array_shift($lines) ?? '', escape: ''));

        $rows = [];
        foreach ($lines as $index => $line) {
            $cells = array_map(static fn (null|string $cell): string => trim((string) $cell), str_getcsv($line, escape: ''));
            $rows[$index + 2] = array_pad($cells, count($headers), '');
        }

        return new ParticipantSheet($headers, $rows);
    }

    private function apply(ParticipantImportPlan $plan): void
    {
        self::getContainer()->get(ParticipantImportApplier::class)->apply(self::EVENT, $plan);
        $this->entityManager->flush();
    }

    /**
     * @return list<string> the team's members by name
     */
    private function members(string $roundId, string $teamName): array
    {
        /** @var list<string> $names */
        $names = $this->database->fetchFirstColumn(
            'SELECT cp.name FROM competition_participant_round cpr
             INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
             INNER JOIN competition_team ct ON ct.id = cpr.team_id
             WHERE ct.round_id = :round AND ct.name = :team ORDER BY cp.name',
            ['round' => $roundId, 'team' => $teamName],
        );

        return $names;
    }

    /**
     * @param list<string> $params
     */
    private function rowCount(string $sql, array $params): int
    {
        $count = $this->database->fetchOne($sql, $params);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    /**
     * @param list<string> $roundIds
     */
    private function participant(string $name, array $roundIds): CompetitionParticipant
    {
        $competition = $this->entityManager->find(Competition::class, self::EVENT);
        assert($competition instanceof Competition);

        $participant = new CompetitionParticipant(Uuid::uuid7(), $name, null, $competition);
        $this->entityManager->persist($participant);

        foreach ($roundIds as $roundId) {
            $this->entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $this->round($roundId)));
        }

        return $participant;
    }

    private function round(string $roundId): CompetitionRound
    {
        $round = $this->entityManager->find(CompetitionRound::class, $roundId);
        assert($round instanceof CompetitionRound);

        return $round;
    }

    private function addRound(string $name, RoundCategory $category): string
    {
        $competition = $this->entityManager->find(Competition::class, self::EVENT);
        assert($competition instanceof Competition);

        $round = new CompetitionRound(Uuid::uuid7(), $competition, $name, 60, TestClock::now()->modify('+40 days'), category: $category);
        $this->entityManager->persist($round);
        $this->entityManager->flush();

        return $round->id->toString();
    }

    private static function operations(ParticipantImportPlan $plan): ParticipantImportOperations
    {
        assert($plan->operations instanceof ParticipantImportOperations);

        return $plan->operations;
    }

    /**
     * @param array<TranslatableMessage> $messages
     * @return list<string>
     */
    private function texts(array $messages): array
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        return array_values(array_map(static fn (TranslatableMessage $message): string => $message->trans($translator, 'en'), $messages));
    }
}
