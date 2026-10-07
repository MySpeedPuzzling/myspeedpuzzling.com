<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\OfficialResults;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Results\RoundResultChangeOutcome;
use SpeedPuzzling\Web\Services\RoundResultChangesParser;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class RecordRoundResultsHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAppliesAResultAndRecordsWhoEnteredIt(): void
    {
        $recorded = $this->record([self::change(self::filip(), 'result', null, ['seconds' => 4000])]);

        self::assertSame(['applied'], self::statuses($recorded));
        self::assertSame([self::filip()], $recorded->changedEntryRefs);
        $row = $this->entryRow(OfficialResultsFixture::ENTRY_A_FILIP);
        self::assertSame(4000, $row['result_seconds']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $row['result_entered_by_id']);
        self::assertNotNull($row['result_entered_at']);
        self::assertSame(['seconds' => 4000], $recorded->outcomes[0]->jsonSerialize()['current']);
    }

    public function testAReplayChangesNothing(): void
    {
        $change = self::change(self::filip(), 'result', null, ['seconds' => 4000]);

        $this->record([$change]);
        $replayed = $this->record([$change]);

        self::assertSame(['unchanged'], self::statuses($replayed));
        self::assertSame([], $replayed->changedEntryRefs);
    }

    public function testAChangeFromAnOutdatedValueIsAConflictWithWhatIsThereNow(): void
    {
        $recorded = $this->record([self::change(self::anna(), 'result', null, ['seconds' => 1000])]);

        $outcome = $recorded->outcomes[0];
        self::assertSame('conflict', $outcome->status->value);
        self::assertSame('changed_meanwhile', $outcome->reason);
        $json = $outcome->jsonSerialize();
        self::assertSame(['seconds' => 3600], $json['current']);
        self::assertSame(['playerId' => PlayerFixture::PLAYER_WITH_STRIPE, 'name' => 'Sarah Williams'], $json['enteredBy']);
        self::assertSame(3600, $this->entryRow(OfficialResultsFixture::ENTRY_A_ANNA)['result_seconds']);
    }

    public function testValuesOutsideTheRulesAreRejected(): void
    {
        $recorded = $this->record([
            self::change(self::filip(), 'result', null, ['seconds' => 0]),
            self::change(self::filip(), 'result', null, ['seconds' => RoundEntryResult::MAX_SECONDS + 1]),
            // The round's puzzle has 1000 pieces - a finished puzzle gets a time
            self::change(self::filip(), 'result', null, ['piecesPlaced' => 1000]),
            self::change(self::filip(), 'result', null, ['piecesPlaced' => 0]),
            self::change(self::filip(), 'table_number', null, 0),
            self::change(self::filip(), 'table_number', null, 10000),
            self::change(self::filip(), 'result', null, ['piecesPlaced' => 999]),
        ]);

        self::assertSame(['rejected', 'rejected', 'rejected', 'rejected', 'rejected', 'rejected', 'applied'], self::statuses($recorded));
        self::assertSame(
            ['invalid_seconds', 'invalid_seconds', 'invalid_pieces_placed', 'invalid_pieces_placed', 'invalid_table_number', 'invalid_table_number', null],
            array_map(static fn (RoundResultChangeOutcome $outcome): null|string => $outcome->reason, $recorded->outcomes),
        );
        self::assertSame(999, $this->entryRow(OfficialResultsFixture::ENTRY_A_FILIP)['result_pieces_placed']);
    }

    public function testATableNumberOfAnotherEntryIsRefusedButASwapInOneSetWorks(): void
    {
        $taken = $this->record([self::change(self::ben(), 'table_number', 2, 1)]);

        self::assertSame(['rejected'], self::statuses($taken));
        self::assertSame('table_number_taken', $taken->outcomes[0]->reason);
        self::assertSame(2, $this->entryRow(OfficialResultsFixture::ENTRY_A_BEN)['table_number']);

        $swapped = $this->record([
            self::change(self::anna(), 'table_number', 1, 2),
            self::change(self::ben(), 'table_number', 2, 1),
        ]);

        self::assertSame(['applied', 'applied'], self::statuses($swapped));
        self::assertSame(2, $this->entryRow(OfficialResultsFixture::ENTRY_A_ANNA)['table_number']);
        self::assertSame(1, $this->entryRow(OfficialResultsFixture::ENTRY_A_BEN)['table_number']);
    }

    public function testARefusedNumberNeverBlocksTheRestOfTheSet(): void
    {
        $recorded = $this->record([
            self::change(self::filip(), 'table_number', null, 3),
            self::change(self::filip(), 'result', null, ['seconds' => 5000]),
        ]);

        self::assertSame(['rejected', 'applied'], self::statuses($recorded));
        $row = $this->entryRow(OfficialResultsFixture::ENTRY_A_FILIP);
        self::assertNull($row['table_number']);
        self::assertSame(5000, $row['result_seconds']);
    }

    public function testAnEntryOfAnotherRoundIsNotFound(): void
    {
        $recorded = $this->record([self::change('participant_round:' . OfficialResultsFixture::ENTRY_B_GINA, 'qualified', true, false)]);

        self::assertSame(['rejected'], self::statuses($recorded));
        self::assertSame('entry_not_found', $recorded->outcomes[0]->reason);
        self::assertNotNull($this->entryRow(OfficialResultsFixture::ENTRY_B_GINA)['qualified_at']);
    }

    public function testARoundOfAnotherCompetitionIsRefused(): void
    {
        $this->expectException(CompetitionRoundNotFound::class);

        $this->record([self::change(self::filip(), 'qualified', false, true)], competitionId: CompetitionFixture::COMPETITION_WJPC_2024);
    }

    public function testQualifiedMarkSetAndTakenAway(): void
    {
        $this->record([self::change(self::cara(), 'qualified', false, true)]);
        self::assertNotNull($this->entryRow(OfficialResultsFixture::ENTRY_A_CARA)['qualified_at']);

        $this->record([self::change(self::cara(), 'qualified', true, false)]);
        self::assertNull($this->entryRow(OfficialResultsFixture::ENTRY_A_CARA)['qualified_at']);
    }

    public function testDidNotStartAndClearingAResult(): void
    {
        $this->record([self::change(self::filip(), 'result', null, ['didNotStart' => true])]);
        $didNotStart = $this->entryRow(OfficialResultsFixture::ENTRY_A_FILIP);
        self::assertTrue($didNotStart['result_did_not_start']);

        $this->record([self::change(self::filip(), 'result', ['didNotStart' => true], null)]);
        $row = $this->entryRow(OfficialResultsFixture::ENTRY_A_FILIP);
        self::assertFalse($row['result_did_not_start']);
        self::assertNull($row['result_seconds']);
        self::assertNull($row['result_pieces_placed']);
        // Cleared by somebody - still recorded who and when
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $row['result_entered_by_id']);
    }

    public function testAPersonTypedInAtTheVenueIsCreatedOnceWithTheDevicesId(): void
    {
        $entryId = Uuid::uuid7()->toString();
        $change = self::newEntryChange(['clientEntryId' => $entryId, 'kind' => 'person', 'name' => 'Zoe Newcomer', 'country' => 'cz'], 'result', null, ['seconds' => 4500]);

        $first = $this->record([$change]);
        $second = $this->record([$change]);

        self::assertSame(['applied'], self::statuses($first));
        self::assertSame(['unchanged'], self::statuses($second));
        self::assertSame(['participant_round:' . $entryId], $first->changedEntryRefs);

        $participants = $this->database->fetchAllAssociative(
            "SELECT cp.id, cp.source, cp.country FROM competition_participant cp WHERE cp.competition_id = :competitionId AND cp.name = 'Zoe Newcomer'",
            ['competitionId' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
        );
        self::assertCount(1, $participants);
        self::assertSame('manual', $participants[0]['source']);
        self::assertSame('cz', $participants[0]['country']);

        $row = $this->entryRow($entryId);
        self::assertSame($participants[0]['id'], $row['participant_id']);
        self::assertSame(OfficialResultsFixture::ROUND_GROUP_A, $row['round_id']);
        self::assertSame(4500, $row['result_seconds']);
    }

    public function testAPairTypedInAtTheVenueComesWithItsPeople(): void
    {
        $teamId = Uuid::uuid7()->toString();

        $recorded = $this->record([
            self::newEntryChange(['clientEntryId' => $teamId, 'kind' => 'team', 'name' => 'Fresh Pair', 'members' => ['Xena One', 'Yuri Two']], 'table_number', null, 9),
            self::change('team:' . $teamId, 'result', null, ['seconds' => 7000]),
        ], OfficialResultsFixture::ROUND_PAIRS);

        self::assertSame(['applied', 'applied'], self::statuses($recorded));
        $team = $this->database->fetchAssociative('SELECT name, table_number, result_seconds, round_id FROM competition_team WHERE id = :id', ['id' => $teamId]);
        self::assertSame(['name' => 'Fresh Pair', 'table_number' => 9, 'result_seconds' => 7000, 'round_id' => OfficialResultsFixture::ROUND_PAIRS], $team);

        $members = $this->database->fetchFirstColumn(
            'SELECT cp.name FROM competition_participant_round cpr INNER JOIN competition_participant cp ON cp.id = cpr.participant_id WHERE cpr.team_id = :id AND cpr.round_id = :roundId ORDER BY cp.name',
            ['id' => $teamId, 'roundId' => OfficialResultsFixture::ROUND_PAIRS],
        );
        self::assertSame(['Xena One', 'Yuri Two'], $members);
    }

    public function testANewEntryMustFitTheRound(): void
    {
        $recorded = $this->record([
            self::newEntryChange(['clientEntryId' => Uuid::uuid7()->toString(), 'kind' => 'team', 'name' => 'Wrong Round'], 'qualified', false, true),
            self::newEntryChange(['clientEntryId' => Uuid::uuid7()->toString(), 'kind' => 'person', 'name' => null], 'qualified', false, true),
            // The id of an entry of another round - never taken over
            self::newEntryChange(['clientEntryId' => OfficialResultsFixture::ENTRY_B_GINA, 'kind' => 'person', 'name' => 'Gina Again'], 'qualified', false, true),
        ]);

        self::assertSame(
            ['entry_kind_mismatch', 'entry_name_missing', 'entry_id_taken'],
            array_map(static fn (RoundResultChangeOutcome $outcome): null|string => $outcome->reason, $recorded->outcomes),
        );
        self::assertSame([], $recorded->changedEntryRefs);
    }

    public function testARejectedChangeCreatesNobody(): void
    {
        $this->record([
            self::newEntryChange(['clientEntryId' => Uuid::uuid7()->toString(), 'kind' => 'person', 'name' => 'Never Created'], 'result', null, ['seconds' => 0]),
        ]);

        self::assertFalse($this->database->fetchOne("SELECT 1 FROM competition_participant WHERE name = 'Never Created'"));
    }

    public function testADryRunAnswersWithoutWriting(): void
    {
        $recorded = $this->record([
            self::change(self::filip(), 'result', null, ['seconds' => 4000]),
            self::newEntryChange(['clientEntryId' => Uuid::uuid7()->toString(), 'kind' => 'person', 'name' => 'Dry Runner'], 'qualified', false, true),
        ], dryRun: true);

        self::assertTrue($recorded->dryRun);
        self::assertSame(['applied', 'applied'], self::statuses($recorded));
        self::assertNull($this->entryRow(OfficialResultsFixture::ENTRY_A_FILIP)['result_seconds']);
        self::assertFalse($this->database->fetchOne("SELECT 1 FROM competition_participant WHERE name = 'Dry Runner'"));
    }

    public function testAReplayedChangeNeverBringsBackAValueCorrectedSince(): void
    {
        // A referee's result whose answer got lost, cleared at the desk, then sent again by the device's outbox
        $lost = self::change(self::filip(), 'result', null, ['seconds' => 4000]);

        self::assertSame(['applied'], self::statuses($this->record([$lost])));
        self::assertSame(['applied'], self::statuses($this->record([self::change(self::filip(), 'result', ['seconds' => 4000], null)])));

        $replayed = $this->record([$lost]);

        self::assertSame(['unchanged'], self::statuses($replayed));
        self::assertNull($replayed->outcomes[0]->jsonSerialize()['current']);
        self::assertSame([], $replayed->changedEntryRefs);
        self::assertNull($this->entryRow(OfficialResultsFixture::ENTRY_A_FILIP)['result_seconds']);
    }

    public function testAChangeThatFoundItsValueThereIsNotAppliedLaterEither(): void
    {
        $sameAsSaved = self::change(self::anna(), 'result', null, ['seconds' => 3600]);

        self::assertSame(['unchanged'], self::statuses($this->record([$sameAsSaved])));
        $this->record([self::change(self::anna(), 'result', ['seconds' => 3600], null)]);

        self::assertSame(['unchanged'], self::statuses($this->record([$sameAsSaved])));
        self::assertNull($this->entryRow(OfficialResultsFixture::ENTRY_A_ANNA)['result_seconds']);
    }

    public function testOnlyChangesThatWentThroughLeaveAReceipt(): void
    {
        $applied = self::change(self::filip(), 'result', null, ['seconds' => 4000]);
        $conflict = self::change(self::anna(), 'result', null, ['seconds' => 1000]);
        $rejected = self::change(self::ben(), 'table_number', 2, 0);

        $this->record([$applied, $conflict, $rejected]);
        $this->record([self::change(self::filip(), 'result', ['seconds' => 4000], ['seconds' => 4100])], dryRun: true);

        $receipts = $this->database->fetchAllKeyValue(
            'SELECT id::text, status FROM round_result_change_receipt WHERE id IN (:ids)',
            ['ids' => [$applied['clientChangeId'], $conflict['clientChangeId'], $rejected['clientChangeId']]],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );
        $appliedId = $applied['clientChangeId'];
        assert(is_string($appliedId));
        self::assertSame([$appliedId => 'applied'], $receipts);
    }

    public function testAChangeIdTakenInAnotherRoundIsRefused(): void
    {
        $change = self::change(self::filip(), 'qualified', false, true);
        $this->record([$change]);

        $elsewhere = $this->record([['clientChangeId' => $change['clientChangeId'], 'entry' => 'participant_round:' . OfficialResultsFixture::ENTRY_B_IVAN, 'field' => 'qualified', 'from' => false, 'to' => true]], OfficialResultsFixture::ROUND_GROUP_B);

        self::assertSame(['rejected'], self::statuses($elsewhere));
        self::assertNull($this->entryRow(OfficialResultsFixture::ENTRY_B_IVAN)['qualified_at']);
    }

    public function testAPersonOfTheEventIsPutIntoTheRoundInsteadOfTypedInAgain(): void
    {
        $entryId = Uuid::uuid7()->toString();
        $participants = $this->participantCount();

        $recorded = $this->record([
            self::newEntryChange(['clientEntryId' => $entryId, 'kind' => 'person', 'participantId' => OfficialResultsFixture::PARTICIPANT_GINA, 'name' => 'Gina Quick'], 'result', null, ['seconds' => 4321]),
        ]);

        self::assertSame(['applied'], self::statuses($recorded));
        self::assertSame($participants, $this->participantCount(), 'nobody new in the event');
        $row = $this->entryRow($entryId);
        self::assertSame(OfficialResultsFixture::PARTICIPANT_GINA, $row['participant_id']);
        self::assertSame(OfficialResultsFixture::ROUND_GROUP_A, $row['round_id']);
        self::assertSame(4321, $row['result_seconds']);
    }

    public function testAPersonOfTheEventMustBeOneNotInTheRoundYet(): void
    {
        $recorded = $this->record([
            // In Group A already
            self::newEntryChange(['clientEntryId' => Uuid::uuid7()->toString(), 'kind' => 'person', 'participantId' => OfficialResultsFixture::PARTICIPANT_BEN], 'qualified', false, true),
            // Not a participant of this event
            self::newEntryChange(['clientEntryId' => Uuid::uuid7()->toString(), 'kind' => 'person', 'participantId' => Uuid::uuid7()->toString()], 'qualified', false, true),
            // Put in twice by one set
            self::newEntryChange(['clientEntryId' => Uuid::uuid7()->toString(), 'kind' => 'person', 'participantId' => OfficialResultsFixture::PARTICIPANT_HUGO], 'qualified', false, true),
            self::newEntryChange(['clientEntryId' => Uuid::uuid7()->toString(), 'kind' => 'person', 'participantId' => OfficialResultsFixture::PARTICIPANT_HUGO], 'qualified', false, true),
        ]);

        self::assertSame(
            ['participant_already_in_round', 'participant_not_found', null, 'duplicate_entry'],
            array_map(static fn (RoundResultChangeOutcome $outcome): null|string => $outcome->reason, $recorded->outcomes),
        );
    }

    public function testAPairIsBuiltFromPeopleOfTheEventAndANewcomer(): void
    {
        // Cara is in the Pairs Final already, in no pair yet - her row joins the pair (one row per person and round)
        $caraRowId = Uuid::uuid7()->toString();
        $this->database->insert('competition_participant_round', [
            'id' => $caraRowId,
            'participant_id' => OfficialResultsFixture::PARTICIPANT_CARA,
            'round_id' => OfficialResultsFixture::ROUND_PAIRS_FINAL,
            'result_did_not_start' => 'false',
        ]);
        $teamId = Uuid::uuid7()->toString();
        $participants = $this->participantCount();

        $recorded = $this->record([
            self::newEntryChange(['clientEntryId' => $teamId, 'kind' => 'team', 'name' => null, 'members' => [
                ['participantId' => OfficialResultsFixture::PARTICIPANT_ANNA, 'name' => 'Anna Fast'],
                ['participantId' => OfficialResultsFixture::PARTICIPANT_CARA],
                'Newbie Third',
            ]], 'result', null, ['seconds' => 6100]),
        ], OfficialResultsFixture::ROUND_PAIRS_FINAL);

        self::assertSame(['applied'], self::statuses($recorded));
        self::assertSame($participants + 1, $this->participantCount(), 'only the typed-in member is new');

        $members = $this->database->fetchAllKeyValue(
            'SELECT cp.name, cpr.id FROM competition_participant_round cpr INNER JOIN competition_participant cp ON cp.id = cpr.participant_id WHERE cpr.team_id = :id ORDER BY cp.name',
            ['id' => $teamId],
        );
        self::assertSame(['Anna Fast', 'Cara Tied', 'Newbie Third'], array_keys($members));
        self::assertSame($caraRowId, $members['Cara Tied']);
    }

    public function testSomebodyInAPairOfTheRoundAlreadyCannotJoinAnother(): void
    {
        $recorded = $this->record([
            self::newEntryChange(['clientEntryId' => Uuid::uuid7()->toString(), 'kind' => 'team', 'members' => [['participantId' => OfficialResultsFixture::PARTICIPANT_ANNA], 'Somebody New']], 'qualified', false, true),
        ], OfficialResultsFixture::ROUND_PAIRS);

        self::assertSame('participant_already_in_round', $recorded->outcomes[0]->reason);
        self::assertFalse($this->database->fetchOne("SELECT 1 FROM competition_participant WHERE name = 'Somebody New'"));
    }

    private function participantCount(): int
    {
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]);
        assert(is_int($count) || is_string($count));

        return (int) $count;
    }

    /**
     * @param list<array<string, mixed>> $changes wire format
     */
    private function record(
        array $changes,
        string $roundId = OfficialResultsFixture::ROUND_GROUP_A,
        bool $dryRun = false,
        string $competitionId = OfficialResultsFixture::COMPETITION_RESULTS_CUP,
    ): RecordedRoundResults {
        $envelope = $this->messageBus->dispatch(new RecordRoundResults(
            competitionId: $competitionId,
            roundId: $roundId,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            changes: RoundResultChangesParser::parse($changes),
            dryRun: $dryRun,
        ));

        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        assert($recorded instanceof RecordedRoundResults);

        return $recorded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function change(string $entry, string $field, mixed $from, mixed $to): array
    {
        return ['clientChangeId' => Uuid::uuid7()->toString(), 'entry' => $entry, 'field' => $field, 'from' => $from, 'to' => $to];
    }

    /**
     * @param array<string, mixed> $newEntry
     * @return array<string, mixed>
     */
    private static function newEntryChange(array $newEntry, string $field, mixed $from, mixed $to): array
    {
        return ['clientChangeId' => Uuid::uuid7()->toString(), 'newEntry' => $newEntry, 'field' => $field, 'from' => $from, 'to' => $to];
    }

    /**
     * @return list<string>
     */
    private static function statuses(RecordedRoundResults $recorded): array
    {
        return array_map(static fn (RoundResultChangeOutcome $outcome): string => $outcome->status->value, $recorded->outcomes);
    }

    /**
     * @return array<string, mixed>
     */
    private function entryRow(string $id): array
    {
        $row = $this->database->fetchAssociative('SELECT * FROM competition_participant_round WHERE id = :id', ['id' => $id]);
        assert(is_array($row));

        return $row;
    }

    private static function anna(): string
    {
        return 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA;
    }

    private static function ben(): string
    {
        return 'participant_round:' . OfficialResultsFixture::ENTRY_A_BEN;
    }

    private static function cara(): string
    {
        return 'participant_round:' . OfficialResultsFixture::ENTRY_A_CARA;
    }

    private static function filip(): string
    {
        return 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP;
    }
}
