<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Value\SkillTier;

readonly final class PuzzleIntelligenceRecalculator
{
    /** @var list<int> Piece counts for which player skills are computed */
    public const array SKILL_PIECES_COUNTS = [500];

    /** @var list<int> Piece counts for which MSP Rating is computed */
    public const array RATING_PIECES_COUNTS = [500];

    private const int BATCH_SIZE = 200;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private PlayerBaselineCalculator $baselineCalculator,
        private PuzzleDifficultyCalculator $difficultyCalculator,
        private PlayerSkillCalculator $skillCalculator,
        private DerivedMetricsCalculator $derivedMetricsCalculator,
        private MspRatingCalculator $ratingCalculator,
        private ImprovementRatioCalculator $improvementRatioCalculator,
    ) {
    }

    /**
     * @return array{baselines_direct: int, baselines_interpolated: int, scaling_exponent: float, difficulties: int, metrics: int, improvement_ratios: int, skills: int, rating: int, history: int, snapshots: int}
     */
    public function recalculate(null|string $specificPlayer = null, null|string $specificPuzzle = null): array
    {
        $now = $this->clock->now();

        /*
         * Recalculation dependency chain — each level depends on previous ones.
         * DO NOT reorder these steps.
         *
         * Level 1: Global scaling exponent (computed within baselines)
         * Level 2: Player baselines (direct + interpolated/extrapolated)
         *          Improvement ratios (independent — uses raw solve times only)
         * Level 3: Puzzle difficulty (uses baselines for difficulty indices)
         * Level 4: Global learning rate + derived metrics (uses difficulty)
         * Level 5: Player skills + MSP Rating + personality metrics (use difficulty)
         * Level 6: Rating snapshots + skill history (uses skills + rating)
         */

        // Level 1+2: Baselines + improvement ratios (independent)
        $baselines = $this->computeBaselines($now, $specificPlayer);
        $improvementRatios = $this->computeImprovementRatios($now, $specificPlayer);

        // Level 3: Puzzle difficulty
        $difficulties = $this->computePuzzleDifficulty($now, $specificPuzzle);

        // Level 4+5: Derived metrics, player skills, MSP Rating
        $metrics = $this->computeDerivedMetrics($specificPuzzle);
        $skills = $this->computePlayerSkills($now, $specificPlayer);
        $rating = $this->computeRatings($now, $specificPlayer);

        // Level 6: History + snapshots
        $history = $this->recordSkillHistory($now);
        $snapshots = $this->recordRatingSnapshots($now);

        return [
            'baselines_direct' => $baselines['direct'],
            'baselines_interpolated' => $baselines['interpolated'],
            'scaling_exponent' => $baselines['scaling_exponent'],
            'difficulties' => $difficulties,
            'metrics' => $metrics,
            'improvement_ratios' => $improvementRatios,
            'skills' => $skills,
            'rating' => $rating,
            'history' => $history,
            'snapshots' => $snapshots,
        ];
    }

    /**
     * @return array{direct: int, interpolated: int, scaling_exponent: float}
     */
    private function computeBaselines(\DateTimeImmutable $now, null|string $specificPlayer): array
    {
        // Preload all first-attempt data in one query (skip for single-player mode)
        if ($specificPlayer === null) {
            $this->baselineCalculator->preloadAllFirstAttempts();
        }

        // Pass 1: Compute direct baselines
        $players = $this->getPlayersWithSolves($specificPlayer);
        $pieceCounts = $this->getDistinctPieceCounts();
        $directCount = 0;
        $buffer = [];

        foreach ($players as $playerId) {
            foreach ($pieceCounts as $piecesCount) {
                $result = $this->baselineCalculator->calculateForPlayer($playerId, $piecesCount);

                if ($result !== null) {
                    $buffer[] = [
                        'player_id' => $playerId,
                        'pieces_count' => $piecesCount,
                        'baseline_seconds' => $result['baseline_seconds'],
                        'qualifying_solves_count' => $result['qualifying_count'],
                        'baseline_type' => 'direct',
                        'computed_at' => $now->format('Y-m-d H:i:s'),
                    ];
                    $directCount++;

                    if (count($buffer) >= self::BATCH_SIZE) {
                        $this->flushBaselineBuffer($buffer);
                        $buffer = [];
                    }
                }
            }
        }

        if ($buffer !== []) {
            $this->flushBaselineBuffer($buffer);
        }

        $this->baselineCalculator->clearPreloadedData();

        // Clean up stale direct baselines so gap detection works correctly
        $this->connection->executeStatement(
            "DELETE FROM player_baseline WHERE baseline_type = 'direct' AND computed_at < :now",
            ['now' => $now->format('Y-m-d H:i:s')],
        );

        // Pass 2: Compute global scaling exponent + interpolated/extrapolated baselines
        $scalingExponent = $this->baselineCalculator->computeScalingExponent();
        $gaps = $this->baselineCalculator->findBaselineGaps();
        $interpolatedCount = 0;

        // Cache direct baselines per player to avoid repeated queries
        $playerBaselineCache = [];
        $buffer = [];

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
                $baselineType = 'interpolated';
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
                $baselineType = 'extrapolated';
            }

            $buffer[] = [
                'player_id' => $playerId,
                'pieces_count' => $targetPieces,
                'baseline_seconds' => $baseline,
                'qualifying_solves_count' => 0,
                'baseline_type' => $baselineType,
                'computed_at' => $now->format('Y-m-d H:i:s'),
            ];
            $interpolatedCount++;

            if (count($buffer) >= self::BATCH_SIZE) {
                $this->flushBaselineBuffer($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->flushBaselineBuffer($buffer);
        }

        // Clean up all stale baselines (direct cleanup already happened above, this catches interpolated/extrapolated)
        $this->connection->executeStatement(
            'DELETE FROM player_baseline WHERE computed_at < :now',
            ['now' => $now->format('Y-m-d H:i:s')],
        );

        return [
            'direct' => $directCount,
            'interpolated' => $interpolatedCount,
            'scaling_exponent' => $scalingExponent,
        ];
    }

    private function computePuzzleDifficulty(\DateTimeImmutable $now, null|string $specificPuzzle): int
    {
        if ($specificPuzzle === null) {
            $this->difficultyCalculator->preloadAllData();
        }

        $puzzleIds = $this->getPuzzlesWithSolves($specificPuzzle);
        $count = 0;
        $buffer = [];

        foreach ($puzzleIds as $puzzleId) {
            $result = $this->difficultyCalculator->calculateForPuzzle($puzzleId);

            $buffer[] = [
                'puzzle_id' => $puzzleId,
                'difficulty_score' => $result['difficulty_score'],
                'difficulty_tier' => $result['difficulty_tier']?->value,
                'confidence' => $result['confidence']->value,
                'sample_size' => $result['sample_size'],
                'indices_p25' => $result['indices_p25'],
                'indices_p75' => $result['indices_p75'],
                'computed_at' => $now->format('Y-m-d H:i:s'),
            ];

            if ($result['difficulty_score'] !== null) {
                $count++;
            }

            if (count($buffer) >= self::BATCH_SIZE) {
                $this->flushDifficultyBuffer($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->flushDifficultyBuffer($buffer);
        }

        $this->difficultyCalculator->clearPreloadedData();

        return $count;
    }

    private function computeDerivedMetrics(null|string $specificPuzzle): int
    {
        if ($specificPuzzle === null) {
            $this->derivedMetricsCalculator->preloadAllData();
        }

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
        $buffer = [];

        foreach ($allMetrics as $puzzleId => $metrics) {
            // Normalize memorability against global learning rate
            if ($metrics['memorability_score'] !== null && $globalLearningRate !== null && $globalLearningRate > 0) {
                $metrics['memorability_score'] = round($metrics['memorability_score'] / $globalLearningRate, 3);
            }

            $buffer[] = [
                'puzzle_id' => $puzzleId,
                'memorability_score' => $metrics['memorability_score'],
                'skill_sensitivity_score' => $metrics['skill_sensitivity_score'],
                'predictability_score' => $metrics['predictability_score'],
                'box_dependence_score' => $metrics['box_dependence_score'],
                'improvement_ceiling_score' => $metrics['improvement_ceiling_score'],
            ];
            $count++;

            if (count($buffer) >= self::BATCH_SIZE) {
                $this->flushDerivedMetricsBuffer($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->flushDerivedMetricsBuffer($buffer);
        }

        $this->derivedMetricsCalculator->clearPreloadedData();

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
        // v2: All players (including private) get skill scores.
        // Two-pass approach: compute all scores first, then percentiles from in-memory data.
        $players = $this->getPlayersWithSolves($specificPlayer);
        $count = 0;

        foreach (self::SKILL_PIECES_COUNTS as $piecesCount) {
            if ($specificPlayer === null) {
                $this->skillCalculator->preloadPuzzleData($piecesCount);
            }

            // Pass 1: Compute all raw skill scores
            /** @var array<string, array{skill_score: float, confidence: \SpeedPuzzling\Web\Value\MetricConfidence, qualifying_puzzles_count: int}> $scores */
            $scores = [];
            $allScoreValues = [];

            foreach ($players as $playerId) {
                $result = $this->skillCalculator->calculateScoreForPlayer($playerId, $piecesCount);

                if ($result !== null) {
                    $scores[$playerId] = $result;
                    $allScoreValues[] = $result['skill_score'];
                }
            }

            $this->skillCalculator->clearPreloadedData();

            // Pass 2: Compute percentiles from in-memory sorted scores, write results
            sort($allScoreValues);
            $totalScores = count($allScoreValues);
            $buffer = [];

            foreach ($scores as $playerId => $result) {
                // Binary search: count of values <= skillScore in sorted array
                $lo = 0;
                $hi = $totalScores;

                while ($lo < $hi) {
                    $mid = intdiv($lo + $hi, 2);

                    if ($allScoreValues[$mid] <= $result['skill_score']) {
                        $lo = $mid + 1;
                    } else {
                        $hi = $mid;
                    }
                }

                $percentile = $totalScores > 0 ? ($lo / $totalScores) * 100.0 : 50.0;

                $buffer[] = [
                    'player_id' => $playerId,
                    'pieces_count' => $piecesCount,
                    'skill_score' => $result['skill_score'],
                    'skill_tier' => SkillTier::fromPercentile($percentile)->value,
                    'skill_percentile' => round($percentile, 2),
                    'confidence' => $result['confidence']->value,
                    'qualifying_puzzles_count' => $result['qualifying_puzzles_count'],
                    'computed_at' => $now->format('Y-m-d H:i:s'),
                ];
                $count++;

                if (count($buffer) >= self::BATCH_SIZE) {
                    $this->flushSkillBuffer($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $this->flushSkillBuffer($buffer);
            }
        }

        // Clean up: remove entries not updated in this run (players who no longer qualify)
        $this->connection->executeStatement(
            'DELETE FROM player_skill WHERE computed_at < :now',
            ['now' => $now->format('Y-m-d H:i:s')],
        );

        // Clean up skill data for piece counts no longer computed
        $this->connection->executeStatement(
            'DELETE FROM player_skill WHERE pieces_count != ALL(:pieceCounts)',
            ['pieceCounts' => '{' . implode(',', self::SKILL_PIECES_COUNTS) . '}'],
        );

        return $count;
    }

    private function computeRatings(\DateTimeImmutable $now, null|string $specificPlayer): int
    {
        // v2: Upsert-only approach — no DELETE before recompute.
        // Data is always present during recalculation (zero downtime).
        // Stale entries are cleaned up at the end using computed_at timestamp.
        $players = $this->getPublicPlayersWithSolves($specificPlayer);
        $count = 0;

        foreach (self::RATING_PIECES_COUNTS as $piecesCount) {
            $this->ratingCalculator->precomputePuzzleRankings($piecesCount);

            if ($specificPlayer === null) {
                $this->ratingCalculator->preloadAllPlayerSolves($piecesCount);
            }

            $buffer = [];

            foreach ($players as $playerId) {
                $playerRating = $this->ratingCalculator->calculateForPlayer($playerId, $piecesCount);

                if ($playerRating > 0.0) {
                    $buffer[] = [
                        'player_id' => $playerId,
                        'pieces_count' => $piecesCount,
                        'elo_rating' => $playerRating,
                        'computed_at' => $now->format('Y-m-d H:i:s'),
                    ];
                    $count++;

                    if (count($buffer) >= self::BATCH_SIZE) {
                        $this->flushRatingBuffer($buffer);
                        $buffer = [];
                    }
                }
            }

            if ($buffer !== []) {
                $this->flushRatingBuffer($buffer);
            }

            $this->ratingCalculator->clearCache();
        }

        // Clean up: remove entries not updated in this run (players who no longer qualify)
        $this->connection->executeStatement(
            'DELETE FROM player_elo WHERE computed_at < :now',
            ['now' => $now->format('Y-m-d H:i:s')],
        );

        // Clean up rating data for piece counts no longer computed
        $this->connection->executeStatement(
            'DELETE FROM player_elo WHERE pieces_count != ALL(:pieceCounts)',
            ['pieceCounts' => '{' . implode(',', self::RATING_PIECES_COUNTS) . '}'],
        );

        // Clean up rating data for private players
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

    private function recordRatingSnapshots(\DateTimeImmutable $now): int
    {
        $snapshotDate = $now->format('Y-m-d');

        // Bulk insert snapshots for all players with baselines, joining skill and rating data
        $this->connection->executeStatement("
            INSERT INTO player_rating_snapshot (id, player_id, pieces_count, snapshot_date, skill_score, skill_tier, skill_percentile, elo_rating, elo_rank, baseline_seconds, baseline_type, computed_at)
            SELECT
                gen_random_uuid(),
                pb.player_id,
                pb.pieces_count,
                :snapshotDate::timestamp,
                ps.skill_score,
                ps.skill_tier,
                ps.skill_percentile,
                pe.elo_rating,
                (SELECT COUNT(*) FROM player_elo pe2 INNER JOIN player p2 ON p2.id = pe2.player_id WHERE pe2.pieces_count = pb.pieces_count AND p2.is_private = false AND pe2.elo_rating >= pe.elo_rating),
                pb.baseline_seconds,
                pb.baseline_type,
                :now
            FROM player_baseline pb
            LEFT JOIN player_skill ps ON ps.player_id = pb.player_id AND ps.pieces_count = pb.pieces_count
            LEFT JOIN player_elo pe ON pe.player_id = pb.player_id AND pe.pieces_count = pb.pieces_count
            ON CONFLICT (player_id, pieces_count, snapshot_date) DO UPDATE SET
                skill_score = EXCLUDED.skill_score,
                skill_tier = EXCLUDED.skill_tier,
                skill_percentile = EXCLUDED.skill_percentile,
                elo_rating = EXCLUDED.elo_rating,
                elo_rank = EXCLUDED.elo_rank,
                baseline_seconds = EXCLUDED.baseline_seconds,
                baseline_type = EXCLUDED.baseline_type,
                computed_at = EXCLUDED.computed_at
        ", [
            'snapshotDate' => $snapshotDate,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);

        /** @var int|string $count */
        $count = $this->connection->fetchOne("
            SELECT COUNT(*) FROM player_rating_snapshot WHERE snapshot_date = :snapshotDate::timestamp
        ", ['snapshotDate' => $snapshotDate]);

        return (int) $count;
    }

    private function computeImprovementRatios(\DateTimeImmutable $now, null|string $specificPlayer): int
    {
        $count = 0;

        // Preload all transition data in one query (skip for single-player mode)
        if ($specificPlayer === null) {
            $this->improvementRatioCalculator->preloadAllTransitions();
        }

        // Global ratios: per piece count (skip when recalculating a single player)
        if ($specificPlayer === null) {
            $pieceCounts = $this->getDistinctPieceCounts();
            $buffer = [];

            foreach ($pieceCounts as $piecesCount) {
                $ratios = $this->improvementRatioCalculator->computeGlobalRatios($piecesCount);

                foreach ($ratios as $ratio) {
                    $buffer[] = [
                        'pieces_count' => $piecesCount,
                        'from_attempt' => $ratio['from_attempt'],
                        'gap_bucket' => $ratio['gap_bucket'],
                        'median_ratio' => $ratio['median_ratio'],
                        'sample_size' => $ratio['sample_size'],
                        'computed_at' => $now->format('Y-m-d H:i:s'),
                    ];
                    $count++;

                    if (count($buffer) >= self::BATCH_SIZE) {
                        $this->flushGlobalImprovementRatioBuffer($buffer);
                        $buffer = [];
                    }
                }
            }

            if ($buffer !== []) {
                $this->flushGlobalImprovementRatioBuffer($buffer);
            }

            $this->connection->executeStatement(
                'DELETE FROM global_improvement_ratio WHERE computed_at < :now',
                ['now' => $now->format('Y-m-d H:i:s')],
            );
        }

        // Player ratios: per player (cross-piece-count)
        $players = $this->getPlayersWithSolves($specificPlayer);
        $buffer = [];

        foreach ($players as $playerId) {
            $ratios = $this->improvementRatioCalculator->calculateForPlayer($playerId);

            foreach ($ratios as $ratio) {
                $buffer[] = [
                    'player_id' => $playerId,
                    'from_attempt' => $ratio['from_attempt'],
                    'median_ratio' => $ratio['median_ratio'],
                    'sample_size' => $ratio['sample_size'],
                    'computed_at' => $now->format('Y-m-d H:i:s'),
                ];
                $count++;

                if (count($buffer) >= self::BATCH_SIZE) {
                    $this->flushPlayerImprovementRatioBuffer($buffer);
                    $buffer = [];
                }
            }
        }

        if ($buffer !== []) {
            $this->flushPlayerImprovementRatioBuffer($buffer);
        }

        $this->improvementRatioCalculator->clearPreloadedData();

        $this->connection->executeStatement(
            'DELETE FROM player_improvement_ratio WHERE computed_at < :now',
            ['now' => $now->format('Y-m-d H:i:s')],
        );

        return $count;
    }

    /**
     * @param list<array{pieces_count: int, from_attempt: int, gap_bucket: string, median_ratio: float, sample_size: int, computed_at: string}> $rows
     */
    private function flushGlobalImprovementRatioBuffer(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = ['pieces_count', 'from_attempt', 'gap_bucket', 'median_ratio', 'sample_size', 'computed_at'];
        $values = $this->buildValuesClause($rows, $columns, 'gen_random_uuid()');

        $this->connection->executeStatement("
            INSERT INTO global_improvement_ratio (id, pieces_count, from_attempt, gap_bucket, median_ratio, sample_size, computed_at)
            VALUES {$values['sql']}
            ON CONFLICT (pieces_count, from_attempt, gap_bucket) DO UPDATE SET
                median_ratio = EXCLUDED.median_ratio,
                sample_size = EXCLUDED.sample_size,
                computed_at = EXCLUDED.computed_at
        ", $values['params']);
    }

    /**
     * @param list<array{player_id: string, from_attempt: int, median_ratio: float, sample_size: int, computed_at: string}> $rows
     */
    private function flushPlayerImprovementRatioBuffer(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = ['player_id', 'from_attempt', 'median_ratio', 'sample_size', 'computed_at'];
        $values = $this->buildValuesClause($rows, $columns, 'gen_random_uuid()');

        $this->connection->executeStatement("
            INSERT INTO player_improvement_ratio (id, player_id, from_attempt, median_ratio, sample_size, computed_at)
            VALUES {$values['sql']}
            ON CONFLICT (player_id, from_attempt) DO UPDATE SET
                median_ratio = EXCLUDED.median_ratio,
                sample_size = EXCLUDED.sample_size,
                computed_at = EXCLUDED.computed_at
        ", $values['params']);
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

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     * @return array{sql: string, params: array<string, mixed>}
     */
    private function buildValuesClause(array $rows, array $columns, null|string $idExpression = null): array
    {
        $valuesClauses = [];
        $params = [];

        foreach ($rows as $i => $row) {
            $placeholders = [];

            if ($idExpression !== null) {
                $placeholders[] = $idExpression;
            }

            foreach ($columns as $col) {
                $paramName = "r{$i}_{$col}";
                $placeholders[] = ":{$paramName}";
                $params[$paramName] = $row[$col];
            }

            $valuesClauses[] = '(' . implode(', ', $placeholders) . ')';
        }

        return [
            'sql' => implode(', ', $valuesClauses),
            'params' => $params,
        ];
    }

    /**
     * @param list<array{player_id: string, pieces_count: int, baseline_seconds: int, qualifying_solves_count: int, baseline_type: string, computed_at: string}> $rows
     */
    private function flushBaselineBuffer(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = ['player_id', 'pieces_count', 'baseline_seconds', 'qualifying_solves_count', 'baseline_type', 'computed_at'];
        $values = $this->buildValuesClause($rows, $columns, 'gen_random_uuid()');

        $this->connection->executeStatement("
            INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, baseline_type, computed_at)
            VALUES {$values['sql']}
            ON CONFLICT (player_id, pieces_count) DO UPDATE SET
                baseline_seconds = EXCLUDED.baseline_seconds,
                qualifying_solves_count = EXCLUDED.qualifying_solves_count,
                baseline_type = EXCLUDED.baseline_type,
                computed_at = EXCLUDED.computed_at
        ", $values['params']);
    }

    /**
     * @param list<array{puzzle_id: string, difficulty_score: float|null, difficulty_tier: int|null, confidence: string, sample_size: int, indices_p25: float|null, indices_p75: float|null, computed_at: string}> $rows
     */
    private function flushDifficultyBuffer(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = ['puzzle_id', 'difficulty_score', 'difficulty_tier', 'confidence', 'sample_size', 'indices_p25', 'indices_p75', 'computed_at'];
        $values = $this->buildValuesClause($rows, $columns);

        $this->connection->executeStatement("
            INSERT INTO puzzle_difficulty (puzzle_id, difficulty_score, difficulty_tier, confidence, sample_size, indices_p25, indices_p75, computed_at)
            VALUES {$values['sql']}
            ON CONFLICT (puzzle_id) DO UPDATE SET
                difficulty_score = EXCLUDED.difficulty_score,
                difficulty_tier = EXCLUDED.difficulty_tier,
                confidence = EXCLUDED.confidence,
                sample_size = EXCLUDED.sample_size,
                indices_p25 = EXCLUDED.indices_p25,
                indices_p75 = EXCLUDED.indices_p75,
                computed_at = EXCLUDED.computed_at
        ", $values['params']);
    }

    /**
     * @param list<array{puzzle_id: string, memorability_score: float|null, skill_sensitivity_score: float|null, predictability_score: float|null, box_dependence_score: float|null, improvement_ceiling_score: float|null}> $rows
     */
    private function flushDerivedMetricsBuffer(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $valuesClauses = [];
        $params = [];

        foreach ($rows as $i => $row) {
            $valuesClauses[] = "(:r{$i}_puzzle_id::uuid, :r{$i}_memorability::double precision, :r{$i}_skill_sensitivity::double precision, :r{$i}_predictability::double precision, :r{$i}_box_dependence::double precision, :r{$i}_improvement_ceiling::double precision)";
            $params["r{$i}_puzzle_id"] = $row['puzzle_id'];
            $params["r{$i}_memorability"] = $row['memorability_score'];
            $params["r{$i}_skill_sensitivity"] = $row['skill_sensitivity_score'];
            $params["r{$i}_predictability"] = $row['predictability_score'];
            $params["r{$i}_box_dependence"] = $row['box_dependence_score'];
            $params["r{$i}_improvement_ceiling"] = $row['improvement_ceiling_score'];
        }

        $valuesClause = implode(', ', $valuesClauses);

        $this->connection->executeStatement("
            UPDATE puzzle_difficulty AS pd SET
                memorability_score = v.memorability_score,
                skill_sensitivity_score = v.skill_sensitivity_score,
                predictability_score = v.predictability_score,
                box_dependence_score = v.box_dependence_score,
                improvement_ceiling_score = v.improvement_ceiling_score
            FROM (VALUES {$valuesClause}) AS v(puzzle_id, memorability_score, skill_sensitivity_score, predictability_score, box_dependence_score, improvement_ceiling_score)
            WHERE pd.puzzle_id = v.puzzle_id
        ", $params);
    }

    /**
     * @param list<array{player_id: string, pieces_count: int, skill_score: float, skill_tier: int, skill_percentile: float, confidence: string, qualifying_puzzles_count: int, computed_at: string}> $rows
     */
    private function flushSkillBuffer(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = ['player_id', 'pieces_count', 'skill_score', 'skill_tier', 'skill_percentile', 'confidence', 'qualifying_puzzles_count', 'computed_at'];
        $values = $this->buildValuesClause($rows, $columns, 'gen_random_uuid()');

        $this->connection->executeStatement("
            INSERT INTO player_skill (id, player_id, pieces_count, skill_score, skill_tier, skill_percentile, confidence, qualifying_puzzles_count, computed_at)
            VALUES {$values['sql']}
            ON CONFLICT (player_id, pieces_count) DO UPDATE SET
                skill_score = EXCLUDED.skill_score,
                skill_tier = EXCLUDED.skill_tier,
                skill_percentile = EXCLUDED.skill_percentile,
                confidence = EXCLUDED.confidence,
                qualifying_puzzles_count = EXCLUDED.qualifying_puzzles_count,
                computed_at = EXCLUDED.computed_at
        ", $values['params']);
    }

    /**
     * @param list<array{player_id: string, pieces_count: int, elo_rating: float, computed_at: string}> $rows
     */
    private function flushRatingBuffer(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = ['player_id', 'pieces_count', 'elo_rating', 'computed_at'];
        $values = $this->buildValuesClause($rows, $columns, 'gen_random_uuid()');

        $this->connection->executeStatement("
            INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at)
            VALUES {$values['sql']}
            ON CONFLICT (player_id, pieces_count) DO UPDATE SET
                elo_rating = EXCLUDED.elo_rating,
                computed_at = EXCLUDED.computed_at
        ", $values['params']);
    }
}
