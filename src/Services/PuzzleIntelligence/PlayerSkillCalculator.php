<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\SkillTier;
use Symfony\Contracts\Service\ResetInterface;

final class PlayerSkillCalculator implements ResetInterface
{
    private const int MINIMUM_QUALIFYING_PUZZLES = 20;
    private const int MIN_SOLVERS_PER_PUZZLE = 20;
    private const float DIFFICULTY_BLEND = 0.5;
    private const int DIFF_CONFIDENCE_THRESHOLD = 50;

    /**
     * Pre-computed puzzle data: sorted first-attempt times + difficulty info.
     *
     * @var array<string, array{sorted_times: list<int>, difficulty_score: float, sample_size: int}>|null
     */
    private null|array $puzzleCache = null;

    /**
     * Per-player first-attempt times per puzzle.
     *
     * @var array<string, array<string, int>>|null player_id => puzzle_id => time
     */
    private null|array $playerPuzzleTimesCache = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Bulk-load all puzzle ranking data and player times for a piece count.
     * Call once before the per-player loop.
     */
    public function preloadPuzzleData(int $piecesCount): void
    {
        // Load puzzle difficulty data
        /** @var list<array{puzzle_id: string, difficulty_score: float|string, sample_size: int|string}> $difficulties */
        $difficulties = $this->connection->fetchAllAssociative("
            SELECT pd.puzzle_id, pd.difficulty_score, pd.sample_size
            FROM puzzle_difficulty pd
            JOIN puzzle p ON p.id = pd.puzzle_id
            WHERE p.pieces_count = :piecesCount
                AND pd.difficulty_score IS NOT NULL
                AND pd.confidence != 'insufficient'
        ", ['piecesCount' => $piecesCount]);

        $difficultyMap = [];

        foreach ($difficulties as $row) {
            $difficultyMap[$row['puzzle_id']] = [
                'difficulty_score' => (float) $row['difficulty_score'],
                'sample_size' => (int) $row['sample_size'],
            ];
        }

        // Load all first-attempt times per puzzle per player
        /** @var list<array{puzzle_id: string, player_id: string, seconds_to_solve: int|string}> $firstAttempts */
        $firstAttempts = $this->connection->fetchAllAssociative("
            SELECT pst.puzzle_id, pst.player_id, pst.seconds_to_solve
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            WHERE p.pieces_count = :piecesCount
                AND pst.first_attempt = true
                AND pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
        ", ['piecesCount' => $piecesCount]);

        // Build sorted times per puzzle + player lookup
        $puzzleTimesMap = [];
        $playerTimes = [];

        foreach ($firstAttempts as $row) {
            $puzzleId = $row['puzzle_id'];
            $playerId = $row['player_id'];
            $time = (int) $row['seconds_to_solve'];

            $puzzleTimesMap[$puzzleId][] = $time;
            $playerTimes[$playerId][$puzzleId] = $time;
        }

        // Build final puzzle cache (sorted times + difficulty, filtered by min solvers)
        $puzzleCache = [];

        foreach ($puzzleTimesMap as $puzzleId => $times) {
            if (!isset($difficultyMap[$puzzleId])) {
                continue;
            }

            if (count($times) < self::MIN_SOLVERS_PER_PUZZLE) {
                continue;
            }

            sort($times);
            $puzzleCache[$puzzleId] = [
                'sorted_times' => $times,
                'difficulty_score' => $difficultyMap[$puzzleId]['difficulty_score'],
                'sample_size' => $difficultyMap[$puzzleId]['sample_size'],
            ];
        }

        $this->puzzleCache = $puzzleCache;
        $this->playerPuzzleTimesCache = $playerTimes;
    }

    public function clearPreloadedData(): void
    {
        $this->puzzleCache = null;
        $this->playerPuzzleTimesCache = null;
    }

    public function reset(): void
    {
        $this->puzzleCache = null;
        $this->playerPuzzleTimesCache = null;
    }

    /**
     * @return array{
     *     skill_score: float,
     *     skill_tier: SkillTier,
     *     skill_percentile: float,
     *     confidence: MetricConfidence,
     *     qualifying_puzzles_count: int,
     * }|null
     */
    public function calculateForPlayer(string $playerId, int $piecesCount): null|array
    {
        $weightedPercentiles = $this->computeWeightedPercentiles($playerId, $piecesCount);

        if (count($weightedPercentiles) < self::MINIMUM_QUALIFYING_PUZZLES) {
            return null;
        }

        $skillScore = $this->computeMedian($weightedPercentiles);
        $percentile = $this->computePercentile($piecesCount, $skillScore);
        $confidence = MetricConfidence::fromSampleSize(count($weightedPercentiles), self::MINIMUM_QUALIFYING_PUZZLES);

        return [
            'skill_score' => round($skillScore, 6),
            'skill_tier' => SkillTier::fromPercentile($percentile),
            'skill_percentile' => round($percentile, 2),
            'confidence' => $confidence,
            'qualifying_puzzles_count' => count($weightedPercentiles),
        ];
    }

    /**
     * v2: For each puzzle the player solved (first attempt), compute:
     *   percentile = (slower_count + tied_count/2) / (total - 1)
     *   difficulty_weight = (1 - blend*confidence) + blend*confidence * difficulty_score
     *   weighted_percentile = percentile × difficulty_weight
     *
     * @return list<float>
     */
    private function computeWeightedPercentiles(string $playerId, int $piecesCount): array
    {
        if ($this->puzzleCache !== null && $this->playerPuzzleTimesCache !== null) {
            return $this->computeWeightedPercentilesFromCache($playerId);
        }

        return $this->computeWeightedPercentilesFromDb($playerId, $piecesCount);
    }

    /**
     * @return list<float>
     */
    private function computeWeightedPercentilesFromCache(string $playerId): array
    {
        assert($this->puzzleCache !== null && $this->playerPuzzleTimesCache !== null);

        $playerTimes = $this->playerPuzzleTimesCache[$playerId] ?? [];
        $weightedPercentiles = [];

        foreach ($playerTimes as $puzzleId => $playerTime) {
            if (!isset($this->puzzleCache[$puzzleId])) {
                continue;
            }

            $puzzle = $this->puzzleCache[$puzzleId];
            $sortedTimes = $puzzle['sorted_times'];
            $totalSolvers = count($sortedTimes);

            if ($totalSolvers <= 1) {
                continue;
            }

            // Count slower and tied using sorted array
            $slowerCount = 0;
            $tiedCount = 0;

            foreach ($sortedTimes as $time) {
                if ($time > $playerTime) {
                    $slowerCount++;
                } elseif ($time === $playerTime) {
                    $tiedCount++;
                }
            }

            // Average rank method — exclude self from tied count
            $tiedOthers = max(0, $tiedCount - 1);
            $percentile = ($slowerCount + $tiedOthers / 2.0) / ($totalSolvers - 1);

            // Confidence-scaled difficulty weight
            $confidence = min(1.0, $puzzle['sample_size'] / self::DIFF_CONFIDENCE_THRESHOLD);
            $blend = self::DIFFICULTY_BLEND * $confidence;
            $difficultyWeight = (1.0 - $blend) + $blend * $puzzle['difficulty_score'];

            $weightedPercentiles[] = $percentile * $difficultyWeight;
        }

        return $weightedPercentiles;
    }

    /**
     * @return list<float>
     */
    private function computeWeightedPercentilesFromDb(string $playerId, int $piecesCount): array
    {
        $sql = "
            WITH player_first_attempts AS (
                SELECT DISTINCT ON (pst.puzzle_id)
                    pst.puzzle_id,
                    pst.seconds_to_solve
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                WHERE pst.player_id = :playerId
                    AND p.pieces_count = :piecesCount
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
                ORDER BY pst.puzzle_id,
                    pst.first_attempt DESC,
                    COALESCE(pst.finished_at, pst.tracked_at) ASC
            ),
            puzzle_rankings AS (
                SELECT
                    pfa.puzzle_id,
                    pfa.seconds_to_solve AS player_time,
                    pd.difficulty_score,
                    pd.sample_size,
                    COUNT(*) FILTER (WHERE all_fa.seconds_to_solve > pfa.seconds_to_solve) AS slower_count,
                    COUNT(*) FILTER (WHERE all_fa.seconds_to_solve = pfa.seconds_to_solve AND all_fa.player_id != :playerId) AS tied_count,
                    COUNT(*) AS total_solvers
                FROM player_first_attempts pfa
                JOIN puzzle_difficulty pd ON pd.puzzle_id = pfa.puzzle_id
                JOIN puzzle_solving_time all_fa ON all_fa.puzzle_id = pfa.puzzle_id
                    AND all_fa.puzzling_type = 'solo'
                    AND all_fa.suspicious = false
                    AND all_fa.seconds_to_solve IS NOT NULL
                    AND all_fa.first_attempt = true
                WHERE pd.difficulty_score IS NOT NULL
                    AND pd.confidence != 'insufficient'
                GROUP BY pfa.puzzle_id, pfa.seconds_to_solve, pd.difficulty_score, pd.sample_size
                HAVING COUNT(*) >= :minSolvers
            )
            SELECT
                puzzle_id,
                player_time,
                difficulty_score,
                sample_size,
                slower_count,
                tied_count,
                total_solvers
            FROM puzzle_rankings
        ";

        /** @var list<array{puzzle_id: string, player_time: int|string, difficulty_score: float|string, sample_size: int|string, slower_count: int|string, tied_count: int|string, total_solvers: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
            'minSolvers' => self::MIN_SOLVERS_PER_PUZZLE,
        ]);

        $weightedPercentiles = [];

        foreach ($rows as $row) {
            $totalSolvers = (int) $row['total_solvers'];
            $slowerCount = (int) $row['slower_count'];
            $tiedCount = (int) $row['tied_count'];
            $difficultyScore = (float) $row['difficulty_score'];
            $sampleSize = (int) $row['sample_size'];

            if ($totalSolvers <= 1) {
                continue;
            }

            // Average rank method for ties
            $percentile = ($slowerCount + $tiedCount / 2.0) / ($totalSolvers - 1);

            // Confidence-scaled difficulty weight
            $confidence = min(1.0, $sampleSize / self::DIFF_CONFIDENCE_THRESHOLD);
            $blend = self::DIFFICULTY_BLEND * $confidence;
            $difficultyWeight = (1.0 - $blend) + $blend * $difficultyScore;

            $weightedPercentiles[] = $percentile * $difficultyWeight;
        }

        return $weightedPercentiles;
    }

    /**
     * Compute what percentile this player's skill score falls in
     * among all players with valid skill scores for this piece count.
     */
    private function computePercentile(int $piecesCount, float $skillScore): float
    {
        $result = $this->connection->fetchAssociative("
            SELECT
                COUNT(*) FILTER (WHERE skill_score <= :skillScore) AS below_or_equal,
                COUNT(*) AS total
            FROM player_skill
            WHERE pieces_count = :piecesCount
        ", [
            'skillScore' => $skillScore,
            'piecesCount' => $piecesCount,
        ]);

        /** @var array{below_or_equal: int|string, total: int|string}|false $result */
        if ($result === false || (int) $result['total'] === 0) {
            return 50.0;
        }

        $total = (int) $result['total'];
        $belowOrEqual = (int) $result['below_or_equal'];

        return ($belowOrEqual / $total) * 100.0;
    }

    /**
     * @param list<float> $values
     */
    private function computeMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2.0;
        }

        return $values[$mid];
    }
}
