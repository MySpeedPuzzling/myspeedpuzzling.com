<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\ParticipantsSheet;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\SheetChangesetIdTaken;
use SpeedPuzzling\Web\Query\GetParticipantsSheetVersion;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The participants sheet's write path (ApplyParticipantSheetChanges, participants-spreadsheet.md §6, delivery contract
 * §3) on the "Results Cup" (.claude/fixtures.md "Official results"): Group A / Group B / Final solo, Pairs and Pairs
 * Final duo; official results on most entries; Filip, Eva and their unnamed pair hold none.
 */
final class ApplyParticipantSheetChangesHandlerTest extends KernelTestCase
{
    use SheetChangeSets;

    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testParticipantFieldsAreSetThreeWayAndCleaned(): void
    {
        $applied = $this->applySheetChanges([self::sheetGroup(
            self::fieldChange(Cup::PARTICIPANT_IVAN, 'name', 'Ivan Last', "  Ivan \t Lastly "),
            self::fieldChange(Cup::PARTICIPANT_IVAN, 'country', 'sk', 'CZ'),
            self::fieldChange(Cup::PARTICIPANT_IVAN, 'externalId', null, ' X-12 '),
            self::fieldChange(Cup::PARTICIPANT_IVAN, 'note', null, 'Pays at the door'),
        )]);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertSame(['applied', 'applied', 'applied', 'applied'], self::changeStatuses($applied));
        self::assertSame('Ivan Lastly', $applied->groups[0]->changes[0]->current);

        $ivan = self::participantRow($this->database, Cup::PARTICIPANT_IVAN);
        self::assertSame('Ivan Lastly', $ivan['name']);
        self::assertSame('cz', $ivan['country']);
        self::assertSame('X-12', $ivan['external_id']);
        self::assertSame('Pays at the door', $ivan['organizer_note']);

        // Sent again: there already. From a value nobody holds any more: a conflict with what is there now
        $again = $this->applySheetChanges([
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'name', 'Ivan Last', 'Ivan Lastly')),
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'name', 'Ivan Last', 'Ivan Other')),
            // Empty clears - the import never does that (D14), the sheet does
            self::sheetGroup(
                self::fieldChange(Cup::PARTICIPANT_IVAN, 'externalId', 'X-12', ''),
                self::fieldChange(Cup::PARTICIPANT_IVAN, 'country', 'cz', null),
                self::fieldChange(Cup::PARTICIPANT_IVAN, 'note', 'Pays at the door', '  '),
            ),
        ]);

        self::assertSame(['unchanged', 'conflict', 'applied'], self::groupStatuses($again));
        self::assertSame(['conflict:changed_meanwhile'], self::changeStatuses($again, 1));
        self::assertSame('Ivan Lastly', $again->groups[1]->changes[0]->current);

        $ivan = self::participantRow($this->database, Cup::PARTICIPANT_IVAN);
        self::assertSame('Ivan Lastly', $ivan['name']);
        self::assertNull($ivan['external_id']);
        self::assertNull($ivan['country']);
        self::assertNull($ivan['organizer_note']);
    }

    public function testValuesBreakingTheRulesAreRefused(): void
    {
        $applied = $this->applySheetChanges([
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'name', 'Ivan Last', '   ')),
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'name', 'Ivan Last', str_repeat('x', 256))),
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'country', 'sk', 'Narnia')),
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'externalId', null, str_repeat('1', 256))),
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'note', null, str_repeat('n', CompetitionParticipant::ORGANIZER_NOTE_MAX_LENGTH + 1))),
            self::sheetGroup(['op' => 'newTeam', 'id' => Uuid::uuid7()->toString(), 'round' => Cup::ROUND_PAIRS_FINAL, 'name' => str_repeat('t', 256)]),
        ]);

        self::assertSame(['refused', 'refused', 'refused', 'refused', 'refused', 'refused'], self::groupStatuses($applied));
        self::assertSame(
            ['name_blank', 'name_too_long', 'invalid_country', 'external_id_too_long', 'note_too_long', 'team_name_too_long'],
            array_map(static fn ($group): null|string => $group->changes[0]->reason, $applied->groups),
        );
        self::assertSame(['max' => 255], $applied->groups[1]->changes[0]->parameters);

        $ivan = self::participantRow($this->database, Cup::PARTICIPANT_IVAN);
        self::assertSame('Ivan Last', $ivan['name']);
        self::assertSame('sk', $ivan['country']);
    }

    public function testANewParticipantHasThePagesIdAndIsCreatedOnce(): void
    {
        $id = Uuid::uuid7()->toString();
        $group = self::sheetGroup(['op' => 'newParticipant', 'id' => $id, 'name' => ' Jo  Doe ', 'country' => 'us', 'externalId' => 'R-7']);

        $applied = $this->applySheetChanges([$group]);

        self::assertSame(['applied'], self::changeStatuses($applied));
        $jo = self::participantRow($this->database, $id);
        self::assertSame('Jo Doe', $jo['name']);
        self::assertSame('us', $jo['country']);
        self::assertSame('R-7', $jo['external_id']);
        self::assertSame(ParticipantSource::Manual->value, $jo['source']);
        self::assertSame(Cup::COMPETITION_RESULTS_CUP, $jo['competition_id']);

        // The same change in a new change set (its answer got lost and the receipt was pruned) creates nobody twice
        self::assertSame(['unchanged'], self::changeStatuses($this->applySheetChanges([$group])));
        self::assertSame(1, $this->database->fetchOne("SELECT COUNT(*) FROM competition_participant WHERE name = 'Jo Doe'"));
    }

    public function testANewParticipantNamedLikeSomebodyOnTheListIsWarnedAbout(): void
    {
        $applied = $this->applySheetChanges([self::sheetGroup(['op' => 'newParticipant', 'id' => Uuid::uuid7()->toString(), 'name' => 'anna  FAST'])]);

        self::assertSame(['applied'], self::groupStatuses($applied));
        $warnings = $applied->groups[0]->warnings;
        self::assertCount(1, $warnings);
        self::assertSame('same_name_as_existing', $warnings[0]->code);
        self::assertSame(['name' => 'anna FAST', 'other' => 'Anna Fast'], $warnings[0]->parameters);
    }

    public function testANewRowWithAnIdOfAnotherEventIsRefused(): void
    {
        $applied = $this->applySheetChanges([
            self::sheetGroup(['op' => 'newParticipant', 'id' => MarketplaceEventFixture::PARTICIPANT_EDITION_SELLER_A, 'name' => 'Taken Over']),
            self::sheetGroup(['op' => 'newTeam', 'id' => $this->teamOfAnotherEvent(), 'round' => Cup::ROUND_PAIRS_FINAL, 'name' => null]),
            // A pair of another round of the same event
            self::sheetGroup(['op' => 'newTeam', 'id' => Cup::TEAM_SHARKS, 'round' => Cup::ROUND_PAIRS_FINAL, 'name' => null]),
        ]);

        self::assertSame(['refused:id_taken'], self::changeStatuses($applied, 0));
        self::assertSame(['refused:id_taken'], self::changeStatuses($applied, 1));
        self::assertSame(['refused:id_taken'], self::changeStatuses($applied, 2));
        self::assertSame('Sarah Williams', self::participantRow($this->database, MarketplaceEventFixture::PARTICIPANT_EDITION_SELLER_A)['name']);
    }

    public function testAProfileIsLinkedAndUnlinked(): void
    {
        $applied = $this->applySheetChanges([
            self::sheetGroup(['op' => 'player', 'participant' => Cup::PARTICIPANT_IVAN, 'from' => null, 'to' => PlayerFixture::PLAYER_WITH_FAVORITES]),
            self::sheetGroup(['op' => 'player', 'participant' => Cup::PARTICIPANT_ANNA, 'from' => PlayerFixture::PLAYER_ADMIN, 'to' => null]),
        ]);

        self::assertSame(['applied', 'applied'], self::groupStatuses($applied));

        $ivan = self::participantRow($this->database, Cup::PARTICIPANT_IVAN);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $ivan['player_id']);
        self::assertNotNull($ivan['connected_at']);

        $anna = self::participantRow($this->database, Cup::PARTICIPANT_ANNA);
        self::assertNull($anna['player_id']);
        self::assertNull($anna['connected_at']);
    }

    public function testAProfileIsLinkedToOneActiveParticipantOfTheEventAtMost(): void
    {
        $applied = $this->applySheetChanges([
            // Gina holds PLAYER_PRIVATE
            self::sheetGroup(['op' => 'player', 'participant' => Cup::PARTICIPANT_IVAN, 'from' => null, 'to' => PlayerFixture::PLAYER_PRIVATE]),
            self::sheetGroup(['op' => 'player', 'participant' => Cup::PARTICIPANT_IVAN, 'from' => null, 'to' => Uuid::uuid7()->toString()]),
        ]);

        self::assertSame(['refused:player_linked_elsewhere'], self::changeStatuses($applied, 0));
        self::assertSame(['name' => 'Ivan Last', 'other' => 'Gina Quick'], $applied->groups[0]->changes[0]->parameters);
        self::assertSame(['refused:player_not_found'], self::changeStatuses($applied, 1));
        self::assertNull(self::participantRow($this->database, Cup::PARTICIPANT_IVAN)['player_id']);

        // Moved within one group: Gina lets go, Ivan takes it
        $moved = $this->applySheetChanges([self::sheetGroup(
            ['op' => 'player', 'participant' => Cup::PARTICIPANT_GINA, 'from' => PlayerFixture::PLAYER_PRIVATE, 'to' => null],
            ['op' => 'player', 'participant' => Cup::PARTICIPANT_IVAN, 'from' => null, 'to' => PlayerFixture::PLAYER_PRIVATE],
        )]);

        self::assertSame(['applied'], self::groupStatuses($moved));
        self::assertSame(PlayerFixture::PLAYER_PRIVATE, self::participantRow($this->database, Cup::PARTICIPANT_IVAN)['player_id']);
    }

    public function testAPersonIsPutIntoAndTakenOutOfASoloRound(): void
    {
        $applied = $this->applySheetChanges([
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_IVAN, Cup::ROUND_GROUP_A, 'out', 'in')),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_FILIP, Cup::ROUND_GROUP_A, 'in', 'out')),
        ]);

        self::assertSame(['applied', 'applied'], self::groupStatuses($applied));
        self::assertSame('in', self::storedPlace($this->database, Cup::PARTICIPANT_IVAN, Cup::ROUND_GROUP_A));
        self::assertSame('out', self::storedPlace($this->database, Cup::PARTICIPANT_FILIP, Cup::ROUND_GROUP_A));
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM competition_participant_round WHERE id = :id', ['id' => Cup::ENTRY_A_FILIP]));
    }

    public function testNobodyIsTakenOutOfARoundWhereTheyHoldAResult(): void
    {
        // Anna's player added a time in the Final to their own profile; Ben has an official result in Group A
        $this->ownTimeOfAnnaInTheFinal();

        $applied = $this->applySheetChanges([
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_ANNA, Cup::ROUND_FINAL, 'in', 'out')),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_BEN, Cup::ROUND_GROUP_A, 'in', 'out')),
        ]);

        self::assertSame(['refused:has_result_in_round'], self::changeStatuses($applied, 0));
        self::assertSame(['name' => 'Anna Fast', 'round' => 'Final'], $applied->groups[0]->changes[0]->parameters);
        // The organiser cannot clear a player's own time - its own text
        self::assertSame('participants_sheet_server.reason.has_result_in_round_own_time', $applied->groups[0]->changes[0]->messageKey());
        self::assertSame(['refused:has_result_in_round'], self::changeStatuses($applied, 1));
        self::assertSame('participants_sheet_server.reason.has_result_in_round', $applied->groups[1]->changes[0]->messageKey());
        self::assertSame('in', $applied->groups[1]->changes[0]->current);

        self::assertSame('in', self::storedPlace($this->database, Cup::PARTICIPANT_ANNA, Cup::ROUND_FINAL));
        self::assertSame('in', self::storedPlace($this->database, Cup::PARTICIPANT_BEN, Cup::ROUND_GROUP_A));
    }

    /**
     * Review A-r1: unlinking the profile and taking the person out in the same change set does not get around their
     * player's own time; an unlink saved before does - the time is not theirs any more.
     */
    public function testTheTimeOfThePlayerLinkedWhenTheChangeSetStartedKeepsThePersonIn(): void
    {
        $this->ownTimeOfAnnaInTheFinal();
        $unlink = ['op' => 'player', 'participant' => Cup::PARTICIPANT_ANNA, 'from' => PlayerFixture::PLAYER_ADMIN, 'to' => null];

        $applied = $this->applySheetChanges([
            self::sheetGroup($unlink, self::placeChange(Cup::PARTICIPANT_ANNA, Cup::ROUND_FINAL, 'in', 'out')),
            self::sheetGroup($unlink),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_ANNA, Cup::ROUND_FINAL, 'in', 'out')),
            self::sheetGroup(['op' => 'remove', 'participant' => Cup::PARTICIPANT_ANNA]),
        ]);

        self::assertSame(['refused', 'applied', 'refused', 'refused'], self::groupStatuses($applied));
        self::assertSame(['skipped', 'refused:has_result_in_round'], self::changeStatuses($applied, 0));
        self::assertSame(['refused:has_result_in_round'], self::changeStatuses($applied, 2));
        self::assertSame('participants_sheet_server.reason.has_result_in_round_own_time', $applied->groups[2]->changes[0]->messageKey());
        // Anna holds official results too - her player's own time is what the organiser is told about first
        self::assertSame(['refused:has_result_in_event'], self::changeStatuses($applied, 3));
        self::assertSame(['name' => 'Anna Fast', 'round' => 'Final'], $applied->groups[3]->changes[0]->parameters);
        self::assertSame('participants_sheet_server.reason.has_result_in_event_own_time', $applied->groups[3]->changes[0]->messageKey());
        self::assertNull(self::participantRow($this->database, Cup::PARTICIPANT_ANNA)['player_id']);

        // Unlinked in an earlier save: the time is the player's, not this participant's
        $later = $this->applySheetChanges([self::sheetGroup(self::placeChange(Cup::PARTICIPANT_ANNA, Cup::ROUND_FINAL, 'in', 'out'))]);

        self::assertSame(['applied'], self::groupStatuses($later));
        self::assertSame('out', self::storedPlace($this->database, Cup::PARTICIPANT_ANNA, Cup::ROUND_FINAL));
    }

    /**
     * Review A-r2: a pair's result belongs to its line-up - a member may leave it (out of the round, into the round
     * without a pair, into another pair) while it keeps somebody; the last one may not.
     */
    public function testAMemberMayLeaveAPairWithAResultWhileItKeepsSomebody(): void
    {
        $applied = $this->applySheetChanges([
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_CARA, Cup::ROUND_PAIRS, 'team:' . Cup::TEAM_CORNERS, 'out')),
        ]);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertContains('team_result_line_up_changed', array_map(static fn ($warning): string => $warning->code, $applied->groups[0]->warnings));
        self::assertSame('out', self::storedPlace($this->database, Cup::PARTICIPANT_CARA, Cup::ROUND_PAIRS));

        $last = $this->applySheetChanges([
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_DAN, Cup::ROUND_PAIRS, 'team:' . Cup::TEAM_CORNERS, 'out')),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_DAN, Cup::ROUND_PAIRS, 'team:' . Cup::TEAM_CORNERS, 'in')),
        ]);

        self::assertSame(['refused:team_has_result'], self::changeStatuses($last, 0));
        self::assertSame(['refused:team_has_result'], self::changeStatuses($last, 1));
        self::assertSame(['team' => 'Corner Pieces', 'round' => 'Pairs'], $last->groups[0]->changes[0]->parameters);
        self::assertSame('participants_sheet_server.reason.team_has_result_emptied', $last->groups[0]->changes[0]->messageKey());
        self::assertSame('team:' . Cup::TEAM_CORNERS, self::storedPlace($this->database, Cup::PARTICIPANT_DAN, Cup::ROUND_PAIRS));
    }

    /**
     * Review A-r3: on an event managing registration, people on the waitlist are placed for when they get a spot - a
     * pair with a result left with only them has nobody taking part.
     */
    public function testAPairWithAResultKeepsSomebodyWhoIsNotOnTheWaitlist(): void
    {
        $this->database->executeStatement("UPDATE competition_participant SET registration_status = 'waitlisted', registered_at = NOW() WHERE id = :id", ['id' => Cup::PARTICIPANT_DAN]);
        $caraLeaves = self::sheetGroup(self::placeChange(Cup::PARTICIPANT_CARA, Cup::ROUND_PAIRS, 'team:' . Cup::TEAM_CORNERS, 'in'));

        $this->database->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => Cup::COMPETITION_RESULTS_CUP]);
        $managed = $this->applySheetChanges([$caraLeaves]);

        self::assertSame(['refused:team_has_result'], self::changeStatuses($managed));
        self::assertSame('participants_sheet_server.reason.team_has_result_waitlisted_only', $managed->groups[0]->changes[0]->messageKey());
        self::assertSame('team:' . Cup::TEAM_CORNERS, self::storedPlace($this->database, Cup::PARTICIPANT_CARA, Cup::ROUND_PAIRS));

        // An event that does not manage registration has no waitlist - a status left from before counts for nothing
        $this->database->executeStatement('UPDATE competition SET registration_managed = false WHERE id = :id', ['id' => Cup::COMPETITION_RESULTS_CUP]);
        $unmanaged = $this->applySheetChanges([$caraLeaves]);

        self::assertSame(['applied'], self::groupStatuses($unmanaged));
        self::assertSame('in', self::storedPlace($this->database, Cup::PARTICIPANT_CARA, Cup::ROUND_PAIRS));
    }

    /**
     * Review A-r6: an external id is one participant's - removed ones' too (a restore brings them back with it), the
     * import's rule (ParticipantRules::externalIdTakenBy()).
     */
    public function testAnExternalIdBelongsToOneParticipant(): void
    {
        $removed = $this->participant('Gone Person', removed: true);
        $this->database->executeStatement("UPDATE competition_participant SET external_id = 'R-9' WHERE id = :id", ['id' => $removed]);

        $applied = $this->applySheetChanges([
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'externalId', null, 'R-1')),
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_FILIP, 'externalId', null, ' R-1 ')),
            self::sheetGroup(['op' => 'newParticipant', 'id' => Uuid::uuid7()->toString(), 'name' => 'Jo Doe', 'externalId' => 'R-1']),
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_FILIP, 'externalId', null, 'R-9')),
            // Handed over within one group: let go first
            self::sheetGroup(
                self::fieldChange(Cup::PARTICIPANT_IVAN, 'externalId', 'R-1', null),
                self::fieldChange(Cup::PARTICIPANT_EVA, 'externalId', null, 'R-1'),
            ),
        ]);

        self::assertSame(['applied', 'refused', 'refused', 'refused', 'applied'], self::groupStatuses($applied));
        self::assertSame(['refused:external_id_taken'], self::changeStatuses($applied, 1));
        self::assertSame(['id' => 'R-1', 'other' => 'Ivan Last'], $applied->groups[1]->changes[0]->parameters);
        self::assertNull($applied->groups[1]->changes[0]->current);
        self::assertSame(['refused:external_id_taken'], self::changeStatuses($applied, 2));
        self::assertSame(['id' => 'R-9', 'other' => 'Gone Person'], $applied->groups[3]->changes[0]->parameters);

        self::assertNull(self::participantRow($this->database, Cup::PARTICIPANT_IVAN)['external_id']);
        self::assertNull(self::participantRow($this->database, Cup::PARTICIPANT_FILIP)['external_id']);
        self::assertSame('R-1', self::participantRow($this->database, Cup::PARTICIPANT_EVA)['external_id']);
        self::assertSame(0, $this->database->fetchOne("SELECT COUNT(*) FROM competition_participant WHERE name = 'Jo Doe'"));
    }

    /**
     * Review A-r7: a pair/team the change set creates and leaves without a name and without anybody is never created.
     */
    public function testAnUnnamedPairTheChangeSetCreatesAndEmptiesIsNeverCreated(): void
    {
        $emptied = Uuid::uuid7()->toString();
        $alone = Uuid::uuid7()->toString();
        $filled = Uuid::uuid7()->toString();
        $versionBefore = $this->version();

        $applied = $this->applySheetChanges([
            self::sheetGroup(
                ['op' => 'newTeam', 'id' => $emptied, 'round' => Cup::ROUND_PAIRS, 'name' => null],
                // Eva moves from her pair into the new one and back
                self::placeChange(Cup::PARTICIPANT_EVA, Cup::ROUND_PAIRS, 'team:' . Cup::TEAM_UNNAMED, 'team:' . $emptied),
                self::placeChange(Cup::PARTICIPANT_EVA, Cup::ROUND_PAIRS, 'team:' . $emptied, 'team:' . Cup::TEAM_UNNAMED),
            ),
            self::sheetGroup(['op' => 'newTeam', 'id' => $alone, 'round' => Cup::ROUND_PAIRS_FINAL, 'name' => null]),
        ]);

        self::assertSame(['applied', 'applied'], self::groupStatuses($applied));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM competition_team WHERE id IN (:a, :b)', ['a' => $emptied, 'b' => $alone]));
        self::assertSame('team:' . Cup::TEAM_UNNAMED, self::storedPlace($this->database, Cup::PARTICIPANT_EVA, Cup::ROUND_PAIRS));
        self::assertSame($versionBefore, $applied->versionAfter);

        // Filled in a later group of the same change set: created
        $later = $this->applySheetChanges([
            self::sheetGroup(['op' => 'newTeam', 'id' => $filled, 'round' => Cup::ROUND_PAIRS_FINAL, 'name' => null]),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_IVAN, Cup::ROUND_PAIRS_FINAL, 'out', 'team:' . $filled)),
        ]);

        self::assertSame(['applied', 'applied'], self::groupStatuses($later));
        self::assertSame('team:' . $filled, self::storedPlace($this->database, Cup::PARTICIPANT_IVAN, Cup::ROUND_PAIRS_FINAL));
    }

    /**
     * Review A-r9: whether the event manages registration is read under the event's lock - not from an entity loaded
     * before it (the controller's, still in the identity map).
     */
    public function testRegistrationManagementIsReadUnderTheLock(): void
    {
        $waiting = $this->participant('Waiting Removed', removed: true, status: RegistrationStatus::Waitlisted);

        // Loaded while the event did not manage registration; switched on meanwhile
        $competition = $this->entityManager->find(Competition::class, Cup::COMPETITION_RESULTS_CUP);
        self::assertNotNull($competition);
        self::assertFalse($competition->registrationManaged);
        $this->database->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => Cup::COMPETITION_RESULTS_CUP]);

        $applied = $this->applySheetChanges([self::sheetGroup(['op' => 'restore', 'participant' => $waiting])]);

        self::assertSame(['applied'], self::groupStatuses($applied));
        // A managed event keeps its waitlist
        self::assertSame(RegistrationStatus::Waitlisted->value, self::participantRow($this->database, $waiting)['registration_status']);
    }

    public function testTakenOutAndPutBackIsNoChangeAtAll(): void
    {
        $versionBefore = $this->version();

        $applied = $this->applySheetChanges([
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_FILIP, Cup::ROUND_GROUP_A, 'in', 'out')),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_FILIP, Cup::ROUND_GROUP_A, 'out', 'in')),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_EVA, Cup::ROUND_PAIRS, 'team:' . Cup::TEAM_UNNAMED, 'in')),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_EVA, Cup::ROUND_PAIRS, 'in', 'team:' . Cup::TEAM_UNNAMED)),
        ]);

        self::assertSame(['applied', 'applied', 'applied', 'applied'], self::groupStatuses($applied));
        // The same entry, never deleted and created again (one entry per person and round)
        self::assertSame(1, $this->database->fetchOne('SELECT 1 FROM competition_participant_round WHERE id = :id', ['id' => Cup::ENTRY_A_FILIP]));
        self::assertSame($versionBefore, $applied->versionBefore);
        self::assertSame($versionBefore, $applied->versionAfter);
        self::assertFalse($applied->changedTheSheet());
    }

    public function testMembersMoveBetweenPairsAndTheResultsLineUpIsPointedOut(): void
    {
        $applied = $this->applySheetChanges([
            // Ivan joins the unnamed pair - three people in a pair
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_IVAN, Cup::ROUND_PAIRS, 'out', 'team:' . Cup::TEAM_UNNAMED)),
            // Dan leaves Corner Pieces (a result) for the round's tray
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_DAN, Cup::ROUND_PAIRS, 'team:' . Cup::TEAM_CORNERS, 'in')),
        ]);

        self::assertSame(['applied', 'applied'], self::groupStatuses($applied));
        self::assertSame('team:' . Cup::TEAM_UNNAMED, self::storedPlace($this->database, Cup::PARTICIPANT_IVAN, Cup::ROUND_PAIRS));
        self::assertSame('in', self::storedPlace($this->database, Cup::PARTICIPANT_DAN, Cup::ROUND_PAIRS));

        $sizeWarning = $applied->groups[0]->warnings[0];
        self::assertSame('team_size_off', $sizeWarning->code);
        self::assertSame(Cup::TEAM_UNNAMED, $sizeWarning->teamId);
        self::assertSame(['team' => 'Eva Noshow, Filip Pending, Ivan Last', 'round' => 'Pairs', 'count' => 3, 'expected' => 2], $sizeWarning->parameters);

        $codes = array_map(static fn ($warning): string => $warning->code, $applied->groups[1]->warnings);
        self::assertSame(['team_result_line_up_changed', 'team_size_off'], $codes);
    }

    public function testAnUnnamedPairEmptiedByTheSheetIsDeletedWithIt(): void
    {
        $applied = $this->applySheetChanges([self::sheetGroup(
            self::placeChange(Cup::PARTICIPANT_EVA, Cup::ROUND_PAIRS, 'team:' . Cup::TEAM_UNNAMED, 'in'),
            self::placeChange(Cup::PARTICIPANT_FILIP, Cup::ROUND_PAIRS, 'team:' . Cup::TEAM_UNNAMED, 'out'),
        )]);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertSame([Cup::TEAM_UNNAMED], $applied->groups[0]->deletedTeams);
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM competition_team WHERE id = :id', ['id' => Cup::TEAM_UNNAMED]));
        self::assertSame('in', self::storedPlace($this->database, Cup::PARTICIPANT_EVA, Cup::ROUND_PAIRS));
        self::assertSame('out', self::storedPlace($this->database, Cup::PARTICIPANT_FILIP, Cup::ROUND_PAIRS));
    }

    public function testANamedPairMadeInAdvanceStaysWhenEmptied(): void
    {
        $teamId = Uuid::uuid7()->toString();

        $applied = $this->applySheetChanges([
            self::sheetGroup(
                ['op' => 'newTeam', 'id' => $teamId, 'round' => Cup::ROUND_PAIRS_FINAL, 'name' => 'Late Birds'],
                self::placeChange(Cup::PARTICIPANT_IVAN, Cup::ROUND_PAIRS_FINAL, 'out', 'team:' . $teamId),
            ),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_IVAN, Cup::ROUND_PAIRS_FINAL, 'team:' . $teamId, 'in')),
        ]);

        self::assertSame(['applied', 'applied'], self::groupStatuses($applied));
        self::assertSame([], $applied->groups[1]->deletedTeams);
        self::assertSame('Late Birds', $this->database->fetchOne('SELECT name FROM competition_team WHERE id = :id', ['id' => $teamId]));
    }

    public function testAPairWithAResultIsNeverEmptiedNorDeleted(): void
    {
        $applied = $this->applySheetChanges([
            self::sheetGroup(
                self::placeChange(Cup::PARTICIPANT_CARA, Cup::ROUND_PAIRS, 'team:' . Cup::TEAM_CORNERS, 'in'),
                self::placeChange(Cup::PARTICIPANT_DAN, Cup::ROUND_PAIRS, 'team:' . Cup::TEAM_CORNERS, 'in'),
            ),
            self::sheetGroup(['op' => 'deleteTeam', 'team' => Cup::TEAM_SHARKS]),
        ]);

        self::assertSame(['refused', 'refused'], self::groupStatuses($applied));
        // The change that emptied it is refused, the one before it would have gone through
        self::assertSame(['skipped', 'refused:team_has_result'], self::changeStatuses($applied, 0));
        self::assertSame(['team' => 'Corner Pieces', 'round' => 'Pairs'], $applied->groups[0]->changes[1]->parameters);
        self::assertSame('team:' . Cup::TEAM_CORNERS, $applied->groups[0]->changes[0]->current);
        self::assertSame(['refused:team_has_result'], self::changeStatuses($applied, 1));

        self::assertSame('team:' . Cup::TEAM_CORNERS, self::storedPlace($this->database, Cup::PARTICIPANT_CARA, Cup::ROUND_PAIRS));
        self::assertSame('team:' . Cup::TEAM_CORNERS, self::storedPlace($this->database, Cup::PARTICIPANT_DAN, Cup::ROUND_PAIRS));
        self::assertSame(1, $this->database->fetchOne('SELECT 1 FROM competition_team WHERE id = :id', ['id' => Cup::TEAM_SHARKS]));
    }

    public function testAPairEmptiedByRemovingItsPeopleStays(): void
    {
        $teamId = Uuid::uuid7()->toString();
        $kim = Uuid::uuid7()->toString();
        $pat = Uuid::uuid7()->toString();

        $applied = $this->applySheetChanges([
            self::sheetGroup(
                ['op' => 'newTeam', 'id' => $teamId, 'round' => Cup::ROUND_PAIRS_FINAL, 'name' => null],
                ['op' => 'newParticipant', 'id' => $kim, 'name' => 'Kim Example'],
                ['op' => 'newParticipant', 'id' => $pat, 'name' => 'Pat Sample'],
                self::placeChange($kim, Cup::ROUND_PAIRS_FINAL, 'out', 'team:' . $teamId),
                self::placeChange($pat, Cup::ROUND_PAIRS_FINAL, 'out', 'team:' . $teamId),
            ),
            // Removed people keep their places (a restore brings them back) - so the pair stays too
            self::sheetGroup(
                ['op' => 'remove', 'participant' => $kim],
                ['op' => 'remove', 'participant' => $pat],
            ),
        ]);

        self::assertSame(['applied', 'applied'], self::groupStatuses($applied));
        self::assertSame([], $applied->groups[1]->deletedTeams);
        self::assertSame(1, $this->database->fetchOne('SELECT 1 FROM competition_team WHERE id = :id', ['id' => $teamId]));
        self::assertNotNull(self::participantRow($this->database, $kim)['deleted_at']);
        self::assertSame('team:' . $teamId, self::storedPlace($this->database, $kim, Cup::ROUND_PAIRS_FINAL));
    }

    public function testSomebodyWithADidNotStartIsNotRemoved(): void
    {
        // Eva did not start in Group A - an official result too
        $applied = $this->applySheetChanges([self::sheetGroup(['op' => 'remove', 'participant' => Cup::PARTICIPANT_EVA])]);

        self::assertSame(['refused:has_result_in_event'], self::changeStatuses($applied));
        self::assertSame(['name' => 'Eva Noshow', 'round' => 'Group A'], $applied->groups[0]->changes[0]->parameters);
        self::assertSame('participants_sheet_server.reason.has_result_in_event', $applied->groups[0]->changes[0]->messageKey());
        self::assertNull(self::participantRow($this->database, Cup::PARTICIPANT_EVA)['deleted_at']);
    }

    public function testDeletingAPairKeepsItsPeopleInTheRound(): void
    {
        $applied = $this->applySheetChanges([
            self::sheetGroup(['op' => 'deleteTeam', 'team' => Cup::TEAM_UNNAMED]),
            // Gone already (another organiser, a resend)
            self::sheetGroup(['op' => 'deleteTeam', 'team' => Uuid::uuid7()->toString()]),
            // A pair of another event - never touched
            self::sheetGroup(['op' => 'deleteTeam', 'team' => $this->teamOfAnotherEvent()]),
        ]);

        self::assertSame(['applied', 'unchanged', 'refused'], self::groupStatuses($applied));
        self::assertSame(['refused:team_not_found'], self::changeStatuses($applied, 2));
        self::assertSame([], $applied->groups[0]->deletedTeams, 'Only the teams the sheet deletes by itself are listed');
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM competition_team WHERE id = :id', ['id' => Cup::TEAM_UNNAMED]));
        self::assertSame('in', self::storedPlace($this->database, Cup::PARTICIPANT_EVA, Cup::ROUND_PAIRS));
        self::assertSame('in', self::storedPlace($this->database, Cup::PARTICIPANT_FILIP, Cup::ROUND_PAIRS));
    }

    public function testATypedPairIsCreatedWithItsPeopleAndNamesAreCheckedPerRound(): void
    {
        $teamId = Uuid::uuid7()->toString();
        $joId = Uuid::uuid7()->toString();

        $applied = $this->applySheetChanges([self::sheetGroup(
            ['op' => 'newTeam', 'id' => $teamId, 'round' => Cup::ROUND_PAIRS, 'name' => 'puzzle  SHARKS'],
            ['op' => 'newParticipant', 'id' => $joId, 'name' => 'Jo Doe', 'country' => null],
            self::placeChange($joId, Cup::ROUND_PAIRS, 'out', 'team:' . $teamId),
            self::placeChange(Cup::PARTICIPANT_IVAN, Cup::ROUND_PAIRS, 'out', 'team:' . $teamId),
        )]);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertSame('puzzle SHARKS', $this->database->fetchOne('SELECT name FROM competition_team WHERE id = :id', ['id' => $teamId]));
        self::assertSame('team:' . $teamId, self::storedPlace($this->database, $joId, Cup::ROUND_PAIRS));
        self::assertSame('team:' . $teamId, self::storedPlace($this->database, Cup::PARTICIPANT_IVAN, Cup::ROUND_PAIRS));
        self::assertSame(['team_name_shared'], array_map(static fn ($warning): string => $warning->code, $applied->groups[0]->warnings));

        // Sent again: nothing twice
        $again = $this->applySheetChanges([self::sheetGroup(['op' => 'newTeam', 'id' => $teamId, 'round' => Cup::ROUND_PAIRS, 'name' => 'puzzle SHARKS'])]);
        self::assertSame(['unchanged'], self::groupStatuses($again));
    }

    public function testTeamsBelongToPairAndTeamRoundsOnly(): void
    {
        $applied = $this->applySheetChanges([
            self::sheetGroup(['op' => 'newTeam', 'id' => Uuid::uuid7()->toString(), 'round' => Cup::ROUND_GROUP_A, 'name' => 'Solo Pair']),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_IVAN, Cup::ROUND_GROUP_A, 'out', 'team:' . Cup::TEAM_SHARKS)),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_IVAN, Cup::ROUND_PAIRS_FINAL, 'out', 'team:' . Cup::TEAM_SHARKS)),
            self::sheetGroup(['op' => 'teamSize', 'round' => Cup::ROUND_PAIRS, 'from' => null, 'to' => 3]),
        ]);

        self::assertSame(['refused:not_a_team_round'], self::changeStatuses($applied, 0));
        self::assertSame(['refused:not_a_team_round'], self::changeStatuses($applied, 1));
        self::assertSame(['refused:team_of_another_round'], self::changeStatuses($applied, 2));
        self::assertSame(['team' => 'Puzzle Sharks', 'round' => 'Pairs'], $applied->groups[2]->changes[0]->parameters);
        self::assertSame(['refused:not_a_team_round'], self::changeStatuses($applied, 3));
    }

    public function testATeamIsRenamedThreeWay(): void
    {
        $applied = $this->applySheetChanges([
            self::sheetGroup(['op' => 'renameTeam', 'team' => Cup::TEAM_CORNERS, 'from' => 'Corner Pieces', 'to' => ' Corners ']),
            self::sheetGroup(['op' => 'renameTeam', 'team' => Cup::TEAM_EDGES, 'from' => 'Old Name', 'to' => 'Edges']),
            self::sheetGroup(['op' => 'renameTeam', 'team' => Cup::TEAM_SHARKS, 'from' => 'Puzzle Sharks', 'to' => null]),
        ]);

        self::assertSame(['applied', 'conflict', 'applied'], self::groupStatuses($applied));
        self::assertSame('Edge Hunters', $applied->groups[1]->changes[0]->current);
        self::assertSame('Corners', $this->database->fetchOne('SELECT name FROM competition_team WHERE id = :id', ['id' => Cup::TEAM_CORNERS]));
        self::assertSame('Edge Hunters', $this->database->fetchOne('SELECT name FROM competition_team WHERE id = :id', ['id' => Cup::TEAM_EDGES]));
        self::assertNull($this->database->fetchOne('SELECT name FROM competition_team WHERE id = :id', ['id' => Cup::TEAM_SHARKS]));
    }

    public function testRemoveAndRestore(): void
    {
        $removed = $this->applySheetChanges([
            self::sheetGroup(['op' => 'remove', 'participant' => Cup::PARTICIPANT_FILIP]),
            // Ivan has an official result in Group B
            self::sheetGroup(['op' => 'remove', 'participant' => Cup::PARTICIPANT_IVAN]),
        ]);

        self::assertSame(['applied', 'refused'], self::groupStatuses($removed));
        self::assertSame(['refused:has_result_in_event'], self::changeStatuses($removed, 1));
        self::assertNotNull(self::participantRow($this->database, Cup::PARTICIPANT_FILIP)['deleted_at']);
        self::assertNull(self::participantRow($this->database, Cup::PARTICIPANT_IVAN)['deleted_at']);
        // Removed softly - still in the rounds, so a restore brings everything back
        self::assertSame('in', self::storedPlace($this->database, Cup::PARTICIPANT_FILIP, Cup::ROUND_GROUP_A));

        $again = $this->applySheetChanges([
            self::sheetGroup(['op' => 'remove', 'participant' => Cup::PARTICIPANT_FILIP]),
            // A removed person is restored first, never changed
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_FILIP, 'name', 'Filip Pending', 'Filip Changed')),
            self::sheetGroup(['op' => 'restore', 'participant' => Cup::PARTICIPANT_FILIP]),
            self::sheetGroup(['op' => 'restore', 'participant' => Cup::PARTICIPANT_FILIP]),
        ]);

        self::assertSame(['unchanged', 'refused', 'applied', 'unchanged'], self::groupStatuses($again));
        self::assertSame(['refused:participant_removed'], self::changeStatuses($again, 1));
        self::assertNull(self::participantRow($this->database, Cup::PARTICIPANT_FILIP)['deleted_at']);
    }

    public function testRemovingASelfJoinedPersonMakesTheRowTheOrganisers(): void
    {
        $id = $this->participant('Self Joiner', ParticipantSource::SelfJoined);

        $applied = $this->applySheetChanges([self::sheetGroup(['op' => 'remove', 'participant' => $id])]);

        self::assertSame(['applied'], self::groupStatuses($applied));
        $row = self::participantRow($this->database, $id);
        self::assertNotNull($row['deleted_at']);
        self::assertSame(ParticipantSource::Imported->value, $row['source']);
    }

    public function testARestoreNeverLinksAProfileTwiceAndLeavesTheWaitlistOfAnUnmanagedEvent(): void
    {
        $linked = $this->participant('Linked Before', player: PlayerFixture::PLAYER_WITH_FAVORITES, removed: true);
        $waiting = $this->participant('Waiting Once', removed: true, status: RegistrationStatus::Waitlisted);

        $applied = $this->applySheetChanges([
            self::sheetGroup(['op' => 'player', 'participant' => Cup::PARTICIPANT_IVAN, 'from' => null, 'to' => PlayerFixture::PLAYER_WITH_FAVORITES]),
            self::sheetGroup(['op' => 'restore', 'participant' => $linked]),
            self::sheetGroup(['op' => 'restore', 'participant' => $waiting]),
        ]);

        self::assertSame(['applied', 'refused', 'applied'], self::groupStatuses($applied));
        self::assertSame(['refused:player_linked_elsewhere'], self::changeStatuses($applied, 1));
        self::assertNotNull(self::participantRow($this->database, $linked)['deleted_at']);
        self::assertSame(RegistrationStatus::Reserved->value, self::participantRow($this->database, $waiting)['registration_status']);
    }

    public function testAWaitlistedPersonPlacedIsPointedOut(): void
    {
        $this->database->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => Cup::COMPETITION_RESULTS_CUP]);
        $waiting = $this->participant('Waiting Long', status: RegistrationStatus::Waitlisted);

        $applied = $this->applySheetChanges([self::sheetGroup(self::placeChange($waiting, Cup::ROUND_FINAL, 'out', 'in'))]);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertSame(['waitlisted_member'], array_map(static fn ($warning): string => $warning->code, $applied->groups[0]->warnings));
        self::assertSame('in', self::storedPlace($this->database, $waiting, Cup::ROUND_FINAL));
    }

    public function testTheExpectedTeamSizeOfATeamRound(): void
    {
        $roundId = $this->teamRound();

        $applied = $this->applySheetChanges([
            self::sheetGroup(['op' => 'teamSize', 'round' => $roundId, 'from' => null, 'to' => 4]),
            self::sheetGroup(['op' => 'teamSize', 'round' => $roundId, 'from' => null, 'to' => 5]),
            self::sheetGroup(['op' => 'teamSize', 'round' => $roundId, 'from' => 4, 'to' => 21]),
            self::sheetGroup(['op' => 'teamSize', 'round' => CompetitionSeriesFixture::ROUND_OFFLINE_TEAM, 'from' => null, 'to' => 3]),
        ]);

        self::assertSame(['applied', 'conflict', 'refused', 'refused'], self::groupStatuses($applied));
        self::assertSame(4, $applied->groups[1]->changes[0]->current);
        self::assertSame(['refused:invalid_team_size'], self::changeStatuses($applied, 2));
        self::assertSame(['refused:round_not_found'], self::changeStatuses($applied, 3));
        self::assertSame(4, $this->database->fetchOne('SELECT team_size FROM competition_round WHERE id = :id', ['id' => $roundId]));
        self::assertNull($this->database->fetchOne('SELECT team_size FROM competition_round WHERE id = :id', ['id' => CompetitionSeriesFixture::ROUND_OFFLINE_TEAM]));
    }

    public function testATeamOfAnotherSizeThanExpectedIsPointedOut(): void
    {
        $roundId = $this->teamRound(teamSize: 3);
        $teamId = Uuid::uuid7()->toString();

        $applied = $this->applySheetChanges([self::sheetGroup(
            ['op' => 'newTeam', 'id' => $teamId, 'round' => $roundId, 'name' => 'Four Corners'],
            self::placeChange(Cup::PARTICIPANT_IVAN, $roundId, 'out', 'team:' . $teamId),
            self::placeChange(Cup::PARTICIPANT_FILIP, $roundId, 'out', 'team:' . $teamId),
        )]);

        self::assertSame(['applied'], self::groupStatuses($applied));
        $warning = $applied->groups[0]->warnings[0];
        self::assertSame('team_size_off', $warning->code);
        self::assertSame(['team' => 'Four Corners', 'round' => 'Teams', 'count' => 2, 'expected' => 3], $warning->parameters);
    }

    public function testAGroupIsAllOrNothing(): void
    {
        $applied = $this->applySheetChanges([
            self::sheetGroup(
                self::fieldChange(Cup::PARTICIPANT_IVAN, 'name', 'Ivan Last', 'Ivan Renamed'),
                self::placeChange(Cup::PARTICIPANT_IVAN, Cup::ROUND_FINAL, 'out', 'in'),
                // Ben holds a result in Group A
                self::placeChange(Cup::PARTICIPANT_BEN, Cup::ROUND_GROUP_A, 'in', 'out'),
                self::fieldChange(Cup::PARTICIPANT_BEN, 'country', 'de', 'de'),
            ),
            // An independent group goes through
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_FILIP, 'country', 'cz', 'sk')),
        ]);

        self::assertSame(['refused', 'applied'], self::groupStatuses($applied));
        self::assertSame(['skipped', 'skipped', 'refused:has_result_in_round', 'unchanged'], self::changeStatuses($applied, 0));
        // Skipped changes say what the server holds - nothing of the group
        self::assertSame('Ivan Last', $applied->groups[0]->changes[0]->current);
        self::assertSame('out', $applied->groups[0]->changes[1]->current);

        self::assertSame('Ivan Last', self::participantRow($this->database, Cup::PARTICIPANT_IVAN)['name']);
        self::assertSame('out', self::storedPlace($this->database, Cup::PARTICIPANT_IVAN, Cup::ROUND_FINAL));
        self::assertSame('sk', self::participantRow($this->database, Cup::PARTICIPANT_FILIP)['country']);
    }

    public function testAConflictMakesTheGroupAConflict(): void
    {
        $applied = $this->applySheetChanges([self::sheetGroup(
            self::fieldChange(Cup::PARTICIPANT_IVAN, 'name', 'Ivan Last', 'Ivan Renamed'),
            self::fieldChange(Cup::PARTICIPANT_BEN, 'name', 'Ben Somebody', 'Ben Renamed'),
        )]);

        self::assertSame(['conflict'], self::groupStatuses($applied));
        self::assertSame(['skipped', 'conflict:changed_meanwhile'], self::changeStatuses($applied));
        self::assertSame('Ben Steady', $applied->groups[0]->changes[1]->current);
        self::assertSame('Ivan Last', self::participantRow($this->database, Cup::PARTICIPANT_IVAN)['name']);
    }

    /**
     * IDOR: everything a change names must be of the event the caller was authorised on - anything else is refused and
     * never touched.
     */
    public function testNothingOfAnotherEventIsEverTouched(): void
    {
        $otherParticipant = MarketplaceEventFixture::PARTICIPANT_EDITION_SELLER_A;
        $otherTeam = $this->teamOfAnotherEvent();

        $applied = $this->applySheetChanges([
            self::sheetGroup(self::fieldChange($otherParticipant, 'name', 'Sarah Williams', 'Hijacked')),
            self::sheetGroup(['op' => 'player', 'participant' => $otherParticipant, 'from' => PlayerFixture::PLAYER_WITH_STRIPE, 'to' => null]),
            self::sheetGroup(['op' => 'remove', 'participant' => $otherParticipant]),
            self::sheetGroup(self::placeChange($otherParticipant, Cup::ROUND_GROUP_A, 'out', 'in')),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_IVAN, CompetitionSeriesFixture::ROUND_OFFLINE_SOLO, 'out', 'in')),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_IVAN, Cup::ROUND_PAIRS_FINAL, 'out', 'team:' . $otherTeam)),
            self::sheetGroup(['op' => 'renameTeam', 'team' => $otherTeam, 'from' => 'Elsewhere', 'to' => 'Hijacked']),
            self::sheetGroup(['op' => 'newTeam', 'id' => Uuid::uuid7()->toString(), 'round' => CompetitionSeriesFixture::ROUND_OFFLINE_TEAM, 'name' => 'Hijackers']),
        ]);

        self::assertSame(
            ['participant_not_found', 'participant_not_found', 'participant_not_found', 'participant_not_found', 'round_not_found', 'team_not_found', 'team_not_found', 'round_not_found'],
            array_map(static fn ($group): null|string => $group->changes[0]->reason, $applied->groups),
        );

        $other = self::participantRow($this->database, $otherParticipant);
        self::assertSame('Sarah Williams', $other['name']);
        self::assertNull($other['deleted_at']);
        self::assertNotNull($other['player_id']);
        self::assertSame('out', self::storedPlace($this->database, $otherParticipant, Cup::ROUND_GROUP_A));
        self::assertSame('out', self::storedPlace($this->database, Cup::PARTICIPANT_IVAN, CompetitionSeriesFixture::ROUND_OFFLINE_SOLO));
        self::assertSame('Elsewhere', $this->database->fetchOne('SELECT name FROM competition_team WHERE id = :id', ['id' => $otherTeam]));
        self::assertSame(0, $this->database->fetchOne("SELECT COUNT(*) FROM competition_team WHERE name = 'Hijackers'"));
    }

    public function testAChangeSetSentAgainIsAnsweredFromItsReceipt(): void
    {
        $changesetId = Uuid::uuid7()->toString();

        $first = $this->applySheetChanges([self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'name', 'Ivan Last', 'Ivan Second'))], changesetId: $changesetId);

        // The organiser corrected it back meanwhile - the lost answer's resend must not bring "Ivan Second" back
        $this->applySheetChanges([self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'name', 'Ivan Second', 'Ivan Last'))]);

        $replayed = $this->applySheetChanges([self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'name', 'Ivan Last', 'Ivan Second'))], changesetId: $changesetId);

        self::assertTrue($replayed->replayed);
        self::assertFalse($replayed->changedTheSheet());
        self::assertSame($first->versionBefore, $replayed->versionBefore);
        self::assertSame($first->versionAfter, $replayed->versionAfter);
        self::assertEquals($first->groups, $replayed->groups);
        self::assertSame('Ivan Last', self::participantRow($this->database, Cup::PARTICIPANT_IVAN)['name']);

        $receipt = $this->database->fetchAssociative('SELECT * FROM participant_sheet_change_receipt WHERE id = :id', ['id' => $changesetId]);
        self::assertIsArray($receipt);
        self::assertSame(Cup::COMPETITION_RESULTS_CUP, $receipt['competition_id']);
        self::assertSame($first->versionAfter, $receipt['version_after']);
    }

    public function testAChangeSetIdOfAnotherEventIsRefused(): void
    {
        $changesetId = Uuid::uuid7()->toString();
        $this->applySheetChanges(
            [self::sheetGroup(['op' => 'newParticipant', 'id' => Uuid::uuid7()->toString(), 'name' => 'Elsewhere Person'])],
            changesetId: $changesetId,
            competitionId: CompetitionSeriesFixture::EDITION_OFFLINE_1,
        );

        $this->expectException(SheetChangesetIdTaken::class);

        $this->applySheetChanges([self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'name', 'Ivan Last', 'Ivan Second'))], changesetId: $changesetId);
    }

    public function testADryRunAnswersEverythingAndWritesNothing(): void
    {
        $changesetId = Uuid::uuid7()->toString();
        $version = $this->version();

        $applied = $this->applySheetChanges([
            self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'name', 'Ivan Last', 'Ivan Dry')),
            self::sheetGroup(self::placeChange(Cup::PARTICIPANT_BEN, Cup::ROUND_GROUP_A, 'in', 'out')),
        ], dryRun: true, changesetId: $changesetId);

        self::assertTrue($applied->dryRun);
        self::assertSame(['applied', 'refused'], self::groupStatuses($applied));
        self::assertSame($version, $applied->versionBefore);
        self::assertSame($version, $applied->versionAfter);
        self::assertSame('Ivan Last', self::participantRow($this->database, Cup::PARTICIPANT_IVAN)['name']);
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM participant_sheet_change_receipt WHERE id = :id', ['id' => $changesetId]));
        self::assertSame($version, $this->version());
    }

    public function testTheVersionsAreTheSheetsBeforeAndAfterTheWrite(): void
    {
        $before = $this->version();

        $applied = $this->applySheetChanges([self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'note', null, 'Brings a friend'))]);

        self::assertSame($before, $applied->versionBefore);
        self::assertSame($this->version(), $applied->versionAfter);
        self::assertNotSame($before, $applied->versionAfter);
        self::assertTrue($applied->changedTheSheet());
    }

    private function ownTimeOfAnnaInTheFinal(): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_round_id = :round WHERE id = (SELECT id FROM puzzle_solving_time WHERE player_id = :player ORDER BY id LIMIT 1)',
            ['round' => Cup::ROUND_FINAL, 'player' => PlayerFixture::PLAYER_ADMIN],
        );
    }

    private function version(): string
    {
        return self::getContainer()->get(GetParticipantsSheetVersion::class)->ofCompetition(Cup::COMPETITION_RESULTS_CUP);
    }

    private function participant(
        string $name,
        ParticipantSource $source = ParticipantSource::Manual,
        null|string $player = null,
        bool $removed = false,
        null|RegistrationStatus $status = null,
    ): string {
        $competition = $this->entityManager->find(Competition::class, Cup::COMPETITION_RESULTS_CUP);
        assert($competition !== null);

        $participant = new CompetitionParticipant(Uuid::uuid7(), $name, null, $competition, $source);

        if ($player !== null) {
            $playerEntity = $this->entityManager->find(Player::class, $player);
            assert($playerEntity !== null);
            $participant->connect($playerEntity, new DateTimeImmutable('-1 day'));
        }

        if ($status !== null) {
            $participant->register($status, new DateTimeImmutable('-2 days'));
        }

        if ($removed) {
            $participant->softDelete(new DateTimeImmutable('-1 day'));
        }

        $this->entityManager->persist($participant);
        $this->entityManager->flush();

        return $participant->id->toString();
    }

    private function teamRound(null|int $teamSize = null): string
    {
        $competition = $this->entityManager->find(Competition::class, Cup::COMPETITION_RESULTS_CUP);
        assert($competition !== null);

        $round = new CompetitionRound(
            id: Uuid::uuid7(),
            competition: $competition,
            name: 'Teams',
            minutesLimit: 120,
            startsAt: new DateTimeImmutable('-9 days'),
            category: RoundCategory::Team,
            slug: 'teams',
            timezone: 'Europe/Prague',
            teamSize: $teamSize,
        );
        $this->entityManager->persist($round);
        $this->entityManager->flush();

        return $round->id->toString();
    }

    private function teamOfAnotherEvent(): string
    {
        $round = $this->entityManager->find(CompetitionRound::class, CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);
        $participant = $this->entityManager->find(CompetitionParticipant::class, MarketplaceEventFixture::PARTICIPANT_EDITION_SELLER_A);
        assert($round !== null && $participant !== null);

        $team = new CompetitionTeam(Uuid::uuid7(), $round, 'Elsewhere');
        $this->entityManager->persist($team);
        $this->entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round, $team));
        $this->entityManager->flush();

        return $team->id->toString();
    }
}
