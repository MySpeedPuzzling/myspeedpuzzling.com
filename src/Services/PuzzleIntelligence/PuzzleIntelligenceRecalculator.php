<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\SkillTier;

readonly final class PuzzleIntelligenceRecalculator
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private PlayerBaselineCalculator $baselineCalculator,
        private PuzzleDifficultyCalculator $difficultyCalculator,
        private PlayerSkillCalculator $skillCalculator,
        private DerivedMetricsCalculator $derivedMetricsCalculator,
        private MspEloCalculator $eloCalculator,
    ) {
    }

    /**
     * @return array{baselines: int, difficulties: int, metrics: int, skills: int, elo: int, history: int}
     */
    public function recalculate(null|string $specificPlayer = null, null|string $specificPuzzle = null): array
    {
        $now = $this->clock->now();

        $baselines = $this->computeBaselines($now, $specificPlayer);
        $difficulties = $this->computePuzzleDifficulty($now, $specificPuzzle);
        $metrics = $this->computeDerivedMetrics($specificPuzzle);
        $skills = $this->computePlayerSkills($now, $specificPlayer);
        $elo = $this->computeEloRatings($now, $specificPlayer);
        $history = $this->recordSkillHistory($now);

        return [
            'baselines' => $baselines,
            'difficulties' => $difficulties,
            'metrics' => $metrics,
            'skills' => $skills,
            'elo' => $elo,
            'history' => $history,
        ];
    }

    private function computeBaselines(\DateTimeImmutable $now, null|string $specificPlayer): int
    {
        $players = $this->getPlayersWithSolves($specificPlayer);
        $pieceCounts = $this->getDistinctPieceCounts();
        $count = 0;

        foreach ($players as $playerId) {
            foreach ($pieceCounts as $piecesCount) {
                $result = $this->baselineCalculator->calculateForPlayer($playerId, $piecesCount);

                if ($result !== null) {
                    $this->upsertBaseline($playerId, $piecesCount, $result['baseline_seconds'], $result['qualifying_count'], $now);
                    $count++;
                } else {
                    $this->deleteBaseline($playerId, $piecesCount);
                }
            }
        }

        return $count;
    }

    private function computePuzzleDifficulty(\DateTimeImmutable $now, null|string $specificPuzzle): int
    {
        $puzzleIds = $this->getPuzzlesWithSolves($specificPuzzle);
        $count = 0;

        foreach ($puzzleIds as $puzzleId) {
            $result = $this->difficultyCalculator->calculateForPuzzle($puzzleId);
            $this->upsertDifficulty($puzzleId, $result, $now);

            if ($result['difficulty_score'] !== null) {
                $count++;
            }
        }

        return $count;
    }

    private function computeDerivedMetrics(null|string $specificPuzzle): int
    {
        $puzzleIds = $this->getPuzzlesWithDifficulty($specificPuzzle);
        $count = 0;

        foreach ($puzzleIds as $puzzleId) {
            $metrics = $this->derivedMetricsCalculator->calculateForPuzzle($puzzleId);
            $this->updateDerivedMetrics($puzzleId, $metrics);
            $count++;
        }

        return $count;
    }

    private function computePlayerSkills(\DateTimeImmutable $now, null|string $specificPlayer): int
    {
        $players = $this->getPlayersWithBaselines($specificPlayer);
        $pieceCounts = $this->getDistinctPieceCounts();
        $count = 0;

        foreach ($players as $playerId) {
            foreach ($pieceCounts as $piecesCount) {
                $result = $this->skillCalculator->calculateForPlayer($playerId, $piecesCount);

                if ($result !== null) {
                    $this->upsertSkill($playerId, $piecesCount, $result, $now);
                    $count++;
                } else {
                    $this->deleteSkill($playerId, $piecesCount);
                }
            }
        }

        return $count;
    }

    private function computeEloRatings(\DateTimeImmutable $now, null|string $specificPlayer): int
    {
        $players = $this->getPlayersWithBaselines($specificPlayer);
        $count = 0;

        // MSP-ELO is 500pc only
        $piecesCount = 500;

        foreach ($players as $playerId) {
            if (!$this->eloCalculator->isEligible($playerId, $piecesCount)) {
                continue;
            }

            $eloRating = $this->eloCalculator->calculateForPlayer($playerId, $piecesCount, 'all-time');
            $this->upsertElo($playerId, $piecesCount, 'all-time', $eloRating, $now);
            $count++;
        }

        // Clean up any non-500pc ELO data from before scope change
        $this->connection->executeStatement(
            'DELETE FROM player_elo WHERE pieces_count != :piecesCount',
            ['piecesCount' => $piecesCount],
        );

        return $count;
    }

    private function recordSkillHistory(\DateTimeImmutable $now): int
    {
        $monthStart = new \DateTimeImmutable($now->format('Y-m-01'));

        $this->connection->executeStatement("
            INSERT INTO player_skill_history (id, player_id, pieces_count, month, baseline_seconds, skill_tier, skill_percentile)
            SELECT
                gen_random_uuid(),
                pb.player_id,
                pb.pieces_count,
                :month,
                pb.baseline_seconds,
                ps.skill_tier,
                ps.skill_percentile
            FROM player_baseline pb
            LEFT JOIN player_skill ps ON ps.player_id = pb.player_id AND ps.pieces_count = pb.pieces_count
            ON CONFLICT (player_id, pieces_count, month) DO UPDATE SET
                baseline_seconds = EXCLUDED.baseline_seconds,
                skill_tier = EXCLUDED.skill_tier,
                skill_percentile = EXCLUDED.skill_percentile
        ", [
            'month' => $monthStart->format('Y-m-d H:i:s'),
        ]);

        /** @var int|string $count */
        $count = $this->connection->fetchOne("
            SELECT COUNT(*) FROM player_skill_history WHERE month = :month
        ", ['month' => $monthStart->format('Y-m-d H:i:s')]);

        return (int) $count;
    }

    /**
     * @return list<string>
     */
    private function getPlayersWithSolves(null|string $specificPlayer): array
    {
        if ($specificPlayer !== null) {
            return [$specificPlayer];
        }

        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT player_id FROM puzzle_solving_time WHERE seconds_to_solve IS NOT NULL',
        );
    }

    /**
     * @return list<string>
     */
    private function getPlayersWithBaselines(null|string $specificPlayer): array
    {
        if ($specificPlayer !== null) {
            return [$specificPlayer];
        }

        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT player_id FROM player_baseline',
        );
    }

    /**
     * @return list<int>
     */
    private function getDistinctPieceCounts(): array
    {
        /** @var list<int|string> $raw */
        $raw = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT pieces_count FROM puzzle WHERE approved = true ORDER BY pieces_count',
        );

        return array_map(static fn (int|string $v): int => (int) $v, $raw);
    }

    /**
     * @return list<string>
     */
    private function getPuzzlesWithSolves(null|string $specificPuzzle): array
    {
        if ($specificPuzzle !== null) {
            return [$specificPuzzle];
        }

        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT puzzle_id FROM puzzle_solving_time WHERE seconds_to_solve IS NOT NULL',
        );
    }

    /**
     * @return list<string>
     */
    private function getPuzzlesWithDifficulty(null|string $specificPuzzle): array
    {
        if ($specificPuzzle !== null) {
            return [$specificPuzzle];
        }

        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT puzzle_id FROM puzzle_difficulty WHERE difficulty_score IS NOT NULL',
        );
    }

    private function upsertBaseline(string $playerId, int $piecesCount, int $baselineSeconds, int $qualifyingCount, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement("
            INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, computed_at)
            VALUES (gen_random_uuid(), :playerId, :piecesCount, :baselineSeconds, :qualifyingCount, :now)
            ON CONFLICT (player_id, pieces_count) DO UPDATE SET
                baseline_seconds = EXCLUDED.baseline_seconds,
                qualifying_solves_count = EXCLUDED.qualifying_solves_count,
                computed_at = EXCLUDED.computed_at
        ", [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
            'baselineSeconds' => $baselineSeconds,
            'qualifyingCount' => $qualifyingCount,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    private function deleteBaseline(string $playerId, int $piecesCount): void
    {
        $this->connection->executeStatement(
            'DELETE FROM player_baseline WHERE player_id = :playerId AND pieces_count = :piecesCount',
            ['playerId' => $playerId, 'piecesCount' => $piecesCount],
        );
    }

    /**
     * @param array{difficulty_score: float|null, difficulty_tier: DifficultyTier|null, confidence: MetricConfidence, sample_size: int} $result
     */
    private function upsertDifficulty(string $puzzleId, array $result, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement("
            INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, computed_at)
            VALUES (:puzzleId, :score, :tier, :confidence, :sampleSize, :now)
            ON CONFLICT (puzzle_id) DO UPDATE SET
                difficulty_score = EXCLUDED.difficulty_score,
                difficulty_tier = EXCLUDED.difficulty_tier,
                confidence = EXCLUDED.confidence,
                sample_size = EXCLUDED.sample_size,
                computed_at = EXCLUDED.computed_at
        ", [
            'puzzleId' => $puzzleId,
            'score' => $result['difficulty_score'],
            'tier' => $result['difficulty_tier']?->value,
            'confidence' => $result['confidence']->value,
            'sampleSize' => $result['sample_size'],
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array{memorability_score: float|null, skill_sensitivity_score: float|null, predictability_score: float|null, box_dependence_score: float|null} $metrics
     */
    private function updateDerivedMetrics(string $puzzleId, array $metrics): void
    {
        $this->connection->executeStatement("
            UPDATE puzzle_difficulty SET
                memorability_score = :memorability,
                skill_sensitivity_score = :skillSensitivity,
                predictability_score = :predictability,
                box_dependence_score = :boxDependence
            WHERE puzzle_id = :puzzleId
        ", [
            'puzzleId' => $puzzleId,
            'memorability' => $metrics['memorability_score'],
            'skillSensitivity' => $metrics['skill_sensitivity_score'],
            'predictability' => $metrics['predictability_score'],
            'boxDependence' => $metrics['box_dependence_score'],
        ]);
    }

    /**
     * @param array{skill_score: float, skill_tier: SkillTier, skill_percentile: float, confidence: MetricConfidence, qualifying_puzzles_count: int} $result
     */
    private function upsertSkill(string $playerId, int $piecesCount, array $result, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement("
            INSERT INTO player_skill (id, player_id, pieces_count, skill_score, skill_tier, skill_percentile, confidence, qualifying_puzzles_count, computed_at)
            VALUES (gen_random_uuid(), :playerId, :piecesCount, :skillScore, :skillTier, :percentile, :confidence, :qualifyingCount, :now)
            ON CONFLICT (player_id, pieces_count) DO UPDATE SET
                skill_score = EXCLUDED.skill_score,
                skill_tier = EXCLUDED.skill_tier,
                skill_percentile = EXCLUDED.skill_percentile,
                confidence = EXCLUDED.confidence,
                qualifying_puzzles_count = EXCLUDED.qualifying_puzzles_count,
                computed_at = EXCLUDED.computed_at
        ", [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
            'skillScore' => $result['skill_score'],
            'skillTier' => $result['skill_tier']->value,
            'percentile' => $result['skill_percentile'],
            'confidence' => $result['confidence']->value,
            'qualifyingCount' => $result['qualifying_puzzles_count'],
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    private function deleteSkill(string $playerId, int $piecesCount): void
    {
        $this->connection->executeStatement(
            'DELETE FROM player_skill WHERE player_id = :playerId AND pieces_count = :piecesCount',
            ['playerId' => $playerId, 'piecesCount' => $piecesCount],
        );
    }

    private function upsertElo(string $playerId, int $piecesCount, string $period, int $eloRating, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement("
            INSERT INTO player_elo (id, player_id, pieces_count, period, elo_rating, matches_count, last_solve_at, computed_at)
            VALUES (gen_random_uuid(), :playerId, :piecesCount, :period, :eloRating, 0, :now, :now)
            ON CONFLICT (player_id, pieces_count, period) DO UPDATE SET
                elo_rating = EXCLUDED.elo_rating,
                computed_at = EXCLUDED.computed_at
        ", [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
            'period' => $period,
            'eloRating' => $eloRating,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
