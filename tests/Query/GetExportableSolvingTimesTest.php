<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetExportableSolvingTimes;
use SpeedPuzzling\Web\Query\GetPuzzleResultDetail;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Results\ExportableSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\RoundCategory;
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

    /**
     * H12 scenario 14 / P22 (docs/features/events-page/high-frequency-series.md): the result's event in every state - an
     * edition found for a series pick reads like an edition the player picked, a series-level time names its series
     */
    public function testEventColumnsInEveryLinkState(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $player = PlayerFixture::PLAYER_REGULAR_USER_ID;
        $puzzleId = $scenario->puzzle();
        $seriesId = $scenario->series();
        $editionId = $scenario->edition($seriesId, 'Jam No. 154', '2026-09-10');
        $scenario->round($editionId, RoundCategory::Solo, '2026-09-10 19:00', puzzleIds: [$puzzleId]);

        $times = [
            'none' => $scenario->addTime($player, $puzzleId, '2026-09-01', time: '00:51:00'),
            'one-time event' => $scenario->addTime($player, $puzzleId, '2026-09-02', competitionId: CompetitionFixture::COMPETITION_WJPC_2024, time: '00:52:00'),
            'explicit edition' => $scenario->addTime($player, $puzzleId, '2026-09-10', competitionId: $editionId, time: '00:53:00'),
            'automatic' => $scenario->addTime($player, $puzzleId, '2026-09-10', seriesId: $seriesId, time: '00:54:00'),
            // Another puzzle, far from the jam: neither rule finds an edition
            'series-level' => $scenario->addTime($player, $scenario->puzzle('Quiet Harbor'), '2026-08-01', seriesId: $seriesId, time: '00:55:00'),
        ];
        self::assertSame($editionId, $scenario->link($times['automatic'])['competition_id']);
        self::assertNull($scenario->link($times['series-level'])['competition_id']);

        $rows = $this->rowsById($this->query->byPlayerId(PlayerFixture::PLAYER_REGULAR));
        $events = array_map(
            static fn (string $timeId): array => [$rows[$timeId]->eventId, $rows[$timeId]->eventName, $rows[$timeId]->eventSeriesId, $rows[$timeId]->eventSeriesName],
            $times,
        );

        self::assertSame([
            'none' => [null, null, null, null],
            'one-time event' => [CompetitionFixture::COMPETITION_WJPC_2024, 'WJPC 2024', null, null],
            'explicit edition' => [$editionId, 'Jam No. 154', $seriesId, 'Lantern Weekly Jam'],
            'automatic' => [$editionId, 'Jam No. 154', $seriesId, 'Lantern Weekly Jam'],
            'series-level' => [null, null, $seriesId, 'Lantern Weekly Jam'],
        ], $events);
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
