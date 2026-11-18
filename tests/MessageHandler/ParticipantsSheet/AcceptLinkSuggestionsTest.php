<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\ParticipantsSheet;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\ParticipantLinkSuggestionsSeeding;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Accepting link suggestions is a sheet change (docs/features/competitions-management/participant-linking.md §5.3):
 * a `player` change per person, "Joined by themselves" = the group `[remove(selfJoinedRow), player(row, null →
 * player)]` - atomic: refused as a whole while the self-joined row holds a result. Bulk = one group per person, a
 * refused one never stops the others.
 */
final class AcceptLinkSuggestionsTest extends KernelTestCase
{
    use ParticipantLinkSuggestionsSeeding;
    use SheetChangeSets;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheMergeRemovesTheSelfJoinedRowAndLinksTheOrganisersRow(): void
    {
        $seeded = $this->seedLinkSuggestions($this->database);
        $zoe = $seeded['participants']['zoe'];
        $self = $seeded['participants']['zoeSelf'];
        $player = $seeded['players']['zoe'];

        $applied = $this->applySheetChanges([self::sheetGroup(
            ['op' => 'remove', 'participant' => $self],
            ['op' => 'player', 'participant' => $zoe, 'from' => null, 'to' => $player],
        )], competitionId: $seeded['event']);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertSame($player, $this->row($zoe)['player_id']);
        self::assertNotNull($this->row($self)['deleted_at']);
        // Removed, the self-joined row is the organiser's - "I'm going" never brings it back next to the linked one
        self::assertSame('imported', $this->row($self)['source']);
    }

    public function testAMergeIsAllOrNothing(): void
    {
        $seeded = $this->seedLinkSuggestions($this->database);
        $zoe = $seeded['participants']['zoe'];
        $self = $seeded['participants']['zoeSelf'];
        $this->database->executeStatement(
            'INSERT INTO competition_participant_round (id, participant_id, round_id, result_seconds, result_did_not_start) VALUES (:id, :participant, :round, 3000, false)',
            ['id' => Uuid::uuid7()->toString(), 'participant' => $self, 'round' => $seeded['round']],
        );

        $applied = $this->applySheetChanges([self::sheetGroup(
            ['op' => 'remove', 'participant' => $self],
            ['op' => 'player', 'participant' => $zoe, 'from' => null, 'to' => $seeded['players']['zoe']],
        )], competitionId: $seeded['event']);

        self::assertSame(['refused'], self::groupStatuses($applied));
        // The removal is refused - and with the self-joined row kept, the link would put the player on two rows
        self::assertSame(['refused:has_result_in_event', 'refused:player_linked_elsewhere'], self::changeStatuses($applied));
        self::assertNull($this->row($zoe)['player_id']);
        self::assertNull($this->row($self)['deleted_at']);
        self::assertSame($seeded['players']['zoe'], $this->row($self)['player_id']);
    }

    /**
     * R7 on the server: the dialog's answer may be stale - a self-joined row that became a registration of an event
     * managing registration, or got a place in a round, is never merged away; the organiser merges it by hand.
     */
    public function testAMergeOfARegistrationOrARoundPlaceIsRefused(): void
    {
        $seeded = $this->seedLinkSuggestions($this->database);
        $zoe = $seeded['participants']['zoe'];
        $self = $seeded['participants']['zoeSelf'];
        $merge = [self::sheetGroup(
            ['op' => 'remove', 'participant' => $self],
            ['op' => 'player', 'participant' => $zoe, 'from' => null, 'to' => $seeded['players']['zoe']],
        )];

        // A registration status counts only while the event manages registration
        $this->database->executeStatement("UPDATE competition_participant SET registration_status = 'paid' WHERE id = :id", ['id' => $self]);
        $this->database->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => $seeded['event']]);

        $applied = $this->applySheetChanges($merge, competitionId: $seeded['event']);

        self::assertSame(['refused'], self::groupStatuses($applied));
        self::assertSame(['skipped', 'refused:merge_by_hand'], self::changeStatuses($applied));
        self::assertSame('participant_link_suggestions.sheet.reason.merge_by_hand', $applied->groups[0]->changes[1]->messageKey());
        self::assertNull($this->row($self)['deleted_at']);
        self::assertNull($this->row($zoe)['player_id']);

        // Not managed, but in a round (no result)
        $this->database->executeStatement('UPDATE competition SET registration_managed = false WHERE id = :id', ['id' => $seeded['event']]);
        $this->database->executeStatement(
            'INSERT INTO competition_participant_round (id, participant_id, round_id) VALUES (:id, :participant, :round)',
            ['id' => Uuid::uuid7()->toString(), 'participant' => $self, 'round' => $seeded['round']],
        );

        $applied = $this->applySheetChanges([self::sheetGroup(
            ['op' => 'remove', 'participant' => $self],
            ['op' => 'player', 'participant' => $zoe, 'from' => null, 'to' => $seeded['players']['zoe']],
        )], competitionId: $seeded['event']);

        self::assertSame(['skipped', 'refused:merge_by_hand'], self::changeStatuses($applied));
        self::assertNull($this->row($self)['deleted_at']);

        // Removing the row alone (by hand) still works
        $applied = $this->applySheetChanges([self::sheetGroup(['op' => 'remove', 'participant' => $self])], competitionId: $seeded['event']);
        self::assertSame(['applied'], self::groupStatuses($applied));
    }

    public function testOneRefusedPersonNeverStopsTheOthers(): void
    {
        $seeded = $this->seedLinkSuggestions($this->database);

        $applied = $this->applySheetChanges([
            self::sheetGroup(['op' => 'player', 'participant' => $seeded['participants']['quirin'], 'from' => null, 'to' => $seeded['players']['quirin']]),
            // Dorian's profile is linked to another person of the list meanwhile
            self::sheetGroup(['op' => 'player', 'participant' => $seeded['participants']['dorian'], 'from' => null, 'to' => $seeded['players']['dorian']]),
            self::sheetGroup(['op' => 'player', 'participant' => $seeded['participants']['ottilie'], 'from' => null, 'to' => $seeded['players']['ottilie']]),
        ], competitionId: $seeded['event']);

        self::assertSame(['applied', 'refused', 'applied'], self::groupStatuses($applied));
        self::assertSame(['refused:player_linked_elsewhere'], self::changeStatuses($applied, 1));
        self::assertSame($seeded['players']['quirin'], $this->row($seeded['participants']['quirin'])['player_id']);
        self::assertNull($this->row($seeded['participants']['dorian'])['player_id']);
        self::assertSame($seeded['players']['ottilie'], $this->row($seeded['participants']['ottilie'])['player_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $participantId): array
    {
        $row = $this->database->fetchAssociative('SELECT * FROM competition_participant WHERE id = :id', ['id' => $participantId]);
        self::assertIsArray($row);

        return $row;
    }
}
