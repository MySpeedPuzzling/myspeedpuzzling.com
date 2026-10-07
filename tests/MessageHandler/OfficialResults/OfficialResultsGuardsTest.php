<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\OfficialResults;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CompetitionHasResults;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundHasResults;
use SpeedPuzzling\Web\Exceptions\OfficialResultsChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\OfficialResultsProtected;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Message\DeleteCompetitionTeam;
use SpeedPuzzling\Web\Message\EditCompetitionParticipant;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Message\SoftDeleteCompetitionParticipant;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPlanner;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRowData;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Official data never disappears as a side effect (docs/features/competitions-management/official-results.md, guards).
 */
final class OfficialResultsGuardsTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAPairWithAResultIsNotDeletedOneWithoutIs(): void
    {
        try {
            $this->messageBus->dispatch(new DeleteCompetitionTeam(OfficialResultsFixture::TEAM_SHARKS));
            self::fail('A pair with a result must stay.');
        } catch (OfficialResultsProtected $protected) {
            self::assertSame(OfficialResultsProtected::TEAM_HAS_RESULT, $protected->reason);
        }

        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM competition_team WHERE id = :id', ['id' => OfficialResultsFixture::TEAM_SHARKS]));

        $this->messageBus->dispatch(new DeleteCompetitionTeam(OfficialResultsFixture::TEAM_UNNAMED));
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM competition_team WHERE id = :id', ['id' => OfficialResultsFixture::TEAM_UNNAMED]));
    }

    public function testSomebodyWithAResultStaysInTheEvent(): void
    {
        // Ben: his own result in Group A; Dan: did not finish - still a result
        foreach ([OfficialResultsFixture::PARTICIPANT_BEN, OfficialResultsFixture::PARTICIPANT_DAN] as $participantId) {
            try {
                $this->messageBus->dispatch(new SoftDeleteCompetitionParticipant($participantId));
                self::fail('A participant with a result must stay.');
            } catch (OfficialResultsProtected $protected) {
                self::assertSame(OfficialResultsProtected::PARTICIPANT_HAS_RESULT, $protected->reason);
            }

            self::assertNull($this->database->fetchOne('SELECT deleted_at FROM competition_participant WHERE id = :id', ['id' => $participantId]));
        }

        // Filip: no result in Group A, his pair has none either
        $this->messageBus->dispatch(new SoftDeleteCompetitionParticipant(OfficialResultsFixture::PARTICIPANT_FILIP));
        self::assertNotNull($this->database->fetchOne('SELECT deleted_at FROM competition_participant WHERE id = :id', ['id' => OfficialResultsFixture::PARTICIPANT_FILIP]));
    }

    public function testAMemberOfAPairWithAResultStaysInTheEvent(): void
    {
        $participant = $this->participant('Pair Only', null);
        $round = $this->entityManager->find(CompetitionRound::class, OfficialResultsFixture::ROUND_PAIRS);
        assert($round !== null);
        $team = $this->entityManager->find(CompetitionTeam::class, OfficialResultsFixture::TEAM_EDGES);
        assert($team !== null);
        $this->entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round, $team));
        $this->entityManager->flush();

        $this->expectException(OfficialResultsProtected::class);

        $this->messageBus->dispatch(new SoftDeleteCompetitionParticipant($participant->id->toString()));
    }

    public function testTakingSomebodyOutOfARoundWithTheirResultIsRefusedWithoutChangingAnything(): void
    {
        try {
            // Anna out of the Pairs round (Puzzle Sharks have a result) - and renamed in the same save
            $this->messageBus->dispatch(new EditCompetitionParticipant(
                participantId: OfficialResultsFixture::PARTICIPANT_ANNA,
                name: 'Anna Renamed',
                country: 'cz',
                externalId: null,
                playerId: PlayerFixture::PLAYER_ADMIN,
                roundIds: [OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::ROUND_FINAL],
            ));
            self::fail('Anna must stay in the Pairs round.');
        } catch (OfficialResultsProtected $protected) {
            self::assertSame(OfficialResultsProtected::ENTRY_HAS_RESULT, $protected->reason);
        }

        self::assertSame('Anna Fast', $this->database->fetchOne('SELECT name FROM competition_participant WHERE id = :id', ['id' => OfficialResultsFixture::PARTICIPANT_ANNA]));

        // Filip out of Group A, where he has no result
        $this->messageBus->dispatch(new EditCompetitionParticipant(
            participantId: OfficialResultsFixture::PARTICIPANT_FILIP,
            name: 'Filip Pending',
            country: 'cz',
            externalId: null,
            playerId: null,
            roundIds: [OfficialResultsFixture::ROUND_PAIRS],
        ));
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_A_FILIP]));
    }

    public function testLeavingTheEventKeepsAResultTheOrganiserRecorded(): void
    {
        $withResult = $this->participant('Self Joined Finisher', PlayerFixture::PLAYER_WITH_FAVORITES, ParticipantSource::SelfJoined);
        $round = $this->entityManager->find(CompetitionRound::class, OfficialResultsFixture::ROUND_GROUP_B);
        assert($round !== null);
        $entry = new CompetitionParticipantRound(Uuid::uuid7(), $withResult, $round);
        $entry->recordResult(RoundEntryResult::finished(6000), null, new \DateTimeImmutable());
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new LeaveCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_WITH_FAVORITES));

        $row = $this->database->fetchAssociative('SELECT player_id, deleted_at FROM competition_participant WHERE id = :id', ['id' => $withResult->id->toString()]);
        self::assertSame(['player_id' => null, 'deleted_at' => null], $row);
    }

    public function testLeavingWithoutResultsStaysAsItWas(): void
    {
        $withoutResult = $this->participant('Self Joined Visitor', PlayerFixture::PLAYER_WITH_FAVORITES, ParticipantSource::SelfJoined);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new LeaveCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_WITH_FAVORITES));

        self::assertNotNull($this->database->fetchOne('SELECT deleted_at FROM competition_participant WHERE id = :id', ['id' => $withoutResult->id->toString()]));
    }

    public function testTheCategoryOfARoundWithResultsStays(): void
    {
        try {
            $this->messageBus->dispatch($this->categoryChange(OfficialResultsFixture::ROUND_GROUP_A, RoundCategory::Duo));
            self::fail('The category must stay.');
        } catch (OfficialResultsProtected $protected) {
            self::assertSame(OfficialResultsProtected::ROUND_CATEGORY_LOCKED, $protected->reason);
        }

        self::assertSame('solo', $this->database->fetchOne('SELECT category FROM competition_round WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_A]));

        // The final has nobody with a result yet
        $this->messageBus->dispatch($this->categoryChange(OfficialResultsFixture::ROUND_FINAL, RoundCategory::Team));
        self::assertSame('team', $this->database->fetchOne('SELECT category FROM competition_round WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_FINAL]));
    }

    public function testTheInternalApiNeverDeletesARoundWithOfficialResults(): void
    {
        try {
            $this->messageBus->dispatch(new DeleteCompetitionRound(OfficialResultsFixture::ROUND_GROUP_B, refuseWhenItHasResults: true));
            self::fail('A round with results must stay.');
        } catch (CompetitionRoundHasResults $hasResults) {
            self::assertSame(3, $hasResults->resultsCount);
        }

        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM competition_round WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_B]));
    }

    /**
     * review2-b nit: a qualified mark alone keeps the round's category and the round itself (internal API).
     */
    public function testAQualifiedMarkAloneKeepsTheCategoryAndTheRound(): void
    {
        $this->database->executeStatement('UPDATE competition_participant_round SET qualified_at = NOW() WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_FINAL_ANNA]);

        try {
            $this->messageBus->dispatch($this->categoryChange(OfficialResultsFixture::ROUND_FINAL, RoundCategory::Team));
            self::fail('The category must stay.');
        } catch (OfficialResultsProtected $protected) {
            self::assertSame(OfficialResultsProtected::ROUND_CATEGORY_LOCKED, $protected->reason);
        }

        try {
            $this->messageBus->dispatch(new DeleteCompetitionRound(OfficialResultsFixture::ROUND_FINAL, refuseWhenItHasResults: true));
            self::fail('A round with a qualified mark must stay.');
        } catch (CompetitionRoundHasResults $hasResults) {
            self::assertSame(1, $hasResults->resultsCount);
        }
    }

    /**
     * review2-b m4: the internal API's event delete counts official results (and qualified marks) as results.
     */
    public function testTheInternalApiNeverDeletesAnEventWithOfficialResults(): void
    {
        try {
            $this->messageBus->dispatch(new DeleteCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP, refuseWhenItHasResults: true));
            self::fail('An event with official results must stay.');
        } catch (CompetitionHasResults $hasResults) {
            // Group A 5 (Anna, Ben, Cara, Dan, Eva), Group B 3, Pairs 3 (Sharks, Corners, Edges)
            self::assertSame(11, $hasResults->resultsCount);
        }

        // Only qualified marks left - still official data
        $this->database->executeStatement('UPDATE competition_participant_round SET result_seconds = NULL, result_pieces_placed = NULL, result_did_not_start = false');
        $this->database->executeStatement('UPDATE competition_team SET result_seconds = NULL, result_pieces_placed = NULL, result_did_not_start = false');

        try {
            $this->messageBus->dispatch(new DeleteCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP, refuseWhenItHasResults: true));
            self::fail('An event with qualified marks must stay.');
        } catch (CompetitionHasResults $hasResults) {
            // Anna, Ben (A), Gina, Hugo (B), Sharks, Edges
            self::assertSame(6, $hasResults->resultsCount);
        }

        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM competition WHERE id = :id', ['id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP]));
    }

    public function testTheOrganisersYesCountsOnlyForTheResultsTheyWereShown(): void
    {
        $guard = self::getContainer()->get(OfficialResultsGuard::class);
        $shown = OfficialResultsGuard::hashEntries($guard->entriesWithOfficialData(OfficialResultsFixture::ROUND_GROUP_B));

        // A result changes after the organiser saw the list
        $this->database->executeStatement('UPDATE competition_participant_round SET result_seconds = 5100 WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_B_IVAN]);

        try {
            $this->messageBus->dispatch(new DeleteCompetitionRound(OfficialResultsFixture::ROUND_GROUP_B, confirmedOfficialResultsHash: $shown));
            self::fail('The changed list must be confirmed again.');
        } catch (OfficialResultsChangedMeanwhile) {
        }

        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM competition_round WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_B]));

        $this->entityManager->clear();
        $current = OfficialResultsGuard::hashEntries($guard->entriesWithOfficialData(OfficialResultsFixture::ROUND_GROUP_B));
        $this->messageBus->dispatch(new DeleteCompetitionRound(OfficialResultsFixture::ROUND_GROUP_B, confirmedOfficialResultsHash: $current));

        self::assertFalse($this->database->fetchOne('SELECT 1 FROM competition_round WHERE id = :id', ['id' => OfficialResultsFixture::ROUND_GROUP_B]));
    }

    public function testAFullSyncImportKeepsEverybodyAndEveryPairWithOfficialResults(): void
    {
        $planner = self::getContainer()->get(ParticipantImportPlanner::class);

        // Only Anna and Ben are in the file - in a new pair, which empties Puzzle Sharks
        $plan = $planner->plan(OfficialResultsFixture::COMPETITION_RESULTS_CUP, new ParticipantImportRows([
            self::row(2, 'Anna Fast', [OfficialResultsFixture::ROUND_PAIRS => 'New Crew']),
            self::row(3, 'Ben Steady', [OfficialResultsFixture::ROUND_PAIRS => 'New Crew']),
        ]), ParticipantImportMode::Sync);

        $kept = array_column($plan->removals->participantsKeptWithResults, 'name');
        sort($kept);
        // Everybody but Filip holds a result or a qualified mark - their own or their pair's
        self::assertSame(['Cara Tied', 'Dan Unfinished', 'Eva Noshow', 'Gina Quick', 'Hugo Slow', 'Ivan Last'], $kept);
        self::assertSame(['Filip Pending'], array_column($plan->removals->participants, 'name'));

        // Puzzle Sharks lose both people to "New Crew" but stay with their result; the unnamed pair keeps Eva
        self::assertSame([], $plan->removals->teams);

        $translator = self::getContainer()->get(TranslatorInterface::class);
        $warnings = array_map(static fn (TranslatableMessage $message): string => $message->trans($translator, 'en'), $plan->warnings);
        self::assertContains('"Puzzle Sharks" in Pairs has an official result, so it stays although the file leaves it without people.', $warnings);
    }

    private function participant(string $name, null|string $playerId, ParticipantSource $source = ParticipantSource::Manual): CompetitionParticipant
    {
        $competition = $this->entityManager->find(Competition::class, OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        assert($competition !== null);

        $participant = new CompetitionParticipant(Uuid::uuid7(), $name, null, $competition, $source);

        if ($playerId !== null) {
            $player = $this->entityManager->find(Player::class, $playerId);
            assert($player !== null);
            $participant->connect($player, new \DateTimeImmutable());
        }

        $this->entityManager->persist($participant);

        return $participant;
    }

    private function categoryChange(string $roundId, RoundCategory $category): EditCompetitionRound
    {
        return new EditCompetitionRound(
            roundId: $roundId,
            name: '',
            minutesLimit: 0,
            startsAt: new \DateTimeImmutable(),
            timezone: 'Europe/Prague',
            badgeBackgroundColor: null,
            badgeTextColor: null,
            category: $category,
            keepFields: array_values(array_diff(EditCompetitionRound::FIELDS, ['category'])),
        );
    }

    /**
     * @param array<string, string> $teamsByRound
     */
    private static function row(int $number, string $name, array $teamsByRound): ParticipantImportRowData
    {
        return new ParticipantImportRowData(
            rowNumber: $number,
            name: $name,
            country: null,
            externalId: null,
            playerId: null,
            participantId: null,
            status: null,
            // The team column of a round counts for the rounds the row lists
            roundNames: 'Pairs',
            team: null,
            teamsByRound: $teamsByRound,
        );
    }
}
