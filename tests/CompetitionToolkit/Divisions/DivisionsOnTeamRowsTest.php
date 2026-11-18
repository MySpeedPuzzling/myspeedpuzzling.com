<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\Divisions;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Results\ParticipantImportRow;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportApplier;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPlanner;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use SpeedPuzzling\Web\Value\ColumnMapping;
use SpeedPuzzling\Web\Value\ParticipantImportField;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use SpeedPuzzling\Web\Value\ParticipantSheet;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A team row's Division (participant-import-preview.md "As built: team rows", shared-puzzles-and-divisions.md §3.5) is
 * the division of the team entry it makes - the members' own entries keep none; a solo ticket's is the person's entry.
 * St. Paul's divisions on the Results Cup (DivisionsSetup). Made-up people.
 */
final class DivisionsOnTeamRowsTest extends KernelTestCase
{
    use DivisionsSetup;

    public function testTheRowsDivisionGoesToTheTeamEntry(): void
    {
        self::bootKernel();
        $this->setUpStPaulDivisions(self::getContainer()->get(MessageBusInterface::class), withEntries: false);
        $planner = self::getContainer()->get(ParticipantImportPlanner::class);
        $database = self::getContainer()->get(Connection::class);

        $headers = ['Ticket type', 'Team name', 'Player 1 name', 'Player 2 name', 'Division'];
        $sheet = new ParticipantSheet($headers, [
            2 => ['Pairs', 'Edge Lords', 'Emery Testperson', 'Finley Mock', 'Senior 55+'],
            3 => ['Group A', '', 'Harper Fictional', '', 'junior u16'],
        ]);
        [$rounds, $divisions] = $planner->roundsAndDivisions(Cup::COMPETITION_RESULTS_CUP);
        $mapping = ColumnMapping::detect($headers, $rounds, $divisions !== []);

        // An event with divisions: the Division column stays the entries' division next to the ticket type
        self::assertSame(ParticipantImportField::Division, $mapping->fields[4] ?? null);
        self::assertSame(ParticipantImportField::TicketType, $mapping->fields[0] ?? null);

        $plan = $planner->plan(Cup::COMPETITION_RESULTS_CUP, $mapping->toRows($sheet), ParticipantImportMode::Update);

        self::assertSame([], $plan->errors);
        self::assertSame(ParticipantImportRowAction::New, $plan->rows[0]->action);
        self::assertSame(['Pairs' => ['before' => null, 'after' => 'Senior 55+']], $plan->rows[0]->divisions);
        self::assertSame(['Pairs' => ['before' => null, 'after' => 'Senior 55+']], $plan->rows[1]->divisions);
        self::assertSame(['Group A' => ['before' => null, 'after' => 'Junior U16']], $plan->rows[2]->divisions);

        self::getContainer()->get(ParticipantImportApplier::class)->apply(Cup::COMPETITION_RESULTS_CUP, $plan);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertSame($this->senior, $database->fetchOne('SELECT division_id FROM competition_team WHERE round_id = ? AND name = ?', [Cup::ROUND_PAIRS, 'Edge Lords']));
        self::assertSame([null, null], $database->fetchFirstColumn(
            'SELECT cpr.division_id FROM competition_participant_round cpr INNER JOIN competition_team ct ON ct.id = cpr.team_id WHERE ct.round_id = ? AND ct.name = ?',
            [Cup::ROUND_PAIRS, 'Edge Lords'],
        ));
        self::assertSame($this->junior, $database->fetchOne(
            'SELECT cpr.division_id FROM competition_participant_round cpr INNER JOIN competition_participant cp ON cp.id = cpr.participant_id WHERE cp.name = ? AND cpr.round_id = ?',
            ['Harper Fictional', Cup::ROUND_GROUP_A],
        ));

        // Imported again: nothing changes
        $again = $planner->plan(Cup::COMPETITION_RESULTS_CUP, $mapping->toRows($sheet), ParticipantImportMode::Update);
        self::assertSame(
            [ParticipantImportRowAction::Unchanged, ParticipantImportRowAction::Unchanged, ParticipantImportRowAction::Unchanged],
            array_map(static fn (ParticipantImportRow $row): ParticipantImportRowAction => $row->action, $again->rows),
        );
    }
}
