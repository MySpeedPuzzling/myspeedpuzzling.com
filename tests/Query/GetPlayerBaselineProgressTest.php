<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\GetPlayerBaselineProgress;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PlayerSkillCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerBaselineProgressTest extends KernelTestCase
{
    private GetPlayerBaselineProgress $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();

        /** @var GetPlayerBaselineProgress $query */
        $query = $container->get(GetPlayerBaselineProgress::class);
        $this->query = $query;
    }

    public function testCurrentBaselineReturnsValueForPlayerWithData(): void
    {
        $baseline = $this->query->currentBaseline(PlayerFixture::PLAYER_REGULAR, 500);

        self::assertNotNull($baseline);
        self::assertGreaterThan(0, $baseline);
    }

    public function testCurrentBaselineReturnsNullForUnknownPlayer(): void
    {
        $baseline = $this->query->currentBaseline('00000000-0000-0000-0000-000000000099', 500);

        self::assertNull($baseline);
    }

    public function testBaselineAtPercentileReturnsValue(): void
    {
        $baseline = $this->query->baselineAtPercentile(500, 50.0);

        self::assertNotNull($baseline);
        self::assertGreaterThan(0, $baseline);
    }

    public function testFasterPercentileHasLowerBaseline(): void
    {
        $baseline50 = $this->query->baselineAtPercentile(500, 50.0);
        $baseline85 = $this->query->baselineAtPercentile(500, 85.0);

        if ($baseline50 === null || $baseline85 === null) {
            self::markTestSkipped('Not enough data for percentile comparison');
        }

        self::assertLessThanOrEqual($baseline50, $baseline85);
    }

    public function testSolveProgressReturnsDataForPlayer(): void
    {
        $progress = $this->query->solveProgress(PlayerFixture::PLAYER_REGULAR, PlayerSkillCalculator::MIN_SOLVERS_PER_PUZZLE);

        self::assertNotEmpty($progress);
        self::assertArrayHasKey(500, $progress);
        self::assertGreaterThan(0, $progress[500]['baseline_solves']);
    }

    public function testSolveProgressReturnsEmptyForUnknownPlayer(): void
    {
        $progress = $this->query->solveProgress('00000000-0000-0000-0000-000000000099', PlayerSkillCalculator::MIN_SOLVERS_PER_PUZZLE);

        self::assertEmpty($progress);
    }

    /**
     * solveProgress() used to run one extra query per piece count (Sentry WEB-BN).
     */
    public function testSolveProgressIsASingleQueryWhateverTheNumberOfPieceCounts(): void
    {
        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $progress = $this->query->solveProgress(PlayerFixture::PLAYER_REGULAR, 1);

        self::assertGreaterThan(1, count($progress), 'The player needs solo times at several piece counts for this test to mean anything');
        $queries = $debugDataHolder->getData()['default'] ?? [];
        self::assertIsArray($queries);
        self::assertCount(1, $queries);
    }

    public function testSolveProgressMatchesThePerPieceCountQueriesItReplaced(): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        /** @var list<string> $playerIds */
        $playerIds = $connection->fetchFirstColumn("SELECT DISTINCT player_id FROM puzzle_solving_time WHERE puzzling_type = 'solo'");
        self::assertNotEmpty($playerIds);

        $qualifyingSeen = false;
        $notQualifyingSeen = false;

        foreach ([1, 2, 3, PlayerSkillCalculator::MIN_SOLVERS_PER_PUZZLE] as $minSolvers) {
            foreach ($playerIds as $playerId) {
                $progress = $this->query->solveProgress($playerId, $minSolvers);

                self::assertSame($this->previousSolveProgress($connection, $playerId, $minSolvers), $progress, sprintf('player %s, min solvers %d', $playerId, $minSolvers));

                foreach ($progress as $data) {
                    $qualifyingSeen = $qualifyingSeen || $data['qualifying_puzzles'] > 0;
                    $notQualifyingSeen = $notQualifyingSeen || $data['qualifying_puzzles'] < $data['baseline_solves'];
                }
            }
        }

        self::assertTrue($qualifyingSeen, 'Fixtures must contain qualifying puzzles');
        self::assertTrue($notQualifyingSeen, 'Fixtures must contain non-qualifying puzzles');
    }

    /**
     * The implementation solveProgress() had before, verbatim.
     *
     * @return array<int, array{baseline_solves: int, qualifying_puzzles: int}>
     */
    private function previousSolveProgress(Connection $connection, string $playerId, int $minSolversPerPuzzle): array
    {
        /** @var list<array{pieces_count: int|string, solve_count: int|string}> $rows */
        $rows = $connection->executeQuery("
            WITH first_per_puzzle AS (
                SELECT DISTINCT ON (pst.puzzle_id) p.pieces_count
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                WHERE pst.player_id = :playerId
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
                ORDER BY pst.puzzle_id, pst.first_attempt DESC, COALESCE(pst.finished_at, pst.tracked_at) ASC
            )
            SELECT pieces_count, COUNT(*) AS solve_count
            FROM first_per_puzzle
            GROUP BY pieces_count
            ORDER BY pieces_count
        ", ['playerId' => $playerId])->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            $pc = (int) $row['pieces_count'];

            /** @var array{count: int|string}|false $qualRow */
            $qualRow = $connection->executeQuery("
                WITH player_first_attempt_puzzles AS (
                    SELECT DISTINCT pst.puzzle_id
                    FROM puzzle_solving_time pst
                    JOIN puzzle p ON p.id = pst.puzzle_id
                    WHERE pst.player_id = :playerId
                        AND p.pieces_count = :piecesCount
                        AND pst.puzzling_type = 'solo'
                        AND pst.suspicious = false
                        AND pst.seconds_to_solve IS NOT NULL
                ),
                puzzle_solver_counts AS (
                    SELECT pst.puzzle_id
                    FROM puzzle_solving_time pst
                    JOIN puzzle p ON p.id = pst.puzzle_id
                    WHERE p.pieces_count = :piecesCount
                        AND pst.first_attempt = true
                        AND pst.puzzling_type = 'solo'
                        AND pst.suspicious = false
                        AND pst.seconds_to_solve IS NOT NULL
                    GROUP BY pst.puzzle_id
                    HAVING COUNT(*) >= :minSolvers
                )
                SELECT COUNT(*) AS count
                FROM player_first_attempt_puzzles pfp
                JOIN puzzle_difficulty pd ON pd.puzzle_id = pfp.puzzle_id
                JOIN puzzle_solver_counts psc ON psc.puzzle_id = pfp.puzzle_id
                WHERE pd.difficulty_score IS NOT NULL AND pd.confidence != 'insufficient'
            ", ['playerId' => $playerId, 'piecesCount' => $pc, 'minSolvers' => $minSolversPerPuzzle])->fetchAssociative();

            $result[$pc] = [
                'baseline_solves' => (int) $row['solve_count'],
                'qualifying_puzzles' => $qualRow !== false ? (int) $qualRow['count'] : 0,
            ];
        }

        return $result;
    }
}
