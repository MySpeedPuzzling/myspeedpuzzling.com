<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetExportableSolvingTimes;
use SpeedPuzzling\Web\Query\GetPuzzleResultDetail;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Results\ExportableSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The export's player_rank / puzzle_total_solved are the puzzle page's leaderboard: the same numbers the profile
 * and the result detail show (user report: the export ranked against every attempt, rank above the total).
 */
final class GetExportableSolvingTimesTest extends KernelTestCase
{
    private GetExportableSolvingTimes $query;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->query = self::getContainer()->get(GetExportableSolvingTimes::class);
    }

    public function testBestTimesCarryTheLeaderboardRank(): void
    {
        $playerId = PlayerFixture::PLAYER_REGULAR;
        TestingViewer::signIn(self::getContainer(), $playerId);

        $getRanking = self::getContainer()->get(GetRanking::class);
        $getResultDetail = self::getContainer()->get(GetPuzzleResultDetail::class);

        $soloChecked = 0;
        $groupChecked = 0;

        foreach ($this->query->byPlayerId($playerId) as $row) {
            if ($row->secondsToSolve === null) {
                self::assertNull($row->playerRank, "Relax time {$row->timeId}");
                continue;
            }

            self::assertNotNull($row->playerRank, "Time {$row->timeId}");
            self::assertLessThanOrEqual($row->puzzleTotalSolved, $row->playerRank, "Time {$row->timeId}");

            if ($row->type === 'solo') {
                $ranking = $getRanking->ofPuzzleForPlayer($row->puzzleId, $playerId);
                self::assertNotNull($ranking);
                self::assertSame($ranking->totalPlayers, $row->puzzleTotalSolved, "Total of {$row->timeId}");

                if ($ranking->time === $row->secondsToSolve) {
                    self::assertSame($ranking->rank, $row->playerRank, "Rank of {$row->timeId}");
                    $soloChecked++;
                }

                continue;
            }

            $standing = $getResultDetail->standing($row->puzzleId, $row->type, $this->teamIdOf($row), $playerId);
            self::assertNotNull($standing);
            self::assertSame($standing->total, $row->puzzleTotalSolved, "Total of {$row->timeId}");

            if ($standing->subjectTime === $row->secondsToSolve) {
                self::assertSame($standing->rank, $row->playerRank, "Rank of {$row->timeId}");
                $groupChecked++;
            }
        }

        self::assertGreaterThan(0, $soloChecked);
        self::assertGreaterThan(0, $groupChecked);
    }

    public function testAnySlowerAttemptRanksAmongEverybodyElsesBestTimes(): void
    {
        // PUZZLE_500_01, private players left out: PLAYER_ADMIN 1200 (TIME_32, next best 1780), PLAYER_REGULAR 1750
        // (TIME_36) and 1800 (TIME_01), PLAYER_WITH_STRIPE 2100, PLAYER_WITH_FAVORITES 3000
        $this->markSuspicious(PuzzleSolvingTimeFixture::TIME_32);

        $rows = $this->rowsById($this->query->byPlayerId(PlayerFixture::PLAYER_REGULAR));

        self::assertSame(1, $rows[PuzzleSolvingTimeFixture::TIME_36]->playerRank);
        self::assertSame(4, $rows[PuzzleSolvingTimeFixture::TIME_36]->puzzleTotalSolved);

        // Behind PLAYER_ADMIN's 1780, never behind the player's own 1750
        self::assertSame(2, $rows[PuzzleSolvingTimeFixture::TIME_01]->playerRank);
        self::assertSame(4, $rows[PuzzleSolvingTimeFixture::TIME_01]->puzzleTotalSolved);
    }

    public function testSuspiciousTimeHasNoRank(): void
    {
        $this->markSuspicious(PuzzleSolvingTimeFixture::TIME_32);

        $rows = $this->rowsById($this->query->byPlayerId(PlayerFixture::PLAYER_ADMIN));

        self::assertNull($rows[PuzzleSolvingTimeFixture::TIME_32]->playerRank);
    }

    private function markSuspicious(string $timeId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id',
            ['id' => $timeId],
        );
    }

    /**
     * @param array<ExportableSolvingTime> $rows
     * @return array<string, ExportableSolvingTime>
     */
    private function rowsById(array $rows): array
    {
        $byId = [];

        foreach ($rows as $row) {
            $byId[$row->timeId] = $row;
        }

        return $byId;
    }

    private function teamIdOf(ExportableSolvingTime $row): string
    {
        /** @var string $teamId */
        $teamId = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $row->timeId],
        );

        return $teamId;
    }
}
