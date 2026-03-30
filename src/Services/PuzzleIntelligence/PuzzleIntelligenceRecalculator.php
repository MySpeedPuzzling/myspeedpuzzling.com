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
    /** @var list<int> Piece counts for which player skills are computed */
    public const array SKILL_PIECES_COUNTS = [500];

    /** @var list<int> Piece counts for which MSP-ELO is computed */
    public const array ELO_PIECES_COUNTS = [500];

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
     * @return array{baselines_direct: int, baselines_interpolated: int, scaling_exponent: float, difficulties: int, metrics: int, skills: int, elo: int, history: int}
     */
    public function recalculate(null|string $specificPlayer = null, null|string $specificPuzzle = null): array
    {
        $now = $this->clock->now();

        // Level 1+2: Baselines (direct + interpolated)
        $baselines = $this->computeBaselines($now, $specificPlayer);

        // Level 3: Puzzle difficulty
        $difficulties = $this->computePuzzleDifficulty($now, $specificPuzzle);

        // Level 4+5: Derived metrics, player skills, MSP-ELO (all depend on difficulty)
        $metrics = $this->computeDerivedMetrics($specificPuzzle);
        $skills = $this->computePlayerSkills($now, $specificPlayer);
        $elo = $this->computeEloRatings($now, $specificPlayer);

        // Level 6: History snapshots
        $history = $this->recordSkillHistory($now);

        return [
            'baselines_direct' => $baselines['direct'],
            'baselines_interpolated' => $baselines['interpolated'],
            'scaling_exponent' => $baselines['scaling_exponent'],
            'difficulties' => $difficulties,
            'metrics' => $metrics,
            'skills' => $skills,
            'elo' => $elo,
            'history' => $history,
        ];
    }

    /**
     * @return array{direct: int, interpolated: int, scaling_exponent: float}
     */
    private function computeBaselines(\DateTimeImmutable $now, null|string $specificPlayer): array
    {
        // Pass 1: Compute direct baselines
        $players = $this->getPlayersWithSolves($specificPlayer);
        $pieceCounts = $this->getDistinctPieceCounts();
        $directCount = 0;

        foreach ($players as $playerId) {
            foreach ($pieceCounts as $piecesCount) {
                $result = $this->baselineCalculator->calculateForPlayer($playerId, $piecesCount);

                if ($result !== null) {
                    $this->upsertBaseline($playerId, $piecesCount, $result['baseline_seconds'], $result['qualifying_count'], $now, 'direct');
                    $directCount++;
                } else {
                    $this->deleteBaseline($playerId, $piecesCount);
                }
            }
        }

        // Pass 2: Compute global scaling exponent + interpolated/extrapolated baselines
        $scalingExponent = $this->baselineCalculator->computeScalingExponent();
        $gaps = $this->baselineCalculator->findBaselineGaps();
        $interpolatedCount = 0;

        // Cache direct baselines per player to avoid repeated queries
        $playerBaselineCache = [];

        foreach ($gaps as $gap) {
            $playerId = $gap['player_id'];
            $targetPieces = $gap['pieces_count'];

            if (!isset($playerBaselineCache[$playerId])) {
                $playerBaselineCache[$playerId] = $this->baselineCalculator->getDirectBaselinesForPlayer($playerId);
            }

            $directBaselines = $playerBaselineCache[$playerId];

            if ($directBaselines === []) {
                continue;
            }

            $pieceCounts = array_keys($directBaselines);
            $lower = null;
            $upper = null;

            // Find bracketing baselines
            foreach ($pieceCounts as $pc) {
                if ($pc < $targetPieces) {
                    $lower = $pc;
                }

                if ($pc > $targetPieces && $upper === null) {
                    $upper = $pc;
                }
            }

            if ($lower !== null && $upper !== null) {
                // Interpolated: two brackets exist
                $baseline = $this->baselineCalculator->interpolateBaseline(
                    $targetPieces,
                    $lower,
                    $directBaselines[$lower],
                    $upper,
                    $directBaselines[$upper],
                );
                $this->upsertBaseline($playerId, $targetPieces, $baseline, 0, $now, 'interpolated');
                $interpolatedCount++;
            } else {
                // Extrapolated: use closest baseline + scaling exponent
                $closestPc = $lower ?? $upper;
                assert($closestPc !== null);

                $baseline = $this->baselineCalculator->extrapolateBaseline(
                    $targetPieces,
                    $closestPc,
                    $directBaselines[$closestPc],
                    $scalingExponent,
                );
                $this->upsertBaseline($playerId, $targetPieces, $baseline, 0, $now, 'extrapolated');
                $interpolatedCount++;
            }
        }

        return [
            'direct' => $directCount,
            'interpolated' => $interpolatedCount,
            'scaling_exponent' => $scalingExponent,
        ];
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

        // Pass 1: Compute all metrics. Memorability returns raw puzzle learning rate.
        $allMetrics = [];

        foreach ($puzzleIds as $puzzleId) {
            $metrics = $this->derivedMetricsCalculator->calculateForPuzzle($puzzleId);
            $allMetrics[$puzzleId] = $metrics;
        }

        // Pass 2: Compute global learning rate and normalize memorability
        $rawLearningRates = [];

        foreach ($allMetrics as $metrics) {
            if ($metrics['memorability_score'] !== null) {
                $rawLearningRates[] = $metrics['memorability_score'];
            }
        }

        $globalLearningRate = $rawLearningRates !== [] ? $this->computeMedianFromArray($rawLearningRates) : null;

        // Pass 3: Write normalized metrics
        foreach ($allMetrics as $puzzleId => $metrics) {
            // Normalize memorability against global learning rate
            if ($metrics['memorability_score'] !== null && $globalLearningRate !== null && $globalLearningRate > 0) {
                $metrics['memorability_score'] = round($metrics['memorability_score'] / $globalLearningRate, 3);
            }

            $this->updateDerivedMetrics($puzzleId, $metrics);
            $count++;
        }

        return $count;
    }

    /**
     * @param list<float> $values
     */
    private function computeMedianFromArray(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2.0;
        }

        return $values[$mid];
    }

    private function computePlayerSkills(\DateTimeImmutable $now, null|string $specificPlayer): int
    {
        // v2: All players (including private) get skill scores
        $players = $this->getPlayersWithSolves($specificPlayer);
        $count = 0;

        foreach ($players as $playerId) {
            foreach (self::SKILL_PIECES_COUNTS as $piecesCount) {
                $result = $this->skillCalculator->calculateForPlayer($playerId, $piecesCount);

                if ($result !== null) {
                    $this->upsertSkill($playerId, $piecesCount, $result, $now);
                    $count++;
                } else {
                    $this->deleteSkill($playerId, $piecesCount);
                }
            }
        }

        // Clean up skill data for piece counts no longer computed
        $this->connection->executeStatement(
            'DELETE FROM player_skill WHERE pieces_count != ALL(:pieceCounts)',
            ['pieceCounts' => '{' . implode(',', self::SKILL_PIECES_COUNTS) . '}'],
        );

        return $count;
    }

    private function computeEloRatings(\DateTimeImmutable $now, null|string $specificPlayer): int
    {
        // v2: Public players only for ELO
        $players = $this->getPublicPlayersWithSolves($specificPlayer);
        $count = 0;

        foreach (self::ELO_PIECES_COUNTS as $piecesCount) {
            // Pre-compute puzzle ranking data once for this piece count
            $this->eloCalculator->precomputePuzzleRankings($piecesCount);

            foreach ($players as $playerId) {
                $eloRating = $this->eloCalculator->calculateForPlayer($playerId, $piecesCount);

                if ($eloRating > 0.0) {
                    $this->upsertElo($playerId, $piecesCount, $eloRating, $now);
                    $count++;
                }
            }

            $this->eloCalculator->clearCache();
        }

        // Clean up ELO data for piece counts no longer computed
        $this->connection->executeStatement(
            'DELETE FROM player_elo WHERE pieces_count != ALL(:pieceCounts)',
            ['pieceCounts' => '{' . implode(',', self::ELO_PIECES_COUNTS) . '}'],
        );

        // Clean up ELO data for private players
        $this->connection->executeStatement(
            'DELETE FROM player_elo WHERE player_id IN (SELECT id FROM player WHERE is_private = true)',
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
    private function getPublicPlayersWithSolves(null|string $specificPlayer): array
    {
        if ($specificPlayer !== null) {
            return [$specificPlayer];
        }

        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT pst.player_id FROM puzzle_solving_time pst INNER JOIN player p ON p.id = pst.player_id WHERE pst.seconds_to_solve IS NOT NULL AND p.is_private = false',
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

    private function upsertBaseline(string $playerId, int $piecesCount, int $baselineSeconds, int $qualifyingCount, \DateTimeImmutable $now, string $baselineType = 'direct'): void
    {
        $this->connection->executeStatement("
            INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, baseline_type, computed_at)
            VALUES (gen_random_uuid(), :playerId, :piecesCount, :baselineSeconds, :qualifyingCount, :baselineType, :now)
            ON CONFLICT (player_id, pieces_count) DO UPDATE SET
                baseline_seconds = EXCLUDED.baseline_seconds,
                qualifying_solves_count = EXCLUDED.qualifying_solves_count,
                baseline_type = EXCLUDED.baseline_type,
                computed_at = EXCLUDED.computed_at
        ", [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
            'baselineSeconds' => $baselineSeconds,
            'qualifyingCount' => $qualifyingCount,
            'baselineType' => $baselineType,
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
     * @param array{memorability_score: float|null, skill_sensitivity_score: float|null, predictability_score: float|null, box_dependence_score: float|null, improvement_ceiling_score: float|null} $metrics
     */
    private function updateDerivedMetrics(string $puzzleId, array $metrics): void
    {
        $this->connection->executeStatement("
            UPDATE puzzle_difficulty SET
                memorability_score = :memorability,
                skill_sensitivity_score = :skillSensitivity,
                predictability_score = :predictability,
                box_dependence_score = :boxDependence,
                improvement_ceiling_score = :improvementCeiling
            WHERE puzzle_id = :puzzleId
        ", [
            'puzzleId' => $puzzleId,
            'memorability' => $metrics['memorability_score'],
            'skillSensitivity' => $metrics['skill_sensitivity_score'],
            'predictability' => $metrics['predictability_score'],
            'boxDependence' => $metrics['box_dependence_score'],
            'improvementCeiling' => $metrics['improvement_ceiling_score'],
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

    private function upsertElo(string $playerId, int $piecesCount, float $eloRating, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement("
            INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at)
            VALUES (gen_random_uuid(), :playerId, :piecesCount, :eloRating, :now)
            ON CONFLICT (player_id, pieces_count) DO UPDATE SET
                elo_rating = EXCLUDED.elo_rating,
                computed_at = EXCLUDED.computed_at
        ", [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
            'eloRating' => $eloRating,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
