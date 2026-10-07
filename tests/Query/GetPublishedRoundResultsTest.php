<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetPublishedRoundResults;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\PublishedRoundEntry;
use SpeedPuzzling\Web\Results\PublishedRoundResults;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\OfficialEntryProfileState;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The public read model of published official results (docs/features/competitions-management/official-results.md) on
 * OfficialResultsFixture's "Results Cup": Group A is published (Anna 1:00:00 linked to PLAYER_ADMIN, Ben and Cara tied
 * at 1:10:00, Dan 850 / 1000 pcs, Eva did not start, Filip without a result), Group B and Pairs are not.
 */
final class GetPublishedRoundResultsTest extends KernelTestCase
{
    private const string ADMIN_USER_ID = 'auth0|admin003';

    private GetPublishedRoundResults $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPublishedRoundResults::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testUnpublishedRoundHasNoOfficialResults(): void
    {
        self::assertNull($this->query->forRound($this->round(OfficialResultsFixture::ROUND_GROUP_B), null));
        self::assertNull($this->query->forRound($this->round(OfficialResultsFixture::ROUND_PAIRS), null));
    }

    public function testPublishedRoundWithoutARankedResultHasNone(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_FINAL);

        // Anna is in the Final without a result
        self::assertNull($this->query->forRound($this->round(OfficialResultsFixture::ROUND_FINAL), null));
    }

    public function testRanksTheRankedEntriesAndLeavesDidNotStartAndNoResultOut(): void
    {
        $results = $this->results(OfficialResultsFixture::ROUND_GROUP_A);

        self::assertSame([
            OfficialResultsFixture::ENTRY_A_ANNA => 1,
            // Tied for second, by name
            OfficialResultsFixture::ENTRY_A_BEN => 2,
            OfficialResultsFixture::ENTRY_A_CARA => 2,
            OfficialResultsFixture::ENTRY_A_DAN => 4,
        ], self::ranks($results));

        self::assertSame(1000, $results->piecesCount);
        self::assertSame(PuzzleFixture::PUZZLE_1000_05, $results->profilePuzzleId);
        self::assertSame(3600, $results->entries[0]->result->seconds);
        self::assertSame(850, $results->entries[3]->result->piecesPlaced);
        self::assertSame([true, true, false, false], array_map(static fn (PublishedRoundEntry $entry): bool => $entry->qualified, $results->entries));
        self::assertTrue($results->hasQualified());
    }

    public function testNamesAreTheOrganisersAndOnlyLinkedPlayersLinkTheirProfile(): void
    {
        $results = $this->results(OfficialResultsFixture::ROUND_GROUP_A);

        $anna = $results->entries[0]->entrants[0];
        self::assertSame('Anna Fast', $anna->playerName);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $anna->playerId);
        self::assertSame('cz', $anna->playerCountry?->name);

        $ben = $results->entries[1]->entrants[0];
        self::assertSame('Ben Steady', $ben->playerName);
        self::assertNull($ben->playerId);
        self::assertNull($ben->linkedPlayerId);
        self::assertSame('de', $ben->playerCountry?->name);
    }

    public function testPrivatePlayerKeepsTheOrganisersNameWithoutAnythingOfTheProfile(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);
        $this->database->executeStatement("UPDATE player SET avatar = 'avatars/jane.jpg' WHERE id = :id", ['id' => PlayerFixture::PLAYER_PRIVATE]);

        // Guest and a stranger: Gina is first, by the organiser's name, no profile link, no avatar
        foreach ([null, PlayerFixture::PLAYER_WITH_STRIPE] as $viewer) {
            $this->viewAs($viewer);
            $gina = $this->results(OfficialResultsFixture::ROUND_GROUP_B)->entries[0];

            self::assertSame(1, $gina->rank);
            self::assertSame('Gina Quick', $gina->entrants[0]->playerName);
            self::assertNull($gina->entrants[0]->playerId);
            self::assertNull($gina->entrants[0]->playerAvatar);
        }

        // The friend on her allow list and she herself see her profile
        foreach ([PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_PRIVATE] as $viewer) {
            $this->viewAs($viewer);
            $gina = $this->results(OfficialResultsFixture::ROUND_GROUP_B)->entries[0];

            self::assertSame(PlayerFixture::PLAYER_PRIVATE, $gina->entrants[0]->playerId);
            self::assertSame('avatars/jane.jpg', $gina->entrants[0]->playerAvatar);
        }
    }

    public function testRowsHiddenFromTheViewerAreDroppedWithoutRenumbering(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_GROUP_B);

        self::assertSame([
            OfficialResultsFixture::ENTRY_B_GINA => 1,
            OfficialResultsFixture::ENTRY_B_HUGO => 2,
            OfficialResultsFixture::ENTRY_B_IVAN => 3,
        ], self::ranks($this->results(OfficialResultsFixture::ROUND_GROUP_B)));

        // PLAYER_REGULAR (Hugo) blocks PLAYER_PRIVATE (Gina)
        $this->viewAs(PlayerFixture::PLAYER_REGULAR);

        self::assertSame([
            OfficialResultsFixture::ENTRY_B_HUGO => 2,
            OfficialResultsFixture::ENTRY_B_IVAN => 3,
        ], self::ranks($this->results(OfficialResultsFixture::ROUND_GROUP_B)));
    }

    public function testPairWithAHiddenMemberIsDroppedUnlessTheViewerIsInIt(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_PAIRS);
        $this->block(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_PRIVATE);

        $all = [
            OfficialResultsFixture::TEAM_SHARKS => 1,
            OfficialResultsFixture::TEAM_EDGES => 2,
            // Unfinished after every finished one; the unnamed pair without a result is not shown
            OfficialResultsFixture::TEAM_CORNERS => 3,
        ];
        self::assertSame($all, self::ranks($this->results(OfficialResultsFixture::ROUND_PAIRS)));

        // Hugo blocks Gina, but the pair is his own
        $this->viewAs(PlayerFixture::PLAYER_REGULAR);
        self::assertSame($all, self::ranks($this->results(OfficialResultsFixture::ROUND_PAIRS)));

        $this->viewAs(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame([
            OfficialResultsFixture::TEAM_SHARKS => 1,
            OfficialResultsFixture::TEAM_CORNERS => 3,
        ], self::ranks($this->results(OfficialResultsFixture::ROUND_PAIRS)));
    }

    public function testPairsCarryTheirNameAndMembers(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_PAIRS);

        $results = $this->results(OfficialResultsFixture::ROUND_PAIRS);
        $sharks = $results->entries[0];

        self::assertTrue($sharks->isTeam);
        self::assertSame('Puzzle Sharks', $sharks->teamName);
        self::assertSame(['Anna Fast', 'Ben Steady'], array_map(static fn ($member): string => $member->playerName, $sharks->entrants));
        self::assertSame(2000, $results->piecesCount);
        self::assertSame(1700, $results->entries[2]->result->piecesPlaced);
    }

    public function testPeopleRemovedFromTheEventAreLeftOut(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE id = :id',
            ['id' => OfficialResultsFixture::PARTICIPANT_BEN],
        );

        self::assertSame([
            OfficialResultsFixture::ENTRY_A_ANNA => 1,
            OfficialResultsFixture::ENTRY_A_CARA => 2,
            OfficialResultsFixture::ENTRY_A_DAN => 3,
        ], self::ranks($this->results(OfficialResultsFixture::ROUND_GROUP_A)));
    }

    public function testNobodyIsOfferedAnythingWithoutSigningIn(): void
    {
        foreach ($this->results(OfficialResultsFixture::ROUND_GROUP_A)->entries as $entry) {
            self::assertNull($entry->profileState);
        }
    }

    public function testTheViewersOwnEntryIsOfferedUntilTheyHaveATimeInTheRound(): void
    {
        $this->viewAs(PlayerFixture::PLAYER_ADMIN);

        // Only Anna's - the organiser linked the viewer to her, so the entries nobody is linked to are somebody else's
        self::assertSame([
            OfficialResultsFixture::ENTRY_A_ANNA => OfficialEntryProfileState::Offer,
            OfficialResultsFixture::ENTRY_A_BEN => null,
            OfficialResultsFixture::ENTRY_A_CARA => null,
            OfficialResultsFixture::ENTRY_A_DAN => null,
        ], self::states($this->results(OfficialResultsFixture::ROUND_GROUP_A)));

        // Any time in the round - even a different one - is the one on the profile
        $this->addRoundTime(self::ADMIN_USER_ID, '01:00:50');

        self::assertSame(
            OfficialEntryProfileState::OnProfile,
            self::states($this->results(OfficialResultsFixture::ROUND_GROUP_A))[OfficialResultsFixture::ENTRY_A_ANNA],
        );
    }

    public function testAnUnlinkedPersonIsOfferedOnlyToAViewerOfTheSameName(): void
    {
        $this->viewAs(PlayerFixture::PLAYER_WITH_FAVORITES);

        // Linked to nothing, but nobody of these is Michael Johnson - other people's rows offer nothing, one line does
        $results = $this->results(OfficialResultsFixture::ROUND_GROUP_A);
        self::assertSame([null, null, null, null], array_values(self::states($results)));
        self::assertTrue($results->offersConnecting);

        // The organiser typed his name their own way (accent, case) - the same person as far as the import is concerned
        $this->renameParticipant(OfficialResultsFixture::PARTICIPANT_BEN, 'michael  JÓHNSON');

        // (Ben's row sorts after Cara's tie now - by name)
        self::assertSame([
            OfficialResultsFixture::ENTRY_A_ANNA => null,
            OfficialResultsFixture::ENTRY_A_CARA => null,
            OfficialResultsFixture::ENTRY_A_BEN => OfficialEntryProfileState::Offer,
            OfficialResultsFixture::ENTRY_A_DAN => null,
        ], self::states($this->results(OfficialResultsFixture::ROUND_GROUP_A)));
    }

    public function testASameNamedPersonOfAnotherCountryIsNotOffered(): void
    {
        $this->viewAs(PlayerFixture::PLAYER_WITH_FAVORITES);
        // Michael Johnson is German, this one from the US
        $this->renameParticipant(OfficialResultsFixture::PARTICIPANT_CARA, 'Michael Johnson');

        self::assertNull(self::states($this->results(OfficialResultsFixture::ROUND_GROUP_A))[OfficialResultsFixture::ENTRY_A_CARA]);
    }

    public function testAViewerLinkedToAnEntryWithoutARankedResultIsOfferedNothingElse(): void
    {
        // The organiser linked the viewer to Eva, who did not start - Ben's row, even named like them, is not theirs
        $this->database->executeStatement('UPDATE competition_participant SET player_id = :player WHERE id = :id', [
            'player' => PlayerFixture::PLAYER_WITH_FAVORITES,
            'id' => OfficialResultsFixture::PARTICIPANT_EVA,
        ]);
        $this->renameParticipant(OfficialResultsFixture::PARTICIPANT_BEN, 'Michael Johnson');
        $this->viewAs(PlayerFixture::PLAYER_WITH_FAVORITES);

        $results = $this->results(OfficialResultsFixture::ROUND_GROUP_A);

        self::assertSame([null, null, null, null], array_values(self::states($results)));
        self::assertFalse($results->offersConnecting, 'linked already');
    }

    public function testAPairNobodyIsLinkedToIsOfferedToAViewerLinkedToNoEntry(): void
    {
        // Corner Pieces (Cara, Dan - typed names, nobody linked) finished after all
        $this->database->executeStatement(
            'UPDATE competition_team SET result_seconds = 5900, result_pieces_placed = NULL WHERE id = :id',
            ['id' => OfficialResultsFixture::TEAM_CORNERS],
        );
        $this->publish(OfficialResultsFixture::ROUND_PAIRS);

        $this->viewAs(PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertSame(OfficialEntryProfileState::Offer, self::states($this->results(OfficialResultsFixture::ROUND_PAIRS))[OfficialResultsFixture::TEAM_CORNERS]);

        // Hugo's player is in Edge Hunters: his pair is his, Corner Pieces is somebody else's
        $this->viewAs(PlayerFixture::PLAYER_REGULAR);
        $states = self::states($this->results(OfficialResultsFixture::ROUND_PAIRS));
        self::assertNull($states[OfficialResultsFixture::TEAM_CORNERS]);
        self::assertSame(OfficialEntryProfileState::Offer, $states[OfficialResultsFixture::TEAM_EDGES]);
    }

    public function testTheRankedCountIsTheRoundsWhateverTheViewerMaySee(): void
    {
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_ADMIN);
        $this->viewAs(PlayerFixture::PLAYER_WITH_FAVORITES);

        $results = $this->results(OfficialResultsFixture::ROUND_GROUP_A);

        self::assertCount(3, $results->entries, 'Anna is hidden from this viewer');
        self::assertSame(4, $results->rankedCount);
    }

    public function testATimeInTheRoundMarksTheEntryWithItsResultAndOffersNothingElse(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant_round SET result_seconds = 4300 WHERE id = :id',
            ['id' => OfficialResultsFixture::ENTRY_A_CARA],
        );
        $this->renameParticipant(OfficialResultsFixture::PARTICIPANT_CARA, 'Michael Johnson');
        $this->database->executeStatement("UPDATE competition_participant SET country = 'de' WHERE id = :id", ['id' => OfficialResultsFixture::PARTICIPANT_CARA]);
        $this->viewAs(PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->addRoundTime(PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID, '01:11:40');

        self::assertSame([
            OfficialResultsFixture::ENTRY_A_ANNA => null,
            OfficialResultsFixture::ENTRY_A_BEN => null,
            OfficialResultsFixture::ENTRY_A_CARA => OfficialEntryProfileState::OnProfile,
            OfficialResultsFixture::ENTRY_A_DAN => null,
        ], self::states($this->results(OfficialResultsFixture::ROUND_GROUP_A)));
    }

    public function testATimeTheViewerTookPartInCountsToo(): void
    {
        $this->publish(OfficialResultsFixture::ROUND_PAIRS);
        $this->viewAs(PlayerFixture::PLAYER_ADMIN);

        // Anna's pair "Puzzle Sharks" 1:30:00
        self::assertSame(
            OfficialEntryProfileState::Offer,
            self::states($this->results(OfficialResultsFixture::ROUND_PAIRS))[OfficialResultsFixture::TEAM_SHARKS],
        );

        // Somebody else tracked the pair's time with her in it
        $this->addRoundTime(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, '01:30:00', PuzzleFixture::PUZZLE_2000, ['#admin']);

        self::assertSame(
            OfficialEntryProfileState::OnProfile,
            self::states($this->results(OfficialResultsFixture::ROUND_PAIRS))[OfficialResultsFixture::TEAM_SHARKS],
        );
    }

    public function testARoundWithSeveralPuzzlesOffersNothing(): void
    {
        $this->database->executeStatement(
            'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id, hide_until_round_starts, reveal_mode) VALUES (:id, :roundId, :puzzleId, false, :mode)',
            ['id' => Uuid::uuid7()->toString(), 'roundId' => OfficialResultsFixture::ROUND_GROUP_A, 'puzzleId' => PuzzleFixture::PUZZLE_500_03, 'mode' => 'automatic'],
        );
        $this->viewAs(PlayerFixture::PLAYER_ADMIN);

        $results = $this->results(OfficialResultsFixture::ROUND_GROUP_A);

        self::assertNull($results->piecesCount);
        self::assertNull($results->profilePuzzleId);
        self::assertSame([null, null, null, null], array_values(self::states($results)));
    }

    public function testAPuzzleWhosePictureIsStillHiddenOffersNothing(): void
    {
        $this->database->executeStatement(
            "UPDATE puzzle SET hide_image_until = NOW() + INTERVAL '1 day' WHERE id = :id",
            ['id' => PuzzleFixture::PUZZLE_1000_05],
        );
        $this->viewAs(PlayerFixture::PLAYER_ADMIN);

        $results = $this->results(OfficialResultsFixture::ROUND_GROUP_A);

        self::assertNull($results->profilePuzzleId);
        self::assertNull(self::states($results)[OfficialResultsFixture::ENTRY_A_ANNA]);
    }

    public function testRoundOfAnEntryIsFoundOnlyInItsOwnCompetition(): void
    {
        self::assertSame(
            ['round_id' => OfficialResultsFixture::ROUND_PAIRS, 'competition_name' => 'Results Cup'],
            $this->query->roundOfEntry(OfficialResultsFixture::COMPETITION_RESULTS_CUP, RoundEntryRef::team(OfficialResultsFixture::TEAM_SHARKS)),
        );
        self::assertNull($this->query->roundOfEntry(
            '018d0004-0000-0000-0000-000000000001',
            RoundEntryRef::participantRound(OfficialResultsFixture::ENTRY_A_ANNA),
        ));
        // A team id is not a person's entry
        self::assertNull($this->query->roundOfEntry(
            OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            RoundEntryRef::participantRound(OfficialResultsFixture::TEAM_SHARKS),
        ));
    }

    private function round(string $roundId): EditionRoundDetail
    {
        foreach (self::getContainer()->get(GetEditionRounds::class)->forCompetition(OfficialResultsFixture::COMPETITION_RESULTS_CUP) as $round) {
            if ($round->id === $roundId) {
                return $round;
            }
        }

        self::fail('No such round');
    }

    private function results(string $roundId): PublishedRoundResults
    {
        $viewer = self::getContainer()->get(RetrieveLoggedUserProfile::class)->getProfile();
        $results = $this->query->forRound($this->round($roundId), $viewer?->playerId);
        self::assertNotNull($results);

        return $results;
    }

    private function publish(string $roundId): void
    {
        $this->database->executeStatement(
            'UPDATE competition_round SET results_published_at = NOW(), results_first_published_at = NOW() WHERE id = :id',
            ['id' => $roundId],
        );
    }

    private function renameParticipant(string $participantId, string $name): void
    {
        $this->database->executeStatement('UPDATE competition_participant SET name = :name WHERE id = :id', ['name' => $name, 'id' => $participantId]);
    }

    private function viewAs(null|string $playerId): void
    {
        if ($playerId === null) {
            TestingViewer::signOut(self::getContainer());

            return;
        }

        TestingViewer::signIn(self::getContainer(), $playerId);
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }

    /**
     * A time added like the form adds it - the competition and the puzzle put it into the round (SolvingTimeRoundResolver).
     *
     * @param list<string> $groupPlayers
     */
    private function addRoundTime(string $userId, string $time, string $puzzleId = PuzzleFixture::PUZZLE_1000_05, array $groupPlayers = []): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));
    }

    /**
     * @return array<string, int>
     */
    private static function ranks(PublishedRoundResults $results): array
    {
        $ranks = [];
        foreach ($results->entries as $entry) {
            $ranks[$entry->ref->id] = $entry->rank;
        }

        return $ranks;
    }

    /**
     * @return array<string, null|OfficialEntryProfileState>
     */
    private static function states(PublishedRoundResults $results): array
    {
        $states = [];
        foreach ($results->entries as $entry) {
            $states[$entry->ref->id] = $entry->profileState;
        }

        return $states;
    }
}
