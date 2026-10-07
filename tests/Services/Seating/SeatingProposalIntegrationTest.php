<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Seating;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetSeatingSeedTimes;
use SpeedPuzzling\Web\Results\SeatingProposal;
use SpeedPuzzling\Web\Results\SeatingProposalRow;
use SpeedPuzzling\Web\Services\SeatingProposer;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\SeatingSource;
use SpeedPuzzling\Web\Value\TeamComposition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Seating proposals on the "Results Cup" fixture event (docs/features/competitions-management/seating.md): its Final
 * filled with people from Group A and Group B, its Pairs Final with pairs of known and unknown speed.
 */
final class SeatingProposalIntegrationTest extends KernelTestCase
{
    private Connection $database;
    private SeatingProposer $proposer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->proposer = self::getContainer()->get(SeatingProposer::class);
    }

    public function testTheFinalIsSeatedByTheGroupResultsByDefault(): void
    {
        $this->fillTheFinal();

        $proposal = $this->propose(OfficialResultsFixture::ROUND_FINAL);

        self::assertSame(SeatingSource::EarlierRounds, $proposal->source);
        self::assertSame(SeatingSource::EarlierRounds, $proposal->defaultSource);
        // Group winners first (A before B on equal terms), then the seconds by their time ÷ their group's winner
        // (Hugo 4500 / 3900 before Ben 4200 / 3600), then Ivan; Filip has no result in Group A - last
        self::assertSame(['Anna Fast', 'Gina Quick', 'Hugo Slow', 'Ben Steady', 'Ivan Last', 'Filip Pending'], self::names($proposal));
        self::assertSame([1, 2, 3, 4, 5, 6], self::tables($proposal));
        self::assertSame([true, true, true, true, true, false], array_map(static fn (SeatingProposalRow $row): bool => $row->hasData, $proposal->rows));
        self::assertSame('Group A', $proposal->rows[0]->basisRoundName);
        self::assertSame(1, $proposal->rows[0]->basisRank);
        self::assertSame('Group B', $proposal->rows[2]->basisRoundName);
        self::assertSame(2, $proposal->rows[2]->basisRank);
        self::assertSame(5, $proposal->withData());

        $json = $proposal->jsonSerialize();
        self::assertSame(1, $json['withoutData']);
        self::assertSame('fastest_first', $json['order']);
    }

    public function testSlowestFirstFromAnotherFirstTable(): void
    {
        $this->fillTheFinal();

        $proposal = $this->propose(OfficialResultsFixture::ROUND_FINAL, SeatingSource::EarlierRounds, slowestFirst: true, firstTable: 101);

        self::assertSame(['Ivan Last', 'Ben Steady', 'Hugo Slow', 'Gina Quick', 'Anna Fast', 'Filip Pending'], self::names($proposal));
        self::assertSame([101, 102, 103, 104, 105, 106], self::tables($proposal));
    }

    public function testMySpeedPuzzlingTimesOrderPeopleWithoutRevealingThem(): void
    {
        $this->fillTheFinal();
        $now = self::getContainer()->get(ClockInterface::class)->now();

        // Anna (admin): a baseline for 6000 pieces
        $this->baseline(PlayerFixture::PLAYER_ADMIN, 6000, 20000);
        // Gina is private to the organiser: her baseline must not count
        $this->baseline(PlayerFixture::PLAYER_PRIVATE, 6000, 10000);
        // Hugo (regular): no baseline - the median of his recent own times; too old and suspicious ones left out
        $this->soloTime(PlayerFixture::PLAYER_REGULAR, 18000, $now->modify('-1 month'));
        $this->soloTime(PlayerFixture::PLAYER_REGULAR, 19000, $now->modify('-2 months'));
        $this->soloTime(PlayerFixture::PLAYER_REGULAR, 5000, $now->modify('-30 months'));
        $this->soloTime(PlayerFixture::PLAYER_REGULAR, 1000, $now->modify('-1 week'), suspicious: true);

        $times = self::getContainer()->get(GetSeatingSeedTimes::class);
        self::assertSame(6000, $times->roundPiecesCount(OfficialResultsFixture::ROUND_FINAL));
        $byPlayer = $times->forPlayers([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE], 6000);
        ksort($byPlayer);
        self::assertSame([
            PlayerFixture::PLAYER_REGULAR => 18500,
            PlayerFixture::PLAYER_ADMIN => 20000,
            PlayerFixture::PLAYER_WITH_STRIPE => null,
        ], $byPlayer);

        $proposal = $this->propose(OfficialResultsFixture::ROUND_FINAL, SeatingSource::MspTimes);

        self::assertSame(6000, $proposal->piecesCount);
        self::assertFalse($proposal->piecesCountAssumed);
        self::assertSame(['Hugo Slow', 'Anna Fast', 'Ben Steady', 'Filip Pending', 'Gina Quick', 'Ivan Last'], self::names($proposal));
        self::assertSame(2, $proposal->withData());
        self::assertSame(2, $proposal->withDataBySource[SeatingSource::MspTimes->value]);
        self::assertSame(5, $proposal->withDataBySource[SeatingSource::EarlierRounds->value]);

        // Only the order travels - never anybody's time
        $json = (string) json_encode($proposal, JSON_THROW_ON_ERROR);
        foreach (['18500', '20000', '10000', '18000', '19000'] as $seconds) {
            self::assertStringNotContainsString($seconds, $json);
        }
        self::assertNull($proposal->rows[0]->basisRoundName);
    }

    public function testPairsBySharedTimesElseTheirMembersAndANoPuzzleRoundAssumes500Pieces(): void
    {
        $now = self::getContainer()->get(ClockInterface::class)->now();
        $round = OfficialResultsFixture::ROUND_PAIRS_FINAL;

        // Ben becomes a player
        $this->database->executeStatement('UPDATE competition_participant SET player_id = :player WHERE id = :id', [
            'player' => PlayerFixture::PLAYER_WITH_FAVORITES,
            'id' => OfficialResultsFixture::PARTICIPANT_BEN,
        ]);

        $this->pair($round, 'Speedy', [OfficialResultsFixture::PARTICIPANT_ANNA, OfficialResultsFixture::PARTICIPANT_HUGO]);
        $this->pair($round, 'Middle', [OfficialResultsFixture::PARTICIPANT_BEN, OfficialResultsFixture::PARTICIPANT_CARA]);
        $this->pair($round, 'Hidden', [OfficialResultsFixture::PARTICIPANT_GINA, OfficialResultsFixture::PARTICIPANT_IVAN]);
        $this->pair($round, 'Absent', [OfficialResultsFixture::PARTICIPANT_DAN, OfficialResultsFixture::PARTICIPANT_EVA]);

        // Anna + Hugo puzzled together: their pair time, whatever their own times are
        $puzzlingTeamId = Uuid::uuid7()->toString();
        $this->database->insert('puzzling_team', [
            'id' => $puzzlingTeamId,
            'composition_key' => TeamComposition::keyFromMemberKeys([PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR]),
            'size' => 2,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $this->database->insert('puzzle_solving_time', [
            'id' => Uuid::uuid7()->toString(),
            'player_id' => PlayerFixture::PLAYER_ADMIN,
            'puzzle_id' => PuzzleFixture::PUZZLE_500_01,
            'seconds_to_solve' => 1500,
            'tracked_at' => $now->modify('-1 month')->format('Y-m-d H:i:s'),
            'verified' => 'false',
            'puzzling_type' => 'duo',
            'puzzlers_count' => 2,
            'puzzling_team_id' => $puzzlingTeamId,
        ]);
        $this->baseline(PlayerFixture::PLAYER_ADMIN, 500, 9000);
        $this->baseline(PlayerFixture::PLAYER_REGULAR, 500, 9000);
        // Ben + Cara never puzzled together: Ben's time (Cara has none); Gina is private, Ivan unknown
        $this->baseline(PlayerFixture::PLAYER_WITH_FAVORITES, 500, 2600);
        $this->baseline(PlayerFixture::PLAYER_PRIVATE, 500, 1000);

        $proposal = $this->propose($round, SeatingSource::MspTimes);

        self::assertTrue($proposal->piecesCountAssumed);
        self::assertSame(GetSeatingSeedTimes::DEFAULT_PIECES_COUNT, $proposal->piecesCount);
        self::assertSame(['Speedy', 'Middle', 'Absent', 'Hidden'], self::names($proposal));
        self::assertSame(2, $proposal->withData());
    }

    public function testThePairsFinalIsSeatedByThePairsRound(): void
    {
        $round = OfficialResultsFixture::ROUND_PAIRS_FINAL;
        // The same people as Puzzle Sharks (1st), Edge Hunters (2nd) and Corner Pieces (3rd, unfinished)
        $this->pair($round, 'Sharks again', [OfficialResultsFixture::PARTICIPANT_BEN, OfficialResultsFixture::PARTICIPANT_ANNA]);
        $this->pair($round, 'Corners again', [OfficialResultsFixture::PARTICIPANT_CARA, OfficialResultsFixture::PARTICIPANT_DAN]);
        $this->pair($round, 'Edges again', [OfficialResultsFixture::PARTICIPANT_GINA, OfficialResultsFixture::PARTICIPANT_HUGO]);
        // A new pair of people from the unnamed pair and Group B
        $this->pair($round, 'New pair', [OfficialResultsFixture::PARTICIPANT_EVA, OfficialResultsFixture::PARTICIPANT_IVAN]);

        $proposal = $this->propose($round);

        self::assertSame(SeatingSource::EarlierRounds, $proposal->source);
        self::assertSame(['Sharks again', 'Edges again', 'Corners again', 'New pair'], self::names($proposal));
        self::assertSame('Pairs', $proposal->rows[0]->basisRoundName);
    }

    public function testADrawRepeatsByItsNumberAndByNameIsAlphabetical(): void
    {
        $round = OfficialResultsFixture::ROUND_GROUP_A;

        $draw = $this->propose($round, SeatingSource::Random, randomSeed: 7);
        self::assertSame(7, $draw->randomSeed);
        self::assertSame(self::names($draw), self::names($this->propose($round, SeatingSource::Random, randomSeed: 7)));
        self::assertEqualsCanonicalizing(['Anna Fast', 'Ben Steady', 'Cara Tied', 'Dan Unfinished', 'Eva Noshow', 'Filip Pending'], self::names($draw));
        self::assertSame(6, $draw->withData());

        $byName = $this->propose($round, SeatingSource::Name, randomSeed: 7);
        self::assertNull($byName->randomSeed);
        self::assertSame(['Anna Fast', 'Ben Steady', 'Cara Tied', 'Dan Unfinished', 'Eva Noshow', 'Filip Pending'], self::names($byName));
        // The current tables come along, so the page can say how many would change
        self::assertSame([1, 2, 3, 4, 5, null], array_map(static fn (SeatingProposalRow $row): null|int => $row->currentTableNumber, $byName->rows));
    }

    public function testAFirstRoundWithoutAnyDataIsByName(): void
    {
        // Group B's people have no earlier round and no times (Gina is private, Hugo has no 5000-piece times)
        $proposal = $this->propose(OfficialResultsFixture::ROUND_GROUP_B);

        self::assertSame(SeatingSource::Name, $proposal->defaultSource);
        self::assertSame(['Gina Quick', 'Hugo Slow', 'Ivan Last'], self::names($proposal));
    }

    private function propose(
        string $roundId,
        null|SeatingSource $source = null,
        bool $slowestFirst = false,
        int $randomSeed = 1,
        int $firstTable = 1,
    ): SeatingProposal {
        return $this->proposer->propose(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $roundId, $source, $slowestFirst, $randomSeed, $firstTable, 'en');
    }

    /**
     * Ben, Gina, Hugo, Ivan and Filip join Anna in the Final.
     */
    private function fillTheFinal(): void
    {
        $participantIds = [
            OfficialResultsFixture::PARTICIPANT_BEN,
            OfficialResultsFixture::PARTICIPANT_GINA,
            OfficialResultsFixture::PARTICIPANT_HUGO,
            OfficialResultsFixture::PARTICIPANT_IVAN,
            OfficialResultsFixture::PARTICIPANT_FILIP,
        ];

        foreach ($participantIds as $participantId) {
            $this->database->insert('competition_participant_round', [
                'id' => Uuid::uuid7()->toString(),
                'participant_id' => $participantId,
                'round_id' => OfficialResultsFixture::ROUND_FINAL,
            ]);
        }
    }

    /**
     * @param list<string> $participantIds
     */
    private function pair(string $roundId, string $name, array $participantIds): void
    {
        $teamId = Uuid::uuid7()->toString();
        $this->database->insert('competition_team', ['id' => $teamId, 'round_id' => $roundId, 'name' => $name]);

        foreach ($participantIds as $participantId) {
            $this->database->insert('competition_participant_round', [
                'id' => Uuid::uuid7()->toString(),
                'participant_id' => $participantId,
                'round_id' => $roundId,
                'team_id' => $teamId,
            ]);
        }
    }

    private function baseline(string $playerId, int $piecesCount, int $seconds): void
    {
        // The test database has 500-piece baselines of its own (computed from the fixtures)
        $this->database->executeStatement(
            <<<SQL
INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, computed_at)
VALUES (:id, :player, :pieces, :seconds, 5, '2026-01-01 00:00:00')
ON CONFLICT (player_id, pieces_count) DO UPDATE SET baseline_seconds = EXCLUDED.baseline_seconds
SQL,
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'pieces' => $piecesCount, 'seconds' => $seconds],
        );
    }

    private function soloTime(string $playerId, int $seconds, \DateTimeImmutable $at, bool $suspicious = false): void
    {
        $this->database->insert('puzzle_solving_time', [
            'id' => Uuid::uuid7()->toString(),
            'player_id' => $playerId,
            'puzzle_id' => PuzzleFixture::PUZZLE_6000,
            'seconds_to_solve' => $seconds,
            'tracked_at' => $at->format('Y-m-d H:i:s'),
            'finished_at' => $at->format('Y-m-d H:i:s'),
            'verified' => 'false',
            'suspicious' => $suspicious ? 'true' : 'false',
        ]);
    }

    /**
     * @return list<string>
     */
    private static function names(SeatingProposal $proposal): array
    {
        return array_map(static fn (SeatingProposalRow $row): string => $row->displayName, $proposal->rows);
    }

    /**
     * @return list<int>
     */
    private static function tables(SeatingProposal $proposal): array
    {
        return array_map(static fn (SeatingProposalRow $row): int => $row->tableNumber, $proposal->rows);
    }
}
