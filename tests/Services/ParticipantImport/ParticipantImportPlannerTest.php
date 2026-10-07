<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ParticipantImport;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Results\ParticipantImportPlan;
use SpeedPuzzling\Web\Services\CompetitionParticipantExporter;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPlanner;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantImportOperations;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\ColumnMapping;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use SpeedPuzzling\Web\Value\ParticipantImportRowData;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use SpeedPuzzling\Web\Value\ParticipantSheet;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Synthetic data only - every point of "Why" in docs/features/competitions-management/participant-import-preview.md
 * reproduced with made-up people. The event is EDITION_OFFLINE_1 (Solo Round, Team Round; its only participant is
 * the self-joined "Sarah Williams") plus a Pair Round added per test.
 */
final class ParticipantImportPlannerTest extends KernelTestCase
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

    public function testPlanningWritesNothingAndTheFingerprintIsStable(): void
    {
        $this->participant('Alex Example', [self::SOLO]);
        $this->entityManager->flush();
        $before = $this->eventState();

        $rows = $this->rows([self::row(2, 'Alex Example', roundNames: 'Solo Round, Team Round'), self::row(3, 'Blake Newcomer')]);

        $first = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Update);
        $second = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Update);
        $sync = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Sync);

        self::assertSame($before, $this->eventState());
        self::assertSame($first->fingerprint, $second->fingerprint);
        self::assertNotSame($first->fingerprint, $sync->fingerprint);
        self::assertEquals($first->operations, $second->operations);

        // Anything on the site changing changes the fingerprint
        $this->participant('Casey Later', []);
        $this->entityManager->flush();
        self::assertNotSame($first->fingerprint, $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Update)->fingerprint);
    }

    public function testUpdateOnlyListsWhatFullSyncWouldRemoveAndKeepsIt(): void
    {
        $alex = $this->participant('Alex Example', [self::SOLO]);
        $this->participant('Blake Gone', [self::SOLO]);
        $this->entityManager->flush();

        $rows = $this->rows([self::row(2, 'Alex Example', roundNames: 'Solo Round')]);

        $update = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Update);
        self::assertSame(['Blake Gone'], array_column($update->removals->participants, 'name'));
        self::assertSame(['Sarah Williams'], array_column($update->removals->selfJoined, 'name'));
        self::assertFalse($update->removesAnything());
        self::assertSame(0, self::operations($update)->removed);
        self::assertTrue(self::operations($update)->isEmpty());
        self::assertSame(ParticipantImportRowAction::Unchanged, $update->rows[0]->action);
        self::assertSame($alex->id->toString(), $update->rows[0]->participantId);

        $sync = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Sync);
        self::assertEquals($update->removals, $sync->removals);
        self::assertTrue($sync->removesAnything());
        self::assertTrue($sync->canBeApplied());
        self::assertSame(2, self::operations($sync)->removed);
        self::assertSame(3, $sync->activeParticipantsBefore);
    }

    public function testFullSyncTakesPeopleOutOfRoundsOnlyWhenARoundsColumnIsMapped(): void
    {
        $this->participant('Alex Example', [self::SOLO, self::TEAM]);
        $this->participant('Dana Emptycell', [self::SOLO]);
        $this->entityManager->flush();

        // Rounds mapped: Alex leaves the Team Round; Dana's empty cell keeps her rounds (D14)
        $plan = $this->planner->plan(self::EVENT, $this->rows([
            self::row(2, 'Alex Example', roundNames: 'Solo Round'),
            self::row(3, 'Dana Emptycell', roundNames: ''),
        ], roundsMapped: true), ParticipantImportMode::Sync);

        self::assertSame(['Team Round'], $plan->rows[0]->roundsRemoved);
        self::assertSame(ParticipantImportRowAction::Update, $plan->rows[0]->action);
        self::assertSame([['participantName' => 'Alex Example', 'roundName' => 'Team Round']], array_map(
            static fn (array $entry): array => ['participantName' => $entry['participantName'], 'roundName' => $entry['roundName']],
            $plan->removals->roundEntries,
        ));
        self::assertSame(ParticipantImportRowAction::Unchanged, $plan->rows[1]->action);
        self::assertSame(['Row 3: the rounds cell of "Dana Emptycell" is empty, so they keep the rounds they have on the site.'], $this->texts($plan->rows[1]->messages));

        // No rounds column: nobody leaves a round
        $plan = $this->planner->plan(self::EVENT, $this->rows([
            self::row(2, 'Alex Example'),
            self::row(3, 'Dana Emptycell'),
        ]), ParticipantImportMode::Sync);

        self::assertSame([], $plan->removals->roundEntries);
        self::assertSame([], $plan->rows[0]->roundsRemoved);

        // "Update only" never removes, but lists it
        $plan = $this->planner->plan(self::EVENT, $this->rows([self::row(2, 'Alex Example', roundNames: 'Solo Round'), self::row(3, 'Dana Emptycell', roundNames: 'Solo Round')], roundsMapped: true), ParticipantImportMode::Update);
        self::assertSame([], $plan->rows[0]->roundsRemoved);
        self::assertSame(ParticipantImportRowAction::Unchanged, $plan->rows[0]->action);
        self::assertCount(1, $plan->removals->roundEntries);
        self::assertSame([], self::operations($plan)->deletedEntries);
    }

    public function testAnOlderFileRestoresARemovedParticipantExplicitly(): void
    {
        $removed = $this->participant('Erin Removed', [self::SOLO, self::TEAM]);
        $removed->softDelete(new DateTimeImmutable('2026-09-01 10:00:00'));
        $this->entityManager->flush();

        $plan = $this->planner->plan(self::EVENT, $this->rows([self::row(2, 'Erin Removed', roundNames: 'Solo Round')], roundsMapped: true), ParticipantImportMode::Update);
        $row = $plan->rows[0];
        self::assertSame(ParticipantImportRowAction::Restore, $row->action);
        self::assertEquals(new DateTimeImmutable('2026-09-01 10:00:00'), $row->removedAt);
        self::assertSame(['Solo Round', 'Team Round'], $row->roundsRestored);
        self::assertSame(1, self::operations($plan)->restored);
        self::assertSame(1, self::operations($plan)->updated);

        // Full sync compares the entries coming back with the file like everybody else's
        $plan = $this->planner->plan(self::EVENT, $this->rows([self::row(2, 'Erin Removed', roundNames: 'Solo Round')], roundsMapped: true), ParticipantImportMode::Sync);
        self::assertSame(['Solo Round'], $plan->rows[0]->roundsRestored);
        self::assertSame(['Team Round'], $plan->rows[0]->roundsRemoved);
    }

    public function testRemovedSelfJoinedRowIsNeverMatchedNorCreatedAgain(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = now() WHERE id = :id',
            ['id' => MarketplaceEventFixture::PARTICIPANT_EDITION_SELLER_A],
        );

        $plan = $this->planner->plan(self::EVENT, $this->rows([
            self::row(2, 'Sarah Williams'),
            self::row(3, 'Somebody Else', participantId: MarketplaceEventFixture::PARTICIPANT_EDITION_SELLER_A),
        ]), ParticipantImportMode::Sync);

        // The player's own "I left" record: not restored, and no second record of her either
        foreach ($plan->rows as $row) {
            self::assertSame(ParticipantImportRowAction::Skipped, $row->action);
            self::assertNull($row->participantId);
            self::assertSame(
                [sprintf('Row %d: "Sarah Williams" signed up on MySpeedPuzzling by themselves and left the event again, so the row was skipped. If they take part after all, add them on the participants page.', $row->rowNumber)],
                $this->texts($row->messages),
            );
        }
        self::assertSame([], self::operations($plan)->participants);
        self::assertSame([], $plan->syncBlockers, 'Not a row the plan cannot vouch for');
    }

    public function testApostropheVariantIsTheSamePersonAndTakesTheFilesSpelling(): void
    {
        $dana = $this->participant("Dana O'Neill", [self::SOLO]);
        $this->entityManager->flush();

        $plan = $this->planner->plan(self::EVENT, $this->rows([self::row(2, 'Dana O’Neill')]), ParticipantImportMode::Sync);

        $row = $plan->rows[0];
        self::assertSame(ParticipantImportRowAction::Update, $row->action);
        self::assertSame($dana->id->toString(), $row->participantId);
        self::assertSame([['field' => 'name', 'before' => "Dana O'Neill", 'after' => 'Dana O’Neill']], $row->changes);
        self::assertNotContains("Dana O'Neill", array_column($plan->removals->participants, 'name'));
    }

    public function testOneNameWrittenTwoWaysInTheFileIsWarned(): void
    {
        $plan = $this->planner->plan(self::EVENT, $this->rows([
            self::row(2, "Robin O'Example"),
            self::row(3, 'Robin O’Example'),
        ]), ParticipantImportMode::Update);

        // Two rows of the file are never merged by the name key - only warned (a person on the site would be matched)
        self::assertSame(ParticipantImportRowAction::New, $plan->rows[0]->action);
        self::assertSame(ParticipantImportRowAction::New, $plan->rows[1]->action);
        self::assertSame(2, self::operations($plan)->added);
        self::assertContains('One name is written in different ways in rows 2, 3: "Robin O\'Example", "Robin O’Example". If it is one person, write it the same way everywhere.', $this->texts($plan->warnings));
    }

    public function testNameKeyMatchesOnlyPeopleOnTheSiteNeverAnEarlierRowOfTheFile(): void
    {
        $rows = $this->rows([
            self::row(2, 'Shay O’Example', participantId: '018d0000-0000-0000-0000-00000000bbb1'),
            self::row(3, "Shay O'Example", participantId: '018d0000-0000-0000-0000-00000000bbb2'),
            self::row(4, "Shay O'Example", roundNames: 'Solo Round'),
        ], roundsMapped: true);

        $plan = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Update);

        // Rows differing only by the key stay two people; the exact name still adds up
        self::assertSame(ParticipantImportRowAction::New, $plan->rows[0]->action);
        self::assertSame(ParticipantImportRowAction::New, $plan->rows[1]->action);
        self::assertSame(ParticipantImportRowAction::SamePerson, $plan->rows[2]->action);
        self::assertSame(2, self::operations($plan)->added);
        self::assertSame([['participantKey' => 'new:3', 'roundId' => self::SOLO, 'team' => null]], self::operations($plan)->newEntries);
        self::assertContains('One name is written in different ways in rows 2, 3, 4: "Shay O’Example", "Shay O\'Example". If it is one person, write it the same way everywhere.', $this->texts($plan->warnings));
    }

    public function testAParticipantIdRowNeverTakesOverAnotherLink(): void
    {
        // WJPC 2024: John Regular (EXT-001, PLAYER_REGULAR), Jane Unconnected (nothing), Secret Player (PLAYER_PRIVATE)
        $plan = $this->planner->plan(CompetitionFixture::COMPETITION_WJPC_2024, $this->rows([
            self::row(2, 'John Regular', externalId: 'EXT-999', playerId: PlayerFixture::PLAYER_ADMIN, participantId: CompetitionParticipantFixture::PARTICIPANT_CONNECTED),
            self::row(3, 'Jane Unconnected', playerId: PlayerFixture::PLAYER_PRIVATE, participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED),
        ]), ParticipantImportMode::Update);

        // The organiser's own id is theirs to correct; the connected player is never replaced
        self::assertSame(ParticipantImportRowAction::Update, $plan->rows[0]->action);
        self::assertSame([['field' => 'external_id', 'before' => 'EXT-001', 'after' => 'EXT-999']], $plan->rows[0]->changes);
        self::assertSame([
            sprintf('Row 2: "John Regular" stays connected to their MySpeedPuzzling player - msp_player_id "%s" from the file ignored (an import never replaces a connected player).', PlayerFixture::PLAYER_ADMIN),
        ], $this->texts($plan->rows[0]->messages));

        // Secret Player is an active participant connected to that player already
        self::assertSame(ParticipantImportRowAction::Unchanged, $plan->rows[1]->action);
        self::assertSame(
            [sprintf('Row 3: msp_player_id "%s" is already connected to "Secret Player" in this event, so it was not connected to "Jane Unconnected".', PlayerFixture::PLAYER_PRIVATE)],
            $this->texts($plan->rows[1]->messages),
        );

        // An unconnected participant is connected to a player nobody else of the event has
        $plan = $this->planner->plan(CompetitionFixture::COMPETITION_WJPC_2024, $this->rows([
            self::row(2, 'Jane Unconnected', playerId: PlayerFixture::PLAYER_ADMIN, participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED),
        ]), ParticipantImportMode::Update);
        self::assertSame(ParticipantImportRowAction::Update, $plan->rows[0]->action);
        self::assertSame([['field' => 'msp_player_id', 'before' => null, 'after' => PlayerFixture::PLAYER_ADMIN]], $plan->rows[0]->changes);
    }

    public function testAnExternalIdAnotherParticipantHasIsNeverTakenOver(): void
    {
        $plan = $this->planner->plan(CompetitionFixture::COMPETITION_WJPC_2024, $this->rows([
            self::row(2, 'Jane Unconnected', externalId: 'EXT-001', participantId: CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED),
        ]), ParticipantImportMode::Update);

        // Jane has no external id yet - EXT-001 is John Regular's, so the row cannot give it to her
        self::assertNotContains(['field' => 'external_id', 'before' => null, 'after' => 'EXT-001'], $plan->rows[0]->changes);
    }

    public function testStatusDeletedNeverRemovesSomebodyWithResults(): void
    {
        foreach ([ParticipantImportMode::Update, ParticipantImportMode::Sync] as $mode) {
            // John Regular has results in the Qualification Round; Jane has none
            $plan = $this->planner->plan(CompetitionFixture::COMPETITION_WJPC_2024, $this->rows([
                self::row(2, 'John Regular', externalId: 'EXT-001', status: 'deleted'),
                self::row(3, 'Jane Unconnected', status: 'deleted'),
                self::row(4, 'Secret Player'),
            ]), $mode);

            self::assertSame(ParticipantImportRowAction::Unchanged, $plan->rows[0]->action, $mode->value);
            self::assertSame(['Row 2: "John Regular" has results in this event, so the status "deleted" was ignored and they stay.'], $this->texts($plan->rows[0]->messages));
            self::assertSame(ParticipantImportRowAction::Remove, $plan->rows[1]->action, $mode->value);

            $softDeleted = array_values(array_filter(self::operations($plan)->participants, static fn (array $operation): bool => $operation['softDelete']));
            self::assertContains(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED, array_column($softDeleted, 'key'));
            self::assertNotContains(CompetitionParticipantFixture::PARTICIPANT_CONNECTED, array_column($softDeleted, 'key'));
        }
    }

    public function testAForeignParticipantIdFallsBackToTheNameAndOnlyManyBlockFullSync(): void
    {
        $alex = $this->participant('Alex Example', [self::SOLO]);
        $this->entityManager->flush();

        $plan = $this->planner->plan(self::EVENT, $this->rows([
            self::row(2, 'Alex Example', participantId: '018d0000-0000-0000-0000-00000000ccc1'),
        ]), ParticipantImportMode::Sync);

        self::assertSame($alex->id->toString(), $plan->rows[0]->participantId);
        self::assertSame(ParticipantImportRowAction::Unchanged, $plan->rows[0]->action);
        self::assertSame(
            ['Row 2: participant_id "018d0000-0000-0000-0000-00000000ccc1" is not a participant of this event, so the row was matched like a row without it.'],
            $this->texts($plan->rows[0]->messages),
        );
        self::assertSame([], $plan->syncBlockers);
        self::assertTrue($plan->canBeApplied());
    }

    public function testTwoRowsOfOnePersonWithDifferentTeamsKeepTheFirst(): void
    {
        $plan = $this->planner->plan(self::EVENT, $this->rows([
            self::row(2, 'Alex Example', roundNames: 'Team Round', teamsByRound: [self::TEAM => 'Corner Crew']),
            self::row(3, 'Alex Example', roundNames: 'Team Round', teamsByRound: [self::TEAM => 'Edge Lords']),
        ], roundsMapped: true), ParticipantImportMode::Update);

        self::assertSame(ParticipantImportRowAction::SamePerson, $plan->rows[1]->action);
        self::assertSame(
            ['Row 3: "Alex Example" already has team "Corner Crew" in round "Team Round" from an earlier row, team "Edge Lords" ignored.'],
            $this->texts($plan->rows[1]->messages),
        );
        self::assertSame(['Team Round' => 'Corner Crew'], $plan->rows[0]->teams);
        self::assertSame(['Team Round' => null], $plan->rows[0]->teamsBefore, 'A team the import gives is shown as a change');
        self::assertCount(1, self::operations($plan)->newTeams);
    }

    public function testFullSyncDeletesOnlyTeamsTheImportEmpties(): void
    {
        $round = $this->round(self::TEAM);
        $movedAway = new CompetitionTeam(Uuid::uuid7(), $round, 'Moved Away');
        $leftBehind = new CompetitionTeam(Uuid::uuid7(), $round, 'Left Behind');
        $emptyAlready = new CompetitionTeam(Uuid::uuid7(), $round, 'Made In Advance');
        $unnamed = new CompetitionTeam(Uuid::uuid7(), $round, null);
        $withResults = new CompetitionTeam(Uuid::uuid7(), $round, 'Has Results');
        $target = new CompetitionTeam(Uuid::uuid7(), $round, 'Target Crew');
        foreach ([$movedAway, $leftBehind, $emptyAlready, $unnamed, $withResults, $target] as $team) {
            $this->entityManager->persist($team);
        }

        $this->participant('Mover Alone', [self::TEAM], [self::TEAM => $movedAway]);
        $this->participant('Gone Member', [self::TEAM], [self::TEAM => $leftBehind]);
        $this->participant('Unnamed Stayer', [self::TEAM], [self::TEAM => $unnamed]);
        $this->participant('Target Member', [self::TEAM], [self::TEAM => $target]);
        $resulter = $this->participant('Result Holder', [self::TEAM], [self::TEAM => $withResults]);
        $this->participant('Result Teammate', [self::TEAM], [self::TEAM => $withResults]);
        $player = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_REGULAR);
        self::assertInstanceOf(Player::class, $player);
        $resulter->connect($player, new DateTimeImmutable());
        $this->entityManager->flush();

        // The Result Holder has a result in the Team Round
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET player_id = :player, competition_round_id = :round WHERE id = :id',
            ['player' => PlayerFixture::PLAYER_REGULAR, 'round' => self::TEAM, 'id' => PuzzleSolvingTimeFixture::TIME_11],
        );

        $plan = $this->planner->plan(self::EVENT, $this->rows([
            self::row(2, 'Mover Alone', roundNames: 'Team Round', teamsByRound: [self::TEAM => 'Target Crew']),
            self::row(3, 'Unnamed Stayer', roundNames: 'Team Round', teamsByRound: [self::TEAM => '']),
            self::row(4, 'Target Member', roundNames: 'Team Round', teamsByRound: [self::TEAM => 'Target Crew']),
        ], roundsMapped: true), ParticipantImportMode::Sync);

        self::assertTrue($plan->canBeApplied());
        self::assertSame(
            ['Gone Member', 'Result Teammate', 'Sarah Williams'],
            array_column([...$plan->removals->participants, ...$plan->removals->selfJoined], 'name'),
        );
        self::assertSame(['Result Holder'], array_column($plan->removals->participantsKeptWithResults, 'name'));

        // Moved Away (its member moved) and Left Behind (its member removed) are emptied; the team made in advance,
        // the unnamed team (its member stays) and the team of somebody kept because of results stay
        $deleted = array_column($plan->removals->teams, 'teamName');
        sort($deleted);
        self::assertSame(['Left Behind', 'Moved Away'], $deleted);
        self::assertEqualsCanonicalizing([$movedAway->id->toString(), $leftBehind->id->toString()], self::operations($plan)->deletedTeams);

        self::assertSame(['Team Round' => 'Target Crew'], $plan->rows[0]->teams);
        self::assertSame(['Team Round' => 'Moved Away'], $plan->rows[0]->teamsBefore);
        self::assertSame([], $plan->rows[2]->teamsBefore, 'An unchanged team is no change');
    }

    public function testTheFingerprintFollowsTheRoundsStart(): void
    {
        $rows = $this->rows([self::row(2, 'Alex Example', roundNames: 'Solo Round')], roundsMapped: true);
        $before = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Update)->fingerprint;

        $this->database->executeStatement(
            "UPDATE competition_round SET starts_at = starts_at + interval '1 hour' WHERE id = :id",
            ['id' => self::SOLO],
        );

        self::assertNotSame($before, $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Update)->fingerprint);
    }

    public function testCorrectedTypoNextToTheOldSpellingIsWarned(): void
    {
        $this->participant('Morgan Whitfeld', [self::SOLO]);
        $this->entityManager->flush();

        $plan = $this->planner->plan(self::EVENT, $this->rows([self::row(2, 'Morgan Whitfield')]), ParticipantImportMode::Update);

        self::assertSame(ParticipantImportRowAction::New, $plan->rows[0]->action);
        self::assertContains('Morgan Whitfield (new, row 2) looks like Morgan Whitfeld, who is on the site but not in the file. If it is the same person, use the spelling from the site or fix it there.', $this->texts($plan->warnings));
    }

    public function testTwoTeamsSharingANameKeepTheirMembersAndANewMemberIsNotGuessed(): void
    {
        $first = new CompetitionTeam(Uuid::uuid7(), $this->round(self::TEAM), 'Corner Crew');
        $second = new CompetitionTeam(Uuid::uuid7(), $this->round(self::TEAM), 'Corner Crew');
        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->participant('Ann First', [self::TEAM], [self::TEAM => $first]);
        $this->participant('Ben Second', [self::TEAM], [self::TEAM => $second]);
        $this->entityManager->flush();

        foreach ([ParticipantImportMode::Update, ParticipantImportMode::Sync] as $mode) {
            $plan = $this->planner->plan(self::EVENT, $this->rows([
                self::row(2, 'Ann First', roundNames: 'Team Round', teamsByRound: [self::TEAM => 'Corner Crew']),
                self::row(3, 'Ben Second', roundNames: 'Team Round', teamsByRound: [self::TEAM => 'corner crew']),
                self::row(4, 'Cleo Third', roundNames: 'Team Round', teamsByRound: [self::TEAM => 'Corner Crew']),
            ], roundsMapped: true), $mode);

            self::assertSame(ParticipantImportRowAction::Unchanged, $plan->rows[0]->action, $mode->value);
            self::assertSame(ParticipantImportRowAction::Unchanged, $plan->rows[1]->action, $mode->value);
            self::assertSame(['Team Round' => null], $plan->rows[2]->teams, $mode->value);
            self::assertSame(
                ['Row 4: 2 teams in round "Team Round" are called "Corner Crew", so "Cleo Third" was not put into any of them. Give the teams different names on the site or in the file.'],
                $this->texts($plan->rows[2]->messages),
            );

            $operations = self::operations($plan);
            self::assertSame([], $operations->newTeams);
            self::assertSame([], $operations->entryTeams);
            self::assertSame([['participantKey' => 'new:4', 'roundId' => self::TEAM, 'team' => null]], $operations->newEntries);
        }
    }

    public function testEightPeopleUnderOneTeamNameIntoAnEmptyEventAreWarned(): void
    {
        $pair = $this->addRound('Pair Round', RoundCategory::Duo);

        $rows = [];
        $number = 2;
        foreach (['Edge Lords' => 8, 'Piece Makers' => 4, 'Sky Fillers' => 4] as $team => $size) {
            for ($i = 1; $i <= $size; $i++) {
                $rows[] = self::row($number, sprintf('%s Member %d', $team, $i), roundNames: 'Team Round', teamsByRound: [self::TEAM => $team]);
                $number++;
            }
        }
        for ($i = 1; $i <= 4; $i++) {
            $rows[] = self::row($number, sprintf('Pair Person %d', $i), roundNames: 'Pair Round', teamsByRound: [$pair => 'Twin Peaks']);
            $number++;
        }
        $rows[] = self::row($number, 'Lonely Pairer', roundNames: 'Pair Round', teamsByRound: [$pair => 'Solo Duo']);

        $plan = $this->planner->plan(self::EVENT, $this->rows($rows, roundsMapped: true), ParticipantImportMode::Update);
        $warnings = $this->texts($plan->warnings);

        self::assertContains('8 people are in "Edge Lords" in Team Round – teams in this round usually have 4. If these are different teams, give them different names in the file.', $warnings);
        self::assertContains('4 people are in "Twin Peaks" in Pair Round – a pair has 2. If these are different pairs, give them different names in the file.', $warnings);
        self::assertContains('In Pair Round, "Solo Duo" has only one person.', $warnings);
        self::assertCount(3, $warnings);

        // Applying still puts them into one team each - the organiser saw the warning
        self::assertCount(5, self::operations($plan)->newTeams);
    }

    /**
     * D16 (c) with the organiser's expected team size (participants-spreadsheet.md D5): teams of 4 in a round expecting 3
     * are warned about, although 4 is the most common size of the file.
     */
    public function testTheExpectedTeamSizeOfTheRoundWinsOverTheFilesMostCommonSize(): void
    {
        $this->round(self::TEAM)->changeTeamSize(3);
        $this->entityManager->flush();

        $rows = [];
        $number = 2;
        foreach (['Piece Makers' => 4, 'Sky Fillers' => 4, 'Trio Team' => 3] as $team => $size) {
            for ($i = 1; $i <= $size; $i++) {
                $rows[] = self::row($number, sprintf('%s Member %d', $team, $i), roundNames: 'Team Round', teamsByRound: [self::TEAM => $team]);
                $number++;
            }
        }

        $plan = $this->planner->plan(self::EVENT, $this->rows($rows, roundsMapped: true), ParticipantImportMode::Update);
        $warnings = $this->texts($plan->warnings);

        self::assertContains('4 people are in "Piece Makers" in Team Round – teams in this round usually have 3. If these are different teams, give them different names in the file.', $warnings);
        self::assertContains('4 people are in "Sky Fillers" in Team Round – teams in this round usually have 3. If these are different teams, give them different names in the file.', $warnings);
        self::assertCount(2, $warnings);
    }

    public function testFullSyncMovesTeamsOnlyInRoundsWithTheirOwnTeamColumn(): void
    {
        $round = $this->round(self::TEAM);
        $named = new CompetitionTeam(Uuid::uuid7(), $round, 'Old Crew');
        $other = new CompetitionTeam(Uuid::uuid7(), $round, 'New Crew');
        $unnamed = new CompetitionTeam(Uuid::uuid7(), $round, null);
        foreach ([$named, $other, $unnamed] as $team) {
            $this->entityManager->persist($team);
        }
        $this->participant('Mover One', [self::TEAM], [self::TEAM => $named]);
        $this->participant('Leaver Two', [self::TEAM], [self::TEAM => $named]);
        $this->participant('Unnamed Three', [self::TEAM], [self::TEAM => $unnamed]);
        $this->participant('Stayer Four', [self::TEAM], [self::TEAM => $other]);
        $this->entityManager->flush();

        $fileRows = [
            self::row(2, 'Mover One', roundNames: 'Team Round', teamsByRound: [self::TEAM => 'new crew']),
            self::row(3, 'Leaver Two', roundNames: 'Team Round', teamsByRound: [self::TEAM => '']),
            self::row(4, 'Unnamed Three', roundNames: 'Team Round', teamsByRound: [self::TEAM => '']),
            self::row(5, 'Stayer Four', roundNames: 'Team Round', teamsByRound: [self::TEAM => 'New Crew']),
        ];

        $sync = $this->planner->plan(self::EVENT, $this->rows($fileRows, roundsMapped: true), ParticipantImportMode::Sync);
        self::assertSame([
            ['participantName' => 'Mover One', 'roundName' => 'Team Round', 'from' => 'Old Crew', 'to' => 'New Crew'],
            ['participantName' => 'Leaver Two', 'roundName' => 'Team Round', 'from' => 'Old Crew', 'to' => null],
        ], $sync->removals->teamChanges);
        self::assertSame([$named->id->toString()], self::operations($sync)->deletedTeams, 'Old Crew is emptied, the unnamed team keeps its member');
        self::assertSame(['Team Round' => 'New Crew'], $sync->rows[0]->teams);
        self::assertSame(ParticipantImportRowAction::Unchanged, $sync->rows[2]->action);
        self::assertSame(ParticipantImportRowAction::Unchanged, $sync->rows[3]->action);

        // "Update only" moves nobody (today's message), but shows what sync would
        $update = $this->planner->plan(self::EVENT, $this->rows($fileRows, roundsMapped: true), ParticipantImportMode::Update);
        self::assertSame([], self::operations($update)->entryTeams);
        self::assertEquals($sync->removals, $update->removals);
        self::assertSame(['"Mover One" stays in team "Old Crew" in round "Team Round", team "new crew" from the file ignored (an import never moves anybody to another team).'], $this->texts($update->rows[0]->messages));

        // The generic team column only adds teams - full sync does not move by it
        $generic = $this->planner->plan(self::EVENT, $this->rows([
            self::row(2, 'Mover One', roundNames: 'Team Round', team: 'New Crew'),
            self::row(3, 'Leaver Two', roundNames: 'Team Round', team: ''),
            self::row(4, 'Unnamed Three', roundNames: 'Team Round'),
            self::row(5, 'Stayer Four', roundNames: 'Team Round'),
        ], roundsMapped: true), ParticipantImportMode::Sync);
        self::assertSame([], $generic->removals->teamChanges);
        self::assertSame([], self::operations($generic)->entryTeams);
        self::assertSame([], self::operations($generic)->deletedTeams);
    }

    public function testExportPlannedBackChangesNothingInEitherMode(): void
    {
        $pair = $this->addRound('Pair Round', RoundCategory::Duo);
        $duo = new CompetitionTeam(Uuid::uuid7(), $this->round($pair), 'Swift Pair');
        $unnamed = new CompetitionTeam(Uuid::uuid7(), $this->round(self::TEAM), null);
        $this->entityManager->persist($duo);
        $this->entityManager->persist($unnamed);
        $this->participant('Ann Export', [self::SOLO, $pair, self::TEAM], [$pair => $duo, self::TEAM => $unnamed]);
        $this->participant('Ben Export', [$pair, self::TEAM], [$pair => $duo, self::TEAM => $unnamed]);
        $this->participant('Cid Export', [self::TEAM]);
        $removed = $this->participant('Dee Removed', [self::SOLO]);
        $removed->softDelete(new DateTimeImmutable('2026-09-01'));
        $this->entityManager->flush();

        $rows = $this->exportRows(self::EVENT);

        foreach ([ParticipantImportMode::Update, ParticipantImportMode::Sync] as $mode) {
            $plan = $this->planner->plan(self::EVENT, $rows, $mode);

            self::assertSame([], $plan->syncBlockers, $mode->value);
            self::assertSame([], $this->texts($plan->warnings), $mode->value);
            foreach ($plan->rows as $row) {
                self::assertSame(ParticipantImportRowAction::Unchanged, $row->action, $mode->value . ' ' . $row->name);
                self::assertSame([], $row->messages, $row->name);
            }
            self::assertTrue($plan->removals->isEmpty(), $mode->value);
            // Only the self-joined "Sarah Williams" becomes the organiser's (bookkeeping)
            self::assertTrue(self::operations($plan)->changesNothingVisible(), $mode->value);
        }
    }

    public function testFullSyncIsRefusedWhenThePlanCannotVouchForEveryRow(): void
    {
        $this->participant('Twin Name', [], country: 'us');
        $this->participant('Twin Name', [], country: 'us');
        $this->entityManager->flush();

        $rows = $this->rows([
            self::row(2, '', country: 'cz'),
            self::row(3, 'Twin Name'),
            self::row(4, 'Somebody', roundNames: 'Final Showdown'),
            self::row(5, 'Foreign A', participantId: '018d0000-0000-0000-0000-00000000aaa1'),
            self::row(6, 'Foreign B', participantId: '018d0000-0000-0000-0000-00000000aaa2'),
            self::row(7, 'Foreign C', participantId: '018d0000-0000-0000-0000-00000000aaa3'),
            self::row(8, 'Foreign D', participantId: '018d0000-0000-0000-0000-00000000aaa4'),
        ], roundsMapped: true);
        $plan = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Sync);

        self::assertFalse($plan->canBeApplied());
        self::assertSame([
            'Row 2 has no name.',
            'Row 3 matches several participants of the same name.',
            'Round "Final Showdown" does not exist in this event.',
            '4 rows carry a participant_id of another event - is this the export of another event?',
            'No row of the file matches any of the 3 participants on the site - is the right column chosen for the names, and is it the right file?',
        ], $this->texts($plan->syncBlockers));

        // The participants a skipped ambiguous row may mean are never listed for removal
        self::assertNotContains('Twin Name', array_column($plan->removals->participants, 'name'));

        // "Update only" stays possible
        self::assertTrue($this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Update)->canBeApplied());
    }

    public function testParticipantsWithResultsAreNeverRemoved(): void
    {
        // WJPC 2024: John Regular and Secret Player have results in the Qualification Round
        $plan = $this->planner->plan(CompetitionFixture::COMPETITION_WJPC_2024, $this->rows([
            self::row(2, 'Jane Unconnected', roundNames: 'Final Round'),
            self::row(3, 'John Regular', externalId: 'EXT-001', roundNames: 'Final Round'),
        ], roundsMapped: true), ParticipantImportMode::Sync);

        self::assertSame(['Secret Player'], array_column($plan->removals->participantsKeptWithResults, 'name'));
        self::assertContains('Qualification Round', $plan->removals->participantsKeptWithResults[0]['rounds']);
        self::assertSame(['Michael Johnson'], array_column($plan->removals->selfJoined, 'name'));
        self::assertSame([], $plan->removals->participants);

        // John stays in the Qualification Round he has a result in
        self::assertSame(['John Regular'], array_column($plan->removals->roundEntriesKeptWithResults, 'participantName'));
        self::assertNotContains('John Regular', array_column($plan->removals->roundEntries, 'participantName'));

        $selfJoined = array_values(array_filter(
            self::operations($plan)->participants,
            static fn (array $operation): bool => $operation['key'] === CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED,
        ));
        self::assertCount(1, $selfJoined);
        self::assertTrue($selfJoined[0]['markAsImported']);
        self::assertTrue($selfJoined[0]['softDelete']);
    }

    /**
     * @param list<string> $roundIds
     * @param array<string, CompetitionTeam> $teams round id => team
     */
    private function participant(string $name, array $roundIds, array $teams = [], string $country = 'us', ParticipantSource $source = ParticipantSource::Imported): CompetitionParticipant
    {
        $competition = $this->entityManager->find(Competition::class, self::EVENT);
        assert($competition instanceof Competition);

        $participant = new CompetitionParticipant(Uuid::uuid7(), $name, $country, $competition, $source);
        $this->entityManager->persist($participant);

        foreach ($roundIds as $roundId) {
            $this->entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $this->round($roundId), $teams[$roundId] ?? null));
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

        $round = new CompetitionRound(Uuid::uuid7(), $competition, $name, 60, new DateTimeImmutable('+40 days'), category: $category);
        $this->entityManager->persist($round);
        $this->entityManager->flush();

        return $round->id->toString();
    }

    /**
     * @param array<string, string> $teamsByRound
     */
    private static function row(
        int $number,
        string $name,
        null|string $country = null,
        null|string $externalId = null,
        null|string $participantId = null,
        null|string $roundNames = null,
        null|string $team = null,
        array $teamsByRound = [],
        null|string $playerId = null,
        null|string $status = null,
    ): ParticipantImportRowData {
        return new ParticipantImportRowData(
            rowNumber: $number,
            name: $name,
            country: $country,
            externalId: $externalId,
            playerId: $playerId,
            participantId: $participantId,
            status: $status,
            roundNames: $roundNames,
            team: $team,
            teamsByRound: $teamsByRound,
        );
    }

    /**
     * @param list<ParticipantImportRowData> $rows
     */
    private function rows(array $rows, bool $roundsMapped = false): ParticipantImportRows
    {
        return new ParticipantImportRows($rows, roundsMapped: $roundsMapped);
    }

    private function exportRows(string $competitionId): ParticipantImportRows
    {
        $file = tempnam(sys_get_temp_dir(), 'test_export_');
        assert(is_string($file));
        file_put_contents($file, self::getContainer()->get(CompetitionParticipantExporter::class)->export($competitionId));

        /** @var list<list<null|string>> $cells */
        $cells = IOFactory::load($file)->getActiveSheet()->toArray();
        unlink($file);

        $headers = array_map(static fn (null|string $header): string => (string) $header, array_shift($cells) ?? []);
        $sheetRows = [];
        foreach ($cells as $index => $row) {
            $sheetRows[$index + 2] = array_map(static fn (null|string $cell): string => trim((string) $cell), $row);
        }
        $sheet = new ParticipantSheet($headers, $sheetRows);

        return ColumnMapping::detect($headers, $this->planner->rounds($competitionId))->toRows($sheet);
    }

    private static function operations(ParticipantImportPlan $plan): ParticipantImportOperations
    {
        assert($plan->operations instanceof ParticipantImportOperations);

        return $plan->operations;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventState(): array
    {
        return $this->database->fetchAllAssociative(
            'SELECT cp.id, cp.name, cp.source, cp.deleted_at, cpr.round_id, cpr.team_id
             FROM competition_participant cp
             LEFT JOIN competition_participant_round cpr ON cpr.participant_id = cp.id
             WHERE cp.competition_id = :id ORDER BY cp.id, cpr.id',
            ['id' => self::EVENT],
        );
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
