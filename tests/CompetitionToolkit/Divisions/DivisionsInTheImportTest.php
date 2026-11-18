<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\Divisions;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Results\ParticipantImportPlan;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportApplier;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPlanner;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantImportOperations;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use SpeedPuzzling\Web\Value\ParticipantImportRowData;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The Division column of the participant import (shared-puzzles-and-divisions.md §3.5 - D4) on St. Paul's divisions
 * (DivisionsSetup): the value goes to every entry of the row in a round that uses it (accents and case do not matter),
 * a round of the row that does not use it is a warning naming both, an unknown value an error of the row, empty leaves
 * the division as it is, a pair gets what its members' rows agree on.
 */
final class DivisionsInTheImportTest extends KernelTestCase
{
    use DivisionsSetup;

    private ParticipantImportPlanner $planner;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->planner = self::getContainer()->get(ParticipantImportPlanner::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->setUpStPaulDivisions(self::getContainer()->get(MessageBusInterface::class), withEntries: false);
    }

    public function testTheDivisionGoesToEveryEntryOfTheRowInARoundThatUsesIt(): void
    {
        $plan = $this->plan([
            // Group A and the Final use Senior; Group B none
            new ParticipantImportRowData(2, 'Anna Fast', roundNames: 'Group A, Final', division: '  seniór 55+ '),
            new ParticipantImportRowData(3, 'Hugo Slow', roundNames: 'Group B', division: 'Senior 55+'),
            new ParticipantImportRowData(4, 'Cara Tied', roundNames: 'Group A', division: 'Senor'),
            // Empty: left as it is
            new ParticipantImportRowData(5, 'Ben Steady', roundNames: 'Group A', division: ''),
            // No rounds column value: the rounds the person is in
            new ParticipantImportRowData(6, 'Eva Noshow', division: 'j'),
        ]);

        $rows = $plan->rows;
        self::assertSame(ParticipantImportRowAction::Update, $rows[0]->action);
        self::assertSame([
            'Group A' => ['before' => null, 'after' => 'Senior 55+'],
            'Final' => ['before' => null, 'after' => 'Senior 55+'],
        ], $rows[0]->divisions);

        self::assertSame(['entry_divisions.import.not_in_round'], self::keys($rows[1]->messages));
        self::assertSame(['%row%' => 3, '%round%' => 'Group B', '%division%' => 'Senior 55+'], $rows[1]->messages[0]->getParameters());
        self::assertSame([], $rows[1]->divisions);

        self::assertSame(['entry_divisions.import.unknown'], self::keys($rows[2]->messages));
        self::assertSame('Regular, Senior 55+, Junior U16', $rows[2]->messages[0]->getParameters()['%divisions%']);
        self::assertSame([], $rows[2]->divisions);

        self::assertSame([], $rows[3]->divisions);
        self::assertSame(ParticipantImportRowAction::Unchanged, $rows[3]->action);
        // Eva is in Group A (Junior) and in the unnamed pair of Pairs, which does not use Junior
        self::assertSame(['Group A' => ['before' => null, 'after' => 'Junior U16']], $rows[4]->divisions);
        self::assertSame(['entry_divisions.import.not_in_round'], self::keys($rows[4]->messages));

        $this->apply($plan);
        self::assertSame($this->senior, $this->entryDivision(Cup::PARTICIPANT_ANNA, Cup::ROUND_GROUP_A));
        self::assertSame($this->senior, $this->entryDivision(Cup::PARTICIPANT_ANNA, Cup::ROUND_FINAL));
        self::assertSame($this->junior, $this->entryDivision(Cup::PARTICIPANT_EVA, Cup::ROUND_GROUP_A));
        self::assertNull($this->entryDivision(Cup::PARTICIPANT_CARA, Cup::ROUND_GROUP_A));

        // Imported again: nothing changes
        $again = $this->plan([new ParticipantImportRowData(2, 'Anna Fast', roundNames: 'Group A, Final', division: 'Senior 55+')]);
        self::assertSame(ParticipantImportRowAction::Unchanged, $again->rows[0]->action);
        self::assertTrue(self::operations($again)->isEmpty());
    }

    public function testAPairGetsTheDivisionItsMembersAgreeOn(): void
    {
        $plan = $this->plan([
            // Puzzle Sharks: both say Regular → the pair is Regular
            new ParticipantImportRowData(2, 'Anna Fast', roundNames: 'Pairs', division: 'Regular'),
            new ParticipantImportRowData(3, 'Ben Steady', roundNames: 'Pairs', division: 'regular'),
            // Corner Pieces: they disagree → left as it is
            new ParticipantImportRowData(4, 'Cara Tied', roundNames: 'Pairs', division: 'Regular'),
            new ParticipantImportRowData(5, 'Dan Unfinished', roundNames: 'Pairs', division: 'S'),
        ]);

        self::assertSame(['Pairs' => ['before' => null, 'after' => 'Regular']], $plan->rows[0]->divisions);
        self::assertSame(['entry_divisions.import.members_disagree'], self::keys($plan->rows[2]->messages));
        self::assertSame([['team' => 't:' . Cup::TEAM_SHARKS, 'division' => $this->regular]], self::operations($plan)->teamDivisions);

        $this->apply($plan);
        self::assertSame($this->regular, $this->database->fetchOne('SELECT division_id FROM competition_team WHERE id = ?', [Cup::TEAM_SHARKS]));
        self::assertNull($this->database->fetchOne('SELECT division_id FROM competition_team WHERE id = ?', [Cup::TEAM_CORNERS]));
        // Members' own entries carry none
        self::assertNull($this->entryDivision(Cup::PARTICIPANT_ANNA, Cup::ROUND_PAIRS));
    }

    public function testARoundsOwnDivisionColumnAndANewPerson(): void
    {
        $plan = $this->plan([
            new ParticipantImportRowData(2, 'Zoe Newcomer', roundNames: 'Group A', divisionsByRound: [Cup::ROUND_GROUP_A => 'Junior U16']),
            // A division of a round the row does not name and the person is not in
            new ParticipantImportRowData(3, 'Ivan Last', roundNames: 'Group B', divisionsByRound: [Cup::ROUND_GROUP_A => 'Regular']),
        ]);

        self::assertSame(ParticipantImportRowAction::New, $plan->rows[0]->action);
        self::assertSame(['Group A' => ['before' => null, 'after' => 'Junior U16']], $plan->rows[0]->divisions);
        self::assertSame(['entry_divisions.import.not_entered'], self::keys($plan->rows[1]->messages));

        $this->apply($plan);
        self::assertSame($this->junior, $this->database->fetchOne(
            'SELECT cpr.division_id FROM competition_participant_round cpr INNER JOIN competition_participant cp ON cp.id = cpr.participant_id WHERE cp.name = ? AND cpr.round_id = ?',
            ['Zoe Newcomer', Cup::ROUND_GROUP_A],
        ));
    }

    /**
     * @param list<ParticipantImportRowData> $rows
     */
    private function plan(array $rows): ParticipantImportPlan
    {
        return $this->planner->plan(Cup::COMPETITION_RESULTS_CUP, new ParticipantImportRows($rows, roundsMapped: true), ParticipantImportMode::Update);
    }

    private function apply(ParticipantImportPlan $plan): void
    {
        self::getContainer()->get(ParticipantImportApplier::class)->apply(Cup::COMPETITION_RESULTS_CUP, $plan);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private static function operations(ParticipantImportPlan $plan): ParticipantImportOperations
    {
        $operations = $plan->operations;
        self::assertInstanceOf(ParticipantImportOperations::class, $operations);

        return $operations;
    }

    /**
     * @param list<TranslatableMessage> $messages
     * @return list<string>
     */
    private static function keys(array $messages): array
    {
        return array_map(static fn (TranslatableMessage $message): string => $message->getMessage(), $messages);
    }

    private function entryDivision(string $participantId, string $roundId): null|string
    {
        $division = $this->database->fetchOne('SELECT division_id FROM competition_participant_round WHERE participant_id = ? AND round_id = ?', [$participantId, $roundId]);

        return is_string($division) ? $division : null;
    }
}
