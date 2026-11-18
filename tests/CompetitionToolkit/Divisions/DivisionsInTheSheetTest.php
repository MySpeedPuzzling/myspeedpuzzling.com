<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit\Divisions;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetParticipantsSheetState;
use SpeedPuzzling\Web\Query\GetParticipantsSheetVersion;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\ParticipantsSheet\SheetChangeSets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Divisions in the participants sheet (shared-puzzles-and-divisions.md §3.3, §3.4 - D3): the `division` change of
 * ApplyParticipantSheetChanges - three-way, a solo entry by participant, a pair by team, only a division the round uses,
 * '' clears - and the state's `divisions` / `division` keys, only on rounds that use divisions.
 */
final class DivisionsInTheSheetTest extends KernelTestCase
{
    use DivisionsSetup;
    use SheetChangeSets;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->setUpStPaulDivisions($this->messageBus);
    }

    public function testASoloEntrysDivisionIsThreeWay(): void
    {
        $versions = self::getContainer()->get(GetParticipantsSheetVersion::class);
        $before = $versions->ofCompetition(Cup::COMPETITION_RESULTS_CUP);

        // Filip has none: set to Junior - by its id
        $applied = $this->applySheetChanges([self::sheetGroup(self::divisionChange(Cup::ROUND_GROUP_A, Cup::PARTICIPANT_FILIP, null, '', $this->junior))]);
        self::assertSame(['applied'], self::changeStatuses($applied));
        self::assertSame($this->junior, $this->entryDivision(Cup::PARTICIPANT_FILIP, Cup::ROUND_GROUP_A));
        self::assertNotSame($before, $versions->ofCompetition(Cup::COMPETITION_RESULTS_CUP), 'a division changes the sheet version');

        // Sent again: unchanged; seen as something else meanwhile: a conflict naming what is there now
        self::assertSame(['unchanged'], self::changeStatuses($this->applySheetChanges([self::sheetGroup(self::divisionChange(Cup::ROUND_GROUP_A, Cup::PARTICIPANT_FILIP, null, '', $this->junior))])));
        $conflict = $this->applySheetChanges([self::sheetGroup(self::divisionChange(Cup::ROUND_GROUP_A, Cup::PARTICIPANT_FILIP, null, '', $this->regular))]);
        self::assertSame(['conflict:changed_meanwhile'], self::changeStatuses($conflict));
        self::assertSame($this->junior, $conflict->groups[0]->changes[0]->current);

        // '' clears it
        self::assertSame(['applied'], self::changeStatuses($this->applySheetChanges([self::sheetGroup(self::divisionChange(Cup::ROUND_GROUP_A, Cup::PARTICIPANT_FILIP, null, $this->junior, ''))])));
        self::assertNull($this->entryDivision(Cup::PARTICIPANT_FILIP, Cup::ROUND_GROUP_A));
    }

    public function testOnlyADivisionTheRoundUsesIsTaken(): void
    {
        // The Final uses Senior only; Pairs Regular + Senior
        $refused = $this->applySheetChanges([
            self::sheetGroup(self::divisionChange(Cup::ROUND_FINAL, Cup::PARTICIPANT_ANNA, null, '', $this->regular)),
            self::sheetGroup(self::divisionChange(Cup::ROUND_PAIRS, null, Cup::TEAM_SHARKS, '', $this->junior)),
            self::sheetGroup(self::divisionChange(Cup::ROUND_GROUP_A, Cup::PARTICIPANT_FILIP, null, '', 'b9a0c1e0-0000-4000-8000-000000000001')),
            // A division of a round without any: Group B uses none
            self::sheetGroup(self::divisionChange(Cup::ROUND_GROUP_B, Cup::PARTICIPANT_HUGO, null, '', $this->regular)),
        ]);

        self::assertSame(['refused:not_in_round'], self::changeStatuses($refused, 0));
        self::assertSame(['round' => 'Final', 'division' => 'Regular'], $refused->groups[0]->changes[0]->parameters);
        self::assertSame('entry_divisions.sheet.reason.not_in_round', $refused->groups[0]->changes[0]->messageKey());
        self::assertSame(['refused:not_in_round'], self::changeStatuses($refused, 1));
        self::assertSame(['refused:unknown_division'], self::changeStatuses($refused, 2));
        self::assertSame(['refused:not_in_round'], self::changeStatuses($refused, 3));

        self::assertNull($this->entryDivision(Cup::PARTICIPANT_ANNA, Cup::ROUND_FINAL));
        self::assertNull($this->teamDivision(Cup::TEAM_SHARKS));
    }

    public function testAPairsDivisionIsThePairsNotItsMembers(): void
    {
        $applied = $this->applySheetChanges([
            self::sheetGroup(self::divisionChange(Cup::ROUND_PAIRS, null, Cup::TEAM_SHARKS, '', $this->senior)),
            // A member's own entry in a pair round carries none
            self::sheetGroup(self::divisionChange(Cup::ROUND_PAIRS, Cup::PARTICIPANT_GINA, null, '', $this->regular)),
            // A team in a solo round, a person who is not in the round
            self::sheetGroup(self::divisionChange(Cup::ROUND_GROUP_A, null, Cup::TEAM_EDGES, '', $this->regular)),
            self::sheetGroup(self::divisionChange(Cup::ROUND_FINAL, Cup::PARTICIPANT_BEN, null, '', $this->senior)),
        ]);

        self::assertSame(['applied'], self::changeStatuses($applied, 0));
        self::assertSame($this->senior, $this->teamDivision(Cup::TEAM_SHARKS));
        self::assertNull($this->entryDivision(Cup::PARTICIPANT_ANNA, Cup::ROUND_PAIRS));
        self::assertSame(['refused:division_of_team'], self::changeStatuses($applied, 1));
        self::assertSame(['refused:not_a_team_round'], self::changeStatuses($applied, 2));
        self::assertSame(['refused:division_not_entered'], self::changeStatuses($applied, 3));
    }

    public function testANewPairAndItsDivisionInOneGroup(): void
    {
        $teamId = '018d0020-0000-7000-8000-00000000d301';
        $applied = $this->applySheetChanges([self::sheetGroup(
            ['op' => 'newTeam', 'id' => $teamId, 'round' => Cup::ROUND_PAIRS, 'name' => 'Late Owls'],
            self::divisionChange(Cup::ROUND_PAIRS, null, $teamId, '', $this->regular),
        )]);

        self::assertSame(['applied', 'applied'], self::changeStatuses($applied));
        self::assertSame($this->regular, $this->teamDivision($teamId));
    }

    public function testTheStateCarriesDivisionsOnlyOnRoundsThatUseThem(): void
    {
        $state = json_decode((string) json_encode(self::getContainer()->get(GetParticipantsSheetState::class)->forCompetition(Cup::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_WITH_STRIPE)), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);

        $rounds = [];
        foreach ($state['rounds'] as $round) {
            $rounds[$round['id']] = $round;
        }
        self::assertSame(['Regular', 'Senior 55+', 'Junior U16'], array_column($rounds[Cup::ROUND_GROUP_A]['divisions'], 'name'));
        self::assertSame(['Regular', 'Senior 55+'], array_column($rounds[Cup::ROUND_PAIRS]['divisions'], 'name'));
        self::assertArrayNotHasKey('divisions', $rounds[Cup::ROUND_GROUP_B]);
        self::assertArrayNotHasKey('divisions', $rounds[Cup::ROUND_PAIRS_FINAL]);

        foreach ($state['places'] as $place) {
            if ($place['roundId'] === Cup::ROUND_GROUP_A) {
                self::assertArrayHasKey('division', $place);
            } else {
                // Group B uses none; the Final's and Pairs' members carry none of their own (Pairs: on the team)
                self::assertSame($place['roundId'] === Cup::ROUND_FINAL, array_key_exists('division', $place), $place['roundId']);
            }

            if ($place['participantId'] === Cup::PARTICIPANT_ANNA && $place['roundId'] === Cup::ROUND_GROUP_A) {
                self::assertSame($this->regular, $place['division']);
            }
        }

        foreach ($state['teams'] as $team) {
            self::assertSame($team['roundId'] === Cup::ROUND_PAIRS, array_key_exists('division', $team));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function divisionChange(string $roundId, null|string $participantId, null|string $teamId, string $from, string $to): array
    {
        return ['op' => 'division', 'round' => $roundId, ...($teamId !== null ? ['team' => $teamId] : ['participant' => $participantId]), 'from' => $from, 'to' => $to];
    }

    private function entryDivision(string $participantId, string $roundId): null|string
    {
        $division = $this->database->fetchOne('SELECT division_id FROM competition_participant_round WHERE participant_id = ? AND round_id = ?', [$participantId, $roundId]);

        return is_string($division) ? $division : null;
    }

    private function teamDivision(string $teamId): null|string
    {
        $division = $this->database->fetchOne('SELECT division_id FROM competition_team WHERE id = ?', [$teamId]);

        return is_string($division) ? $division : null;
    }
}
